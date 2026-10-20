<?php

namespace Tests;

use App\Services\Import\Adapter\IndiciaImportAdapter;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Import;

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
     * Build a report-shaped response fixture.
     *
     * @param list<array<string, mixed>> $rows Report rows.
     *
     * @return ResponseInterface Successful response.
     */
    private function response(array $rows): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode(['data' => $rows], JSON_THROW_ON_ERROR));

        return $response;
    }
}
