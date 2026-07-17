<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Persistence model for import offsets by source key.
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
        $row = $this->where('source_key', $sourceKey)->first();

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
        $existing = $this->where('source_key', $sourceKey)->first();

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
        $row = $this->where('source_key', $sourceKey)->first();

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
        $existing = $this->where('source_key', $sourceKey)->first();

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
        $row = $this->where('source_key', $sourceKey)->first();

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
        $existing = $this->where('source_key', $sourceKey)->first();
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
}
