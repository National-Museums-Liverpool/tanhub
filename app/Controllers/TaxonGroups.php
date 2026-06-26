<?php

namespace App\Controllers;

use App\Models\TaxonGroupModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

class TaxonGroups extends BaseController
{
    public function index(): string
    {
        $sort = strtolower((string) $this->request->getGet('sort'));
        $direction = strtolower((string) $this->request->getGet('direction'));

        $allowedSortColumns = ['id', 'title', 'friendly', 'external_key'];

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

    public function edit(int $id): string
    {
        $model = model(TaxonGroupModel::class);
        $taxonGroup = $model->find($id);

        if ($taxonGroup === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->renderPage('taxon-groups/edit', [
            'pageTitle' => 'Edit taxon group',
            'metaDescription' => 'Edit taxon group friendly name.',
            'bodyClass' => 'app-shell',
            'taxonGroup' => $taxonGroup,
        ]);
    }

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

        return redirect()->to(site_url('taxon-groups/' . $id . '/edit'))->with('message', 'Taxon group updated.');
    }
}
