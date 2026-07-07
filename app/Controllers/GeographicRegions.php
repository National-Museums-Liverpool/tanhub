<?php

namespace App\Controllers;

use App\Models\GeographicRegionModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Admin read-only views for geographic regions.
 */
class GeographicRegions extends BaseController
{
    /**
     * Display a paginated, sortable list of geographic regions.
     */
    public function index(): string
    {
        $sort = strtolower((string) $this->request->getGet('sort'));
        $direction = strtolower((string) $this->request->getGet('direction'));

        $allowedSortColumns = ['id', 'higher_geography_identifier', 'higher_geography', 'location_type'];

        if (! in_array($sort, $allowedSortColumns, true)) {
            $sort = 'higher_geography';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        /** @var GeographicRegionModel $model */
        $model = model(GeographicRegionModel::class);
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
        ]);
    }

    /**
     * Show read-only details for a single geographic region.
     */
    public function show(int $id): string
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

        return $this->renderPage('geographic-regions/show', [
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
     * @param array<int, int> $regionIds
     * @return array<int, int>
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
     */
    private function getOccurrenceCountForRegionId(int $id): int
    {
        return (int) db_connect()
            ->table('geographic_regions_occurrences')
            ->where('geographic_region_id', $id)
            ->countAllResults();
    }
}