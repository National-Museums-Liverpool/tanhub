<?php

namespace Tests;

use App\Services\Import\Adapter\IndiciaImportAdapter;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Import;
use InvalidArgumentException;
use RuntimeException;

/**
 * Verify grouped lookup request contracts without a live warehouse.
 *
 * @internal
 */
final class IndiciaImportAdapterTest extends CIUnitTestCase
{
    /**
     * Pair the selected type with iRecord names and retain the active type in rows.
     */
    public function testSelectedGroupFiltersAndNormalizesRegion(): void
    {
        $config = new Import();
        $config->indiciaWarehouseUrl = 'https://warehouse.test';
        $config->geographicRegions['lrc'] = [
            'indiciaLocationType' => 'LRC Boundary',
            'nbnField' => 'cl1012',
            'locations' => [['irecordName' => "Recorder's Centre", 'nbnName' => "Recorder's Centre."]],
        ];
        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->once())->method('get')->with(
            'https://warehouse.test/index.php/services/rest/reports/projects/tanhub/geographic_regions.xml',
            $this->callback(static fn (array $options): bool =>
                $options['query']['location_type'] === 'LRC Boundary'
                && $options['query']['geographic_regions'] === "'Recorder''s Centre'"
                && $options['query']['offset'] === 10),
        )->willReturn($this->response([[
            'id' => 123, 'higher_geography_identifier' => 'LERN',
            'higher_geography' => "Recorder's Centre", 'footprint_geometry' => 'POLYGON EMPTY',
        ]]));
        $batch = (new IndiciaImportAdapter($client, $config, [], 'lrc'))->fetchBatch('geographic_regions', 20, 10);

