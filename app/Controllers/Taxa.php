<?php

namespace App\Controllers;

use App\Models\TaxonModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin views for taxa and moderation controls.
 */
class Taxa extends BaseController
{
    /**
     * Display a paginated, sortable list of taxa.
     */
    public function index(): string
    {
        $sort = strtolower((string) $this->request->getGet('sort'));
        $direction = strtolower((string) $this->request->getGet('direction'));

        $allowedSortColumns = [
            'id',
            'taxon_identifier',
            'scientific_name',
            'vernacular_name',
            'conservation_status',
            'blocked',
        ];

        if (! in_array($sort, $allowedSortColumns, true)) {
            $sort = 'scientific_name';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        /** @var TaxonModel $model */
        $model = model(TaxonModel::class);
        $taxa = $model->orderBy($sort, $direction)->paginate(20);

        return $this->renderPage('taxa/index', [
            'pageTitle' => 'Taxa',
            'metaDescription' => 'Taxa list.',
            'bodyClass' => 'app-shell',
            'taxa' => $taxa,
            'pager' => $model->pager,
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    /**
     * Show taxon details with associated taxon names.
     */
    public function details(int $id): string
    {
        /** @var TaxonModel $model */
        $model = model(TaxonModel::class);
        $taxon = $model->find($id);

        if ($taxon === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $taxonNames = db_connect()
            ->table('taxon_names')
            ->select('name, given_name_identifier, accepted, scientific')
            ->where('taxon_id', $id)
            ->where('deleted_at', null)
            ->orderBy('name', 'asc')
            ->get()
            ->getResultArray();

        $referenceLabels = $this->referenceLabels($taxon);

        return $this->renderPage('taxa/details', [
            'pageTitle' => 'Taxon details',
            'metaDescription' => 'Taxon details and moderation settings.',
            'bodyClass' => 'app-shell',
            'taxon' => $taxon,
            'taxonNames' => $taxonNames,
            'referenceLabels' => $referenceLabels,
            'canModerate' => auth()->user() !== null && auth()->user()->inGroup('admin'),
            'classificationColumns' => $this->classificationColumns($taxon),
        ]);
    }

    /**
     * Update admin moderation fields for a taxon.
     */
    public function update(int $id): RedirectResponse
    {
        $rules = [
            'blocked' => 'permit_empty|in_list[0,1]',
            'blocked_reason' => 'permit_empty|max_length[2000]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        /** @var TaxonModel $model */
        $model = model(TaxonModel::class);
        $taxon = $model->find($id);

        if ($taxon === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $blocked = (int) $this->request->getPost('blocked') === 1;
        $blockedReason = trim((string) $this->request->getPost('blocked_reason'));

        $model->update($id, [
            'blocked' => $blocked ? 1 : 0,
            'blocked_reason' => $blocked ? ($blockedReason === '' ? null : $blockedReason) : null,
        ]);

        return redirect()->to(site_url('taxa/' . $id))->with('message', 'Taxon moderation settings updated.');
    }

    /**
     * @param array<string, mixed> $taxon
     * @return array<int, string>
     */
    private function classificationColumns(array $taxon): array
    {
        $columns = [];

        foreach (array_keys($taxon) as $column) {
            if (! is_string($column)) {
                continue;
            }

            if (! str_ends_with($column, '_id')) {
                continue;
            }

            if (in_array($column, ['id', 'taxon_rank_id', 'taxon_group_id', 'recording_scheme_id'], true)) {
                continue;
            }

            $columns[] = $column;
        }

        sort($columns);

        return $columns;
    }

    /**
     * Resolve reference labels for core FK fields and dynamic classification FK fields.
     *
     * @param array<string, mixed> $taxon
     * @return array<string, string>
     */
    private function referenceLabels(array $taxon): array
    {
        $labels = [];

        $taxonRankId = (int) ($taxon['taxon_rank_id'] ?? 0);
        $taxonGroupId = (int) ($taxon['taxon_group_id'] ?? 0);
        $recordingSchemeId = (int) ($taxon['recording_scheme_id'] ?? 0);

        if ($taxonRankId > 0) {
            $row = db_connect()
                ->table('taxon_ranks')
                ->select('rank')
                ->where('id', $taxonRankId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();

            $labels['taxon_rank_id'] = $row['rank'] ?? '';
        }

        if ($taxonGroupId > 0) {
            $row = db_connect()
                ->table('taxon_groups')
                ->select('title')
                ->where('id', $taxonGroupId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();

            $labels['taxon_group_id'] = $row['title'] ?? '';
        }

        if ($recordingSchemeId > 0) {
            $row = db_connect()
                ->table('recording_schemes')
                ->select('title')
                ->where('id', $recordingSchemeId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();

            $labels['recording_scheme_id'] = $row['title'] ?? '';
        }

        $classificationIds = [];
        foreach ($this->classificationColumns($taxon) as $column) {
            $fk = (int) ($taxon[$column] ?? 0);
            if ($fk > 0) {
                $classificationIds[$column] = $fk;
            }
        }

        if ($classificationIds !== []) {
            $rows = db_connect()
                ->table('taxa')
                ->select('id, scientific_name')
                ->whereIn('id', array_values($classificationIds))
                ->where('deleted_at', null)
                ->get()
                ->getResultArray();

            $scientificNameById = [];
            foreach ($rows as $row) {
                $scientificNameById[(int) $row['id']] = (string) $row['scientific_name'];
            }

            foreach ($classificationIds as $column => $fk) {
                $labels[$column] = $scientificNameById[$fk] ?? '';
            }
        }

        return $labels;
    }
}
