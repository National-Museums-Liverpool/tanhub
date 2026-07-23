<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Taxon media upload and derivative image configuration.
 */
class TaxonMedia extends BaseConfig
{
    /**
     * Relative directory below writable/uploads for taxon media files.
     *
     * @var string
     */
    public string $uploadSubdirectory = 'taxon-media';

    /**
     * Maximum upload file size in bytes.
     *
     * @var int
     */
    public int $maxUploadBytes = 10485760;

    /**
     * Allowed upload mime types.
     *
     * @var array<int, string>
     */
    public array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * Configured derivative image sizes keyed by variant name.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $variants = [
        'thumbnail' => [
            'width' => 320,
            'height' => 320,
            'mode' => 'fit',
            'quality' => 85,
        ],
        'large' => [
            'width' => 1400,
            'height' => 1400,
            'mode' => 'contain',
            'quality' => 90,
        ],
    ];

    /**
     * Constructor loads scalar overrides from .env.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $uploadSubdirectory = env('taxonMedia.uploadSubdirectory');
        if (is_string($uploadSubdirectory) && trim($uploadSubdirectory) !== '') {
            $this->uploadSubdirectory = trim($uploadSubdirectory);
        }

        $maxUploadBytes = env('taxonMedia.maxUploadBytes');
        if ($maxUploadBytes !== null && $maxUploadBytes !== '') {
            $max = (int) $maxUploadBytes;
            if ($max > 0) {
                $this->maxUploadBytes = $max;
            }
        }

        $allowedMimeTypes = env('taxonMedia.allowedMimeTypes');
        if (is_string($allowedMimeTypes) && trim($allowedMimeTypes) !== '') {
            $parts = array_filter(array_map('trim', explode(',', $allowedMimeTypes)), static fn (string $value): bool => $value !== '');
            if ($parts !== []) {
                $this->allowedMimeTypes = array_values(array_unique($parts));
            }
        }

        $this->applyVariantEnvOverrides();
    }

    /**
     * Apply optional per-variant overrides from .env.
     *
     * @return void
     */
    private function applyVariantEnvOverrides(): void
    {
        foreach (array_keys($this->variants) as $variantKey) {
            $base = 'taxonMedia.variants.' . $variantKey . '.';

            $width = env($base . 'width');
            if ($width !== null && $width !== '') {
                $value = (int) $width;
                if ($value > 0) {
                    $this->variants[$variantKey]['width'] = $value;
                }
            }

            $height = env($base . 'height');
            if ($height !== null && $height !== '') {
                $value = (int) $height;
                if ($value > 0) {
                    $this->variants[$variantKey]['height'] = $value;
                }
            }

            $mode = env($base . 'mode');
            if (is_string($mode) && trim($mode) !== '') {
                $this->variants[$variantKey]['mode'] = trim($mode);
            }

            $quality = env($base . 'quality');
            if ($quality !== null && $quality !== '') {
                $value = (int) $quality;
                if ($value >= 1 && $value <= 100) {
                    $this->variants[$variantKey]['quality'] = $value;
                }
            }
        }
    }
}
