<?php

namespace App\Services\Import\Persistence;

use RuntimeException;

/**
 * Rebuilds geographic region memberships for imported occurrences.
 */
class GeographicRegionsOccurrenceImportService
{
    /**
     * Recompute all geographic region to occurrence links.
     *
     * @return array<string, int|string>
     */
    public function run(bool $dryRun = false): array
    {
        $counts = [
            'status' => 'success',
            'fetched' => 0,
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        try {
            $db = db_connect();
            $assignments = $this->buildAssignments($db);

            $counts['fetched'] = count($assignments);
            $counts['processed'] = $counts['fetched'];

            if ($dryRun) {
                return $counts;
            }

            $db->table('geographic_regions_occurrences')->emptyTable();

            if ($assignments !== []) {
                foreach (array_chunk($assignments, 1000) as $batch) {
                    $db->table('geographic_regions_occurrences')->insertBatch($batch);
                }
            }

            $counts['inserted'] = count($assignments);
        } catch (\Throwable $exception) {
            log_message('error', $exception->getMessage());
            $counts['status'] = 'failed';
            $counts['errors']++;
        }

        return $counts;
    }

    /**
     * Build all region-to-occurrence assignments for the current database.
     *
     * @param object $db
     * @return array<int, array{geographic_region_id: int, occurrence_id: int}>
     */
    private function buildAssignments(object $db): array
    {
        $driver = strtoupper((string) ($db->DBDriver ?? ''));

        if ($driver === 'SQLITE3') {
            return $this->buildAssignmentsInPhp($db);
        }

        return $this->buildAssignmentsWithSpatialSql($db, $driver);
    }

    /**
     * Build assignments using database-native spatial predicates.
     *
     * @param object $db
     * @param string $driver
     * @return array<int, array{geographic_region_id: int, occurrence_id: int}>
     */
    private function buildAssignmentsWithSpatialSql(object $db, string $driver): array
    {
        $prefix = $db->getPrefix();

        if ($driver === 'POSTGRE') {
            $pointExpression = 'ST_SetSRID(ST_MakePoint(o.longitude, o.latitude), 4326)';
        } else {
            $pointExpression = "ST_GeomFromText(CONCAT('POINT(', o.longitude, ' ', o.latitude, ')'))";
        }

        $sql = 'SELECT DISTINCT
                gr.id AS geographic_region_id,
                o.id AS occurrence_id
            FROM ' . $prefix . 'occurrences o
            INNER JOIN ' . $prefix . 'geographic_regions gr
                ON gr.deleted_at IS NULL
            WHERE o.deleted_at IS NULL
                AND o.blocked = 0
                AND o.latitude IS NOT NULL
                AND o.longitude IS NOT NULL
                AND ST_Intersects(gr.footprint_geometry, ' . $pointExpression . ')';

        $rows = $db->query($sql)->getResultArray();
        $assignments = [];

        foreach ($rows as $row) {
            $geographicRegionId = (int) ($row['geographic_region_id'] ?? 0);
            $occurrenceId = (int) ($row['occurrence_id'] ?? 0);

            if ($geographicRegionId <= 0 || $occurrenceId <= 0) {
                continue;
            }

            $assignments[] = [
                'geographic_region_id' => $geographicRegionId,
                'occurrence_id' => $occurrenceId,
            ];
        }

        return $assignments;
    }

    /**
     * Build assignments in PHP for SQLite tests and other non-spatial drivers.
     *
     * @param object $db
     * @return array<int, array{geographic_region_id: int, occurrence_id: int}>
     */
    private function buildAssignmentsInPhp(object $db): array
    {
        $regions = $db->table('geographic_regions')
            ->select('id, footprint_geometry')
            ->where('deleted_at', null)
            ->where('footprint_geometry IS NOT NULL', null, false)
            ->get()
            ->getResultArray();

        $occurrences = $db->table('occurrences')
            ->select('id, latitude, longitude')
            ->where('deleted_at', null)
            ->where('blocked', 0)
            ->where('latitude IS NOT NULL', null, false)
            ->where('longitude IS NOT NULL', null, false)
            ->get()
            ->getResultArray();

        $assignments = [];

        foreach ($occurrences as $occurrence) {
            $occurrenceId = (int) ($occurrence['id'] ?? 0);
            $latitude = (float) ($occurrence['latitude'] ?? 0.0);
            $longitude = (float) ($occurrence['longitude'] ?? 0.0);
            $matchedRegion = false;

            foreach ($regions as $region) {
                $regionId = (int) ($region['id'] ?? 0);
                $geometry = trim((string) ($region['footprint_geometry'] ?? ''));

                if ($regionId <= 0 || $geometry === '') {
                    continue;
                }

                if (! $this->geometryContainsPoint($geometry, $latitude, $longitude)) {
                    continue;
                }

                $assignments[] = [
                    'geographic_region_id' => $regionId,
                    'occurrence_id' => $occurrenceId,
                ];
                $matchedRegion = true;
            }

            if (! $matchedRegion) {
                continue;
            }
        }

        return $assignments;
    }

    /**
     * Determine whether the given geometry contains the supplied point.
     *
     * @param string $geometry
     * @param float $latitude
     * @param float $longitude
     * @return bool
     */
    private function geometryContainsPoint(string $geometry, float $latitude, float $longitude): bool
    {
        $polygons = $this->parsePolygons($geometry);

        foreach ($polygons as $polygon) {
            if ($this->polygonContainsPoint($polygon, $latitude, $longitude)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse a polygon or multipolygon WKT string into outer rings.
     *
     * @param string $geometry
     * @return array<int, array<int, array{0: float, 1: float}>>
     */
    private function parsePolygons(string $geometry): array
    {
        $geometry = trim($geometry);
        $polygons = [];

        if ($geometry === '') {
            return [];
        }

        if (preg_match_all('/\(\((.*?)\)\)/s', $geometry, $matches) === 0) {
            return [];
        }

        foreach ($matches[1] as $polygonText) {
            $ring = $this->parseRing((string) $polygonText);

            if ($ring !== []) {
                $polygons[] = $ring;
            }
        }

        return $polygons;
    }

    /**
     * Parse a ring from comma-separated coordinate pairs.
     *
     * @param string $ringText
     * @return array<int, array{0: float, 1: float}>
     */
    private function parseRing(string $ringText): array
    {
        $points = [];
        $parts = explode(',', $ringText);

        foreach ($parts as $part) {
            $coordinates = preg_split('/\s+/', trim($part));

            if (! is_array($coordinates) || count($coordinates) < 2) {
                continue;
            }

            if (! is_numeric($coordinates[0]) || ! is_numeric($coordinates[1])) {
                continue;
            }

            $points[] = [(float) $coordinates[0], (float) $coordinates[1]];
        }

        return $points;
    }

    /**
     * Determine whether a polygon ring contains a point.
     *
     * @param array<int, array{0: float, 1: float}> $ring
     * @param float $latitude
     * @param float $longitude
     * @return bool
     */
    private function polygonContainsPoint(array $ring, float $latitude, float $longitude): bool
    {
        $pointCount = count($ring);

        if ($pointCount < 3) {
            return false;
        }

        $inside = false;
        $j = $pointCount - 1;

        for ($i = 0; $i < $pointCount; $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];

            if ($this->pointOnSegment($longitude, $latitude, $xi, $yi, $xj, $yj)) {
                return true;
            }

            $intersects = (($yi > $latitude) !== ($yj > $latitude))
                && ($longitude < (($xj - $xi) * ($latitude - $yi) / (($yj - $yi) === 0.0 ? 1.0 : ($yj - $yi))) + $xi);

            if ($intersects) {
                $inside = ! $inside;
            }

            $j = $i;
        }

        return $inside;
    }

    /**
     * Determine whether a point lies on a line segment.
     *
     * @param float $px
     * @param float $py
     * @param float $x1
     * @param float $y1
     * @param float $x2
     * @param float $y2
     * @return bool
     */
    private function pointOnSegment(float $px, float $py, float $x1, float $y1, float $x2, float $y2): bool
    {
        $cross = ($px - $x1) * ($y2 - $y1) - ($py - $y1) * ($x2 - $x1);

        if (abs($cross) > 1.0e-9) {
            return false;
        }

        $dot = ($px - $x1) * ($px - $x2) + ($py - $y1) * ($py - $y2);

        return $dot <= 0.0;
    }
}
