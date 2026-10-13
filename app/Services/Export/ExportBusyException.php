<?php

namespace App\Services\Export;

use RuntimeException;

/**
 * Signal normal single-host request contention without treating it as lost progress.
 */
final class ExportBusyException extends RuntimeException
{
}
