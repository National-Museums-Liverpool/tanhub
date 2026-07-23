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
    protected function setUp(): void
    {
        parent::setUp();

        $migrate = service('migrations');
        $migrate->setNamespace(null);
        $migrate->latest();

        $this->seedTaxonFixture();
    }

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

    public function testUploadRejectsInvalidMimeType(): void
    {
        $service = $this->makeService();
        $sourcePath = $this->createTextSourceFile('not an image');
        $upload = $this->makeUploadedFileMock($sourcePath, 'not-image.txt', 'text/plain', true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Uploaded file type is not allowed.');

        $service->uploadForTaxon(1, $upload);
    }

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
            'rarity_group_name' => 'common',
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

    private function createPngSourceFile(): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is required for image variant generation tests.');
        }

        $path = tempnam(sys_get_temp_dir(), 'taxon_media_png_');

        if ($path === false) {
            $this->fail('Unable to create temporary file for PNG upload.');
        }

        $image = imagecreatetruecolor(48, 32);

        if (! $image) {
            $this->fail('Unable to create GD test image resource.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 47, 31, $white);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function createTextSourceFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'taxon_media_txt_');

        if ($path === false) {
            $this->fail('Unable to create temporary file for text upload.');
        }

        file_put_contents($path, $contents);

        return $path;
    }

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
    private string $sourcePath;
    private string $forcedMimeType;
    private bool $moved = false;

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

    public function isValid(): bool
    {
        return $this->getError() === UPLOAD_ERR_OK;
    }

    public function hasMoved(): bool
    {
        return $this->moved;
    }

    public function getMimeType(): string
    {
        return $this->forcedMimeType;
    }

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
