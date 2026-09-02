<?php

namespace Tests;

use App\Models\TaxonMediaBulkImportModel;
use App\Models\TaxonMediaBulkImportRowModel;
use App\Models\TaxonMediaModel;
use App\Models\TaxonMediaVariantModel;
use App\Services\TaxonMediaBulkImportService;
use App\Services\TaxonMediaUploadService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use Config\TaxonMedia;
use Config\TaxonMediaImport;
use InvalidArgumentException;

/**
 * @internal
 */
final class TaxonMediaBulkImportServiceTest extends CIUnitTestCase
{
    /**
     * Migrate and seed the small taxonomy used by each import test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $migrate = service('migrations');
        $migrate->setNamespace(null);
        $migrate->latest();

        $this->seedTaxonomy();
    }

    /**
     * Verify direct and accepted-name resolution, resumable batches, and publish primaries.
     */
    public function testStagesProcessesAndPublishesImport(): void
    {
        $existingUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        db_connect()->table('taxon_media')->insert([
            'uuid' => $existingUuid,
            'taxon_id' => 1,
            'original_filename' => 'existing.png',
            'storage_path' => '1/' . $existingUuid . '/original.png',
            'mime_type' => 'image/png',
            'bytes' => 10,
            'width' => 10,
            'height' => 10,
            'sort_order' => 0,
            'is_primary' => 1,
        ]);

        $csv = $this->uploadFile($this->createCsv(
            "taxon_identifier,photo_filename,is_primary\n"
            . "TAXON-1,first.png,1\n"
            . "ALIAS-2,second.png,0\n"
            . "TAXON-1,third.png,1\n",
        ), 'media.csv', 'text/csv');
        $photos = [
            $this->uploadFile($this->createPng(), 'first.png', 'image/png'),
            $this->uploadFile($this->createPng(), 'second.png', 'image/png'),
            $this->uploadFile($this->createPng(), 'third.png', 'image/png'),
        ];

        $service = $this->makeService();
        $staged = $service->stage($csv, $photos);

        $this->assertSame(3, $staged['total_rows']);
        $firstBatch = $service->runBatch((int) $staged['id'], 1);
        $this->assertSame('queued', $firstBatch['status']);
        $this->assertSame(1, $firstBatch['processed_rows']);
        $this->assertSame(1, count(array_filter(
            $service->status((int) $staged['id'])['files'],
            static fn (array $file): bool => $file['status'] === 'processed',
        )));
        $this->assertSame(0, db_connect()->table('taxon_media')->where('bulk_import_id', null)->where('original_filename', 'first.png')->countAllResults());

        $service->runBatch((int) $staged['id'], 10);

        $import = db_connect()->table('taxon_media_bulk_imports')->where('id', (int) $staged['id'])->get()->getRowArray();
        $this->assertSame('published', (string) $import['status']);
        $this->assertSame(3, (int) $import['processed_rows']);

        $published = db_connect()->table('taxon_media')->where('bulk_import_id', null)->whereIn('original_filename', ['first.png', 'second.png', 'third.png'])->get()->getResultArray();
        $this->assertCount(3, $published);
        $this->assertSame(1, (int) array_values(array_filter($published, static fn (array $row): bool => $row['original_filename'] === 'third.png'))[0]['is_primary']);
        $this->assertSame(0, (int) db_connect()->table('taxon_media')->where('uuid', $existingUuid)->get()->getRowArray()['is_primary']);
    }

