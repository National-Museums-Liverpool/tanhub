<?php

namespace App\Controllers;

use App\Models\TaxonRankModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Admin management for taxon ranks.
 */
class TaxonRanks extends BaseController
{
    /**
     * Display a paginated, sortable list of taxon ranks.
     */
    public function index(): string
    {
        $sort = strtolower((string) $this->request->getGet('sort'));
        $direction = strtolower((string) $this->request->getGet('direction'));
        $q = trim((string) $this->request->getGet('q'));

        $allowedSortColumns = ['id', 'rank', 'abbr', 'sort_order'];

        if (! in_array($sort, $allowedSortColumns, true)) {
            $sort = 'sort_order';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        $model = model(TaxonRankModel::class);

        if ($q !== '') {
            $model->groupStart()
                ->like('rank', $q)
                ->orLike('abbr', $q)
                ->orLike('sort_order', $q)
                ->groupEnd();
        }

        $taxonRanks = $model->orderBy($sort, $direction)->paginate(20);

        return $this->renderPage('taxon-ranks/index', [
            'pageTitle' => 'Taxon ranks',
            'metaDescription' => 'Taxon ranks list.',
            'bodyClass' => 'app-shell',
            'taxonRanks' => $taxonRanks,
            'pager' => $model->pager,
            'sort' => $sort,
            'direction' => $direction,
            'q' => $q,
        ]);
    }

    /**
     * Render read-only details for a single taxon rank.
     */
    public function details(int $id): string
    {
        $model = model(TaxonRankModel::class);
        $taxonRank = $model->find($id);

        if ($taxonRank === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->renderPage('taxon-ranks/details', [
            'pageTitle' => 'Taxon rank details',
            'metaDescription' => 'Read-only taxon rank details.',
            'bodyClass' => 'app-shell',
            'taxonRank' => $taxonRank,
        ]);
    }
}
