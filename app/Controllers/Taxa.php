<?php

namespace App\Controllers;

use App\Models\TaxonModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Files\UploadedFile;
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
        $q = trim((string) $this->request->getGet('q'));

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

        if ($q !== '') {
            $model->groupStart()
                ->like('taxon_identifier', $q)
                ->orLike('scientific_name', $q)
                ->orLike('vernacular_name', $q)
                ->orLike('conservation_status', $q)
                ->groupEnd();
        }

        $taxa = $model->orderBy($sort, $direction)->paginate(20);

        return $this->renderPage('taxa/index', [
            'pageTitle' => 'Taxa',
            'metaDescription' => 'Taxa list.',
            'bodyClass' => 'app-shell',
            'taxa' => $taxa,
            'pager' => $model->pager,
            'sort' => $sort,
            'direction' => $direction,
            'q' => $q,
        ]);
    }

    /**
     * Show taxon details with associated taxon names.
        *
        * @param int $id
        * @return string
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
        $taxonMedia = service('taxonMediaReadService')->getByTaxonId($id);
        $user = auth()->user();
        $canEditDetails = $user !== null && $user->inGroup('admin', 'manager');
        $canModerate = $user !== null && $user->inGroup('admin');

        return $this->renderPage('taxa/details', [
            'pageTitle' => 'Taxon details',
            'metaDescription' => 'Taxon details and moderation settings.',
            'bodyClass' => 'app-shell',
            'taxon' => $taxon,
            'taxonNames' => $taxonNames,
            'taxonMedia' => $taxonMedia,
            'referenceLabels' => $referenceLabels,
            'canEditDetails' => $canEditDetails,
            'canModerate' => $canModerate,
            'classificationColumns' => $this->classificationColumns($taxon),
        ]);
    }

    /**
     * Upload media for the given taxon.
     *
     * @param int $id
     * @return RedirectResponse
     */
    public function uploadMedia(int $id): RedirectResponse
    {
        $user = auth()->user();
        $canUploadMedia = $user !== null && $user->inGroup('admin', 'manager');

        if (! $canUploadMedia) {
            return redirect()->back()->with('error', 'You are not authorised to upload media for this taxon.');
        }

        /** @var TaxonModel $model */
        $model = model(TaxonModel::class);
        $taxon = $model->find($id);

        if ($taxon === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $mediaFile = $this->request->getFile('media_file');

        if (! $mediaFile instanceof UploadedFile || $mediaFile->getError() === UPLOAD_ERR_NO_FILE) {
            return redirect()->back()->withInput()->with('mediaErrors', ['media_file' => 'Please choose an image file to upload.']);
        }

        $rules = [
            'alt_text' => 'permit_empty|max_length[500]',
            'caption' => 'permit_empty|max_length[65535]',
            'attribution' => 'permit_empty|max_length[255]',
            'license' => 'permit_empty|max_length[100]',
            'sort_order' => 'permit_empty|is_natural',
            'is_primary' => 'permit_empty|in_list[0,1]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('mediaErrors', $this->validator->getErrors());
        }

        try {
            $metadata = [
                'alt_text' => $this->request->getPost('alt_text'),
                'caption' => $this->request->getPost('caption'),
                'attribution' => $this->request->getPost('attribution'),
                'license' => $this->request->getPost('license'),
                'sort_order' => $this->request->getPost('sort_order'),
                'is_primary' => $this->request->getPost('is_primary'),
            ];

            service('taxonMediaUploadService')->uploadForTaxon($id, $mediaFile, $metadata);
        } catch (\InvalidArgumentException $exception) {
            return redirect()->back()->withInput()->with('mediaErrors', ['media_file' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            log_message('error', 'Taxon media upload failed: ' . $exception->getMessage());

            return redirect()->back()->withInput()->with('error', 'Media upload failed. Please try again.');
        }

        return redirect()->to(site_url('taxa/' . $id))->with('message', 'Taxon media uploaded.');
    }

    /**
     * Update admin moderation fields for a taxon.
     */
    public function update(int $id): RedirectResponse
    {
        $user = auth()->user();
        $canEditDetails = $user !== null && $user->inGroup('admin', 'manager');
        $canModerate = $user !== null && $user->inGroup('admin');

        if (! $canEditDetails && ! $canModerate) {
            return redirect()->back()->with('error', 'You are not authorised to update this taxon.');
        }

        $postData = $this->request->getPost();

        $rules = [];

        if ($canEditDetails && is_array($postData) && array_key_exists('rarity_group_name', $postData)) {
            $rules['rarity_group_name'] = 'permit_empty|max_length[100]';
        }

        if ($canEditDetails && is_array($postData) && array_key_exists('taxon_remarks', $postData)) {
            $rules['taxon_remarks'] = 'permit_empty|max_length[65535]';
        }

        if ($canModerate) {
            $rules['blocked'] = 'permit_empty|in_list[0,1]';
            $rules['blocked_reason'] = 'permit_empty|max_length[2000]';
        }

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        /** @var TaxonModel $model */
        $model = model(TaxonModel::class);
        $taxon = $model->find($id);

        if ($taxon === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $updateData = [];

        if ($canEditDetails && is_array($postData) && array_key_exists('rarity_group_name', $postData)) {
            $updateData['rarity_group_name'] = trim((string) $this->request->getPost('rarity_group_name'));
        }

        if ($canEditDetails && is_array($postData) && array_key_exists('taxon_remarks', $postData)) {
            $taxonRemarks = trim((string) $this->request->getPost('taxon_remarks'));
            $updateData['taxon_remarks'] = $taxonRemarks === '' ? null : $taxonRemarks;
        }

        if ($canModerate) {
            $blocked = (int) $this->request->getPost('blocked') === 1;
            $blockedReason = trim((string) $this->request->getPost('blocked_reason'));

            $updateData['blocked'] = $blocked ? 1 : 0;
            $updateData['blocked_reason'] = $blocked ? ($blockedReason === '' ? null : $blockedReason) : null;
        }

        if ($updateData !== []) {
            $model->update($id, $updateData);
        }

        return redirect()->to(site_url('taxa/' . $id))->with('message', 'Taxon details updated.');
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
