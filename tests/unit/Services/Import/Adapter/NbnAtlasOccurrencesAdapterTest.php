<?php

namespace Tests;

use App\Services\Import\Adapter\NbnAtlasOccurrencesAdapter;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * @internal
 */
final class NbnAtlasOccurrencesAdapterTest extends CIUnitTestCase
{
    public function testFetchPageThrowsWhenEndpointMissing(): void
    {
        $adapter = new NbnAtlasOccurrencesAdapter(
            $this->createMock(CURLRequest::class),
            [],
            1,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NBN endpoint is not configured');

        $adapter->fetchPage(null, 10);
    }

    public function testFetchPageNormalizesRecordsAndCheckpoint(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn((string) json_encode([
            'occurrences' => [
                [
                    'occurrenceID' => 'occ-1',
                    'taxonID' => 'tax-1',
                    'scientificNameID' => 'sn-1',
                    'eventDate' => '2024-05-01',
                    'gridReference' => 'SU123456',
                    'recordedBy' => 'Recorder One',
                    'identifiedBy' => 'Identifier One',
                    'decimalLatitude' => '53.4808',
                    'decimalLongitude' => '-2.2426',
                    'gridReferenceSystem' => 'EPSG:4326',
                    'coordinateUncertaintyInMeters' => '1500',
                    'lastModified' => '2025-01-02T12:34:56Z',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->once())
            ->method('get')
            ->with(
                'https://example.test/nbn',
                $this->callback(static function (array $options): bool {
                    return isset($options['query']['limit'])
                        && $options['query']['limit'] === 25
                        && $options['query']['since'] === 'checkpoint-1';
                })
            )
            ->willReturn($response);

        $adapter = new NbnAtlasOccurrencesAdapter(
            $client,
            [
                'endpoint' => 'https://example.test/nbn',
                'checkpoint_param' => 'since',
                'checkpoint_field' => 'lastModified',
            ],
            10,
        );

        $page = $adapter->fetchPage('checkpoint-1', 25);

        $this->assertCount(1, $page->records);
        $this->assertFalse($page->hasMore);
        $this->assertSame('2025-01-02T12:34:56Z', $page->nextCheckpoint);

        $record = $page->records[0];
        $this->assertSame('occ-1', $record['remote_id']);
        $this->assertSame('tax-1', $record['taxon_identifier']);
        $this->assertSame('sn-1', $record['given_name_identifier']);
        $this->assertSame('SU123456', $record['grid_ref']);
        $this->assertSame('SU123', $record['grid_ref_2km']);
        $this->assertSame('EPSG:4326', $record['grid_ref_system']);
        $this->assertSame(1500.0, $record['coordinate_uncertainty_in_meters']);
        $this->assertSame('2025-01-02T12:34:56Z', $record['_checkpoint']);
    }

    public function testFetchPageThrowsForHttpErrors(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);

        $client = $this->createMock(CURLRequest::class);
        $client->method('get')->willReturn($response);

        $adapter = new NbnAtlasOccurrencesAdapter(
            $client,
            ['endpoint' => 'https://example.test/nbn'],
            10,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NBN request failed with status 500');

        $adapter->fetchPage(null, 50);
    }

    public function testFetchPageThrowsForInvalidJson(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn('not-json');

        $client = $this->createMock(CURLRequest::class);
        $client->method('get')->willReturn($response);

        $adapter = new NbnAtlasOccurrencesAdapter(
            $client,
            ['endpoint' => 'https://example.test/nbn'],
            10,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NBN response was not valid JSON object/array.');

        $adapter->fetchPage(null, 50);
    }

    public function testFetchPageSupportsAlternatePayloadRecordsKey(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn((string) json_encode([
            'records' => [
                [
                    'occurrenceID' => 'occ-2',
                    'grid_ref' => 'SU999999',
                    'grid_ref_2km' => 'SU99A',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $client = $this->createMock(CURLRequest::class);
        $client->method('get')->willReturn($response);

        $adapter = new NbnAtlasOccurrencesAdapter(
            $client,
            [
                'endpoint' => 'https://example.test/nbn',
                'records_key' => 'occurrences',
            ],
            10,
        );

        $page = $adapter->fetchPage(null, 50);

        $this->assertCount(1, $page->records);
        $this->assertSame('occ-2', $page->records[0]['remote_id']);
        $this->assertSame('SU99A', $page->records[0]['grid_ref_2km']);
    }
}
