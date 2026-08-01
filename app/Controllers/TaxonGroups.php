<?php

namespace App\Controllers;

use App\Models\TaxonGroupModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin management for taxon groups.
 *
 * Taxon groups are imported from Indicia (see {@see \App\Services\Import})
 * and mostly read-only here; the only editable field exposed by this
 * controller is the local `friendly` display name override, via
 * {@see self::update()}.
 */
class TaxonGroups extends BaseController
{
    /**
     * Display a paginated, sortable list of taxon groups.
     *
     * @return string Rendered HTML for the taxon groups index view.
     */
    public function index(): string
    {
        $sort = strtolower((string) $this->request->getGet('sort'));
        $direction = strtolower((string) $this->request->getGet('direction'));
        $q = trim((string) $this->request->getGet('q'));

        $allowedSortColumns = ['id', 'title', 'friendly', 'external_key', 'implied'];

        if (! in_array($sort, $allowedSortColumns, true)) {
            $sort = 'title';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        $model = model(TaxonGroupModel::class);

        if ($q !== '') {
            $model->groupStart()
                ->like('title', $q)
                ->orLike('friendly', $q)
                ->orLike('external_key', $q)
                ->orLike('indicia_taxon_group_id', $q)
                ->groupEnd();
        }

        $taxonGroups = $model->orderBy($sort, $direction)->paginate(20);

        return $this->renderPage('taxon-groups/index', [
            'pageTitle' => 'Taxon groups',
            'metaDescription' => 'Taxon groups list.',
            'bodyClass' => 'app-shell',
            'taxonGroups' => $taxonGroups,
            'pager' => $model->pager,
            'sort' => $sort,
            'direction' => $direction,
            'q' => $q,
        ]);
    }

    /**
     * Render the edit form for a single taxon group.
     *
     * @param int $id Taxon group identifier.
     * @return string Rendered HTML for the taxon group edit view.
     * @throws PageNotFoundException If no taxon group exists with the given ID.
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
     *
     * Currently only the `friendly` display name is editable; an empty
     * submitted value clears the override back to `null` (falling back to
     * the imported `title`/`external_key` elsewhere in the UI).
     *
     * @param int $id Taxon group identifier.
     * @return RedirectResponse Redirect back to the taxon group details page.
     * @throws PageNotFoundException If no taxon group exists with the given ID.
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
