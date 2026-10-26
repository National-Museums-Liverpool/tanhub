<?php

namespace App\Services\Export;

use App\Models\ExportJobModel;
use App\Services\Search\SearchCriteria;
use App\Services\Search\TaxonSearchService;
use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Exports;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Capture immutable rows and write private CSVs through locked, resumable requests.
 */
final class ExportService
{
    /** @var BaseConnection Job, staging and source connection. */
    private BaseConnection $db;

    /** @var Exports Validated deployment limits. */
    private Exports $config;

    /** @var string Private single-host artifact root. */
    private string $root;

    /**
     * Resolve export dependencies without creating files on read-only requests.
     *
     * @param BaseConnection|null $db Explicit test connection.
     * @param Exports|null $config Export limits.
     * @param string|null $root Private test artifact root.
     */
    public function __construct(?BaseConnection $db = null, ?Exports $config = null, ?string $root = null)
    {
        $this->db = $db ?? db_connect();
        $this->config = $config ?? config(Exports::class);
        $this->root = $root ?? WRITEPATH . 'exports';
    }

    /**
     * Explain whether deployments have configured the required resource limits.
     *
     * @return string|null Actionable configuration error, or null when enabled.
     */
    public function configurationError(): ?string
    {
        foreach (['fileBudgetBytes', 'freeReserveBytes', 'stagingBudgetBytes', 'batchSize',
            'maxActivePerUser', 'retentionSeconds', 'inactivitySeconds', 'historySeconds',
            'cleanupBatchSize'] as $key) {
            if ($this->config->{$key} < 1) {
                return 'CSV exports require a positive exports.' . $key . ' deployment setting.';
            }
        }
        if (PHP_INT_SIZE < 8) {
            return 'Large CSV exports require 64-bit PHP.';
        }
        if ($this->config->xAccelRedirectPrefix !== ''
            && preg_match('~^/[a-zA-Z0-9/_-]+/$~D', $this->config->xAccelRedirectPrefix) !== 1) {
            return 'exports.xAccelRedirectPrefix must be a trusted absolute internal URL prefix ending in /.';
        }

        return null;
    }

    /**
     * List recent owned jobs without leaking other users' contracts.
     *
     * @param int $owner Staff user ID.
     * @return list<array<string, mixed>> Most recent job checkpoints.
     */
    public function recent(int $owner): array
    {
        return (new ExportJobModel($this->db))->where('owner_id', $owner)
            ->orderBy('id', 'desc')->findAll(50);
    }