        $this->assertSame('LRC Boundary', $batch->rows[0]['location_type']);
        $this->assertSame('LERN', $batch->rows[0]['higher_geography_identifier']);
        $this->assertFalse($batch->hasMore);
    }

    /**
     * Two regions sharing a square must not be deduplicated into one lookup row.
     */
    public function testGridSquaresRetainEveryRegionAndExactPageBoundary(): void
    {
        $config = new Import();
        $config->indiciaWarehouseUrl = 'https://warehouse.test';
        $row = [
            'square' => 'SU99A', 'centre_easting' => 400000, 'centre_northing' => 100000,
            'centre_lat' => 50, 'centre_lon' => -1, 'partial' => false,
        ];
        $client = $this->createMock(CURLRequest::class);
        $client->method('get')->willReturn($this->response([
            $row + ['higher_geography_identifier' => '1'],
            $row + ['higher_geography_identifier' => '2'],
        ]));
        $batch = (new IndiciaImportAdapter($client, $config, []))->fetchBatch('grid_square_stats', 2, 0);

        $this->assertCount(2, $batch->rows);
        $this->assertSame(['1', '2'], array_column($batch->rows, 'higher_geography_identifier'));
        $this->assertTrue($batch->hasMore);
    }

    /**
     * Configured group names become quoted report parameters and duplicate rows collapse.
     */
    public function testTaxonGroupReportFormatsConfiguredNamesAndDeduplicatesRows(): void
    {
        $config = new Import();
        $config->indiciaWarehouseUrl = 'https://warehouse.test/';
        $config->taxonGroups = ' Bees, "Birds", , ';
        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->once())->method('get')->with(
            'https://warehouse.test/index.php/services/rest/reports/projects/tanhub/taxon_groups.xml',
            $this->callback(static fn (array $options): bool =>
                $options['query']['taxon_groups'] === "'Bees','Birds'"
                && $options['query']['offset'] === 0),
        )->willReturn($this->response([
            ['id' => 1, 'taxon_group_external_key' => 'bees', 'taxon_group' => ' Bees '],
            ['id' => 2, 'external_key' => 'bees', 'title' => 'Updated bees'],
            ['id' => 3, 'external_key' => '', 'title' => 'No key'],
        ]));

        $batch = (new IndiciaImportAdapter($client, $config, []))->fetchBatch('TAXON_GROUPS', 10, 0);

        $this->assertSame([[
            'external_key' => 'bees',
            'indicia_taxon_group_id' => 2,
            'title' => 'Updated bees',
            'implied' => 0,
        ]], $batch->rows);
    }

    /**
     * Recording scheme rows use report aliases and retain the last row per external key.
     */
    public function testRecordingSchemesNormalizeAliasesAndIgnoreBlankKeys(): void
    {
        $config = new Import();
        $config->indiciaWarehouseUrl = 'https://warehouse.test';
        $client = $this->createMock(CURLRequest::class);
        $client->method('get')->willReturn($this->response([
            [
                'recording_scheme_external_key' => ' SCHEME-1 ',
                'recording_scheme' => ' First title ',
                'recording_scheme_description' => ' First description ',
            ],
            ['external_key' => 'SCHEME-1', 'title' => 'Latest title', 'description' => 'Latest description'],
            ['external_key' => ' ', 'title' => 'No key'],
        ]));

        $batch = (new IndiciaImportAdapter($client, $config, []))->fetchBatch('recording_schemes', 10, 0);

        $this->assertSame([[
            'external_key' => 'SCHEME-1',
            'title' => 'Latest title',
            'description' => 'Latest description',
        ]], $batch->rows);
    }

    /**
     * Taxon rank aliases are normalized and rows without a rank are discarded.
     */
    public function testTaxonRanksNormalizeAliasesAndIgnoreBlankRanks(): void
    {
        $config = new Import();
        $config->indiciaWarehouseUrl = 'https://warehouse.test';
        $client = $this->createMock(CURLRequest::class);
        $client->method('get')->willReturn($this->response([
            ['rank' => ' Species ', 'abbr' => 'sp', 'sort_order' => '10'],
            ['name' => 'Species', 'external_key' => 'species', 'sort' => '11'],
            ['title' => ' ', 'slug' => 'blank'],
        ]));

        $batch = (new IndiciaImportAdapter($client, $config, []))->fetchBatch('taxon_ranks', 10, 0);

        $this->assertSame([[
            'rank' => 'Species',
            'abbr' => 'species',
            'sort_order' => 11,
        ]], $batch->rows);
    }

    /**
     * Taxa and taxon-name reports filter to configured live groups and normalize their rows.
     */
    public function testTaxaAndNamesUseConfiguredGroupIdsAndNormalizeRows(): void
    {
        $migrations = service('migrations');
        $migrations->setNamespace(null);
        $migrations->latest();

        $db = db_connect();
        $this->assertTrue($db->transBegin());

        try {
            $now = date('Y-m-d H:i:s');
            $db->table('taxon_groups')->insertBatch([
                [
                    'title' => 'Adapter test group',
                    'friendly' => null,
                    'external_key' => 'adapter-test-group',
                    'indicia_taxon_group_id' => 990001,
                    'implied' => 0,
                    'created_at' => $now,
                    'deleted_at' => null,
                ],
                [
                    'title' => 'Adapter implied group',
                    'friendly' => null,
                    'external_key' => 'adapter-implied-group',
                    'indicia_taxon_group_id' => 990002,
                    'implied' => 1,
                    'created_at' => $now,
                    'deleted_at' => null,
                ],
                [
                    'title' => 'Adapter deleted group',
                    'friendly' => null,
                    'external_key' => 'adapter-deleted-group',
                    'indicia_taxon_group_id' => 990003,
                    'implied' => 0,
                    'created_at' => $now,
                    'deleted_at' => $now,
                ],
            ]);

            $config = new Import();
            $config->indiciaWarehouseUrl = 'https://warehouse.test';
            $config->taxonGroups = [
                'Adapter test group',
                'Adapter implied group',
                'Adapter deleted group',
            ];
            $config->taxonRanks = ['Species', 'Genus'];

            $taxaRows = [[
                'taxon_identifier' => 'TX-1',
                'scientific_name_identifier' => 'SN-1',
                'scientific_name' => 'Example species',
                'id_difficulty' => '2',
                'higher_taxa' => '{"genus":"Example"}',
            ]];
            $nameRows = [
                [
                    'organism_key' => 'TX-1',
                    'name_identifier' => 'NAME-1',
                    'taxon_name' => ' First name ',
                    'is_accepted' => 1,
                    'is_scientific' => 1,
                ],
                [
                    'taxon_identifier' => 'TX-1',
                    'given_name_identifier' => 'NAME-1',
                    'name' => 'Latest name',
                    'accepted' => 1,
                    'scientific' => 1,
                ],
                ['taxon_identifier' => '', 'given_name_identifier' => 'NAME-2', 'name' => 'No taxon'],
            ];
            $client = $this->createMock(CURLRequest::class);
            $queries = [];
            $client->expects($this->exactly(2))->method('get')
                ->willReturnCallback(function (string $endpoint, array $options) use (&$queries, $taxaRows, $nameRows): ResponseInterface {
                    $queries[] = $options['query'];

                    return $this->response(str_ends_with($endpoint, '/taxa.xml') ? $taxaRows : $nameRows);
                });
            $adapter = new IndiciaImportAdapter($client, $config, []);

            $taxa = $adapter->fetchBatch('taxa', 10, 0);
            $names = $adapter->fetchBatch('taxon_names', 10, 0);

            $this->assertSame("'Species','Genus'", $queries[0]['reporting_taxon_ranks']);
            $this->assertSame('990001', $queries[0]['taxon_group_ids']);
            $this->assertSame('990001', $queries[1]['taxon_group_ids']);
            $this->assertSame('TX-1', $taxa->rows[0]['taxon_identifier']);
            $this->assertSame(2, $taxa->rows[0]['id_difficulty']);
            $this->assertSame('Example', $taxa->rows[0]['higher_taxa']->genus);
            $this->assertSame([[
                'taxon_identifier' => 'TX-1',
                'given_name_identifier' => 'NAME-1',
                'name' => 'Latest name',
                'accepted' => 1,
                'scientific' => 1,
            ]], $names->rows);
        } finally {
            $db->transRollback();
        }
    }

    /**
     * Nonpositive pagination values are clamped before the HTTP request.
     */
    public function testPaginationClampsLimitAndOffset(): void
    {
        $config = new Import();
        $config->indiciaWarehouseUrl = 'https://warehouse.test';
        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->once())->method('get')->with(
            $this->anything(),
            $this->callback(static fn (array $options): bool =>
                $options['query']['limit'] === 1
                && $options['query']['offset'] === 0),
        )->willReturn($this->response([['external_key' => 'SCHEME-1', 'title' => 'Scheme']]));

        $batch = (new IndiciaImportAdapter($client, $config, []))->fetchBatch('recording_schemes', 0, -5);

        $this->assertSame(0, $batch->offset);
        $this->assertSame(1, $batch->nextOffset);
        $this->assertTrue($batch->hasMore);
    }

    /**
     * Missing configuration and unsupported entities fail before issuing a request.
     */
    public function testMissingUrlAndUnsupportedEntityAreRejected(): void
    {
        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->never())->method('get');

        $missingUrlConfig = new Import();
        $missingUrlConfig->indiciaWarehouseUrl = '';
        try {
            (new IndiciaImportAdapter($client, $missingUrlConfig, []))->fetchBatch('recording_schemes', 10, 0);
            $this->fail('A missing warehouse URL should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Indicia import source URL is not configured.', $exception->getMessage());
        }

        try {
            $config = new Import();
            $config->indiciaWarehouseUrl = 'https://warehouse.test';
            (new IndiciaImportAdapter($client, $config, []))->fetchBatch('unsupported', 10, 0);
            $this->fail('An unsupported entity should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Unsupported import entity: unsupported', $exception->getMessage());
        }
    }

    /**
     * HTTP failures and invalid JSON are reported instead of becoming empty batches.
     */
    public function testHttpFailureAndInvalidJsonAreReported(): void
    {
        $config = new Import();
        $config->indiciaWarehouseUrl = 'https://warehouse.test';
        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->exactly(2))->method('get')
            ->willReturnOnConsecutiveCalls(
                $this->responseWithBody('Service unavailable', 503),
                $this->responseWithBody('{invalid json'),
            );
        $adapter = new IndiciaImportAdapter($client, $config, []);

        try {
            $adapter->fetchBatch('recording_schemes', 10, 0);
            $this->fail('An HTTP error status should be reported.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Indicia request failed with status 503 for entity recording_schemes',
                $exception->getMessage(),
            );
        }

        try {
            $adapter->fetchBatch('recording_schemes', 10, 0);
            $this->fail('An invalid JSON response should be reported.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Indicia response was not valid JSON for entity recording_schemes',
                $exception->getMessage(),
            );
        }
    }

    /**
     * Build a report-shaped response fixture.
     *
     * @param list<array<string, mixed>> $rows Report rows.
     *
     * @return ResponseInterface Successful response.
     */
    private function response(array $rows): ResponseInterface
    {
        return $this->responseWithBody(json_encode(['data' => $rows], JSON_THROW_ON_ERROR));
    }

    /**
     * Build an HTTP response fixture with a controlled status and body.
     *
     * @param string $body Response body.
     * @param int $statusCode HTTP status code.
     *
     * @return ResponseInterface Mocked HTTP response.
     */
    private function responseWithBody(string $body, int $statusCode = 200): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($body);

        return $response;
    }
}
