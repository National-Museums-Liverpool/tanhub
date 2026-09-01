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
            'startIndex' => 25,
            'pageSize' => 25,
            'totalRecords' => 200,
            'occurrences' => [
                [
                    'uuid' => 'nbn-uuid-1',
                    'occurrenceID' => 'occ-1',
                    'taxonConceptID' => 'NHMSYS0001',
                    'eventDate' => '2024-05-01',
                    'gridReference' => 'SU123456',
                    'recordedBy' => 'Recorder One',
                    'identifiedBy' => 'Identifier One',
                    'decimalLatitude' => '53.4808',
                    'decimalLongitude' => '-2.2426',
                    'gridReferenceSystem' => 'EPSG:4326',
                    'dataProviderName' => 'Biological Records Centre',
                    'dataResourceName' => 'iRecord Bats',
                    'identificationVerificationStatus' => 'V',
                    'coordinateUncertaintyInMeters' => '1500',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->once())
            ->method('get')
            ->with(
                $this->callback(static function (string $url): bool {
                    $parts = parse_url($url);
                    $queryString = (string) ($parts['query'] ?? '');
                    parse_str($queryString, $query);
                    preg_match_all('/(?:^|&)fq=([^&]*)/', $queryString, $matches);
                    $fq = array_map('urldecode', $matches[1] ?? []);

                    return $query['pageSize'] === '25'
                        && $query['startIndex'] === '25'
                        && $query['q'] === '*:*'
                        && in_array('cl254:("Cheshire" OR "South Lancashire")', $fq, true)
                        && in_array('taxonRankID:[6000 TO *]', $fq, true)
                        && in_array('kingdom:Animalia', $fq, true)
                        && in_array('-phylum:Chordata', $fq, true)
                        && in_array('-order:Lepidoptera', $fq, true)
                        && in_array('-(user_assertions:"50005" OR user_assertions:"50006" OR user_assertions:"50001")', $fq, true);
                })
            )
            ->willReturn($response);

        $adapter = new NbnAtlasOccurrencesAdapter(
            $client,
            [
                'endpoint' => 'https://example.test/nbn',
                'min_taxon_rank_id' => 6000,
                'geographic_regions' => ['Cheshire', 'South Lancashire'],
                'nbn_filter_query' => 'fq=kingdom:Animalia&fq=-phylum:Chordata&fq=-order:Lepidoptera',
            ],
            10,
        );

        $page = $adapter->fetchPage('25', 25);

        $this->assertCount(1, $page->records);
        $this->assertTrue($page->hasMore);
        $this->assertSame('occurrenceID:occ-1', $page->nextCheckpoint);

        $record = $page->records[0];
        $this->assertSame('nbn-uuid-1', $record['remote_id']);
        $this->assertSame('occ-1', $record['occurrence_id']);
        $this->assertSame('NHMSYS0001', $record['scientific_name_identifier']);
        $this->assertSame('NHMSYS0001', $record['given_name_identifier']);
        $this->assertSame('Biological Records Centre', $record['data_provider_name']);
        $this->assertSame('iRecord Bats', $record['source_name']);
        $this->assertSame('2024-05-01', $record['from_date']);
        $this->assertSame('2024-05-01', $record['to_date']);
        $this->assertSame('SU123456', $record['grid_ref']);
        $this->assertSame('SU123', $record['grid_ref_2km']);
        $this->assertSame('EPSG:4326', $record['grid_ref_system']);
        $this->assertSame('V', $record['identification_verification_status']);
        $this->assertSame(1500.0, $record['coordinate_uncertainty_in_meters']);
    }

    /**
     * Verify supported NBN event dates become inclusive ISO date ranges.
     *
     * @param string      $eventDate   Raw NBN event date.
     * @param string|null $expectedFrom Expected inclusive range start.
     * @param string|null $expectedTo   Expected inclusive range end.
     *
     * @return void
     *
     * @dataProvider eventDateProvider
     */
    public function testFetchPageNormalizesEventDateRanges(
        string $eventDate,
        ?string $expectedFrom,
        ?string $expectedTo,
    ): void {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn((string) json_encode([
            'totalRecords' => 1,
            'occurrences' => [[
                'uuid' => 'date-test',
                'taxonConceptID' => 'NHMSYS0001',
                'eventDate' => $eventDate,
            ]],
        ], JSON_THROW_ON_ERROR));

        $client = $this->createMock(CURLRequest::class);
        $client->method('get')->willReturn($response);
        $adapter = new NbnAtlasOccurrencesAdapter(
            $client,
            ['endpoint' => 'https://example.test/nbn'],
            10,
        );

        $record = $adapter->fetchPage(null, 50)->records[0];

        $this->assertSame($expectedFrom, $record['from_date']);
        $this->assertSame($expectedTo, $record['to_date']);
    }

    /**
     * Supply supported, partial, interval, and invalid NBN event dates.
     *
     * @return array<string, array{0: string, 1: string|null, 2: string|null}> Test cases.
     */
    public static function eventDateProvider(): array
    {
        return [
            'ISO full date' => ['2014-05-15', '2014-05-15', '2014-05-15'],
            'ISO date and time' => ['2014-05-15T10:30:00Z', '2014-05-15', '2014-05-15'],
            'UK full date' => ['27/11/1991', '1991-11-27', '1991-11-27'],
            'Unix timestamp in milliseconds' => ['1556496000000', '2019-04-29', '2019-04-29'],
            'pre-1970 Unix timestamp in milliseconds' => ['-2208988800000', '1900-01-01', '1900-01-01'],
            'year and month' => ['2024-02', '2024-02-01', '2024-02-29'],
            'year only' => ['1986', '1986-01-01', '1986-12-31'],
            'ISO interval' => ['2020-06/2021-02', '2020-06-01', '2021-02-28'],
            'invalid date' => ['2024-02-31', null, null],
        ];
    }

    /**
     * Verify separate NBN date parts are used when eventDate is omitted.
     *
     * @param array<string, int|string> $dateParts    Raw NBN date fields.
     * @param string                    $expectedFrom Expected inclusive range start.
     * @param string                    $expectedTo   Expected inclusive range end.
     *
     * @return void
     *
     * @dataProvider separateDatePartsProvider
     */
    public function testFetchPageNormalizesSeparateDateParts(
        array $dateParts,
        string $expectedFrom,
        string $expectedTo,
    ): void {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn((string) json_encode([
            'totalRecords' => 1,
            'occurrences' => [[
                'uuid' => 'date-parts-test',
                'taxonConceptID' => 'NHMSYS0001',
            ] + $dateParts],
        ], JSON_THROW_ON_ERROR));

        $client = $this->createMock(CURLRequest::class);
        $client->method('get')->willReturn($response);
        $adapter = new NbnAtlasOccurrencesAdapter(
            $client,
            ['endpoint' => 'https://example.test/nbn'],
            10,
        );

        $record = $adapter->fetchPage(null, 50)->records[0];

        $this->assertSame($expectedFrom, $record['from_date']);
        $this->assertSame($expectedTo, $record['to_date']);
    }

    /**
     * Supply valid date-part combinations returned by NBN search.
     *
     * @return array<string, array{0: array<string, int|string>, 1: string, 2: string}> Test cases.
     */
    public static function separateDatePartsProvider(): array
    {
        return [
            'year and month' => [['year' => 1991, 'month' => '06'], '1991-06-01', '1991-06-30'],
            'year only' => [['year' => 1991], '1991-01-01', '1991-12-31'],
        ];
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
            'totalRecords' => 1,
            'startIndex' => 0,
            'pageSize' => 50,
            'records' => [
                [
                    'uuid' => 'uuid-2',
                    'taxonConceptID' => 'NHMSYS0002',
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
        $this->assertSame('uuid-2', $page->records[0]['remote_id']);
        $this->assertSame('NHMSYS0002', $page->records[0]['scientific_name_identifier']);
        $this->assertSame('SU99A', $page->records[0]['grid_ref_2km']);
        $this->assertFalse($page->hasMore);
        $this->assertSame('1', $page->nextCheckpoint);
    }

    public function testFetchPageUsesOccurrenceIdCursorAfterLegacyOffsetWindow(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn((string) json_encode([
            'totalRecords' => 1000000,
            'startIndex' => 4800,
            'pageSize' => 50,
            'occurrences' => [
                ['uuid' => 'uuid-5000', 'occurrenceID' => '5000', 'taxonConceptID' => 'NHMSYS0001'],
            ],
        ], JSON_THROW_ON_ERROR));

        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->once())
            ->method('get')
            ->with($this->callback(static function (string $url): bool {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return $query['startIndex'] === '4800';
            }))
            ->willReturn($response);

        $adapter = new NbnAtlasOccurrencesAdapter(
            $client,
            ['endpoint' => 'https://example.test/nbn'],
            10,
        );

        $page = $adapter->fetchPage('5000', 200);

        $this->assertSame('occurrenceID:5000', $page->nextCheckpoint);
    }

    public function testFetchPageAddsExclusiveOccurrenceIdCursorFilter(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn((string) json_encode([
            'totalRecords' => 1000000,
            'startIndex' => 0,
            'pageSize' => 1,
            'occurrences' => [
                ['uuid' => 'uuid-5001', 'occurrenceID' => '5001', 'taxonConceptID' => 'NHMSYS0001'],
            ],
        ], JSON_THROW_ON_ERROR));

        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->once())
            ->method('get')
            ->with($this->callback(static function (string $url): bool {
                $queryString = (string) parse_url($url, PHP_URL_QUERY);
                parse_str($queryString, $query);

                return $query['startIndex'] === '0'
                    && str_contains(urldecode($queryString), 'fq=occurrenceID:[5000 TO *]');
            }))
            ->willReturn($response);

        $adapter = new NbnAtlasOccurrencesAdapter(
            $client,
            ['endpoint' => 'https://example.test/nbn'],
            10,
        );

        $page = $adapter->fetchPage('occurrenceID:5000', 200);

        $this->assertTrue($page->hasMore);
    }

    public function testFetchPageSupportsSingleRawConfiguredFilterClause(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn((string) json_encode([
            'totalRecords' => 0,
            'startIndex' => 0,
            'pageSize' => 10,
            'occurrences' => [],
        ], JSON_THROW_ON_ERROR));

        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->once())
            ->method('get')
            ->with(
                $this->callback(static function (string $url): bool {
                    return str_contains($url, 'fq=kingdom%3AAnimalia');
                })
            )
            ->willReturn($response);

        $adapter = new NbnAtlasOccurrencesAdapter(
            $client,
            [
                'endpoint' => 'https://example.test/nbn',
                'nbn_filter_query' => 'kingdom:Animalia',
            ],
            10,
        );

        $page = $adapter->fetchPage(null, 10);

        $this->assertSame([], $page->records);
        $this->assertFalse($page->hasMore);
    }
}
