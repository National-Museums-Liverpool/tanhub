<?php

namespace App\Services\Import\Adapter;

use CodeIgniter\HTTP\CURLRequest;
use DateTimeImmutable;
use RuntimeException;

/**
 * Fetches normalized occurrence records from an NBN Atlas-compatible endpoint.
 */
class NbnAtlasOccurrencesAdapter implements OccurrenceSourceAdapterInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly CURLRequest $client,
        private readonly array $config,
        private readonly int $timeout,
    ) {
    }

    /**
     * Fetch one normalized page of records.
     */
    public function fetchPage(?string $checkpoint, int $limit): ImportPage
    {
        $endpoint = (string) ($this->config['endpoint'] ?? '');

        if ($endpoint === '') {
            throw new RuntimeException('NBN endpoint is not configured. Set import.nbn.endpoint.');
        }

        $query = $this->buildQuery($checkpoint, $limit);
        $requestUrl = $this->buildRequestUrl($endpoint, $query);
        log_message('debug', 'NBN request URL: ' . $requestUrl);
        $response = $this->client->get($requestUrl, [
            'http_errors' => false,
            'timeout' => $this->timeout,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('NBN request failed with status ' . $response->getStatusCode());
        }

        $payload = json_decode($response->getBody(), true);

        if (! is_array($payload)) {
            throw new RuntimeException('NBN response was not valid JSON object/array.');
        }

        $records = $this->extractRecords($payload);
        $normalized = [];
        $startIndex = $this->intFromAny([
            $payload['startIndex'] ?? null,
            $payload['start'] ?? null,
            $query['start'] ?? null,
        ]) ?? 0;
        $pageSize = $this->intFromAny([
            $payload['pageSize'] ?? null,
            $query['pageSize'] ?? null,
            $limit,
        ]) ?? max(1, $limit);
        $nextOffset = $startIndex + count($records);
        $totalRecords = $this->intFromAny([$payload['totalRecords'] ?? null]);

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $normalized[] = $this->normalizeRecord($record);
        }

        $hasMore = count($records) >= $pageSize;

        if ($totalRecords !== null && ! $this->isCursorCheckpoint($checkpoint)) {
            $hasMore = $nextOffset < $totalRecords;
        }

        $lastOccurrenceId = $this->lastOccurrenceId($records);
        $nextCheckpoint = $lastOccurrenceId === null
            ? (string) $nextOffset
            : 'occurrenceID:' . $lastOccurrenceId;

        return new ImportPage($normalized, $nextCheckpoint, $hasMore);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQuery(?string $checkpoint, int $limit): array
    {
        $query = (array) ($this->config['query'] ?? []);
        $query['q'] = trim((string) ($query['q'] ?? '*:*'));
        $query['pageSize'] = max(1, $limit);
        $query['sort'] = 'occurrenceID';
        $cursor = $this->cursorFromCheckpoint($checkpoint);
        $legacyStart = $this->normaliseStartCheckpoint($checkpoint);

        // NBN Atlas limits offset pagination to its max result window (currently
        // 5000). Re-read the final safe page for legacy checkpoints at that
        // boundary, then continue using the occurrenceID cursor.
        if ($cursor === null && $legacyStart >= 5000) {
            $legacyStart = max(0, $legacyStart - max(1, $limit));
        }

        $query['startIndex'] = $cursor === null ? $legacyStart : 0;

        $filters = [];
        $regionFilter = $this->buildOrFilter('cl254', $this->normalisedListValues($this->config['geographic_regions'] ?? []));

        if ($regionFilter !== null) {
            $filters[] = $regionFilter;
        }

        if ($this->config['min_taxon_rank_id'] ?? null) {
            $filters[] = 'taxonRankID:[' . (int) $this->config['min_taxon_rank_id'] . ' TO *]';
        }

        foreach ($this->configuredNbnFqClauses() as $configuredClause) {
            $filters[] = $configuredClause;
        }

        if ($cursor !== null) {
            $filters[] = 'occurrenceID:[' . $this->escapeFilterValue($cursor) . ' TO *]';
        }

        $filters[] = '-(user_assertions:"50005" OR user_assertions:"50006" OR user_assertions:"50001")';
        $query['fq'] = implode('&fq=', $filters);

        return $query;
    }

    /**
     * Determine whether a checkpoint contains an occurrence cursor.
     *
     * @param string|null $checkpoint Stored checkpoint value.
     *
     * @return bool True when the checkpoint is an occurrenceID cursor.
     */
    private function isCursorCheckpoint(?string $checkpoint): bool
    {
        return $this->cursorFromCheckpoint($checkpoint) !== null;
    }

    /**
     * Extract an occurrence ID cursor from a checkpoint.
     *
     * @param string|null $checkpoint Stored checkpoint value.
     *
     * @return string|null Cursor value, or null for a legacy numeric offset.
     */
    private function cursorFromCheckpoint(?string $checkpoint): ?string
    {
        if ($checkpoint === null || ! str_starts_with($checkpoint, 'occurrenceID:')) {
            return null;
        }

        $cursor = trim(substr($checkpoint, strlen('occurrenceID:')));

        return $cursor === '' ? null : $cursor;
    }

    /**
     * Read the final occurrence ID from a raw API page.
     *
     * @param array<int, array<string, mixed>> $records Raw API records.
     *
     * @return string|null Last occurrence ID when present.
     */
    private function lastOccurrenceId(array $records): ?string
    {
        if ($records === []) {
            return null;
        }

        $last = $records[array_key_last($records)];
        $value = $last['occurrenceID'] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Build a URL with each NBN fq filter represented as its own parameter.
     *
     * @param string               $endpoint NBN API endpoint.
     * @param array<string, mixed> $query    Request query values.
     *
     * @return string Request URL.
     */
    private function buildRequestUrl(string $endpoint, array $query): string
    {
        $filterString = (string) ($query['fq'] ?? '');
        unset($query['fq']);

        $queryString = http_build_query($query);
        $filters = explode('&fq=', $filterString);

        foreach ($filters as $filter) {
            if (trim($filter) === '') {
                continue;
            }

            $queryString .= ($queryString === '' ? '' : '&') . 'fq=' . rawurlencode($filter);
        }

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $queryString;
    }

    /**
     * @return array<int, string>
     */
    private function configuredNbnFqClauses(): array
    {
        $configured = trim((string) ($this->config['nbn_filter_query'] ?? $this->config['filter_query'] ?? ''));

        if ($configured === '') {
            return [];
        }

        // Accept either a full query-fragment (fq=...&fq=...) or a single raw fq clause.
        if (! str_contains($configured, '&') && ! str_starts_with($configured, 'fq=')) {
            return [$configured];
        }

        $clauses = [];

        foreach (explode('&', $configured) as $part) {
            $segment = trim($part);

            if ($segment === '') {
                continue;
            }

            if (str_starts_with($segment, 'fq=')) {
                $segment = substr($segment, 3);
            }

            $segment = trim(urldecode($segment));

            if ($segment === '') {
                continue;
            }

            $clauses[] = $segment;
        }

        return array_values(array_unique($clauses));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractRecords(array $payload): array
    {
        $recordsKey = (string) ($this->config['records_key'] ?? 'occurrences');

        if (isset($payload[$recordsKey]) && is_array($payload[$recordsKey])) {
            return array_values(array_filter($payload[$recordsKey], 'is_array'));
        }

        if (isset($payload['records']) && is_array($payload['records'])) {
            return array_values(array_filter($payload['records'], 'is_array'));
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return array_values(array_filter($payload['data'], 'is_array'));
        }

        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        return [];
    }

    /**
     * Normalize an NBN Atlas occurrence record for persistence.
     *
     * @param array<string, mixed> $record Raw NBN Atlas occurrence record.
     *
     * @return array<string, mixed> Normalized occurrence record.
     */
    private function normalizeRecord(array $record): array
    {
        $gridRef = (string) ($record['grid_ref'] ?? $record['gridReference'] ?? '');
        $gridRef2km = (string) ($record['grid_ref_2km'] ?? $record['gridReference2Km'] ?? '');
        $gridRefSystem = (string) ($record['grid_ref_system']
            ?? $record['gridReferenceSystem']
            ?? $record['spatialReferenceSystem']
            ?? $record['coordinateSystem']
            ?? '');
        $coordinateUncertainty = $this->numberFromAny([
            $record['coordinate_uncertainty_in_meters'] ?? null,
            $record['coordinateUncertaintyInMeters'] ?? null,
            $record['uncertaintyInMeters'] ?? null,
            $record['coordinateUncertainty'] ?? null,
        ]);

        if ($gridRef2km === '' && $gridRef !== '') {
            $gridRef2km = strtoupper(substr(str_replace(' ', '', $gridRef), 0, 5));
        }

        $remoteId = trim((string) ($record['uuid'] ?? ''));

        $taxonConceptId = trim((string) ($record['taxonConceptID'] ?? $record['taxonConceptId'] ?? ''));

        if ($taxonConceptId === '') {
            $taxonConceptId = trim((string) ($record['scientificNameID'] ?? ''));
        }

        $eventDate = $record['eventDate'] ?? $this->eventDateFromParts($record);
        [$fromDate, $toDate] = $this->normalizeEventDate($eventDate);

        return [
            'remote_id' => $remoteId,
            'occurrence_id' => trim((string) ($record['occurrenceID'] ?? '')),
            'source_name' => (string) ($record['dataResourceName'] ?? $record['source_name'] ?? 'NBN Atlas'),
            'data_provider_name' => (string) ($record['dataProviderName'] ?? $record['data_provider_name'] ?? ''),
            'scientific_name_identifier' => $taxonConceptId,
            'given_name_identifier' => $taxonConceptId,
            'from_date' => $record['from_date'] ?? $fromDate,
            'to_date' => $record['to_date'] ?? $toDate,
            'grid_ref' => $gridRef,
            'grid_ref_system' => $gridRefSystem,
            'grid_ref_2km' => $gridRef2km,
            'locality' => $record['locality'] ?? null,
            'recorded_by' => $record['recorded_by'] ?? $record['recordedBy'] ?? null,
            'identified_by' => $record['identified_by'] ?? $record['identifiedBy'] ?? null,
            'identification_verification_status' => $record['identification_verification_status'] ?? $record['identificationVerificationStatus'] ?? 'UN',
            'sex' => $record['sex'] ?? null,
            'life_stage' => $record['life_stage'] ?? null,
            'organism_quantity' => $record['organism_quantity'] ?? null,
            'latitude' => $record['decimalLatitude'] ?? null,
            'longitude' => $record['decimalLongitude'] ?? null,
            'coordinate_uncertainty_in_meters' => $coordinateUncertainty,
            'blocked' => (bool) ($record['blocked'] ?? false),
            'blocked_reason' => $record['blocked_reason'] ?? null,
        ];
    }

    /**
     * Build an event date from the separate date parts returned by NBN search.
     *
     * @param array<string, mixed> $record Raw NBN Atlas occurrence record.
     *
     * @return string|null Most precise valid date represented by the available parts.
     */
    private function eventDateFromParts(array $record): ?string
    {
        $year = $record['year'] ?? null;

        if (! is_scalar($year) || preg_match('/^\d{4}$/', trim((string) $year)) !== 1) {
            return null;
        }

        $year = (int) $year;

        if ($year < 1) {
            return null;
        }

        $month = $record['month'] ?? null;

        if ($month === null || (is_scalar($month) && trim((string) $month) === '')) {
            return sprintf('%04d', $year);
        }

        if (! is_scalar($month) || preg_match('/^\d{1,2}$/', trim((string) $month)) !== 1) {
            return null;
        }

        $month = (int) $month;

        if (! checkdate($month, 1, $year)) {
            return null;
        }

        $day = $record['day'] ?? null;

        if ($day === null || (is_scalar($day) && trim((string) $day) === '')) {
            return sprintf('%04d-%02d', $year, $month);
        }

        if (! is_scalar($day) || preg_match('/^\d{1,2}$/', trim((string) $day)) !== 1) {
            return null;
        }

        $day = (int) $day;

        return checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }

    /**
     * Convert a Darwin Core event date into an inclusive date range.
     *
    * Supports Unix timestamps in milliseconds, ISO 8601 calendar dates at
    * year, month, or day precision, ISO 8601 intervals, and the NBN
    * compatibility format `DD/MM/YYYY`.
     *
     * @param mixed $value Raw event date value.
     *
     * @return array{0: string|null, 1: string|null} Inclusive start and end dates.
     */
    private function normalizeEventDate($value): array
    {
        if (! is_scalar($value)) {
            return [null, null];
        }

        $eventDate = trim((string) $value);

        if ($eventDate === '') {
            return [null, null];
        }

        $extent = $this->dateExtent($eventDate);

        if ($extent !== null) {
            return $extent;
        }

        $timestampDate = $this->dateFromUnixMilliseconds($eventDate);

        if ($timestampDate !== null) {
            return [$timestampDate, $timestampDate];
        }

        $interval = explode('/', $eventDate, 2);

        if (count($interval) !== 2) {
            return [null, null];
        }

        $startExtent = $this->dateExtent(trim($interval[0]));
        $endExtent = $this->dateExtent(trim($interval[1]));

        if ($startExtent === null || $endExtent === null || $startExtent[0] > $endExtent[1]) {
            return [null, null];
        }

        return [$startExtent[0], $endExtent[1]];
    }

    /**
     * Convert a Unix timestamp in milliseconds to its UTC calendar date.
     *
     * @param string $value Potential millisecond timestamp.
     *
     * @return string|null UTC date, or null when the value is not a plausible timestamp.
     */
    private function dateFromUnixMilliseconds(string $value): ?string
    {
        if (preg_match('/^-?\d{11,14}$/', $value) !== 1) {
            return null;
        }

        $milliseconds = (int) $value;
        $seconds = intdiv($milliseconds, 1000);

        if ($milliseconds < 0 && $milliseconds % 1000 !== 0) {
            $seconds--;
        }

        return (new DateTimeImmutable('@' . $seconds))->format('Y-m-d');
    }

    /**
     * Expand one supported date value to its earliest and latest date.
     *
     * @param string $value Date value at year, month, or day precision.
     *
     * @return array{0: string, 1: string}|null Inclusive extent, or null when invalid.
     */
    private function dateExtent(string $value): ?array
    {
        if (preg_match('/^(\d{4})$/', $value, $matches) === 1) {
            $year = (int) $matches[1];

            return $year > 0 ? [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)] : null;
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $matches) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];

            if ($year <= 0 || ! checkdate($month, 1, $year)) {
                return null;
            }

            $firstDate = sprintf('%04d-%02d-01', $year, $month);
            $lastDay = (new DateTimeImmutable($firstDate))->format('t');

            return [$firstDate, sprintf('%04d-%02d-%s', $year, $month, $lastDay)];
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            return checkdate($month, $day, $year) ? [$value, $value] : null;
        }

        if (preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d+)?)?(?:Z|[+-](?:[01]\d|2[0-3]):?[0-5]\d)?$/',
            $value,
            $matches,
        ) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

            return checkdate($month, $day, $year) ? [$date, $date] : null;
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $matches) === 1) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];

            if (! checkdate($month, $day, $year)) {
                return null;
            }

            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

            return [$date, $date];
        }

        return null;
    }

    /**
     * @param array<int, string> $values
     */
    private function buildOrFilter(string $field, array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $escapedValues = array_map(fn (string $value): string => '"' . $this->escapeFilterValue($value) . '"', $values);

        return $field . ':(' . implode(' OR ', $escapedValues) . ')';
    }

    /**
     * Escape one value for use in an NBN Atlas filter expression.
     *
     * @param string $value Raw filter value.
     *
     * @return string Escaped filter value.
     */
    private function escapeFilterValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * @param mixed $value
     */
    private function normaliseStartCheckpoint($value): int
    {
        if (! is_scalar($value)) {
            return 0;
        }

        $string = trim((string) $value);

        if ($string === '' || ! ctype_digit($string)) {
            return 0;
        }

        return max(0, (int) $string);
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalisedListValues($value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $normalised = [];

        foreach ($values as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $string = trim((string) $item);

            if ($string === '') {
                continue;
            }

            $normalised[] = $string;
        }

        return array_values(array_unique($normalised));
    }

    /**
     * @param array<int, mixed> $values
     */
    private function intFromAny(array $values): ?int
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $string = trim((string) $value);

            if ($string === '' || ! ctype_digit($string)) {
                continue;
            }

            return (int) $string;
        }

        return null;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function numberFromAny(array $values): ?float
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $string = trim((string) $value);

            if ($string === '' || ! is_numeric($string)) {
                continue;
            }

            return (float) $string;
        }

        return null;
    }
}
