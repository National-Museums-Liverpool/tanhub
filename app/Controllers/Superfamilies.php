<?php

namespace App\Controllers;

use App\Models\SuperfamilyModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Admin read-only views for superfamilies.
 */
class Superfamilies extends BaseController
{
    /**
     * Display a paginated, sortable list of superfamilies with related taxa counts.
     */
    public function index(): string
    {
        $sort = strtolower((string) $this->request->getGet('sort'));
        $direction = strtolower((string) $this->request->getGet('direction'));

        $allowedSortColumns = ['id', 'taxon_identifier', 'scientific_name', 'vernacular_name'];

        if (! in_array($sort, $allowedSortColumns, true)) {
            $sort = 'scientific_name';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        /** @var SuperfamilyModel $model */
        $model = model(SuperfamilyModel::class);
        $superfamilies = $model->orderBy($sort, $direction)->paginate(20);

        $countsBySuperfamilyId = $this->getTaxaCountsByForeignKey('superfamily_id', array_column($superfamilies, 'id'));

        foreach ($superfamilies as &$superfamily) {
            $superfamily['taxa_count'] = $countsBySuperfamilyId[(int) $superfamily['id']] ?? 0;
        }
        unset($superfamily);

        return $this->renderPage('superfamilies/index', [
            'pageTitle' => 'Superfamilies',
            'metaDescription' => 'Superfamilies list.',
            'bodyClass' => 'app-shell',
            'superfamilies' => $superfamilies,
            'pager' => $model->pager,
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    /**
     * Show read-only details for a single superfamily.
     */
    public function show(int $id): string
    {
        /** @var SuperfamilyModel $model */
        $model = model(SuperfamilyModel::class);
        $superfamily = $model->find($id);

        if ($superfamily === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $taxaCount = $this->getTaxaCountForForeignKey('superfamily_id', $id);

        return $this->renderPage('superfamilies/show', [
            'pageTitle' => 'Superfamily details',
            'metaDescription' => 'Read-only superfamily details.',
            'bodyClass' => 'app-shell',
            'superfamily' => $superfamily,
            'taxaCount' => $taxaCount,
        ]);
    }

    /**
     * Return taxa counts keyed by a foreign key for the provided IDs.
     *
     * @param array<int, int|string> $ids
     * @return array<int, int>
     */
    private function getTaxaCountsByForeignKey(string $foreignKey, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = db_connect()
            ->table('taxa')
            ->select($foreignKey . ' AS related_id, COUNT(*) AS taxa_count')
            ->whereIn($foreignKey, $ids)
            ->where('deleted_at', null)
            ->groupBy($foreignKey)
            ->get()
            ->getResultArray();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['related_id']] = (int) $row['taxa_count'];
        }

        return $counts;
    }

    /**
     * Return the number of taxa for a single foreign key value.
     */
    private function getTaxaCountForForeignKey(string $foreignKey, int $id): int
    {
        return (int) db_connect()
            ->table('taxa')
            ->where($foreignKey, $id)
            ->where('deleted_at', null)
            ->countAllResults();
    }
}
