<?php

namespace App\Models;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Model;

/**
 * Model for the `import_offsets` table.
 *
 * Tracks resumable-fetch progress per import source key (e.g.
 * `indicia-taxonomy:taxa`), used by import orchestrators to resume paged or
 * checkpoint-based fetches across runs, and by
 * {@see \App\Controllers\Imports} to display/gate task status and
 * dependency completion. One row exists per `source_key`, upserted lazily
 * by the setter methods below.
 */
class ImportOffsetModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'import_offsets';

    /**
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * @var string
     */
    protected $returnType = 'array';

    /**
     * Mass-assignable columns.
     *
     * - `source_key`       Unique key identifying the import task/entity being tracked.
     * - `next_offset`      Numeric offset/page to resume a paged fetch from.
     * - `next_checkpoint`  Opaque checkpoint token to resume a token-based fetch from.
     * - `is_complete`      1 once the source has been fully imported (no more pages/tokens).
     *
     * @var array<int, string>
     */
    protected $allowedFields = [
        'source_key',
        'next_offset',
        'next_checkpoint',
        'is_complete',
    ];

    /**
     * @var bool
     */
    protected $useTimestamps = true;

    /**
     * @var string
     */
    protected $createdField = 'created_at';

    /**
     * @var string
     */
    protected $updatedField = 'updated_at';

    /**
     * Get the stored numeric offset for a source key.
     *
     * @param string $sourceKey Source/entity tracking key.
     *
     * @return int Non-negative next offset.
     */
    public function getOffset(string $sourceKey): int
    {
        $row = $this->findBySourceKey($sourceKey);

        if (! is_array($row)) {
            return 0;
        }

        return max(0, (int) ($row['next_offset'] ?? 0));
    }

    /**
     * Persist a numeric offset for a source key.
     *
     * @param string $sourceKey Source/entity tracking key.
     * @param int    $nextOffset Next offset value.
     *
     * @return void
     */
    public function setOffset(string $sourceKey, int $nextOffset): void
    {
        $offset = max(0, $nextOffset);
        $existing = $this->findBySourceKey($sourceKey);

        if (is_array($existing) && isset($existing['id'])) {
            $this->update((int) $existing['id'], ['next_offset' => $offset]);
            return;
        }

        $this->insert([
            'source_key' => $sourceKey,
            'next_offset' => $offset,
            'is_complete' => 0,
        ]);
    }

    /**
     * Get the stored checkpoint token for a source key.
     *
     * @param string $sourceKey Source/entity tracking key.
     *
     * @return string|null Checkpoint token, or null when unavailable.
     */
    public function getCheckpoint(string $sourceKey): ?string
    {
        $row = $this->findBySourceKey($sourceKey);

        if (! is_array($row)) {
            return null;
        }

        $checkpoint = $row['next_checkpoint'] ?? null;

        if (! is_scalar($checkpoint)) {
            return null;
        }

        $checkpoint = trim((string) $checkpoint);

        return $checkpoint !== '' ? $checkpoint : null;
    }

    /**
     * Persist a checkpoint token for a source key.
     *
     * @param string      $sourceKey Source/entity tracking key.
     * @param string|null $nextCheckpoint Next checkpoint token.
     *
     * @return void
     */
    public function setCheckpoint(string $sourceKey, ?string $nextCheckpoint): void
    {
        $checkpoint = $nextCheckpoint === null ? null : trim($nextCheckpoint);
        $checkpoint = $checkpoint === '' ? null : $checkpoint;

        $this->runWithReconnect(function () use ($sourceKey, $checkpoint): void {
            $existing = $this->findBySourceKey($sourceKey);

            if (is_array($existing) && isset($existing['id'])) {
                $this->update((int) $existing['id'], ['next_checkpoint' => $checkpoint]);

                return;
            }

            $this->insert([
                'source_key' => $sourceKey,
                'next_offset' => 0,
                'next_checkpoint' => $checkpoint,
                'is_complete' => 0,
            ]);
        });
    }

    /**
     * Get completion state for a source key.
     *
     * @param string $sourceKey Source/entity tracking key.
     *
     * @return bool True when the import stream has been fully exhausted.
     */
    public function isComplete(string $sourceKey): bool
    {
        $row = $this->findBySourceKey($sourceKey);

        if (! is_array($row)) {
            return false;
        }

        return ((int) ($row['is_complete'] ?? 0)) === 1;
    }

    /**
     * Persist completion state for a source key.
     *
     * @param string $sourceKey Source/entity tracking key.
     * @param bool   $isComplete Whether the stream has been fully exhausted.
     *
     * @return void
     */
    public function setCompletion(string $sourceKey, bool $isComplete): void
    {
        $existing = $this->findBySourceKey($sourceKey);
        $value = $isComplete ? 1 : 0;

        if (is_array($existing) && isset($existing['id'])) {
            $this->update((int) $existing['id'], ['is_complete' => $value]);

            return;
        }

        $this->insert([
            'source_key' => $sourceKey,
            'next_offset' => 0,
            'next_checkpoint' => null,
            'is_complete' => $value,
        ]);
    }

    /**
     * Find an offset row using a new builder so conditions cannot leak between queries.
     *
     * @param string $sourceKey Source/entity tracking key.
     *
     * @return array<string, mixed>|null Matching row, or null when it does not exist.
     */
    private function findBySourceKey(string $sourceKey): ?array
    {
        $row = $this->db
            ->table($this->table)
            ->where('source_key', $sourceKey)
            ->get()
            ->getRowArray();

        return is_array($row) ? $row : null;
    }

    /**
     * Run checkpoint persistence, reconnecting once after a lost database connection.
     *
     * The import can spend more than the server's 60-second `wait_timeout` waiting
     * for an external API. Only this idempotent checkpoint operation is retried; the
     * occurrence import itself is never repeated here.
     *
     * @param callable(): void $operation Persistence operation to execute.
     *
     * @return void
     *
     * @throws DatabaseException If the operation fails for a reason other than a lost
     *                            connection, or still fails after one reconnect.
     */
    private function runWithReconnect(callable $operation): void
    {
        try {
            $operation();
        } catch (DatabaseException $exception) {
            if (! $this->isLostConnection($exception)) {
                throw $exception;
            }

            $this->db->close();
            $this->db->initialize();
            $operation();
        }
    }

    /**
     * Determine whether a database exception indicates a connection loss.
     *
     * @param DatabaseException $exception Database exception to inspect.
     *
     * @return bool True for common MySQL lost-connection messages.
     */
    private function isLostConnection(DatabaseException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'server has gone away')
            || str_contains($message, 'lost connection');
    }
}
