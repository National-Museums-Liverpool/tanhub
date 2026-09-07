<?php

namespace Tests;

use App\Services\Import\Adapter\IndiciaOccurrencesAdapter;
use App\Services\Import\Support\OsgbGridReferenceBuilder;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use ReflectionMethod;

/**
 * @internal
 */
final class IndiciaOccurrencesAdapterTest extends CIUnitTestCase
{
    /**
     * Verify coarse grid references cannot be converted to tetrads.
     */
    public function testCalculateDintyTetradReturnsNullForTooCoarseGridReference(): void
    {
        $this->assertNull((new OsgbGridReferenceBuilder())->calculateDintyTetrad('SU12'));
    }

    /**
     * Verify one-kilometre grid references are converted to tetrads.
     */
    public function testCalculateDintyTetradConvertsOneKilometreGridReference(): void
    {
        $this->assertSame('SU13H', (new OsgbGridReferenceBuilder())->calculateDintyTetrad('SU1234'));
    }

    /**
     * Verify finer grid references are converted to tetrads.
     */
    public function testCalculateDintyTetradConvertsFinerGridReference(): void
    {
        $this->assertSame('SU14H', (new OsgbGridReferenceBuilder())->calculateDintyTetrad('SU123456'));
    }

    /**
     * Verify normalized records retain grid metadata and coordinate uncertainty.
     */
    public function testNormalizeRecordIncludesGridSystemAndUncertainty(): void
    {
        $record = [
            '_id' => 'remote-1',
            'taxon' => [
                'accepted_taxon_id' => 'TVK-1',
                'taxon_id' => 'GIVEN-1',
            ],
            'location' => [
                'output_sref' => 'SU1234',
                'output_sref_system' => 'WGS84',
                'coordinate_uncertainty_in_meters' => 1500,
                'point' => '53.4808,-2.2426',
            ],
        ];

        $normalized = $this->normalizeRecord($record);

        $this->assertSame('WGS84', $normalized['grid_ref_system']);
        $this->assertSame(1500, $normalized['coordinate_uncertainty_in_meters']);
        $this->assertSame('53.4808', $normalized['latitude']);
        $this->assertSame('-2.2426', $normalized['longitude']);
        $this->assertSame('SU13H', $normalized['grid_ref_2km']);
    }

    /**
     * Verify an unconfigured Indicia endpoint is rejected.
     */
    public function testFetchPageThrowsWhenEndpointMissing(): void
    {
        $adapter = new IndiciaOccurrencesAdapter(
            $this->createMock(CURLRequest::class),
            [],
            1,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Indicia endpoint is not configured');

        $adapter->fetchPage(null, 10);
    }

    /**
     * Verify request construction, checkpoint filtering, and record normalization.
     */
    public function testFetchPagePostsFiltersAndNormalizesRecords(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn((string) json_encode([
            'meta' => ['count' => 1, 'total' => 2, 'offset' => 0],
            'records' => [[
                '_id' => 'remote-1',
                'metadata' => ['tracking' => 'track-1'],
                'taxon' => ['accepted_taxon_id' => 'TVK-1', 'taxon_id' => 'GIVEN-1'],
                'location' => [
                    'output_sref' => 'SU1234',
                    'output_sref_system' => 'OSGB',
                    'coordinate_uncertainty_in_meters' => 100,
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                'https://warehouse.test/index.php/services/rest/es/_search/',
                $this->callback(function (array $options): bool {
                    $body = json_decode((string) $options['body'], true);

                    return $options['headers']['X-Project-Id'] === 'project-1'
                        && $options['headers']['Authorization'] === 'USER:user-1:SECRET:secret-1'
                        && $body['size'] === 10
                        && $body['query']['bool']['filter'][5]['range']['metadata.tracking']['gt'] === 'old-checkpoint';
                }),
            )
            ->willReturn($response);

        $adapter = new IndiciaOccurrencesAdapter($client, [
            'warehouse_url' => 'https://warehouse.test',
            'es_endpoint' => 'es',
            'project_id' => 'project-1',
            'username' => 'user-1',
            'secret' => 'secret-1',
            'taxon_groups' => [],
            'geographic_regions' => [],
            'geographic_region_location_type' => 'Vice County',
            'maximum_coordinate_uncertainty_in_meters' => 10000,
        ], 10);

        $page = $adapter->fetchPage('old-checkpoint', 10);

        $this->assertCount(1, $page->records);
        $this->assertTrue($page->hasMore);
        $this->assertSame('track-1', $page->nextCheckpoint);
        $this->assertSame('remote-1', $page->records[0]['remote_id']);
        $this->assertSame('TVK-1', $page->records[0]['scientific_name_identifier']);
    }

    /**
     * Verify HTTP failures are reported to the caller.
     */
    public function testFetchPageThrowsForHttpErrors(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(503);
        $response->method('getBody')->willReturn('service unavailable');

        $client = $this->createMock(CURLRequest::class);
        $client->method('post')->willReturn($response);

        $adapter = new IndiciaOccurrencesAdapter($client, [
            'endpoint' => 'https://warehouse.test/search',
            'maximum_coordinate_uncertainty_in_meters' => 10000,
        ], 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Indicia request failed with status 503. Response: service unavailable');

        $adapter->fetchPage(null, 10);
    }

    /**
     * Verify malformed responses are rejected.
     */
    public function testFetchPageThrowsForInvalidJson(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn('not-json');

        $client = $this->createMock(CURLRequest::class);
        $client->method('post')->willReturn($response);

        $adapter = new IndiciaOccurrencesAdapter($client, [
            'endpoint' => 'https://warehouse.test/search',
            'maximum_coordinate_uncertainty_in_meters' => 10000,
        ], 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Indicia response was not valid JSON');

        $adapter->fetchPage(null, 10);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    /**
     * Invoke the adapter's record normalization helper.
     *
     * @param array<string, mixed> $record Raw occurrence record.
     * @return array<string, mixed> Normalized occurrence record.
     */
    private function normalizeRecord(array $record): array
    {
        $method = new ReflectionMethod(IndiciaOccurrencesAdapter::class, 'normalizeRecord');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke($this->newAdapter(), $record);

        return $result;
    }

    /**
     * Build an adapter with a mocked HTTP client.
     *
     * @return IndiciaOccurrencesAdapter Adapter under test.
     */
    private function newAdapter(): IndiciaOccurrencesAdapter
    {
        return new IndiciaOccurrencesAdapter(
            $this->createMock(\CodeIgniter\HTTP\CURLRequest::class),
            [],
            1,
        );
    }
}