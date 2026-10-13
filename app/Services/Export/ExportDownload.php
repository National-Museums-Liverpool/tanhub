<?php

namespace App\Services\Export;

use CodeIgniter\HTTP\DownloadResponse;

/**
 * Keep the export delivery lock alive until the file response has been sent.
 */
final class ExportDownload extends DownloadResponse
{
    /** @var resource Delivery lock held until response destruction. */
    private $lock;

    /**
     * Create a bounded-memory file response protected from cleanup.
     *
     * @param string $path Validated private file.
     * @param string $name Public filename.
     * @param resource $lock Shared job lock.
     * @param string $internalUri Optional protected Nginx internal URI.
     */
    public function __construct(string $path, string $name, $lock, private readonly string $internalUri = '')
    {
        parent::__construct($name, false);
        $this->lock = $lock;
        $this->setFilePath($path);
        $this->setContentType('text/csv');
        $this->setHeader('Cache-Control', 'private, no-store');
        if ($internalUri !== '') {
            $this->setHeader('X-Accel-Redirect', $internalUri);
        }
    }

    /**
     * Delegate to an explicitly configured internal location or stream bounded chunks.
     *
     * @return $this Sent file response.
     */
    public function sendBody()
    {
        return $this->internalUri !== '' ? $this : parent::sendBody();
    }

    /**
     * Release delivery protection after the response lifetime.
     */
    public function __destruct()
    {
        flock($this->lock, LOCK_UN);
        fclose($this->lock);
    }
}
