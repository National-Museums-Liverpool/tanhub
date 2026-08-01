<?php

namespace App\Services;

use App\Models\TaxonMediaModel;
use App\Models\TaxonMediaVariantModel;
use Config\TaxonMedia;

/**
 * Read-side service for taxon media and variants.
 *
 * Not part of the import pipeline; this is the query/serving counterpart to
 * {@see \App\Services\TaxonMediaUploadService}, which writes the media and
 * variant rows this service reads back for display and file serving.
 */
class TaxonMediaReadService
{
    /**
     * Model for the persisted taxon media rows (one per uploaded original file).
     *
     * @var TaxonMediaModel
     */
    private TaxonMediaModel $mediaModel;

    /**
     * Model for the persisted derived-image-variant rows for each media row.
     *
     * @var TaxonMediaVariantModel
     */
    private TaxonMediaVariantModel $variantModel;

    /**
     * Taxon media configuration, used to resolve the upload storage subdirectory.
     *
     * @var TaxonMedia
     */
    private TaxonMedia $config;

    /**
     * Construct the read service with its model and configuration dependencies.
     *
     * @param TaxonMediaModel        $mediaModel   Media rows model.
     * @param TaxonMediaVariantModel $variantModel Media variant rows model.
     * @param TaxonMedia             $config       Taxon media configuration.
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
     * Convenience wrapper around {@see self::getByTaxonIds()} for the common
     * single-taxon case.
     *
     * @param int $taxonId Taxon ID to fetch media for.
     *
     * @return array<int, array<string, mixed>> Media payloads for the taxon
     *                                           (see {@see self::getByTaxonIds()}
     *                                           for the shape of each entry),
     *                                           or an empty array when none exist.
     */
    public function getByTaxonId(int $taxonId): array
    {
        $map = $this->getByTaxonIds([$taxonId]);

        return $map[$taxonId] ?? [];
    }

    /**
     * Fetch media rows for multiple taxon IDs keyed by taxon_id.
     *
     * Loads media rows for the given taxa (excluding soft-deleted rows),
     * ordered so the primary image sorts first within each taxon, then loads
     * all variant rows for those media rows in a single follow-up query and
     * groups them by `variant_key`. This two-query approach avoids N+1
     * queries when rendering media galleries for many taxa at once.
     *
     * @param array<int, int> $taxonIds Taxon IDs to fetch media for; non-positive
     *                                  or duplicate IDs are ignored.
     *
     * @return array<int, array<int, array<string, mixed>>> Media payloads keyed
     *         by taxon ID. Each payload entry includes `uuid`, `original_filename`,
     *         `mime_type`, `bytes`, `width`, `height`, `alt_text`, `caption`,
     *         `attribution`, `license`, `sort_order`, `is_primary`, `url`, and
     *         `variants` (keyed by variant key; see {@see self::variantPayload()}).
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
     * Looks up the media row by UUID, then, when a non-"original" variant key
     * is requested, looks up the matching variant row instead. Returns `null`
     * (rather than throwing) when the media/variant row does not exist or the
     * underlying file is missing from disk, so controllers can respond 404.
     * The resolved path is validated by {@see self::absoluteStoragePath()} to
     * guarantee it stays within the configured upload directory.
     *
     * @param string $uuid       Media UUID.
     * @param string $variantKey Variant key to serve, or `original` for the
     *                           unmodified uploaded file.
     *
     * @return array<string, mixed>|null Array with `path`, `filename`, and
     *                                   `mime_type` keys, or null when the
     *                                   asset cannot be resolved.
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
     * @param string               $mediaUuid  UUID of the owning media row, used to build the URL.
     * @param array<string, mixed> $variantRow Raw variant row (must include `variant_key`,
     *                                          `mime_type`, `bytes`, `width`, `height`).
     *
     * @return array<string, mixed> Public payload with `variant_key`, `mime_type`,
     *                              `bytes`, `width`, `height`, and `url`.
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
     * Resolves both the configured upload base directory and the requested
     * path via `realpath()` and rejects the result unless it is the base
     * directory itself or nested inside it. This prevents path traversal
     * (e.g. `../../` sequences in a stored path) from resolving to a file
     * outside the upload directory.
     *
     * @param string $relativePath Path stored on the media/variant row, relative
     *                             to the upload base directory.
     *
     * @return string|null Validated absolute path, or null when the base
     *                     directory or resolved path do not exist, or the
     *                     resolved path escapes the base directory.
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
