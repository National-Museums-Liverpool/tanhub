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
 */
class TaxonMediaUploadService
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
     * Upload and persist a media file for a taxon.
     *
     * @param int $taxonId
     * @param UploadedFile $file
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
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
        $imageSize = $this->readImageSize($originalAbsolutePath);

        $db = db_connect();
        $db->transException(true)->transStart();

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

        try {
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
        } finally {
            if ($variantSourcePath !== $originalAbsolutePath && is_file($variantSourcePath)) {
                @unlink($variantSourcePath);
            }
        }

        $db->transComplete();

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
    }

    /**
     * Validate uploaded image constraints.
     *
     * @param UploadedFile $file
     * @return string
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
     * Create and persist one configured image variant.
     *
     * @param int $mediaId
     * @param string $mediaUuid
     * @param string $variantKey
     * @param string $sourceAbsolutePath
     * @param string $relativeDirectory
     * @param array<string, mixed> $variantConfig
     * @param string $mimeType
     * @param string $extension
     * @return array<string, mixed>|null
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
     * @param object $image
     * @param string $mode
     * @param int $targetWidth
     * @param int $targetHeight
     * @param string $sourceAbsolutePath
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
     * @param string $sourceAbsolutePath
     * @param int $maxWidth
     * @param int $maxHeight
     * @return array{width:int,height:int}
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
     * @param string $sourceAbsolutePath
     * @param string $mimeType
     * @return bool
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
     * @param string $sourceAbsolutePath
     * @param string $mimeType
     * @return string
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
     * @param string $sourceAbsolutePath
     * @return int
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
     * @param string $sourceAbsolutePath
     * @param string $targetAbsolutePath
     * @param string $mimeType
     * @param int $orientation
     * @return bool
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
     * @param string $path
     * @param string $mimeType
     * @return resource|false
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
     * @param resource $image
     * @param string $path
     * @param string $mimeType
     * @return bool
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
     * @param string $mimeType
     * @return string
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
     * Read image dimensions when available.
     *
     * @param string $absolutePath
     * @return array<string, int|null>
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
     * @return string
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
     * @return string
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
     * @param mixed $value
     * @return string|null
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
