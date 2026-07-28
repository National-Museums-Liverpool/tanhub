<?php

namespace Tests;

use App\Services\Import\Support\OsgbGridReferenceBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class OsgbGridReferenceBuilderTest extends CIUnitTestCase
{
    public function testSelectSquareSizeMapsToSmallestSupportedSize(): void
    {
        $builder = new OsgbGridReferenceBuilder();

        $this->assertSame(1, $builder->selectSquareSize(1));
        $this->assertSame(10, $builder->selectSquareSize(9));
        $this->assertSame(100, $builder->selectSquareSize(11));
        $this->assertSame(1000, $builder->selectSquareSize(900));
        $this->assertSame(2000, $builder->selectSquareSize(1500));
        $this->assertSame(10000, $builder->selectSquareSize(2001));
        $this->assertSame(100000, $builder->selectSquareSize(50000));
    }

    public function testSelectSquareSizeFallsBackTo2000ForInvalidValues(): void
    {
        $builder = new OsgbGridReferenceBuilder();

        $this->assertSame(2000, $builder->selectSquareSize(null));
        $this->assertSame(2000, $builder->selectSquareSize(''));
        $this->assertSame(2000, $builder->selectSquareSize('foo'));
        $this->assertSame(2000, $builder->selectSquareSize(0));
        $this->assertSame(2000, $builder->selectSquareSize(-10));
    }

    public function testBuildFromWgs84GeneratesDintyFor2000Size(): void
    {
        $builder = new OsgbGridReferenceBuilder();
        $result = $builder->buildFromWgs84(53.4808, -2.2426, 1500);

        $this->assertNotNull($result);
        $this->assertSame(2000, $result['size']);
        $this->assertMatchesRegularExpression('/^[A-Z]{2}\d{2}[A-HJ-Z]$/', $result['grid_ref']);
        $this->assertSame($result['grid_ref'], $builder->calculateDintyTetrad($result['grid_ref']));
    }

    public function testBuildFromWgs84GeneratesTenKilometrePrecisionWhenNeeded(): void
    {
        $builder = new OsgbGridReferenceBuilder();
        $result = $builder->buildFromWgs84(53.4808, -2.2426, 8000);

        $this->assertNotNull($result);
        $this->assertSame(10000, $result['size']);
        $this->assertMatchesRegularExpression('/^[A-Z]{2}\d{2}$/', $result['grid_ref']);
        $this->assertNull($builder->calculateDintyTetrad($result['grid_ref']));
    }

    public function testBuildFromWgs84ReturnsNullForOutOfBoundsOrInvalidCoordinates(): void
    {
        $builder = new OsgbGridReferenceBuilder();

        $this->assertNull($builder->buildFromWgs84(null, -2.2426, 1500));
        $this->assertNull($builder->buildFromWgs84('foo', -2.2426, 1500));
        $this->assertNull($builder->buildFromWgs84(0, 0, 1500));
    }
}
