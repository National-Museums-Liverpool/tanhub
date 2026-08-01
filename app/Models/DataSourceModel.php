<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `data_sources` table.
 *
 * Data sources are the small, fixed set of external providers (e.g. NBN
 * Atlas, iRecord) that occurrences/taxa are imported from. Rows are
 * maintained as static reference data by {@see \App\Database\Seeds\DataSourcesSeeder}
 * rather than created/edited through the UI, which is why the table has no
 * created/updated timestamps.
 */
class DataSourceModel extends Model
{
    /**
     * @var string Underlying database table.
     */
    protected $table = 'data_sources';

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
     * - `abbr`  Short code used elsewhere (e.g. import source keys) to refer to this source.
     * - `title` Human-readable display name.
     * - `url`   Canonical URL for the source, shown as a citation/link.
     *
     * @var array<int, string>
     */
    protected $allowedFields = [
        'abbr',
        'title',
        'url',
    ];

    /**
     * @var bool No created_at/updated_at columns; rows are seeded, not user-edited.
     */
    protected $useTimestamps = false;
}