    /**
     * Resolve an owned opaque job identifier.
     *
     * @param string $uuid Opaque job ID.
     * @param int $owner Staff user ID.
     * @return array<string, mixed> Job checkpoint.
     */
    public function owned(string $uuid, int $owner): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $uuid) !== 1) {
            throw new InvalidArgumentException('Export not found.');
        }
        $job = (new ExportJobModel($this->db))->where('uuid', $uuid)->where('owner_id', $owner)->first();
        if ($job === null) {
            throw new InvalidArgumentException('Export not found.');
        }

        return $job;
    }

    /**
     * Validate and freeze a new rolling-capture contract under atomic admission.
     *
     * @param int $owner Requesting user.
     * @param string $resource Taxa or occurrences.
     * @param array<string, mixed> $input Applied grid criteria.
     * @param string $template Allowlisted template.
     * @param int|null $estimatedTotal Grid result count used only for approximate progress.
     * @return array<string, mixed> New job.
     */
    public function create(int $owner, string $resource, array $input, string $template, ?int $estimatedTotal = null): array
    {
        $this->requireEnabled();
        if (! in_array($resource, ['taxa', 'occurrences'], true)) {
            throw new InvalidArgumentException('Choose taxa or occurrences.');
        }
        if ($estimatedTotal !== null && $estimatedTotal < 0) {
            throw new InvalidArgumentException('Enter a non-negative estimated export row count.');
        }

        return $this->locked(function () use ($owner, $resource, $input, $template, $estimatedTotal): array {
            $active = $this->db->table('export_jobs')->where('owner_id', $owner)
                ->whereIn('status', ['capturing', 'writing', 'paused'])->countAllResults();
            if ($active >= $this->config->maxActivePerUser) {
                throw new InvalidArgumentException('Your unfinished export limit has been reached. Resume or cancel an existing export.');
            }
            $this->checkFileBudget(0);
            $taxa = $resource === 'taxa';
            $search = new TaxonSearchService($this->db);
            $criteria = $search->criteria($input, $taxa);
            $ordering = $search->ordering($input, $taxa);
            $errors = $criteria->errors + $ordering['errors'];
            if ($errors !== []) {
                throw new InvalidArgumentException(implode(' ', $errors));
            }
            $this->checkCollation($ordering['column']);
            $classifications = $this->classificationContract($search);
            $columns = ExportTemplate::columns($taxa, $template, $classifications);
            $contract = ['version' => ExportTemplate::VERSION, 'template' => $template,
                'filters' => $criteria->values, 'ordering' => $ordering,
                'classifications' => $classifications, 'columns' => $columns,
                'estimated_total' => $estimatedTotal];
            if ($template === 'stats') {
                $contract['statistics_scope'] = $this->statisticsScope($criteria->value('geographic_region_id'));
            }
            // The ceiling excludes records inserted after this job starts without claiming a snapshot.
            $maximum = $this->db->table($resource)->selectMax('id')->get()->getRowArray();
            $uuid = bin2hex(random_bytes(16));
            $now = gmdate('Y-m-d H:i:s');
            $this->db->table('export_jobs')->insert([
                'uuid' => $uuid, 'owner_id' => $owner, 'resource' => $resource,
                'contract' => $this->json($contract), 'status' => 'capturing', 'phase' => 'capturing',
                'max_id' => $maximum['id'] ?? 0, 'created_at' => $now, 'progress_at' => $now,
            ]);

            return $this->owned($uuid, $owner);
        });
    }

    /**
     * Process exactly one recoverable batch, serializing competing tabs and requests.
     *
     * @param string $uuid Job UUID.
     * @param int $owner Current owner.
     * @return array<string, mixed> Committed progress.
     */
    public function process(string $uuid, int $owner): array
    {
        $this->requireEnabled();
        return $this->locked(function () use ($uuid, $owner): array {
            $job = $this->owned($uuid, $owner);
            if (! in_array($job['status'], ['capturing', 'writing', 'paused'], true)) {
                return $job;
            }
            if ($this->inactive($job)) {
                $this->update($job, ['status' => 'expired', 'finished_at' => gmdate('Y-m-d H:i:s')]);
                return $this->owned($uuid, $owner);
            }
            $lock = $this->fileLock($uuid, LOCK_EX);
            try {
                $contract = json_decode($job['contract'], true, 512, JSON_THROW_ON_ERROR);
                if (! in_array($contract['version'], [1, 2, ExportTemplate::VERSION], true)) {
                    throw new RuntimeException('The export template changed. Cancel and create a new export.');
                }
                if ($job['phase'] === 'capturing') {
                    $this->capture($job, $contract);
                } else {
                    $this->write($job, $contract);
                }
            } catch (Throwable $exception) {
                if ($this->db->transDepth > 0) {
                    $this->db->transRollback();
                }
                log_message('error', 'Export {uuid} batch failed: {error}', [
                    'uuid' => $uuid, 'error' => $exception->getMessage(),
                ]);
                $this->update($job, ['status' => 'paused',
                    'error' => 'Batch failed; committed progress is safe. Check server logs and limits, then Resume, regenerate CSV, or cancel.']);
                throw $exception;
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }

            return $this->owned($uuid, $owner);
        });
    }

    /**
     * Pause, cancel or regenerate output without recapturing completed staged values.
     *
     * @param string $uuid Job UUID.
     * @param int $owner Current owner.
     * @param string $action Pause, cancel or regenerate.
     * @return array<string, mixed> Updated job.
     */
    public function action(string $uuid, int $owner, string $action): array
    {
        return $this->locked(function () use ($uuid, $owner, $action): array {
            $job = $this->owned($uuid, $owner);
            if (! in_array($job['status'], ['capturing', 'writing', 'paused'], true)) {
                throw new InvalidArgumentException('This export is no longer unfinished.');
            }
            if ($action === 'cancel') {
                $changes = ['status' => 'cancelled', 'finished_at' => gmdate('Y-m-d H:i:s')];
            } elseif ($action === 'pause') {
                $changes = ['status' => 'paused', 'error' => null];
            } elseif ($action === 'regenerate' && $job['phase'] === 'writing') {
                $lock = $this->fileLock($uuid, LOCK_EX);
                try {
                    $this->removeFile($uuid . '.part');
                    $this->removeFile($uuid . '.csv');
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
                $changes = ['status' => 'paused', 'file_offset' => 0, 'written_rows' => 0,
                    'write_cursor' => null, 'error' => null];
            } else {
                throw new InvalidArgumentException('Choose a valid export action.');
            }
            $this->update($job, $changes);
            return $this->owned($uuid, $owner);
        });
    }

    /**
     * Create a protected streaming response without a whole-file read.
     *
     * @param string $uuid Job UUID.
     * @param int $owner Current owner.
     * @return ExportDownload File response holding a cleanup lock.
     */
    public function download(string $uuid, int $owner): ExportDownload
    {
        $lock = $this->fileLock($uuid, LOCK_SH);
        try {
            $job = $this->owned($uuid, $owner);
            if ($job['status'] !== 'completed' || $job['expires_at'] <= gmdate('Y-m-d H:i:s')) {
                throw new InvalidArgumentException('This export is not ready or has expired.');
            }
            $path = $this->path($uuid . '.csv');
            if (! is_file($path) || filesize($path) !== (int) $job['file_offset']) {
                throw new RuntimeException('The completed export file is missing or damaged.');
            }
            // Keep an expired artifact briefly while Nginx opens its protected file.
            $this->update($job, ['delivery_until' => gmdate('Y-m-d H:i:s', time() + 60)]);
            $uri = $this->config->xAccelRedirectPrefix === '' ? ''
                : $this->config->xAccelRedirectPrefix . $uuid . '.csv';
            return new ExportDownload($path, $job['resource'] . '-' . $uuid . '.csv', $lock, $uri);
        } catch (Throwable $exception) {
            flock($lock, LOCK_UN);
            fclose($lock);
            throw $exception;
        }
    }

    /**
     * Reclaim one bounded staging batch and expired artifacts without requiring cron.
     */
    public function cleanup(): void
    {
        $this->locked(function (): void {
            $now = gmdate('Y-m-d H:i:s');
            $cutoff = gmdate('Y-m-d H:i:s', time() - $this->config->inactivitySeconds);
            $this->db->table('export_jobs')->whereIn('status', ['capturing', 'writing', 'paused'])
                ->where('progress_at <', $cutoff)
                ->update(['status' => 'expired', 'finished_at' => $now]);
            $this->db->table('export_jobs')->where('status', 'completed')->where('expires_at <=', $now)
                ->update(['status' => 'expired']);
            $jobs = $this->db->table('export_jobs')->whereIn('status', ['completed', 'expired', 'cancelled', 'failed'])
                ->groupStart()->where('staging_cleaned', 0)
                ->orGroupStart()->where('status !=', 'completed')->where('files_cleaned', 0)->groupEnd()
                ->orWhere('finished_at <', gmdate('Y-m-d H:i:s', time() - $this->config->historySeconds))
                ->groupEnd()
                ->orderBy('id')->get(5)->getResultArray();
            foreach ($jobs as $job) {
                if ($job['delivery_until'] !== null && $job['delivery_until'] > $now) {
                    continue;
                }
                $lock = $this->fileLock($job['uuid'], LOCK_EX, false);
                if ($lock === false) {
                    continue;
                }
                $retired = false;
                try {
                    if ($job['status'] !== 'completed') {
                        $this->removeFile($job['uuid'] . '.part');
                        $this->removeFile($job['uuid'] . '.csv');
                        $this->update($job, ['files_cleaned' => 1]);
                    }
                    $ids = array_column($this->db->table('export_rows')->select('record_id')
                        ->where('job_id', $job['id'])->orderBy('record_id')
                        ->get($this->config->cleanupBatchSize)->getResultArray(), 'record_id');
                    if ($ids !== []) {
                        $this->db->table('export_rows')->where('job_id', $job['id'])->whereIn('record_id', $ids)->delete();
                    } else {
                        $this->update($job, ['staging_cleaned' => 1]);
                        if ($job['status'] !== 'completed' && $job['finished_at'] !== null
                            && $job['finished_at'] < gmdate('Y-m-d H:i:s', time() - $this->config->historySeconds)) {
                            $this->db->table('export_jobs')->where('id', $job['id'])->delete();
                            $retired = true;
                        }
                    }
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
                if ($retired) {
                    $this->removeFile($job['uuid'] . '.lock');
                }
            }
        });
    }

    /**
     * Capture one bounded eligible-ID window and atomically advance its checkpoint.
     *
     * @param array<string, mixed> $job Current job.
     * @param array<string, mixed> $contract Frozen template and query criteria.
     */
    private function capture(array $job, array $contract): void
    {
        $search = new TaxonSearchService($this->db);
        if ($this->classificationContract($search) !== $contract['classifications']) {
            throw new RuntimeException('Classification configuration changed. Create a new export.');
        }
        $taxa = $job['resource'] === 'taxa';
        $criteria = new SearchCriteria($contract['filters'], array_keys($contract['classifications']), $taxa);
        $alias = $taxa ? 't' : 'o';
        $candidateCriteria = $criteria;
        if ($taxa && $criteria->needsOccurrences()) {
            // Pick taxa by taxonomic filters first. The full query below checks occurrence
            // evidence only for this bounded ID batch, avoiding a full evidence UNION per batch.
            $candidateFilters = array_intersect_key($contract['filters'], array_flip(array_merge(
                ['q', 'taxon_group_id', 'taxon_blocked', 'taxon_id', 'taxon_name', 'rank_id'],
                array_map(static fn (string $rank): string => $rank . '_id',
                    array_keys($contract['classifications']))
            )));
            $candidateCriteria = new SearchCriteria(
                $candidateFilters, array_keys($contract['classifications']), true
            );
        }
        // Capture order is deliberately by immutable ID; grid order is frozen separately
        // in each staged row and applied later by write().
        $candidateIds = array_column($search->query($candidateCriteria, $taxa, null, true)
            ->where($alias . '.id >', $job['capture_id'])->where($alias . '.id <=', $job['max_id'])
            ->orderBy($alias . '.id')->get($this->config->batchSize)->getResultArray(), 'id');
        $query = $search->query($criteria, $taxa, $taxa ? ($candidateIds ?: [0]) : null)
            ->select($contract['ordering']['column'] . ' AS export_sort')
            ->whereIn($alias . '.id', $candidateIds === [] ? [0] : $candidateIds)
            ->orderBy($alias . '.id');
        if (! $taxa) {
            $query->select('recorded.name AS recorded_name');
        }
        $rows = $search->hydrate($query->get($this->config->batchSize)->getResultArray(), $taxa);
        if ($contract['template'] === 'stats' && $rows !== []) {
            $this->hydrateStats($rows, $contract['filters']['geographic_region_id']);
        }
        $staged = [];
        $bytes = 0;
        $numeric = in_array($contract['ordering']['sort'], ['id', 'rank_title', 'blocked', 'data_source_id',
            'taxon_id', 'taxon_name_id'], true);
        foreach ($rows as $row) {
            [$present, $value] = ExportTemplate::sortKey($row['export_sort'], $numeric);
            $secondary = ! $taxa && $contract['ordering']['sort'] === 'from_date' ? $row['to_date'] : null;
            [$datePresent, $dateValue] = ExportTemplate::sortKey($secondary, false);
            $payload = $this->json(ExportTemplate::cells($row, $contract['columns']));
            if (strlen($payload) > 60000) {
                throw new RuntimeException('A projected export row exceeds the staging payload field limit.');
            }
            $bytes += strlen($payload);
            $staged[] = ['job_id' => $job['id'], 'record_id' => $row['id'],
                'sort_present' => $present, 'sort_value' => $value,
                'date_present' => $datePresent, 'date_value' => $dateValue, 'payload' => $payload];
        }
        if ((int) $job['payload_bytes'] + $bytes > $this->config->stagingBudgetBytes) {
            throw new RuntimeException('This export exceeds the configured database staging payload budget.');
        }
        $this->transaction(function () use ($job, $staged, $bytes, $candidateIds): void {
            if ($staged !== []) {
                $this->db->table('export_rows')->insertBatch($staged);
            }
            $changes = ['status' => 'capturing', 'error' => null,
                'captured_rows' => (int) $job['captured_rows'] + count($staged),
                'payload_bytes' => (int) $job['payload_bytes'] + $bytes,
                'progress_at' => gmdate('Y-m-d H:i:s')];
            if ($job['capture_started_at'] === null) {
                $changes['capture_started_at'] = gmdate('Y-m-d H:i:s');
            }
            if ($candidateIds === []) {
                // Only an empty filtered ID query ends capture; an empty hydration batch can
                // instead mean matching source rows changed between the two queries.
                $changes += ['phase' => 'writing', 'capture_finished_at' => gmdate('Y-m-d H:i:s')];
                $changes['status'] = 'writing';
            } else {
                // Stage rows and advance together so retries never skip uncaptured IDs.
                $changes['capture_id'] = $candidateIds[array_key_last($candidateIds)];
            }
            $this->update($job, $changes);
        });
    }

    /**
     * Read one indexed output batch and checkpoint a fully flushed CSV append.
     *
     * @param array<string, mixed> $job Current job.
     * @param array<string, mixed> $contract Frozen columns and ordering.
     */
    private function write(array $job, array $contract): void
    {
        $query = $this->db->table('export_rows')->where('job_id', $job['id']);
        $keys = ['sort_present', 'sort_value', 'date_present', 'date_value', 'record_id'];
        $direction = $contract['ordering']['direction'];
        if ($job['write_cursor'] !== null) {
            $cursor = json_decode($job['write_cursor'], true, 512, JSON_THROW_ON_ERROR);
            $this->after($query, $keys, $cursor, $direction);
        }
        foreach ($keys as $key) {
            $query->orderBy($key, $direction);
        }
        $rows = $query->get($this->config->batchSize)->getResultArray();
        if ($rows === [] && (int) $job['written_rows'] !== (int) $job['captured_rows']) {
            throw new RuntimeException('Staged export rows are missing. Create a new export.');
        }
        $part = $this->path($job['uuid'] . '.part');
        $final = $this->path($job['uuid'] . '.csv');
        if (is_file($final)) {
            if ($rows !== [] || filesize($final) !== (int) $job['file_offset']) {
                throw new RuntimeException('Published export does not match its checkpoint.');
            }
            $this->complete($job);
            return;
        }
        $file = fopen($part, 'c+b');
        if ($file === false) {
            throw new RuntimeException('Cannot open the export CSV.');
        }
        try {
            $size = fstat($file)['size'];
            $offset = (int) $job['file_offset'];
            if ($size < $offset) {
                throw new RuntimeException('CSV is shorter than its committed checkpoint. Regenerate CSV.');
            }
            // A crash may leave bytes beyond the last DB checkpoint; discard that uncommitted tail.
            if (! ftruncate($file, $offset) || fseek($file, $offset) !== 0) {
                throw new RuntimeException('Cannot recover the CSV checkpoint.');
            }
            $buffer = fopen('php://temp/maxmemory:1048576', 'w+b');
            if ($buffer === false) {
                throw new RuntimeException('Cannot create the CSV batch buffer.');
            }
            try {
                if ($offset === 0) {
                    fwrite($buffer, "\xEF\xBB\xBF");
                    $this->csv($buffer, array_values($contract['columns']));
                }
                foreach ($rows as $row) {
                    $this->csv($buffer, json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR));
                }
                $length = ftell($buffer);
                $this->checkFileBudget($length);
                rewind($buffer);
                // Make appended bytes durable before committing their offset, so a retry can
                // safely truncate only data that was never acknowledged in the database.
                if (stream_copy_to_stream($buffer, $file) !== $length || ! fflush($file) || ! fsync($file)) {
                    throw new RuntimeException('CSV batch could not be written durably.');
                }
            } finally {
                fclose($buffer);
            }
            $changes = ['status' => 'writing', 'error' => null, 'file_offset' => ftell($file),
                'written_rows' => (int) $job['written_rows'] + count($rows),
                'progress_at' => gmdate('Y-m-d H:i:s')];
            if ($rows !== []) {
                $last = $rows[array_key_last($rows)];
                $changes['write_cursor'] = $this->json(array_intersect_key($last, array_flip($keys)));
            }
            $this->transaction(fn () => $this->update($job, $changes));
            $job = array_replace($job, $changes);
        } finally {
            fclose($file);
        }
        if ($rows === []) {
            // Publish only after a subsequent empty read confirms that every staged row is written.
            if (! rename($part, $final)) {
                throw new RuntimeException('Cannot publish the completed CSV.');
            }
            $this->complete($job);
        }
    }

    /**
     * Add lexicographic predicates with database-native equality and collation.
     *
     * @param BaseBuilder $query Indexed staging query.
     * @param list<string> $keys Ordered non-null key columns.
     * @param array<string, mixed> $cursor Last committed row keys.
     * @param string $direction Validated asc/desc.
     */
    private function after(BaseBuilder $query, array $keys, array $cursor, string $direction): void
    {
        // Build the lexicographic "after cursor" predicate to match the indexed multi-column
        // ordering without OFFSET, including ties in the primary and secondary grid sort keys.
        $query->groupStart();
        foreach ($keys as $index => $key) {
            $query->orGroupStart();
            foreach (array_slice($keys, 0, $index) as $prior) {
                $query->where($prior, $cursor[$prior]);
            }
            $query->where($key . ($direction === 'asc' ? ' >' : ' <'), $cursor[$key])->groupEnd();
        }
        $query->groupEnd();
    }

    /**
     * Hydrate one stored statistics scope without multiplying taxon rows.
     *
     * @param list<array<string, mixed>> $rows Captured taxa, modified in place.
     * @param string $region Selected region or empty for global.
     */
    private function hydrateStats(array &$rows, string $region): void
    {
        $query = $this->db->table('taxon_stats')->whereIn('taxon_id', array_column($rows, 'id'))
            ->where('geographic_region_id', $region === '' ? null : $region);
        $stats = [];
        foreach ($query->get()->getResultArray() as $row) {
            if (isset($stats[$row['taxon_id']])) {
                throw new RuntimeException('Duplicate stored taxon statistics exist for the selected scope.');
            }
            $stats[$row['taxon_id']] = $row;
        }
        $scope = $this->statisticsScope($region);
        foreach ($rows as &$row) {
            $values = $stats[$row['id']] ?? [];
            unset($values['id'], $values['taxon_id']);
            $row += $values + ['stats_scope' => $scope, 'stats_available' => $values !== []];
        }
        unset($row);
    }

    /**
     * Resolve the stored statistics scope for export metadata and legacy CSV columns.
     *
     * @param string $region Selected region ID or empty for global statistics.
     * @return string Human-readable statistics scope.
     */
    private function statisticsScope(string $region): string
    {
        if ($region === '') {
            return 'Global';
        }
        $row = $this->db->table('geographic_regions')->select('higher_geography')->where('id', $region)
            ->get()->getRowArray();
        if ($row === null) {
            throw new InvalidArgumentException('The selected statistics region is no longer available.');
        }
        return (string) $row['higher_geography'];
    }

    /**
     * Mark a fully published attempt ready with independently enforced expiry.
     *
     * @param array<string, mixed> $job Completed output checkpoint.
     */
    private function complete(array $job): void
    {
        $this->update($job, ['status' => 'completed', 'error' => null,
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->config->retentionSeconds)]);
    }

    /**
     * Persist a checkpoint while the single-host export lock is held.
     *
     * @param array<string, mixed> $job Locked checkpoint.
     * @param array<string, mixed> $changes Fields to update.
     */
    private function update(array $job, array $changes): void
    {
        if (! $this->db->table('export_jobs')->where('id', $job['id'])->update($changes)) {
            throw new RuntimeException('Cannot save export progress.');
        }
    }

    /**
     * Execute a short staging/checkpoint transaction with explicit rollback.
     *
     * @param Closure $work Atomic operation.
     */
    private function transaction(Closure $work): void
    {
        $this->db->transException(true)->transBegin();
        try {
            $work();
            // Staged rows and their progress cursor must commit or roll back as one checkpoint.
            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('Export checkpoint transaction failed.');
            }
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /**
     * Serialize admission, batches and cleanup without locking a paused job.
     *
     * @param Closure $work Operation protected by the host-wide export lock.
     * @return mixed Operation result.
     */
    private function locked(Closure $work): mixed
    {
        // One host-local lock serializes admission, capture, writing and cleanup between requests.
        $lock = $this->fileLock('global', LOCK_EX);
        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Acquire a non-blocking local export lock without arbitrary path input.
     *
     * @param string $name UUID or global lock name.
     * @param int $mode Shared/exclusive lock mode.
     * @param bool $required Whether busy should be reported as an error.
     * @return resource|false Lock handle or false when optional lock is busy.
     */
    private function fileLock(string $name, int $mode, bool $required = true)
    {
        if ($name !== 'global' && preg_match('/^[a-f0-9]{32}$/D', $name) !== 1) {
            throw new InvalidArgumentException('Export not found.');
        }
        if (is_link($this->root)) {
            throw new RuntimeException('Export root must not be a symbolic link.');
        }
        if (! is_dir($this->root) && ! mkdir($this->root, 0700, true) && ! is_dir($this->root)) {
            throw new RuntimeException('Cannot create private export storage.');
        }
        $file = fopen($this->path($name . '.lock'), 'c+b');
        if ($file === false) {
            throw new RuntimeException('Cannot open export lock.');
        }
        if (! flock($file, $mode | LOCK_NB)) {
            fclose($file);
            if ($required) {
                throw new ExportBusyException('Another export request is busy. Please try again.');
            }
            return false;
        }
        return $file;
    }

    /**
     * Validate an internally generated path and reject symbolic links.
     *
     * @param string $name Internal artifact basename.
     * @return string Private file path.
     */
    private function path(string $name): string
    {
        if (preg_match('/^(?:[a-f0-9]{32}|global)\.(?:part|csv|lock)$/D', $name) !== 1) {
            throw new RuntimeException('Invalid export artifact path.');
        }
        $path = $this->root . DIRECTORY_SEPARATOR . $name;
        if (is_link($path)) {
            throw new RuntimeException('Export artifacts must not be symbolic links.');
        }
        return $path;
    }

    /**
     * Delete only a resolved named artifact, surfacing failed cleanup.
     *
     * @param string $name Internal artifact basename.
     */
    private function removeFile(string $name): void
    {
        $path = $this->path($name);
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException('Cannot remove expired export artifact.');
        }
    }

    /**
     * Check shared directory usage and filesystem reserve before bounded writes.
     *
     * @param int $additional Bytes about to be appended.
     */
    private function checkFileBudget(int $additional): void
    {
        $used = 0;
        foreach (new \DirectoryIterator($this->root) as $entry) {
            if ($entry->isFile() && ! $entry->isLink()) {
                $used += $entry->getSize();
            }
        }
        $free = disk_free_space($this->root);
        if ($free === false || $used + $additional > $this->config->fileBudgetBytes
            || $free - $additional < $this->config->freeReserveBytes) {
            throw new RuntimeException('Export storage budget or filesystem free-space reserve has been reached.');
        }
    }

    /**
     * Reject source/staging collation differences instead of changing grid ordering.
     *
     * @param string $column Safe SQL column from the shared allowlist.
     */
    private function checkCollation(string $column): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return;
        }
        [$alias, $field] = explode('.', $column);
        $table = match ($alias) {
            't' => 'taxa', 'o' => 'occurrences', 'tr' => 'taxon_ranks',
            'tg' => 'taxon_groups', 'ds' => 'data_sources', default => 'taxa',
        };
        $source = $this->db->query('SELECT COLLATION_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $field])->getRowArray();
        $staging = $this->db->query('SELECT COLLATION_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable('export_rows'), 'sort_value'])->getRowArray();
        if ($source === null || $staging === null
            || ($source['COLLATION_NAME'] !== null && $source['COLLATION_NAME'] !== $staging['COLLATION_NAME'])) {
            throw new RuntimeException('Export staging collation does not match the selected grid sort column.');
        }
    }

    /**
     * Reject disabled exports before admitting or advancing work.
     */
    private function requireEnabled(): void
    {
        $error = $this->configurationError();
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
    }

    /**
     * Determine whether a checkpoint has exceeded its inactivity allowance.
     *
     * @param array<string, mixed> $job Checkpoint.
     * @return bool Whether unfinished data should expire.
     */
    private function inactive(array $job): bool
    {
        return $job['progress_at'] < gmdate('Y-m-d H:i:s', time() - $this->config->inactivitySeconds);
    }

    /**
     * Encode frozen values with explicit errors rather than partial JSON.
     *
     * @param mixed $value JSON-compatible data.
     * @return string Encoded data.
     */
    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Freeze classification identity/labels without treating import timestamps as configuration.
     *
     * @param TaxonSearchService $search Current reporting configuration.
     * @return array<string, array<string, string>> Visible rank identities and headings.
     */
    private function classificationContract(TaxonSearchService $search): array
    {
        return array_map(static fn (array $rank): array => [
            'id' => (string) $rank['id'], 'rank' => (string) $rank['rank'],
        ], $search->classifications());
    }

    /**
     * Write one fully escaped CSV record.
     *
     * @param resource $file Batch buffer.
     * @param list<string> $cells Ordered cell values.
     */
    private function csv($file, array $cells): void
    {
        if (fputcsv($file, $cells, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('Cannot encode CSV record.');
        }
    }
}
