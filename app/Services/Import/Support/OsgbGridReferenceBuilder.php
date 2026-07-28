<?php

namespace App\Services\Import\Support;

use proj4php\Point;
use proj4php\Proj;
use proj4php\Proj4php;

/**
 * Builds OSGB grid references from WGS84 coordinates.
 */
class OsgbGridReferenceBuilder
{
    /**
     * Supported grid square sizes in metres.
     *
     * @var array<int, int>
     */
    private const SUPPORTED_SQUARE_SIZES = [1, 10, 100, 1000, 2000, 10000, 100000];

    /**
     * DINTY tetrad alphabet omits I.
     *
     * @var string
     */
    private const DINTY_TETRAD_LETTERS = 'ABCDEFGHIJKLMNPQRSTUVWXYZ';

    /**
     * @var Proj4php
     */
    private Proj4php $proj4;

    /**
     * @var Proj
     */
    private Proj $wgs84;

    /**
     * @var Proj
     */
    private Proj $osgb27700;

    /**
     * Initialize projection dependencies.
     */
    public function __construct()
    {
        $this->proj4 = new Proj4php();
        $this->proj4->addDef('EPSG:27700', '+proj=tmerc +lat_0=49 +lon_0=-2 +k=0.9996012717 +x_0=400000 +y_0=-100000 +ellps=airy +datum=OSGB36 +units=m +no_defs');
        $this->wgs84 = new Proj('EPSG:4326', $this->proj4);
        $this->osgb27700 = new Proj('EPSG:27700', $this->proj4);
    }

    /**
     * Build an OSGB grid reference from a WGS84 coordinate and uncertainty value.
     *
     * @param mixed $latitude Latitude in decimal degrees.
     * @param mixed $longitude Longitude in decimal degrees.
     * @param mixed $coordinateUncertaintyInMeters Coordinate uncertainty in metres.
     *
     * @return array{grid_ref: string, size: int}|null Null when conversion fails.
     */
    public function buildFromWgs84($latitude, $longitude, $coordinateUncertaintyInMeters): ?array
    {
        $normalisedLatitude = $this->normaliseFloat($latitude);
        $normalisedLongitude = $this->normaliseFloat($longitude);

        if ($normalisedLatitude === null || $normalisedLongitude === null) {
            return null;
        }

        [$easting, $northing] = $this->projectWgs84ToOsgb($normalisedLatitude, $normalisedLongitude);

        if ($easting === null || $northing === null) {
            return null;
        }

        $size = $this->selectSquareSize($coordinateUncertaintyInMeters);
        $gridRef = $this->gridReferenceFromEastingNorthing($easting, $northing, $size);

        if ($gridRef === null) {
            return null;
        }

        return [
            'grid_ref' => $gridRef,
            'size' => $size,
        ];
    }

    /**
     * Select the grid square size from coordinate uncertainty.
     *
     * @param mixed $coordinateUncertaintyInMeters Coordinate uncertainty in metres.
     *
     * @return int Grid square size in metres.
     */
    public function selectSquareSize($coordinateUncertaintyInMeters): int
    {
        $uncertainty = $this->normaliseFloat($coordinateUncertaintyInMeters);

        if ($uncertainty === null || $uncertainty <= 0) {
            return 2000;
        }

        foreach (self::SUPPORTED_SQUARE_SIZES as $squareSize) {
            if ($uncertainty <= $squareSize) {
                return $squareSize;
            }
        }

        return 100000;
    }

