<?php

namespace App\Controllers;

use App\Services\TaxonMediaReadService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public file delivery controller for taxon media assets.
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
     * @param string $uuid
     * @param string $variantKey
     * @return ResponseInterface
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
