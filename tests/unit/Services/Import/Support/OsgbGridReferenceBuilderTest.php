<?php

namespace Tests;

use App\Services\Import\Support\OsgbGridReferenceBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class OsgbGridReferenceBuilderTest extends CIUnitTestCase
{
    /**
     * Verify uncertainty values map to the smallest supported square size.
     */
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

    /**
     * Verify invalid uncertainty values use the default square size.
     */
    public function testSelectSquareSizeFallsBackTo2000ForInvalidValues(): void
    {
        $builder = new OsgbGridReferenceBuilder();

        $this->assertSame(2000, $builder->selectSquareSize(null));
        $this->assertSame(2000, $builder->selectSquareSize(''));
        $this->assertSame(2000, $builder->selectSquareSize('foo'));
        $this->assertSame(2000, $builder->selectSquareSize(0));
        $this->assertSame(2000, $builder->selectSquareSize(-10));
    }

    /**
     * Verify WGS84 coordinates generate a DINTY reference for 2km precision.
     */
    public function testBuildFromWgs84GeneratesDintyFor2000Size(): void
    {
        $builder = new OsgbGridReferenceBuilder();
        $result = $builder->buildFromWgs84(53.4808, -2.2426, 1500);

        $this->assertNotNull($result);
        $this->assertSame(2000, $result['size']);
        $this->assertMatchesRegularExpression('/^[A-Z]{2}\d{2}[A-HJ-Z]$/', $result['grid_ref']);
        $this->assertSame($result['grid_ref'], $builder->calculateDintyTetrad($result['grid_ref']));
    }

    /**
     * Verify DINTY letters increase northwards within each easting column.
     */
    public function testCalculateDintyTetradUsesEastingAsTheTetradColumn(): void
    {
        $builder = new OsgbGridReferenceBuilder();

        $this->assertSame('SU12A', $builder->calculateDintyTetrad('SU1020'));
        $this->assertSame('SU12E', $builder->calculateDintyTetrad('SU1028'));
        $this->assertSame('SU12V', $builder->calculateDintyTetrad('SU1820'));
    }

    /**
     * Verify an 8-digit grid reference is converted using its easting and northing axes.
     */
    public function testCalculateDintyTetradConvertsSuppliedGridReference(): void
    {
        $builder = new OsgbGridReferenceBuilder();

        $this->assertSame('SD21V', $builder->calculateDintyTetrad('SD29081025'));
    }

    /**
     * Verify WGS84 coordinates generate a hectad when uncertainty requires it.
     */
    public function testBuildFromWgs84GeneratesTenKilometrePrecisionWhenNeeded(): void
    {
        $builder = new OsgbGridReferenceBuilder();
        $result = $builder->buildFromWgs84(53.4808, -2.2426, 8000);

        $this->assertNotNull($result);
        $this->assertSame(10000, $result['size']);
        $this->assertMatchesRegularExpression('/^[A-Z]{2}\d{2}$/', $result['grid_ref']);
        $this->assertNull($builder->calculateDintyTetrad($result['grid_ref']));
    }

    /**
     * Verify invalid or out-of-bounds coordinates return null.
     */
    public function testBuildFromWgs84ReturnsNullForOutOfBoundsOrInvalidCoordinates(): void
    {
        $builder = new OsgbGridReferenceBuilder();

        $this->assertNull($builder->buildFromWgs84(null, -2.2426, 1500));
        $this->assertNull($builder->buildFromWgs84('foo', -2.2426, 1500));
        $this->assertNull($builder->buildFromWgs84(0, 0, 1500));
    }
}