    /**
     * Derive DINTY tetrad from an OSGB grid reference where possible.
     *
     * @param string $gridRef OSGB grid reference.
     *
     * @return string|null DINTY tetrad or null when unavailable.
     */
    public function calculateDintyTetrad(string $gridRef): ?string
    {
        $gridRef = strtoupper(preg_replace('/\s+/', '', trim($gridRef)) ?? '');

        if ($gridRef === '') {
            return null;
        }

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z]$/', $gridRef) === 1) {
            return str_contains($gridRef, 'I') ? null : $gridRef;
        }

        if (preg_match('/^[A-Z]{2}\d+$/', $gridRef) !== 1) {
            return null;
        }

        $letters = substr($gridRef, 0, 2);
        $digits = substr($gridRef, 2);

        if (strlen($digits) < 4 || strlen($digits) % 2 !== 0 || str_contains($letters, 'I')) {
            return null;
        }

        $precisionDigits = (int) (strlen($digits) / 2);
        $scale = 10 ** (5 - $precisionDigits);
        $hectadScale = 10 ** ($precisionDigits - 1);

        $eastingDigits = (int) substr($digits, 0, $precisionDigits);
        $northingDigits = (int) substr($digits, $precisionDigits);

        $eastingHectad = intdiv($eastingDigits, $hectadScale);
        $northingHectad = intdiv($northingDigits, $hectadScale);

        $eastingWithinHectad = ($eastingDigits % $hectadScale) * $scale;
        $northingWithinHectad = ($northingDigits % $hectadScale) * $scale;

        $tetradX = intdiv($eastingWithinHectad, 2000);
        $tetradY = intdiv($northingWithinHectad, 2000);

        if ($tetradX < 0 || $tetradX > 4 || $tetradY < 0 || $tetradY > 4) {
            return null;
        }

        $tetradIndex = ($tetradY * 5) + $tetradX;

        return $letters . $eastingHectad . $northingHectad . self::DINTY_TETRAD_LETTERS[$tetradIndex];
    }

    /**
     * Convert WGS84 latitude/longitude to OSGB easting/northing.
     *
     * @param float $latitude Latitude in decimal degrees.
     * @param float $longitude Longitude in decimal degrees.
     *
     * @return array{0: float|null, 1: float|null} Easting/northing pair.
     */
    private function projectWgs84ToOsgb(float $latitude, float $longitude): array
    {
        try {
            $sourcePoint = new Point($longitude, $latitude, $this->wgs84);
            $targetPoint = $this->proj4->transform($this->osgb27700, $sourcePoint);
        } catch (\Throwable) {
            return [null, null];
        }

        $easting = isset($targetPoint->x) ? (float) $targetPoint->x : null;
        $northing = isset($targetPoint->y) ? (float) $targetPoint->y : null;

        if ($easting === null || $northing === null) {
            return [null, null];
        }

        if (! is_finite($easting) || ! is_finite($northing)) {
            return [null, null];
        }

        return [$easting, $northing];
    }

    /**
     * Build an OSGB grid reference for the requested grid square size.
     *
     * @param float $easting Easting in EPSG:27700.
     * @param float $northing Northing in EPSG:27700.
     * @param int $size Grid square size in metres.
     *
     * @return string|null Formatted OSGB grid reference or null when invalid.
     */
    private function gridReferenceFromEastingNorthing(float $easting, float $northing, int $size): ?string
    {
        if (! in_array($size, self::SUPPORTED_SQUARE_SIZES, true)) {
            return null;
        }

        if ($easting < 0 || $northing < 0 || $easting >= 700000 || $northing >= 1300000) {
            return null;
        }

        $eastingInt = (int) floor($easting);
        $northingInt = (int) floor($northing);
        $letters = $this->gridLetters($eastingInt, $northingInt);

        if ($letters === null) {
            return null;
        }

        if ($size === 2000) {
            return $this->dintyFromEastingNorthing($letters, $eastingInt, $northingInt);
        }

        if ($size === 100000) {
            return $letters;
        }

        $digitsPerCoordinate = 5 - (int) round(log10((float) $size));

        if ($digitsPerCoordinate < 1 || $digitsPerCoordinate > 5) {
            return null;
        }

        $eastingWithin = $eastingInt % 100000;
        $northingWithin = $northingInt % 100000;

        $east = intdiv($eastingWithin, $size);
        $north = intdiv($northingWithin, $size);

        return sprintf('%s%0*d%0*d', $letters, $digitsPerCoordinate, $east, $digitsPerCoordinate, $north);
    }

    /**
     * Resolve two-letter OSGB 100km square prefix.
     *
     * @param int $easting Easting in metres.
     * @param int $northing Northing in metres.
     *
     * @return string|null Two-letter square or null when outside OSGB coverage.
     */
    private function gridLetters(int $easting, int $northing): ?string
    {
        $e100k = intdiv($easting, 100000);
        $n100k = intdiv($northing, 100000);

        if ($e100k < 0 || $e100k > 6 || $n100k < 0 || $n100k > 12) {
            return null;
        }

        $l1 = (19 - $n100k) - ((19 - $n100k) % 5) + intdiv($e100k + 10, 5);
        $l2 = ((19 - $n100k) * 5 % 25) + ($e100k % 5);

        if ($l1 > 7) {
            $l1++;
        }

        if ($l2 > 7) {
            $l2++;
        }

        return chr($l1 + 65) . chr($l2 + 65);
    }

    /**
     * Build DINTY tetrad code from easting/northing.
     *
     * @param string $letters Two-letter OSGB prefix.
     * @param int $easting Easting in metres.
     * @param int $northing Northing in metres.
     *
     * @return string DINTY tetrad code.
     */
    private function dintyFromEastingNorthing(string $letters, int $easting, int $northing): string
    {
        $eastingWithin = $easting % 100000;
        $northingWithin = $northing % 100000;

        $eastingHectad = intdiv($eastingWithin, 10000);
        $northingHectad = intdiv($northingWithin, 10000);

        $tetradX = intdiv($eastingWithin % 10000, 2000);
        $tetradY = intdiv($northingWithin % 10000, 2000);
        $tetradIndex = ($tetradY * 5) + $tetradX;

        return $letters . $eastingHectad . $northingHectad . self::DINTY_TETRAD_LETTERS[$tetradIndex];
    }

    /**
     * Coerce scalar input into float when possible.
     *
     * @param mixed $value Input value.
     *
     * @return float|null Float value or null when invalid.
     */
    private function normaliseFloat($value): ?float
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '' || ! is_numeric($string)) {
            return null;
        }

        return (float) $string;
    }
}