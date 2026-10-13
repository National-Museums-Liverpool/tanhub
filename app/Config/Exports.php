<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Resource limits for browser-driven, private CSV exports.
 */
class Exports extends BaseConfig
{
    /** @var int Required total artifact-directory budget; zero disables exports. */
    public int $fileBudgetBytes = 0;

    /** @var int Required filesystem free-space reserve; zero disables exports. */
    public int $freeReserveBytes = 0;

    /** @var int Required per-job staged JSON payload allowance; zero disables exports. */
    public int $stagingBudgetBytes = 0;

    /** @var int Maximum matching IDs captured or staged rows written by a single request. */
    public int $batchSize = 500;

    /** @var int Maximum unfinished jobs per owner. */
    public int $maxActivePerUser = 2;

    /** @var int Completed-file retention in seconds. */
    public int $retentionSeconds = 86400;

    /** @var int Unfinished-job inactivity retention in seconds. */
    public int $inactivitySeconds = 86400;

    /** @var int Terminal history retention in seconds. */
    public int $historySeconds = 604800;

    /** @var int Maximum staged rows deleted by one cleanup request. */
    public int $cleanupBatchSize = 500;

    /** @var string Optional Nginx internal location prefix; empty uses PHP streaming. */
    public string $xAccelRedirectPrefix = '';
}
