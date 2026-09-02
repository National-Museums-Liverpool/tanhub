<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Bulk taxon media import storage and worker settings.
 */
class TaxonMediaImport extends BaseConfig
{
    /**
     * Relative directory below writable/uploads for staged imports.
     *
     * @var string
     */
    public string $uploadSubdirectory = 'taxon-media-bulk';

    /**
     * Number of staged media rows processed by one worker batch.
     *
     * @var int
     */
    public int $workerBatchSize = 25;

    /**
     * Maximum number of seconds a command worker may run continuously.
     *
     * @var int
     */
    public int $workerMaxRuntimeSeconds = 300;

    /** @var int Maximum CSV rows in one import. */
    public int $maxRows = 10000;

    /** @var int Maximum staged files in one import. */
    public int $maxFiles = 10000;

    /** @var int Maximum total staged bytes in one import. */
    public int $maxStagedBytes = 10737418240;

    /** @var int Seconds after which a processing job is considered stale. */
    public int $staleJobSeconds = 900;

    /**
     * Constructor loads scalar overrides from .env.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $subdirectory = env('taxonMediaImport.uploadSubdirectory');
        if (is_string($subdirectory) && trim($subdirectory) !== '') {
            $this->uploadSubdirectory = trim($subdirectory);
        }

        $batchSize = env('taxonMediaImport.workerBatchSize');
        if ($batchSize !== null && $batchSize !== '' && (int) $batchSize > 0) {
            $this->workerBatchSize = (int) $batchSize;
        }

        $maxRuntime = env('taxonMediaImport.workerMaxRuntimeSeconds');
        if ($maxRuntime !== null && $maxRuntime !== '' && (int) $maxRuntime > 0) {
            $this->workerMaxRuntimeSeconds = (int) $maxRuntime;
        }

        foreach (['maxRows', 'maxFiles', 'maxStagedBytes', 'staleJobSeconds'] as $property) {
            $value = env('taxonMediaImport.' . $property);
            if ($value !== null && $value !== '' && (int) $value > 0) {
                $this->{$property} = (int) $value;
            }
        }
    }
}
