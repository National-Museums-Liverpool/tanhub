<?php

namespace App\Controllers;

use App\Models\TaxonGroupModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin management for taxon groups.
 */
class TaxonGroups extends BaseController
{
    /**
     * Display a paginated, sortable list of taxon groups.
     */
    public function index(): string
    {
        $sort = strtolower((string) $this->request->getGet('sort'));
        $direction = strtolower((string) $this->request->getGet('direction'));

        $allowedSortColumns = ['id', 'title', 'friendly', 'external_key', 'implied'];

        if (! in_array($sort, $allowedSortColumns, true)) {
            $sort = 'title';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        $model = model(TaxonGroupModel::class);
        $taxonGroups = $model->orderBy($sort, $direction)->paginate(20);

        return $this->renderPage('taxon-groups/index', [
            'pageTitle' => 'Taxon groups',
            'metaDescription' => 'Taxon groups list.',
            'bodyClass' => 'app-shell',
            'taxonGroups' => $taxonGroups,
            'pager' => $model->pager,
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    /**
     * Render the edit form for a single taxon group.
     */
    public function details(int $id): string
    {
        $model = model(TaxonGroupModel::class);
        $taxonGroup = $model->find($id);

        if ($taxonGroup === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->renderPage('taxon-groups/details', [
            'pageTitle' => 'Edit taxon group',
            'metaDescription' => 'Edit taxon group friendly name.',
            'bodyClass' => 'app-shell',
            'taxonGroup' => $taxonGroup,
        ]);
    }

    /**
     * Update the editable fields for a taxon group.
     */
    public function update(int $id): RedirectResponse
    {
        $rules = [
            'friendly' => 'permit_empty|max_length[200]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $model = model(TaxonGroupModel::class);
        $taxonGroup = $model->find($id);

        if ($taxonGroup === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $friendly = trim((string) $this->request->getPost('friendly'));

        $model->update($id, [
            'friendly' => $friendly === '' ? null : $friendly,
        ]);

        return redirect()->to(site_url('taxon-groups/' . $id))->with('message', 'Taxon group updated.');
    }
}
