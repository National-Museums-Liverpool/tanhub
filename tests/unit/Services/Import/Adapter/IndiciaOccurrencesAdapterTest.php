<?php

namespace Tests;

use App\Services\Import\Adapter\IndiciaOccurrencesAdapter;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

/**
 * @internal
 */
final class IndiciaOccurrencesAdapterTest extends CIUnitTestCase
{
    public function testCalculateTetradReturnsNullForTooCoarseGridReference(): void
    {
        $this->assertNull($this->calculateTetrad('SU12'));
    }

    public function testCalculateTetradConvertsOneKilometreGridReference(): void
    {
        $this->assertSame('SU13L', $this->calculateTetrad('SU1234'));
    }

    public function testCalculateTetradConvertsFinerGridReference(): void
    {
        $this->assertSame('SU14L', $this->calculateTetrad('SU123456'));
    }

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
    }

    private function calculateTetrad(string $gridRef): ?string
    {
        $method = new ReflectionMethod(IndiciaOccurrencesAdapter::class, 'calculateTetrad');
        $method->setAccessible(true);

        /** @var ?string $result */
        $result = $method->invoke($this->newAdapter(), $gridRef);

        return $result;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function normalizeRecord(array $record): array
    {
        $method = new ReflectionMethod(IndiciaOccurrencesAdapter::class, 'normalizeRecord');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke($this->newAdapter(), $record);

        return $result;
    }

    private function newAdapter(): IndiciaOccurrencesAdapter
    {
        return new IndiciaOccurrencesAdapter(
            $this->createMock(\CodeIgniter\HTTP\CURLRequest::class),
            [],
            1,
        );
    }
}