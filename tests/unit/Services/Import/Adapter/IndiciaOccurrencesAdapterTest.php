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

    private function calculateTetrad(string $gridRef): ?string
    {
        $method = new ReflectionMethod(IndiciaOccurrencesAdapter::class, 'calculateTetrad');
        $method->setAccessible(true);

        /** @var ?string $result */
        $result = $method->invoke($this->newAdapter(), $gridRef);

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