    /**
     * Verify drafts accept files independently and cannot queue until complete.
     */
    public function testDraftStagesFilesIndividuallyAndPreflightQueuesOnlyWhenComplete(): void
    {
        $service = $this->makeService();
        $csv = $this->uploadFile($this->createCsv("taxon_identifier,photo_filename\nTAXON-1,one.png\nTAXON-1,two.png\n"), 'media.csv', 'text/csv');
        $draft = $service->createDraft($csv);

        $this->assertSame('draft', $draft['status']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $draft['uuid']);
        $this->assertSame((int) $draft['id'], (int) $service->importByUuid($draft['uuid'])['id']);
        $this->assertSame(0, db_connect()->table('taxon_media')->countAllResults());
        $service->stagePhoto((int) $draft['id'], null, $this->uploadFile($this->createPng(), 'one.png', 'image/png'));

        $status = $service->status((int) $draft['id']);
        $this->assertTrue($status['files'][0]['staged']);
        $this->assertArrayNotHasKey('staged_path', $status['files'][0]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('preflight failed');
        $service->finalize((int) $draft['id']);
    }

    /**
     * Verify duplicate CSV names and extra uploaded files are rejected before staging.
     */
    public function testRejectsDuplicateAndMismatchedPhotoFiles(): void
    {
        $service = $this->makeService();
        $csv = $this->uploadFile($this->createCsv("taxon_identifier,photo_filename\nTAXON-1,photo.png\nTAXON-1,photo.png\n"), 'media.csv', 'text/csv');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate photo_filename');
        $service->stage($csv, [$this->uploadFile($this->createPng(), 'photo.png', 'image/png')]);
    }

    /**
     * Verify an extra uploaded photo is rejected before staging.
     */
    public function testRejectsExtraPhotoFile(): void
    {
        $service = $this->makeService();
        $csv = $this->uploadFile($this->createCsv("taxon_identifier,photo_filename\nTAXON-1,photo.png\n"), 'media.csv', 'text/csv');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('match the uploaded files exactly');
        $service->stage($csv, [
            $this->uploadFile($this->createPng(), 'photo.png', 'image/png'),
            $this->uploadFile($this->createPng(), 'extra.png', 'image/png'),
        ]);
    }

    /**
     * Verify multiple identifier headers are rejected.
     */
    public function testRejectsMultipleIdentifierHeaders(): void
    {
        $service = $this->makeService();
        $csv = $this->uploadFile($this->createCsv("taxon_identifier,scientific_name,photo_filename\nTAXON-1,First species,photo.png\n"), 'media.csv', 'text/csv');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly one identifier header');
        $service->stage($csv, []);
    }

    /**
     * Verify ambiguous accepted-name fallbacks are rejected.
     */
    public function testRejectsAmbiguousAcceptedNameFallback(): void
    {
        $now = date('Y-m-d H:i:s');
        db_connect()->table('taxon_names')->insert([
            'uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'taxon_id' => 1,
            'name' => 'Shared alias',
            'given_name_identifier' => 'SHARED-ALIAS',
            'accepted' => 1,
            'scientific' => 1,
        ]);
        db_connect()->table('taxon_names')->insert([
            'uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'taxon_id' => 2,
            'name' => 'Shared alias',
            'given_name_identifier' => 'SHARED-ALIAS-2',
            'accepted' => 1,
            'scientific' => 1,
        ]);

        $service = $this->makeService();
        $csv = $this->uploadFile($this->createCsv("scientific_name,photo_filename\nShared alias,photo.png\n"), 'media.csv', 'text/csv');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be disambiguated');
        $service->stage($csv, []);
    }

    /**
     * Build the service with real models and the application's media configuration.
     *
     * @return TaxonMediaBulkImportService Configured bulk import service.
     */
    private function makeService(): TaxonMediaBulkImportService
    {
        return new TaxonMediaBulkImportService(
            new TaxonMediaUploadService(new TaxonMediaModel(), new TaxonMediaVariantModel(), config(TaxonMedia::class)),
            new TaxonMediaBulkImportModel(),
            new TaxonMediaBulkImportRowModel(),
            config(TaxonMediaImport::class),
        );
    }

    /**
     * Seed two taxa and one accepted fallback name.
     *
     * @return void
     */
    private function seedTaxonomy(): void
    {
        $db = db_connect();
        foreach (['taxon_media_variants', 'taxon_media_bulk_import_rows', 'taxon_media_bulk_imports', 'taxon_media', 'taxon_names', 'taxa', 'taxon_groups', 'taxon_ranks', 'recording_schemes'] as $table) {
            $db->table($table)->emptyTable();
        }
        $now = date('Y-m-d H:i:s');
        $this->insertUsingExistingColumns('taxon_groups', ['id' => 1, 'title' => 'Test group', 'friendly' => 'Test group', 'external_key' => 'test', 'indicia_taxon_group_id' => 1, 'implied' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $this->insertUsingExistingColumns('taxon_ranks', ['id' => 1, 'rank' => 'Species', 'abbr' => 'sp', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->insertUsingExistingColumns('recording_schemes', ['id' => 1, 'external_key' => 'TEST', 'title' => 'Test scheme', 'created_at' => $now, 'updated_at' => $now]);
        $taxa = [
            ['id' => 1, 'taxon_identifier' => 'TAXON-1', 'scientific_name_identifier' => 'SCI-1', 'scientific_name' => 'First species', 'vernacular_name' => 'First', 'taxon_rank_id' => 1, 'taxon_group_id' => 1, 'recording_scheme_id' => 1, 'rarity_group_name' => 'Test', 'blocked' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'taxon_identifier' => 'TAXON-2', 'scientific_name_identifier' => 'SCI-2', 'scientific_name' => 'Second species', 'vernacular_name' => 'Second', 'taxon_rank_id' => 1, 'taxon_group_id' => 1, 'recording_scheme_id' => 1, 'rarity_group_name' => 'Test', 'blocked' => 0, 'created_at' => $now, 'updated_at' => $now],
        ];
        foreach ($taxa as $taxon) {
            $this->insertUsingExistingColumns('taxa', $taxon);
        }
        $this->insertUsingExistingColumns('taxon_names', ['uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'taxon_id' => 2, 'name' => 'Second species', 'given_name_identifier' => 'ALIAS-2', 'accepted' => 1, 'scientific' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Insert only columns present in the active test schema.
     *
     * @param string               $table Table name.
     * @param array<string, mixed> $data  Candidate row data.
     * @return void
     */
    private function insertUsingExistingColumns(string $table, array $data): void
    {
        $columns = array_flip(db_connect()->getFieldNames($table));
        db_connect()->table($table)->insert(array_intersect_key($data, $columns));
    }

    /**
     * Create a temporary CSV fixture.
     *
     * @param string $contents CSV contents.
     * @return string Temporary CSV path.
     */
    private function createCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'taxon_media_csv_');
        if ($path === false) {
            $this->fail('Unable to create CSV fixture.');
        }
        file_put_contents($path, $contents);
        return $path;
    }

    /**
     * Create a temporary PNG fixture.
     *
     * @return string Temporary PNG path.
     */
    private function createPng(): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is required for bulk media tests.');
        }
        $path = tempnam(sys_get_temp_dir(), 'taxon_media_bulk_png_');
        $image = imagecreatetruecolor(48, 32);
        imagepng($image, $path);
        imagedestroy($image);
        return (string) $path;
    }

    /**
     * Wrap a fixture path as an uploaded file double.
     *
     * @param string $path Fixture path.
     * @param string $filename Client filename.
     * @param string $mimeType MIME type.
     * @return UploadedFile Uploaded file double.
     */
    private function uploadFile(string $path, string $filename, string $mimeType): UploadedFile
    {
        return new BulkImportTestUploadedFile($path, $filename, $mimeType, (int) filesize($path), UPLOAD_ERR_OK, null);
    }
}

/**
 * Uploaded-file double for bulk media service tests.
 */
final class BulkImportTestUploadedFile extends UploadedFile
{
    /** @var string Forced MIME type. */
    private string $forcedMimeType;

    /** @var bool Whether the fixture has been moved. */
    private bool $moved = false;

    /**
     * Create an uploaded-file fixture.
     *
     * @param string      $path         Fixture path.
     * @param string      $originalName Client filename.
     * @param string|null $mimeType     MIME type.
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
    ) {
        parent::__construct($path, $originalName, $mimeType, $size, $error, $clientPath);
        $this->forcedMimeType = $mimeType ?? 'application/octet-stream';
    }

    /**
     * Return whether the fixture represents a successful upload.
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
     * @return bool Whether the fixture has been moved.
     */
    public function hasMoved(): bool
    {
        return $this->moved;
    }

    /**
     * Return the fixture MIME type.
     *
     * @return string MIME type.
     */
    public function getMimeType(): string
    {
        return $this->forcedMimeType;
    }
}
