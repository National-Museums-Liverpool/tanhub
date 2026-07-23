<?php

namespace App\Services;

use App\Models\TaxonMediaModel;
use App\Models\TaxonMediaVariantModel;
use Config\TaxonMedia;

/**
 * Read-side service for taxon media and variants.
 */
class TaxonMediaReadService
{
    /**
     * @var TaxonMediaModel
     */
    private TaxonMediaModel $mediaModel;

    /**
     * @var TaxonMediaVariantModel
     */
    private TaxonMediaVariantModel $variantModel;

    /**
     * @var TaxonMedia
     */
    private TaxonMedia $config;

    /**
     * @param TaxonMediaModel $mediaModel
     * @param TaxonMediaVariantModel $variantModel
     * @param TaxonMedia $config
     */
    public function __construct(TaxonMediaModel $mediaModel, TaxonMediaVariantModel $variantModel, TaxonMedia $config)
    {
        $this->mediaModel = $mediaModel;
        $this->variantModel = $variantModel;
        $this->config = $config;
    }

    /**
     * Fetch media rows for a single taxon ID.
     *
     * @param int $taxonId
     * @return array<int, array<string, mixed>>
     */
    public function getByTaxonId(int $taxonId): array
    {
        $map = $this->getByTaxonIds([$taxonId]);

        return $map[$taxonId] ?? [];
    }

    /**
     * Fetch media rows for multiple taxon IDs keyed by taxon_id.
     *
     * @param array<int, int> $taxonIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getByTaxonIds(array $taxonIds): array
    {
        $ids = array_values(array_unique(array_filter($taxonIds, static fn (int $value): bool => $value > 0)));

        if ($ids === []) {
            return [];
        }

        $mediaRows = $this->mediaModel
            ->whereIn('taxon_id', $ids)
            ->where('deleted_at', null)
            ->orderBy('taxon_id', 'ASC')
            ->orderBy('is_primary', 'DESC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();

        if ($mediaRows === []) {
            return [];
        }

        $mediaIds = array_map(static fn (array $row): int => (int) $row['id'], $mediaRows);
        $variantRows = $this->variantModel
            ->whereIn('taxon_media_id', $mediaIds)
            ->orderBy('taxon_media_id', 'ASC')
            ->orderBy('variant_key', 'ASC')
            ->findAll();

        $variantsByMediaId = [];

        foreach ($variantRows as $variantRow) {
            $mediaId = (int) $variantRow['taxon_media_id'];
            $variantKey = (string) $variantRow['variant_key'];

            $variantsByMediaId[$mediaId][$variantKey] = $variantRow;
        }

        $results = [];

        foreach ($mediaRows as $mediaRow) {
            $mediaId = (int) $mediaRow['id'];
            $taxonId = (int) $mediaRow['taxon_id'];
            $mediaUuid = (string) $mediaRow['uuid'];

            $variantPayloads = [];

            foreach (($variantsByMediaId[$mediaId] ?? []) as $variantKey => $variantRow) {
                $variantPayloads[$variantKey] = $this->variantPayload($mediaUuid, $variantRow);
            }

            $results[$taxonId][] = [
                'uuid' => $mediaUuid,
                'original_filename' => (string) $mediaRow['original_filename'],
                'mime_type' => (string) $mediaRow['mime_type'],
                'bytes' => (int) $mediaRow['bytes'],
                'width' => $mediaRow['width'] === null ? null : (int) $mediaRow['width'],
                'height' => $mediaRow['height'] === null ? null : (int) $mediaRow['height'],
                'alt_text' => $mediaRow['alt_text'] === null ? null : (string) $mediaRow['alt_text'],
                'caption' => $mediaRow['caption'] === null ? null : (string) $mediaRow['caption'],
                'attribution' => $mediaRow['attribution'] === null ? null : (string) $mediaRow['attribution'],
                'license' => $mediaRow['license'] === null ? null : (string) $mediaRow['license'],
                'sort_order' => (int) $mediaRow['sort_order'],
                'is_primary' => (int) $mediaRow['is_primary'] === 1,
                'url' => site_url('taxon-media/' . rawurlencode($mediaUuid)),
                'variants' => $variantPayloads,
            ];
        }

        return $results;
    }

    /**
     * Resolve an absolute file path for serving a media asset.
     *
     * @param string $uuid
     * @param string $variantKey
     * @return array<string, mixed>|null
     */
    public function resolveAsset(string $uuid, string $variantKey = 'original'): ?array
    {
        $mediaRow = $this->mediaModel
            ->where('uuid', $uuid)
            ->where('deleted_at', null)
            ->first();

        if (! is_array($mediaRow)) {
            return null;
        }

        $path = (string) $mediaRow['storage_path'];
        $mime = (string) $mediaRow['mime_type'];

        if ($variantKey !== 'original') {
            $variant = $this->variantModel
                ->where('taxon_media_id', (int) $mediaRow['id'])
                ->where('variant_key', $variantKey)
                ->first();

            if (! is_array($variant)) {
                return null;
            }

            $path = (string) $variant['storage_path'];
            $mime = (string) $variant['mime_type'];
        }

        $absolutePath = $this->absoluteStoragePath($path);

        if ($absolutePath === null || ! is_file($absolutePath)) {
            return null;
        }

        return [
            'path' => $absolutePath,
            'filename' => (string) $mediaRow['original_filename'],
            'mime_type' => $mime,
        ];
    }

    /**
     * Build public payload for a variant row.
     *
     * @param string $mediaUuid
     * @param array<string, mixed> $variantRow
     * @return array<string, mixed>
     */
    private function variantPayload(string $mediaUuid, array $variantRow): array
    {
        $variantKey = (string) $variantRow['variant_key'];

        return [
            'variant_key' => $variantKey,
            'mime_type' => (string) $variantRow['mime_type'],
            'bytes' => (int) $variantRow['bytes'],
            'width' => $variantRow['width'] === null ? null : (int) $variantRow['width'],
            'height' => $variantRow['height'] === null ? null : (int) $variantRow['height'],
            'url' => site_url('taxon-media/' . rawurlencode($mediaUuid) . '/' . rawurlencode($variantKey)),
        ];
    }

    /**
     * Convert a relative storage path to a validated absolute path.
     *
     * @param string $relativePath
     * @return string|null
     */
    private function absoluteStoragePath(string $relativePath): ?string
    {
        $base = rtrim((string) WRITEPATH, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . trim($this->config->uploadSubdirectory, '/\\');

        $baseReal = realpath($base);
        if ($baseReal === false) {
            return null;
        }

        $fullPath = $baseReal . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
        $real = realpath($fullPath);

        if ($real === false) {
            return null;
        }

        if (strpos($real, $baseReal . DIRECTORY_SEPARATOR) !== 0 && $real !== $baseReal) {
            return null;
        }

        return $real;
    }
}
