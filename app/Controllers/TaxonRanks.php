<?php

namespace App\Controllers;

use App\Models\TaxonRankModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

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

        $allowedSortColumns = ['id', 'rank', 'abbr', 'sort_order'];

        if (! in_array($sort, $allowedSortColumns, true)) {
            $sort = 'sort_order';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        $model = model(TaxonRankModel::class);
        $taxonRanks = $model->orderBy($sort, $direction)->paginate(20);

        return $this->renderPage('taxon-ranks/index', [
            'pageTitle' => 'Taxon ranks',
            'metaDescription' => 'Taxon ranks list.',
            'bodyClass' => 'app-shell',
            'taxonRanks' => $taxonRanks,
            'pager' => $model->pager,
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    /**
     * Render the edit form for a single taxon rank.
     */
    public function details(int $id): string
    {
        $model = model(TaxonRankModel::class);
        $taxonRank = $model->find($id);

        if ($taxonRank === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->renderPage('taxon-ranks/details', [
            'pageTitle' => 'Edit taxon rank',
            'metaDescription' => 'Edit taxon rank friendly name.',
            'bodyClass' => 'app-shell',
            'taxonRank' => $taxonRank,
        ]);
    }

    /**
     * Update the editable fields for a taxon rank.
     */
    public function update(int $id): RedirectResponse
    {
        $rules = [
            'friendly' => 'permit_empty|max_length[200]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $model = model(TaxonRankModel::class);
        $taxonRank = $model->find($id);

        if ($taxonRank === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $friendly = trim((string) $this->request->getPost('friendly'));

        $model->update($id, [
            'friendly' => $friendly === '' ? null : $friendly,
        ]);

        return redirect()->to(site_url('taxon-ranks/' . $id))->with('message', 'Taxon rank updated.');
    }
}
