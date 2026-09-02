<?php

namespace Tests;

use App\Models\TaxonMediaModel;
use App\Models\TaxonMediaVariantModel;
use App\Services\TaxonMediaUploadService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use Config\TaxonMedia;
use InvalidArgumentException;
use RuntimeException;

/**
 * @internal
 */
final class TaxonMediaUploadServiceTest extends CIUnitTestCase
{
    /**
     * Prepare the database fixtures used by each media test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $migrate = service('migrations');
        $migrate->setNamespace(null);
        $migrate->latest();

        $this->seedTaxonFixture();
    }

    /**
     * Verify an upload creates its media and configured variant rows.
     */
    public function testUploadCreatesMediaAndVariantRows(): void
    {
        $service = $this->makeService();
        $sourcePath = $this->createPngSourceFile();
        $upload = $this->makeUploadedFileMock($sourcePath, 'service-upload.png', 'image/png', true);

        $result = $service->uploadForTaxon(1, $upload, [
            'alt_text' => 'Service test alt',
            'caption' => 'Service test caption',
            'attribution' => 'Service test attribution',
            'license' => 'CC BY 4.0',
            'sort_order' => 2,
            'is_primary' => 1,
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('uuid', $result);
        $this->assertArrayHasKey('variants', $result);
        $this->assertNotEmpty($result['variants']);

        $media = db_connect()->table('taxon_media')->where('id', (int) $result['id'])->get()->getRowArray();

        $this->assertNotNull($media);
        $this->assertSame('service-upload.png', (string) $media['original_filename']);
        $this->assertSame('Service test alt', (string) $media['alt_text']);
        $this->assertSame('Service test caption', (string) $media['caption']);
        $this->assertGreaterThan(0, (int) $media['bytes']);
        $this->assertGreaterThan(0, (int) $media['width']);
        $this->assertGreaterThan(0, (int) $media['height']);

        $variants = db_connect()->table('taxon_media_variants')
            ->where('taxon_media_id', (int) $result['id'])
            ->get()
            ->getResultArray();

        $this->assertNotEmpty($variants);

        $variantsByKey = [];
        foreach ($variants as $variant) {
            $variantsByKey[(string) $variant['variant_key']] = $variant;
        }

        $this->assertArrayHasKey('thumbnail', $variantsByKey);
        $this->assertArrayHasKey('large', $variantsByKey);
        $this->assertSame(320, (int) $variantsByKey['thumbnail']['width']);
        $this->assertSame(320, (int) $variantsByKey['thumbnail']['height']);
        $this->assertSame(1400, (int) $variantsByKey['large']['width']);
        $this->assertLessThan(1400, (int) $variantsByKey['large']['height']);
    }

    /**
     * Verify uploads with unsupported MIME types are rejected.
     */
    public function testUploadRejectsInvalidMimeType(): void
    {
        $service = $this->makeService();
        $sourcePath = $this->createTextSourceFile('not an image');
        $upload = $this->makeUploadedFileMock($sourcePath, 'not-image.txt', 'text/plain', true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Uploaded file type is not allowed.');

        $service->uploadForTaxon(1, $upload);
    }

    /**
     * Verify uploads with a failed PHP upload status are rejected before storage.
     */
    public function testUploadRejectsFailedUpload(): void
    {
        $service = $this->makeService();
        $sourcePath = $this->createPngSourceFile();
        $upload = $this->makeUploadedFileMock($sourcePath, 'failed.png', 'image/png', false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Uploaded file is not valid.');

        $service->uploadForTaxon(1, $upload);
    }

    /**
     * Verify uploads cannot be attached to a non-positive taxon ID.
     */
    public function testUploadRejectsNonPositiveTaxonId(): void
    {
        $service = $this->makeService();
        $sourcePath = $this->createPngSourceFile();
        $upload = $this->makeUploadedFileMock($sourcePath, 'invalid-taxon.png', 'image/png', true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('taxonId must be a positive integer.');

        $service->uploadForTaxon(0, $upload);
    }

    /**
     * Verify uploads above the configured byte limit are rejected.
     */
    public function testUploadRejectsFileAboveConfiguredLimit(): void
    {
        $config = config(TaxonMedia::class);
        $config->maxUploadBytes = 1;

        $service = $this->makeService($config);
        $sourcePath = $this->createPngSourceFile();
        $upload = $this->makeUploadedFileMock($sourcePath, 'too-large.png', 'image/png', true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Uploaded file size exceeds configured limits.');

        $service->uploadForTaxon(1, $upload);
    }

    /**
     * Verify oversized originals are downscaled before persistence.
     */
    public function testUploadDownscalesOriginalWhenMaxDimensionsConfigured(): void
    {
        $config = config(TaxonMedia::class);
        $config->maxOriginalWidth = 100;
        $config->maxOriginalHeight = 100;

        $service = $this->makeService($config);
        $sourcePath = $this->createPngSourceFile(400, 200);
        $upload = $this->makeUploadedFileMock($sourcePath, 'oversized.png', 'image/png', true);

        $result = $service->uploadForTaxon(1, $upload);

        $row = db_connect()->table('taxon_media')->where('id', (int) $result['id'])->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame(100, (int) $row['width']);
        $this->assertSame(50, (int) $row['height']);

        $absolutePath = rtrim((string) WRITEPATH, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . trim($config->uploadSubdirectory, '/\\')
            . DIRECTORY_SEPARATOR . ltrim((string) $row['storage_path'], '/\\');

        $savedSize = @getimagesize($absolutePath);
        $this->assertIsArray($savedSize);
        $this->assertSame(100, (int) $savedSize[0]);
        $this->assertSame(50, (int) $savedSize[1]);
    }

    /**
     * Verify metadata updates are persisted for an existing media row.
     */
    public function testUpdateMetadataForExistingMediaRow(): void
    {
        $service = $this->makeService();
        $uuid = '99999999-9999-4999-8999-999999999999';
        $now = date('Y-m-d H:i:s');

        db_connect()->table('taxon_media')->insert([
            'uuid' => $uuid,
            'taxon_id' => 1,
            'original_filename' => 'metadata-update.jpg',
            'storage_path' => '1/' . $uuid . '/original.jpg',
            'mime_type' => 'image/jpeg',
            'bytes' => 123,
            'width' => 50,
            'height' => 50,
            'alt_text' => 'Old alt',
            'caption' => 'Old caption',
            'attribution' => 'Old attribution',
            'license' => 'Old license',
            'sort_order' => 0,
            'is_primary' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $service->updateMetadataForTaxonMedia(1, $uuid, [
            'alt_text' => 'New alt',
            'caption' => 'New caption',
            'attribution' => 'New attribution',
            'license' => 'CC BY',
            'sort_order' => 4,
            'is_primary' => 1,
        ]);

        $row = db_connect()->table('taxon_media')->where('uuid', $uuid)->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('New alt', (string) $row['alt_text']);
        $this->assertSame('New caption', (string) $row['caption']);
        $this->assertSame('New attribution', (string) $row['attribution']);
        $this->assertSame('CC BY', (string) $row['license']);
        $this->assertSame(4, (int) $row['sort_order']);
        $this->assertSame(1, (int) $row['is_primary']);
    }

    /**
     * Verify variant persistence failures remove all uploaded files and rows.
     */
    public function testUploadFailureRemovesOrphanedFilesAndDirectory(): void
    {
        $config = config(TaxonMedia::class);
        $baseDirectory = rtrim((string) WRITEPATH, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . trim($config->uploadSubdirectory, '/\\');
        $taxonDirectory = $baseDirectory . DIRECTORY_SEPARATOR . '1';

        if (! is_dir($taxonDirectory) && ! mkdir($taxonDirectory, 0775, true) && ! is_dir($taxonDirectory)) {
            $this->fail('Unable to create taxon media fixture directory.');
        }

        $beforeDirectories = glob($taxonDirectory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        if ($beforeDirectories === false) {
            $beforeDirectories = [];
        }

        $variantModel = new class extends TaxonMediaVariantModel {
            /**
             * Force variant persistence to fail.
             *
             * @param array<string, mixed>|null $row Variant row data.
             * @param bool                     $returnID Whether to return an ID.
             * @return never
             */
            public function insert($row = null, bool $returnID = true)
            {
                throw new RuntimeException('Forced variant persistence failure.');
            }
        };

        $service = new TaxonMediaUploadService(
            model(TaxonMediaModel::class),
            $variantModel,
            $config
        );

        $sourcePath = $this->createPngSourceFile();
        $upload = $this->makeUploadedFileMock($sourcePath, 'forced-failure.png', 'image/png', true);

        try {
            $service->uploadForTaxon(1, $upload, []);
            $this->fail('Expected uploadForTaxon to fail when variant insert throws.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced variant persistence failure.', $exception->getMessage());
        }

        $afterDirectories = glob($taxonDirectory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        if ($afterDirectories === false) {
            $afterDirectories = [];
        }

        $this->assertCount(count($beforeDirectories), $afterDirectories);

        $mediaCount = db_connect()->table('taxon_media')->countAllResults();
        $variantCount = db_connect()->table('taxon_media_variants')->countAllResults();

        $this->assertSame(0, $mediaCount);
        $this->assertSame(0, $variantCount);
    }

    /**
     * Verify media persistence failures roll back and remove the moved original.
     */
    public function testMediaPersistenceFailureRemovesOrphanedFilesAndDirectory(): void
    {
        $config = config(TaxonMedia::class);
        $baseDirectory = rtrim((string) WRITEPATH, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . trim($config->uploadSubdirectory, '/\\');
        $taxonDirectory = $baseDirectory . DIRECTORY_SEPARATOR . '1';

        if (! is_dir($taxonDirectory) && ! mkdir($taxonDirectory, 0775, true) && ! is_dir($taxonDirectory)) {
            $this->fail('Unable to create taxon media fixture directory.');
        }

        $beforeDirectories = glob($taxonDirectory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        if ($beforeDirectories === false) {
            $beforeDirectories = [];
        }

        $mediaModel = new class extends TaxonMediaModel {
            /**
             * Force media persistence to fail.
             *
             * @param array<string, mixed>|null $row Media row data.
             * @param bool                     $returnID Whether to return an ID.
             * @return never
             */
            public function insert($row = null, bool $returnID = true)
            {
                throw new RuntimeException('Forced media persistence failure.');
            }
        };

        $service = new TaxonMediaUploadService(
            $mediaModel,
            model(TaxonMediaVariantModel::class),
            $config
        );

        $sourcePath = $this->createPngSourceFile();
        $upload = $this->makeUploadedFileMock($sourcePath, 'media-failure.png', 'image/png', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forced media persistence failure.');

        try {
            $service->uploadForTaxon(1, $upload);
        } finally {
            $afterDirectories = glob($taxonDirectory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
            if ($afterDirectories === false) {
                $afterDirectories = [];
            }

            $this->assertCount(count($beforeDirectories), $afterDirectories);
            $this->assertSame(0, db_connect()->table('taxon_media')->countAllResults());
            $this->assertSame(0, db_connect()->table('taxon_media_variants')->countAllResults());
        }
    }

    /**
     * Build the upload service with the test database models.
     *
     * @param TaxonMedia|null $config Media configuration override.
     * @return TaxonMediaUploadService Upload service under test.
     */
    private function makeService(?TaxonMedia $config = null): TaxonMediaUploadService
    {
        if ($config === null) {
            $config = config(TaxonMedia::class);
        }

        return new TaxonMediaUploadService(
            model(TaxonMediaModel::class),
            model(TaxonMediaVariantModel::class),
            $config
        );
    }

    /**
     * Seed the taxon and related lookup rows required by media tests.
     */
    private function seedTaxonFixture(): void
    {
        $db = db_connect();
        $now = date('Y-m-d H:i:s');

        $db->table('taxon_media_variants')->emptyTable();
        $db->table('taxon_media')->emptyTable();
        $db->table('geographic_regions_occurrences')->emptyTable();
        $db->table('occurrences')->emptyTable();
        $db->table('taxon_names')->emptyTable();
        $db->table('taxon_stats')->emptyTable();
        $db->table('taxon_year_stats')->emptyTable();
        $db->table('taxa')->emptyTable();
        $db->table('taxon_groups')->emptyTable();
        $db->table('taxon_ranks')->emptyTable();
        $db->table('recording_schemes')->emptyTable();

        $this->insertUsingExistingColumns('taxon_groups', [
            'id' => 1,
            'title' => 'Bees',
            'friendly' => 'Bee species',
            'external_key' => 'bees',
            'indicia_taxon_group_id' => 10,
            'implied' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $this->insertUsingExistingColumns('taxon_ranks', [
            'id' => 1,
            'rank' => 'Species',
            'abbr' => 'sp',
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $this->insertUsingExistingColumns('recording_schemes', [
            'id' => 1,
            'external_key' => 'SCHEME-0001',
            'title' => 'Alpha scheme',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $this->insertUsingExistingColumns('taxa', [
            'id' => 1,
            'taxon_identifier' => 'NHMSYS0021054498',
            'scientific_name_identifier' => 'TVK-001',
            'scientific_name' => 'Bombus terrestris',
            'scientific_name_authorship' => 'Linnaeus, 1758',
            'vernacular_name' => 'Buff-tailed Bumblebee',
            'taxon_rank_id' => 1,
            'order_id' => null,
            'superfamily_id' => null,
            'family_id' => null,
            'genus_id' => null,
            'species_id' => null,
            'taxon_group_id' => 1,
            'id_difficulty' => 2,
            'recording_scheme_id' => 1,
            'conservation_status' => 'LC',
            'taxon_remarks' => null,
            'rarity_group_name' => 'Bees, Wasps and Ants',
            'rarity_category' => 4,
            'blocked' => 0,
            'blocked_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
    }

    /**
     * Insert a fixture row using only fields present in the active schema.
     */
    private function insertUsingExistingColumns(string $table, array $data): void
    {
        $db = db_connect();
        $existingColumns = array_flip($db->getFieldNames($table));
        $filteredData = array_intersect_key($data, $existingColumns);

        $db->table($table)->insert($filteredData);
    }

    /**
     * Create a temporary PNG fixture.
     *
     * @param int $width Image width.
     * @param int $height Image height.
     * @return string Temporary PNG path.
     */
    private function createPngSourceFile(int $width = 48, int $height = 32): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is required for image variant generation tests.');
        }

        $path = tempnam(sys_get_temp_dir(), 'taxon_media_png_');

        if ($path === false) {
            $this->fail('Unable to create temporary file for PNG upload.');
        }

        $image = imagecreatetruecolor($width, $height);

        if (! $image) {
            $this->fail('Unable to create GD test image resource.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $white);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * Create a temporary text fixture.
     *
     * @param string $contents Text contents.
     * @return string Temporary text path.
     */
    private function createTextSourceFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'taxon_media_txt_');

        if ($path === false) {
            $this->fail('Unable to create temporary file for text upload.');
        }

        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Build an uploaded-file test double.
     *
     * @param string $sourcePath Source fixture path.
     * @param string $filename Client filename.
     * @param string $mimeType Reported MIME type.
     * @param bool $isValid Whether the upload should report success.
     * @return UploadedFile Uploaded-file double.
     */
    private function makeUploadedFileMock(string $sourcePath, string $filename, string $mimeType, bool $isValid): UploadedFile
    {
        $error = $isValid ? UPLOAD_ERR_OK : UPLOAD_ERR_NO_FILE;

        return new TestUploadedFile($sourcePath, $filename, $mimeType, (int) filesize($sourcePath), $error, null);
    }
}

/**
 * Test double that behaves like an uploaded file in CLI test execution.
 */
final class TestUploadedFile extends UploadedFile
{
    /** @var string Source fixture path. */
    private string $sourcePath;

    /** @var string MIME type reported by the test double. */
    private string $forcedMimeType;

    /** @var bool Whether the file has been moved. */
    private bool $moved = false;

    /**
     * Create an uploaded-file test double.
     *
     * @param string      $path         Source file path.
     * @param string      $originalName Client filename.
     * @param string|null $mimeType     Reported MIME type.
     * @param int|null    $size         File size.
     * @param int|null    $error        Upload error code.
     * @param string|null $clientPath   Client-side path.
     */
    public function __construct(
        string $path,
        string $originalName,
        ?string $mimeType = null,
        ?int $size = null,
        ?int $error = null,
        ?string $clientPath = null
    )
    {
        parent::__construct($path, $originalName, $mimeType, $size, $error, $clientPath);

        $this->sourcePath = $path;
        $this->forcedMimeType = $mimeType ?? 'application/octet-stream';
    }

    /**
     * Return whether the upload status is successful.
     *
     * @return bool Whether the upload is valid.
     */
    public function isValid(): bool
    {
        return $this->getError() === UPLOAD_ERR_OK;
    }

    /**
     * Return whether the fixture has been moved.
     *
     * @return bool Whether the fixture was moved.
     */
    public function hasMoved(): bool
    {
        return $this->moved;
    }

    /**
     * Return the configured fixture MIME type.
     *
     * @return string Fixture MIME type.
     */
    public function getMimeType(): string
    {
        return $this->forcedMimeType;
    }

    /**
     * Copy the fixture into the requested upload directory.
     *
     * @param string      $targetPath Destination directory.
     * @param string|null $name       Destination filename.
     * @param bool        $overwrite  Whether an existing file may be replaced.
     * @return bool Whether the copy succeeded.
     */
    public function move(string $targetPath, ?string $name = null, bool $overwrite = false)
    {
        $destinationName = $name ?? basename($this->sourcePath);
        $destination = rtrim($targetPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $destinationName;

        if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0775, true) && ! is_dir(dirname($destination))) {
            throw new RuntimeException('Unable to create upload destination directory.');
        }

        if (! copy($this->sourcePath, $destination)) {
            throw new RuntimeException('Unable to copy upload fixture.');
        }

        $this->moved = true;

        return true;
    }
}
