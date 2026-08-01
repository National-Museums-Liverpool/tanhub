<?php

namespace App\Controllers;

use App\Services\TaxonMediaReadService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public file delivery controller for taxon media assets.
 *
 * Streams original uploads and generated variants (thumbnails, etc.) that
 * were produced by {@see \App\Services\TaxonMediaUploadService}, resolving
 * the stored file path and MIME type via {@see TaxonMediaReadService}.
 */
class TaxonMediaFiles extends BaseController
{
    /**
     * Serve the original media file.
     *
     * @param string $uuid
     * @return ResponseInterface
     */
    public function show(string $uuid): ResponseInterface
    {
        return $this->respondAsset($uuid, 'original');
    }

    /**
     * Serve a named media variant.
     *
     * @param string $uuid
     * @param string $variantKey
     * @return ResponseInterface
     */
    public function variant(string $uuid, string $variantKey): ResponseInterface
    {
        return $this->respondAsset($uuid, $variantKey);
    }

    /**
     * Resolve and stream a media asset response.
     *
     * Returns a plain-text 404 (rather than throwing) when the asset record
     * cannot be resolved or the underlying file is missing/unreadable, since
     * this endpoint is typically embedded as an `<img>`/download source
     * where a normal exception page would be a poor experience.
     *
     * @param string $uuid       Taxon media UUID.
     * @param string $variantKey Variant key (e.g. `original`, `thumbnail`).
     * @return ResponseInterface Streamed file response, or a 404 response.
     */
    private function respondAsset(string $uuid, string $variantKey): ResponseInterface
    {
        /** @var TaxonMediaReadService $service */
        $service = service('taxonMediaReadService');
        $asset = $service->resolveAsset($uuid, $variantKey);

        if ($asset === null) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        $body = @file_get_contents((string) $asset['path']);

        if ($body === false) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', (string) $asset['mime_type'])
            ->setHeader('Content-Length', (string) strlen($body))
            ->setHeader('Cache-Control', 'public, max-age=86400')
            ->setBody($body);
    }
}
