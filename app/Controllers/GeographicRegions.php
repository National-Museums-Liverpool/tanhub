<?php

namespace App\Controllers;

use App\Models\GeographicRegionModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Admin read-only views for the `geographic_regions` lookup table.
 *
 * Regions are imported from Indicia (see {@see \App\Services\Import}) rather
 * than created here, so this controller only exposes a searchable/sortable
 * listing and a details page, both enriched with occurrence counts from the
 * `geographic_regions_occurrences` join table.
 */
class GeographicRegions extends BaseController
{
    /**
     * Display a paginated, sortable list of geographic regions.
     *
     * Joins in a per-region occurrence count so the list can show usage at a
     * glance without an N+1 query per row.
     *
     * @return string Rendered HTML for the geographic regions index view.
     */
    public function index(): string
    {
        $sort = strtolower((string) $this->request->getGet('sort'));
        $direction = strtolower((string) $this->request->getGet('direction'));
        $q = trim((string) $this->request->getGet('q'));

        $allowedSortColumns = ['id', 'higher_geography_identifier', 'higher_geography', 'location_type'];

        if (! in_array($sort, $allowedSortColumns, true)) {
            $sort = 'higher_geography';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        /** @var GeographicRegionModel $model */
        $model = model(GeographicRegionModel::class);

        if ($q !== '') {
            $model->groupStart()
                ->like('higher_geography_identifier', $q)
                ->orLike('higher_geography', $q)
                ->orLike('location_type', $q)
                ->groupEnd();
        }

        $regions = $model->orderBy($sort, $direction)->paginate(20);

        $regionIds = array_map(static fn (array $region): int => (int) $region['id'], $regions);
        $occurrenceCounts = $this->getOccurrenceCountsByRegionId($regionIds);

        foreach ($regions as &$region) {
            $region['occurrence_count'] = $occurrenceCounts[(int) $region['id']] ?? 0;
        }
        unset($region);

        return $this->renderPage('geographic-regions/index', [
            'pageTitle' => 'Geographic regions',
            'metaDescription' => 'Geographic regions list.',
            'bodyClass' => 'app-shell',
            'geographicRegions' => $regions,
            'pager' => $model->pager,
            'sort' => $sort,
            'direction' => $direction,
            'q' => $q,
        ]);
    }

    /**
     * Show read-only details for a single geographic region.
     *
     * @param int $id Geographic region identifier.
     * @return string Rendered HTML for the geographic region details view.
     * @throws PageNotFoundException If no region exists with the given ID.
     */
    public function details(int $id): string
    {
        /** @var GeographicRegionModel $model */
        $model = model(GeographicRegionModel::class);
        $region = $model->find($id);

        if ($region === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $dataSource = db_connect()
            ->table('data_sources')
            ->select('abbr, title')
            ->where('id', $region['data_source_id'])
            ->get()
            ->getRowArray();

        $occurrenceCount = $this->getOccurrenceCountForRegionId($id);

        return $this->renderPage('geographic-regions/details', [
            'pageTitle' => 'Geographic region details',
            'metaDescription' => 'Read-only geographic region details.',
            'bodyClass' => 'app-shell',
            'geographicRegion' => $region,
            'dataSource' => $dataSource,
            'occurrenceCount' => $occurrenceCount,
        ]);
    }

    /**
     * Return occurrence counts keyed by region ID.
     *
     * @param array<int, int> $regionIds Region IDs to count occurrences for.
     * @return array<int, int> Occurrence count keyed by `geographic_region_id`.
     */
    private function getOccurrenceCountsByRegionId(array $regionIds): array
    {
        if ($regionIds === []) {
            return [];
        }

        $rows = db_connect()
            ->table('geographic_regions_occurrences')
            ->select('geographic_region_id, COUNT(*) AS occurrence_count')
            ->whereIn('geographic_region_id', $regionIds)
            ->groupBy('geographic_region_id')
            ->get()
            ->getResultArray();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['geographic_region_id']] = (int) $row['occurrence_count'];
        }

        return $counts;
    }

    /**
     * Return the occurrence count for a single region ID.
     *
     * @param int $id Geographic region identifier.
     * @return int Number of linked rows in `geographic_regions_occurrences`.
     */
    private function getOccurrenceCountForRegionId(int $id): int
    {
        return (int) db_connect()
            ->table('geographic_regions_occurrences')
            ->where('geographic_region_id', $id)
            ->countAllResults();
    }
}