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

    public function getOffset(string $sourceKey): int
    {
        $row = $this->where('source_key', $sourceKey)->first();

        if (! is_array($row)) {
            return 0;
        }

        return max(0, (int) ($row['next_offset'] ?? 0));
    }

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
        ]);
    }
}
