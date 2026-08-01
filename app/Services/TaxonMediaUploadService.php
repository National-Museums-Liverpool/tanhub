<?php

namespace App\Services;

use App\Models\TaxonMediaModel;
use App\Models\TaxonMediaVariantModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\TaxonMedia;
use InvalidArgumentException;
use RuntimeException;

/**
 * Write-side service for taxon media uploads and variant generation.
 *
 * Not part of the biological-records import pipeline; this handles
 * user-uploaded taxon images. Persists an uploaded original file plus a set
 * of resized variants (per {@see \Config\TaxonMedia::$variants}) and their
 * database rows. {@see \App\Services\TaxonMediaReadService} is the read-side
 * counterpart that serves the files this service writes.
 */
class TaxonMediaUploadService
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
     * Taxon media configuration: allowed MIME types, size/dimension limits,
     * configured variants, storage subdirectory, and EXIF auto-reorient flag.
     *
     * @var TaxonMedia
     */
    private TaxonMedia $config;

    /**
     * Construct the upload service with its model and configuration dependencies.
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
     * Upload and persist a media file for a taxon.
     *
     * Validates the upload (see {@see self::assertUploadIsValid()}), moves it
     * into a UUID-named directory under the taxon's upload folder, downscales
     * it if it exceeds the configured maximum original dimensions, then
     * inserts the media row and all configured variants inside a single
     * database transaction. If anything fails after the file has been moved,
     * the transaction is rolled back and the entire upload directory for this
     * item is deleted so no orphaned files remain on disk.
     *
     * @param int                  $taxonId  Taxon to attach the media to; must be positive.
     * @param UploadedFile         $file     Uploaded file from the request.
     * @param array<string, mixed> $metadata Optional metadata: `alt_text`, `caption`,
     *                                       `attribution`, `license` (strings), `sort_order`
     *                                       (int), `is_primary` (bool).
     *
     * @return array<string, mixed> Persisted media summary with `id`, `uuid`,
     *                              `storage_path`, `mime_type`, `bytes`, `width`,
     *                              `height`, and `variants` (keyed by variant key).
     *
     * @throws InvalidArgumentException When `taxonId` is not positive or the upload
     *                                   fails validation.
     * @throws RuntimeException          When the storage directory cannot be created.
     * @throws \Throwable                Any failure during persistence or variant
     *                                   generation, after rollback and directory cleanup.
     */
    public function uploadForTaxon(int $taxonId, UploadedFile $file, array $metadata = []): array
    {
        if ($taxonId <= 0) {
            throw new InvalidArgumentException('taxonId must be a positive integer.');
        }

        $mimeType = $this->assertUploadIsValid($file);

        $uuid = $this->createUuidV4();
        $extension = strtolower((string) $file->getExtension());
        $extension = $extension === '' ? 'bin' : $extension;
        $root = $this->baseDirectory();
        $relativeDirectory = $taxonId . DIRECTORY_SEPARATOR . $uuid;
        $absoluteDirectory = $root . DIRECTORY_SEPARATOR . $relativeDirectory;

        if (! is_dir($absoluteDirectory) && ! mkdir($absoluteDirectory, 0775, true) && ! is_dir($absoluteDirectory)) {
            throw new RuntimeException('Unable to create media storage directory.');
        }

        $originalBasename = 'original.' . $extension;
        $file->move($absoluteDirectory, $originalBasename, true);

        $originalAbsolutePath = $absoluteDirectory . DIRECTORY_SEPARATOR . $originalBasename;
        $relativeOriginalPath = $relativeDirectory . DIRECTORY_SEPARATOR . $originalBasename;
        $this->enforceOriginalDimensionLimits($originalAbsolutePath, $mimeType);
        $imageSize = $this->readImageSize($originalAbsolutePath);
        $db = null;
        $transactionStarted = false;
        $variantSourcePath = $originalAbsolutePath;

        try {
            $db = db_connect();
            $db->transException(true)->transStart();
            $transactionStarted = true;

            $mediaData = [
                'uuid' => $uuid,
                'taxon_id' => $taxonId,
                'original_filename' => (string) $file->getClientName(),
                'storage_path' => $relativeOriginalPath,
                'mime_type' => $mimeType,
                'bytes' => max(0, (int) filesize($originalAbsolutePath)),
                'width' => $imageSize['width'],
                'height' => $imageSize['height'],
                'alt_text' => $this->nullableString($metadata['alt_text'] ?? null),
                'caption' => $this->nullableString($metadata['caption'] ?? null),
                'attribution' => $this->nullableString($metadata['attribution'] ?? null),
                'license' => $this->nullableString($metadata['license'] ?? null),
                'sort_order' => max(0, (int) ($metadata['sort_order'] ?? 0)),
                'is_primary' => (int) (! empty($metadata['is_primary'])),
            ];

            $this->mediaModel->insert($mediaData);
            $mediaId = (int) $this->mediaModel->getInsertID();
            $variantResults = [];
            $variantSourcePath = $this->prepareVariantSourcePath($originalAbsolutePath, $mimeType);

            foreach ($this->config->variants as $variantKey => $variantConfig) {
                if (! is_array($variantConfig)) {
                    continue;
                }

                $result = $this->createVariant(
                    $mediaId,
                    $uuid,
                    (string) $variantKey,
                    $variantSourcePath,
                    $relativeDirectory,
                    $variantConfig,
                    $mimeType,
                    $extension
                );

                if ($result !== null) {
                    $variantResults[$variantKey] = $result;
                }
            }

            $db->transComplete();
            $transactionStarted = false;

            return [
                'id' => $mediaId,
                'uuid' => $uuid,
                'storage_path' => $relativeOriginalPath,
                'mime_type' => $mimeType,
                'bytes' => $mediaData['bytes'],
                'width' => $mediaData['width'],
                'height' => $mediaData['height'],
                'variants' => $variantResults,
            ];
        } catch (\Throwable $exception) {
            if ($transactionStarted && $db !== null) {
                $db->transRollback();
            }

            $this->cleanupUploadDirectory($absoluteDirectory);

            throw $exception;
        } finally {
            if ($variantSourcePath !== $originalAbsolutePath && is_file($variantSourcePath)) {
                @unlink($variantSourcePath);
            }
        }
    }

    /**
     * Update metadata fields for an existing media item linked to a taxon.
     *
     * Looks the media row up scoped to the given taxon ID so a UUID cannot be
     * used to edit another taxon's media. Only metadata columns are updated;
     * the stored file and variants are untouched.
     *
     * @param int                  $taxonId  Owning taxon ID.
     * @param string               $uuid     Media UUID to update.
     * @param array<string, mixed> $metadata New metadata: `alt_text`, `caption`,
     *                                       `attribution`, `license` (strings),
     *                                       `sort_order` (int), `is_primary` (bool).
     *
     * @return array<string, mixed> The updated column values that were persisted.
     *
     * @throws InvalidArgumentException When `taxonId` is not positive, `uuid` is blank,
     *                                  or no matching, non-deleted media row is found
     *                                  for the taxon.
     */
    public function updateMetadataForTaxonMedia(int $taxonId, string $uuid, array $metadata): array
    {
        if ($taxonId <= 0) {
            throw new InvalidArgumentException('taxonId must be a positive integer.');
        }

        $uuid = trim($uuid);
        if ($uuid === '') {
            throw new InvalidArgumentException('A media UUID is required.');
        }

        $row = $this->mediaModel
            ->where('taxon_id', $taxonId)
            ->where('uuid', $uuid)
            ->where('deleted_at', null)
            ->first();

        if (! is_array($row)) {
            throw new InvalidArgumentException('The selected media file was not found for this taxon.');
        }

        $updateData = [
            'alt_text' => $this->nullableString($metadata['alt_text'] ?? null),
            'caption' => $this->nullableString($metadata['caption'] ?? null),
            'attribution' => $this->nullableString($metadata['attribution'] ?? null),
            'license' => $this->nullableString($metadata['license'] ?? null),
            'sort_order' => max(0, (int) ($metadata['sort_order'] ?? 0)),
            'is_primary' => (int) (! empty($metadata['is_primary'])),
        ];

        $this->mediaModel->update((int) $row['id'], $updateData);

        return $updateData;
    }

    /**
     * Rebuild variant files and rows for existing media records.
     *
     * Used for backfilling/repairing variants after a configuration change
     * (e.g. new variant sizes) or after a bulk asset rebuild command. Rows are
     * optionally filtered by media ID and/or taxon ID; when both are omitted,
     * every non-deleted media row is rebuilt. Each row is processed
     * independently: a failure for one row is recorded in `messages` and does
     * not stop processing of the remaining rows. In dry-run mode, no files or
     * database rows are changed; existing variants are only inspected.
     *
     * @param int|null $mediaId Restrict to a single media row ID, or null for no filter.
     * @param int|null $taxonId Restrict to a single taxon's media, or null for no filter.
     * @param bool     $dryRun  When true, report what would happen without writing files
     *                          or deleting/inserting variant rows.
     *
     * @return array<string, mixed> Summary with `processed` (rows examined), `updated`
     *                              (rows successfully rebuilt), `errors` (rows that threw),
     *                              `messages` (list of `"Media ID <id>: <error>"` strings),
     *                              and `dry_run` (bool echoing the input flag).
     */
    public function rebuildExistingVariants(?int $mediaId = null, ?int $taxonId = null, bool $dryRun = false): array
    {
        $builder = $this->mediaModel->where('deleted_at', null);

        if ($mediaId !== null && $mediaId > 0) {
            $builder = $builder->where('id', $mediaId);
        }

        if ($taxonId !== null && $taxonId > 0) {
            $builder = $builder->where('taxon_id', $taxonId);
        }

        $rows = $builder->findAll();

        $processed = 0;
        $updated = 0;
        $errors = 0;
        $messages = [];

        foreach ($rows as $row) {
            $processed++;

            try {
                $this->rebuildVariantsForMediaRow($row, $dryRun);
                $updated++;
            } catch (\Throwable $exception) {
                $errors++;
                $messages[] = 'Media ID ' . (int) ($row['id'] ?? 0) . ': ' . $exception->getMessage();
            }
        }

        return [
            'processed' => $processed,
            'updated' => $updated,
            'errors' => $errors,
            'messages' => $messages,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Validate uploaded image constraints.
     *
     * Checks that the PHP upload itself succeeded and has not already been
     * moved, that its MIME type is in the configured allow-list, and that its
     * size is within the configured maximum. This is a fail-fast guard before
     * any files are moved to permanent storage.
     *
     * @param UploadedFile $file Uploaded file to validate.
     *
     * @return string The validated MIME type.
     *
     * @throws InvalidArgumentException When the upload is invalid, has an
     *                                  unsupported MIME type, or exceeds the
     *                                  configured size limit.
     */
    private function assertUploadIsValid(UploadedFile $file): string
    {
        if (! $file->isValid() || $file->hasMoved()) {
            throw new InvalidArgumentException('Uploaded file is not valid.');
        }

        $mimeType = (string) $file->getMimeType();
        if (! in_array($mimeType, $this->config->allowedMimeTypes, true)) {
            throw new InvalidArgumentException('Uploaded file type is not allowed.');
        }

        $size = (int) $file->getSize();
        if ($size <= 0 || $size > $this->config->maxUploadBytes) {
            throw new InvalidArgumentException('Uploaded file size exceeds configured limits.');
        }

        return $mimeType;
    }

    /**
     * Rebuild variants for a single persisted media row.
     *
     * Reads the original file from its stored path, applies the same
     * EXIF-reorientation preprocessing used at upload time, then (unless
     * dry-run) deletes existing variant files/rows and regenerates every
     * configured variant against the current configuration.
     *
     * @param array<string, mixed> $mediaRow Persisted media row; must include `id`,
     *                                       `uuid`, `storage_path`, `mime_type`.
     * @param bool                 $dryRun   When true, only inspect existing variants;
     *                                       do not delete or regenerate anything.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the row is missing required fields.
     * @throws RuntimeException         When the original file or its storage
     *                                  directory cannot be resolved.
     */
    private function rebuildVariantsForMediaRow(array $mediaRow, bool $dryRun = false): void
    {
        $mediaId = (int) ($mediaRow['id'] ?? 0);
        $mediaUuid = (string) ($mediaRow['uuid'] ?? '');
        $storagePath = (string) ($mediaRow['storage_path'] ?? '');
        $mimeType = (string) ($mediaRow['mime_type'] ?? '');

        if ($mediaId <= 0 || $mediaUuid === '' || $storagePath === '' || $mimeType === '') {
            throw new InvalidArgumentException('Media row is missing required fields.');
        }

        $sourceAbsolutePath = $this->storageAbsolutePath($storagePath);
        if (! is_file($sourceAbsolutePath)) {
            throw new RuntimeException('Original file not found: ' . $sourceAbsolutePath);
        }

        $relativeDirectory = trim((string) dirname($storagePath), '/\\');
        if ($relativeDirectory === '' || $relativeDirectory === '.') {
            throw new RuntimeException('Invalid storage directory for media row.');
        }

        $extension = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = ltrim($this->extensionForMimeType($mimeType), '.');
        }

        $variantSourcePath = $this->prepareVariantSourcePath($sourceAbsolutePath, $mimeType);

        try {
            $existingVariants = $this->variantModel->where('taxon_media_id', $mediaId)->findAll();

            if (! $dryRun) {
                foreach ($existingVariants as $variantRow) {
                    if (isset($variantRow['storage_path']) && is_string($variantRow['storage_path']) && $variantRow['storage_path'] !== '') {
                        $existingPath = $this->storageAbsolutePath((string) $variantRow['storage_path']);
                        if (is_file($existingPath)) {
                            @unlink($existingPath);
                        }
                    }
                }

                $this->variantModel->where('taxon_media_id', $mediaId)->delete();
            }

            foreach ($this->config->variants as $variantKey => $variantConfig) {
                if (! is_array($variantConfig) || $dryRun) {
                    continue;
                }

                $this->createVariant(
                    $mediaId,
                    $mediaUuid,
                    (string) $variantKey,
                    $variantSourcePath,
                    $relativeDirectory,
                    $variantConfig,
                    $mimeType,
                    $extension
                );
            }
        } finally {
            if ($variantSourcePath !== $sourceAbsolutePath && is_file($variantSourcePath)) {
                @unlink($variantSourcePath);
            }
        }
    }

    /**
     * Create and persist one configured image variant.
     *
     * Skips (returns null) when the variant config has no positive
     * width/height, allowing a malformed variant entry to be ignored rather
     * than failing the whole upload/rebuild.
     *
     * @param int                  $mediaId              Owning media row ID.
     * @param string               $mediaUuid             Owning media row UUID, used to build the URL.
     * @param string               $variantKey            Variant key (e.g. `thumb`, `large`).
     * @param string               $sourceAbsolutePath    Absolute path to the (possibly reoriented)
     *                                                     source image to resize from.
     * @param string               $relativeDirectory     Directory (relative to the upload base) the
     *                                                     variant file is written into.
     * @param array<string, mixed> $variantConfig         Variant config: `width`, `height` (int),
     *                                                     `mode` (`fit`|`contain`), `quality` (int).
     * @param string               $mimeType              MIME type shared with the original file.
     * @param string               $extension             File extension (without dot) for the variant file.
     *
     * @return array<string, mixed>|null Variant summary with `variant_key`, `storage_path`,
     *                                   `bytes`, `width`, `height`, `url`; or null when the
     *                                   variant config is invalid and was skipped.
     */
    private function createVariant(
        int $mediaId,
        string $mediaUuid,
        string $variantKey,
        string $sourceAbsolutePath,
        string $relativeDirectory,
        array $variantConfig,
        string $mimeType,
        string $extension
    ): ?array {
        $width = isset($variantConfig['width']) ? (int) $variantConfig['width'] : 0;
        $height = isset($variantConfig['height']) ? (int) $variantConfig['height'] : 0;
        $mode = strtolower(trim((string) ($variantConfig['mode'] ?? 'fit')));
        $quality = isset($variantConfig['quality']) ? (int) $variantConfig['quality'] : 85;

        if ($width <= 0 || $height <= 0 || $variantKey === '') {
            return null;
        }

        $variantFilename = $variantKey . '.' . $extension;
        $absoluteTargetPath = $this->baseDirectory()
            . DIRECTORY_SEPARATOR . $relativeDirectory
            . DIRECTORY_SEPARATOR . $variantFilename;
        $relativeTargetPath = $relativeDirectory . DIRECTORY_SEPARATOR . $variantFilename;

        $image = service('image')->withFile($sourceAbsolutePath);

        $this->applyResizeMode($image, $mode, $width, $height, $sourceAbsolutePath);

        $image->save($absoluteTargetPath, $quality);

        $size = $this->readImageSize($absoluteTargetPath);

        $this->variantModel->insert([
            'taxon_media_id' => $mediaId,
            'variant_key' => $variantKey,
            'storage_path' => $relativeTargetPath,
            'mime_type' => $mimeType,
            'bytes' => max(0, (int) filesize($absoluteTargetPath)),
            'width' => $size['width'],
            'height' => $size['height'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'variant_key' => $variantKey,
            'storage_path' => $relativeTargetPath,
            'bytes' => max(0, (int) filesize($absoluteTargetPath)),
            'width' => $size['width'],
            'height' => $size['height'],
            'url' => site_url('taxon-media/' . rawurlencode($mediaUuid) . '/' . rawurlencode($variantKey)),
        ];
    }

    /**
     * Apply a configured resize mode to an image handler.
     *
     * Supported modes:
     * - fit: crop to exact dimensions, centered.
     * - contain: scale to fit inside dimensions preserving aspect ratio.
     *
     * @param object $image               CodeIgniter image handler instance (untyped to avoid
     *                                    a hard dependency on the concrete image library class).
     * @param string $mode                Resize mode: `fit` or `contain`; any other value
     *                                    falls back to `fit`.
     * @param int    $targetWidth         Target width in pixels.
     * @param int    $targetHeight        Target height in pixels.
     * @param string $sourceAbsolutePath  Source image path, used to compute aspect ratio
     *                                    for `contain` mode.
     *
     * @return void
     */
    private function applyResizeMode(
        object $image,
        string $mode,
        int $targetWidth,
        int $targetHeight,
        string $sourceAbsolutePath
    ): void {
        if ($mode === 'contain') {
            $dimensions = $this->containDimensions($sourceAbsolutePath, $targetWidth, $targetHeight);
            $image->resize($dimensions['width'], $dimensions['height'], false);

            return;
        }

        $image->fit($targetWidth, $targetHeight, 'center');
    }

    /**
     * Calculate contained dimensions preserving source aspect ratio.
     *
     * Falls back to returning the requested max dimensions unchanged when the
     * source image's own dimensions cannot be determined.
     *
     * @param string $sourceAbsolutePath Source image path used to read its dimensions.
     * @param int    $maxWidth           Maximum allowed width.
     * @param int    $maxHeight          Maximum allowed height.
     *
     * @return array{width:int,height:int} Scaled dimensions that fit within the
     *                                     given bounds while preserving aspect ratio.
     */
    private function containDimensions(string $sourceAbsolutePath, int $maxWidth, int $maxHeight): array
    {
        $sourceSize = $this->readImageSize($sourceAbsolutePath);
        $sourceWidth = (int) ($sourceSize['width'] ?? 0);
        $sourceHeight = (int) ($sourceSize['height'] ?? 0);

        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            return [
                'width' => $maxWidth,
                'height' => $maxHeight,
            ];
        }

        $ratio = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        $ratio = $ratio > 0 ? $ratio : 1;

        $width = max(1, (int) round($sourceWidth * $ratio));
        $height = max(1, (int) round($sourceHeight * $ratio));

        return [
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Determine whether EXIF-based reorientation should be applied.
     *
     * Requires the `autoReorient` config flag, the `exif_read_data()` function
     * to be available, the MIME type to be one EXIF applies to (JPEG/TIFF),
     * and a stored EXIF orientation value greater than 1 (1 means "already
     * upright", so nothing to do).
     *
     * @param string $sourceAbsolutePath Source image path to inspect.
     * @param string $mimeType           Source image MIME type.
     *
     * @return bool True when the image should be reoriented before use.
     */
    private function shouldReorient(string $sourceAbsolutePath, string $mimeType): bool
    {
        if (! $this->config->autoReorient) {
            return false;
        }

        if (! function_exists('exif_read_data')) {
            return false;
        }

        if (! in_array($mimeType, ['image/jpeg', 'image/tiff'], true)) {
            return false;
        }

        $orientation = $this->readExifOrientation($sourceAbsolutePath);

        return $orientation > 1;
    }

    /**
     * Build the source path used for variant generation.
     *
     * When reorientation is not needed, returns the original path unchanged.
     * Otherwise writes a physically-reoriented copy to a temp file (via GD)
     * and returns that path instead, so downstream resizing operates on
     * pixel data that already matches the EXIF-intended orientation. Callers
     * are responsible for deleting the returned path if it differs from
     * `$sourceAbsolutePath` (temp files are not cleaned up here).
     *
     * @param string $sourceAbsolutePath Original, on-disk source image path.
     * @param string $mimeType           Source image MIME type.
     *
     * @return string Path to use for variant generation: either the original
     *                path, or a temporary reoriented copy.
     */
    private function prepareVariantSourcePath(string $sourceAbsolutePath, string $mimeType): string
    {
        if (! $this->shouldReorient($sourceAbsolutePath, $mimeType)) {
            return $sourceAbsolutePath;
        }

        $orientation = $this->readExifOrientation($sourceAbsolutePath);
        if ($orientation <= 1) {
            return $sourceAbsolutePath;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'taxon_media_orient_');
        if ($tempPath === false) {
            return $sourceAbsolutePath;
        }

        $normalizedPath = $tempPath . $this->extensionForMimeType($mimeType);

        if ($this->normalizeOrientationWithGd($sourceAbsolutePath, $normalizedPath, $mimeType, $orientation)) {
            @unlink($tempPath);

            return $normalizedPath;
        }

        @unlink($tempPath);
        @unlink($normalizedPath);

        return $sourceAbsolutePath;
    }

    /**
     * Read EXIF orientation value.
     *
     * @param string $sourceAbsolutePath Image path to read EXIF metadata from.
     *
     * @return int EXIF orientation (1-8); defaults to 1 ("upright") when EXIF
     *             data is absent, unreadable, or out of the valid 1-8 range.
     */
    private function readExifOrientation(string $sourceAbsolutePath): int
    {
        $exif = @exif_read_data($sourceAbsolutePath);

        if (! is_array($exif) || ! isset($exif['Orientation'])) {
            return 1;
        }

        $orientation = (int) $exif['Orientation'];

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * Convert EXIF orientation to a physically-normalized image using GD.
     *
     * Applies the flip/rotate combination that corresponds to the given EXIF
     * orientation value (2-8; see the EXIF spec for the meaning of each
     * value) and saves the result to `$targetAbsolutePath`. Returns false
     * without writing a file if the source image cannot be decoded or a GD
     * rotate operation fails.
     *
     * @param string $sourceAbsolutePath Source image path to read and reorient.
     * @param string $targetAbsolutePath Path the normalized image is written to.
     * @param string $mimeType           Source/target MIME type.
     * @param int    $orientation        EXIF orientation value (1-8).
     *
     * @return bool True when the normalized image was written successfully.
     */
    private function normalizeOrientationWithGd(
        string $sourceAbsolutePath,
        string $targetAbsolutePath,
        string $mimeType,
        int $orientation
    ): bool {
        $source = $this->createGdImageFromPath($sourceAbsolutePath, $mimeType);
        if (! $source) {
            return false;
        }

        $result = $source;

        switch ($orientation) {
            case 2:
                imageflip($result, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $rotated = imagerotate($result, 180, 0);
                if ($rotated === false) {
                    imagedestroy($source);

                    return false;
                }
                imagedestroy($result);
                $result = $rotated;
                break;
            case 4:
                imageflip($result, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $rotated = imagerotate($result, 270, 0);
                if ($rotated === false) {
                    imagedestroy($source);

                    return false;
                }
                imagedestroy($result);
                $result = $rotated;
                imageflip($result, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $rotated = imagerotate($result, 270, 0);
                if ($rotated === false) {
                    imagedestroy($source);

                    return false;
                }
                imagedestroy($result);
                $result = $rotated;
                break;
            case 7:
                $rotated = imagerotate($result, 90, 0);
                if ($rotated === false) {
                    imagedestroy($source);

                    return false;
                }
                imagedestroy($result);
                $result = $rotated;
                imageflip($result, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $rotated = imagerotate($result, 90, 0);
                if ($rotated === false) {
                    imagedestroy($source);

                    return false;
                }
                imagedestroy($result);
                $result = $rotated;
                break;
        }

        $saved = $this->saveGdImageToPath($result, $targetAbsolutePath, $mimeType);

        imagedestroy($result);

        return $saved;
    }

    /**
     * Create a GD image resource from a file path for a supported MIME type.
     *
     * @param string $path     Image file path to decode.
     * @param string $mimeType MIME type determining which `imagecreatefrom*()` to use.
     *
     * @return resource|false Decoded GD image resource, or false when the MIME
     *                        type is unsupported or decoding fails.
     */
    private function createGdImageFromPath(string $path, string $mimeType)
    {
        return match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    /**
     * Save a GD image resource to disk for a supported MIME type.
     *
     * @param resource $image    GD image resource to save.
     * @param string   $path     Destination file path.
     * @param string   $mimeType MIME type determining which `image*()` save function to use.
     *
     * @return bool True when the image was saved successfully.
     */
    private function saveGdImageToPath($image, string $path, string $mimeType): bool
    {
        return match ($mimeType) {
            'image/jpeg' => @imagejpeg($image, $path, 100),
            'image/png' => @imagepng($image, $path, 6),
            'image/gif' => @imagegif($image, $path),
            'image/webp' => function_exists('imagewebp') ? @imagewebp($image, $path, 100) : false,
            default => false,
        };
    }

    /**
     * Downscale the stored original image when configured max dimensions are exceeded.
     *
     * Applies EXIF reorientation first so the downscale operates on
     * correctly-oriented pixel data, then overwrites the original file in
     * place when it exceeds the configured maximum width/height. No-op when
     * limits are not configured (`<= 0`) or the source is already within bounds.
     *
     * @param string $originalAbsolutePath Absolute path to the stored original file;
     *                                     overwritten in place if downscaled.
     * @param string $mimeType             Original file MIME type.
     *
     * @return void
     */
    private function enforceOriginalDimensionLimits(string $originalAbsolutePath, string $mimeType): void
    {
        $maxWidth = (int) $this->config->maxOriginalWidth;
        $maxHeight = (int) $this->config->maxOriginalHeight;

        if ($maxWidth <= 0 || $maxHeight <= 0) {
            return;
        }

        $sourceSize = $this->readImageSize($originalAbsolutePath);
        $sourceWidth = (int) ($sourceSize['width'] ?? 0);
        $sourceHeight = (int) ($sourceSize['height'] ?? 0);

        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            return;
        }

        if ($sourceWidth <= $maxWidth && $sourceHeight <= $maxHeight) {
            return;
        }

        $variantSourcePath = $this->prepareVariantSourcePath($originalAbsolutePath, $mimeType);

        try {
            $target = $this->containDimensions($variantSourcePath, $maxWidth, $maxHeight);

            service('image')
                ->withFile($variantSourcePath)
                ->resize($target['width'], $target['height'], false)
                ->save($originalAbsolutePath, 95);
        } finally {
            if ($variantSourcePath !== $originalAbsolutePath && is_file($variantSourcePath)) {
                @unlink($variantSourcePath);
            }
        }
    }

    /**
     * Resolve the file extension conventionally used for a MIME type.
     *
     * @param string $mimeType MIME type to map.
     *
     * @return string Extension including the leading dot (e.g. `.jpg`), or
     *                `.img` for unrecognized MIME types.
     */
    private function extensionForMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            default => '.img',
        };
    }

    /**
     * Build absolute path from relative storage path.
     *
     * @param string $relativePath Path stored on a media/variant row, relative
     *                             to the upload base directory.
     *
     * @return string Absolute filesystem path.
     */
    private function storageAbsolutePath(string $relativePath): string
    {
        return $this->baseDirectory() . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
    }

    /**
     * Remove upload files and directory after a failed upload attempt.
     *
     * Resolves both the configured base directory and the target directory
     * with `realpath()` and only deletes when the target is confirmed to be
     * inside the base directory, guarding against deleting anything outside
     * the upload tree if path resolution behaves unexpectedly.
     *
     * @param string $absoluteDirectory Upload item directory to remove.
     *
     * @return void
     */
    private function cleanupUploadDirectory(string $absoluteDirectory): void
    {
        $basePath = realpath($this->baseDirectory());
        $targetPath = realpath($absoluteDirectory);

        if ($basePath === false || $targetPath === false) {
            return;
        }

        $basePrefix = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (! str_starts_with($targetPath, $basePrefix)) {
            return;
        }

        $this->removeDirectoryRecursive($targetPath);
    }

    /**
     * Remove all files/directories recursively, then remove the root directory.
     *
     * @param string $directory Directory to delete recursively.
     *
     * @return void
     */
    private function removeDirectoryRecursive(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->removeDirectoryRecursive($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }

    /**
     * Read image dimensions when available.
     *
     * @param string $absolutePath Image file path to inspect.
     *
     * @return array<string, int|null> Array with `width` and `height`, both
     *                                 null when dimensions could not be read.
     */
    private function readImageSize(string $absolutePath): array
    {
        $info = @getimagesize($absolutePath);

        if (! is_array($info) || ! isset($info[0], $info[1])) {
            return ['width' => null, 'height' => null];
        }

        return [
            'width' => (int) $info[0],
            'height' => (int) $info[1],
        ];
    }

    /**
     * Resolve base directory for stored taxon media.
     *
     * @return string Absolute path to the configured taxon media upload directory.
     */
    private function baseDirectory(): string
    {
        return rtrim((string) WRITEPATH, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . trim($this->config->uploadSubdirectory, '/\\');
    }

    /**
     * Generate a random RFC4122 version 4 UUID.
     *
     * Used as the directory name and public identifier for each uploaded
     * media item, keeping media URLs unguessable and independent of the
     * database auto-increment ID.
     *
     * @return string Lowercase, hyphenated UUID v4 string.
     */
    private function createUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Normalize a scalar field to nullable string.
     *
     * Non-scalar input (e.g. arrays) and blank/whitespace-only strings are
     * both normalized to null so optional metadata fields are stored
     * consistently as either a trimmed string or null, never an empty string.
     *
     * @param mixed $value Raw metadata value to normalize.
     *
     * @return string|null Trimmed string, or null when not scalar or blank.
     */
    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
