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

        $this->assertUploadIsValid($file);

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
            'mime_type' => (string) $file->getMimeType(),
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

        foreach ($this->config->variants as $variantKey => $variantConfig) {
            if (! is_array($variantConfig)) {
                continue;
            }

            $result = $this->createVariant(
                $mediaId,
                $uuid,
                (string) $variantKey,
                $originalAbsolutePath,
                $relativeDirectory,
                $variantConfig,
                (string) $file->getMimeType(),
                $extension
            );

            if ($result !== null) {
                $variantResults[$variantKey] = $result;
            }
        }

        $db->transComplete();

        return [
            'id' => $mediaId,
            'uuid' => $uuid,
            'storage_path' => $relativeOriginalPath,
            'mime_type' => (string) $file->getMimeType(),
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
     * @return void
     */
    private function assertUploadIsValid(UploadedFile $file): void
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

        $image = service('image')
            ->withFile($sourceAbsolutePath)
            ->reorient();

        if ($mode === 'contain') {
            $image->resize($width, $height, true, 'auto');
        } else {
            $image->fit($width, $height, 'center');
        }

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
