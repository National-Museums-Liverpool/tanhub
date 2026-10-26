<?php

namespace App\Controllers;

use App\Models\TaxonModel;
use App\Services\Search\TaxonSearchService;
use App\Services\Stats\TaxonRarityStatusService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin views for taxa and moderation controls.
 *
 * Combines read-only taxonomic data (imported via {@see \App\Services\Import})
 * with two editable concerns layered on top: manager-level detail fields
 * (rarity group, remarks and scoped curated decisions) and admin-level moderation (blocked/blocked
 * reason), plus taxon media upload/edit delegated to
 * {@see \App\Services\TaxonMediaUploadService} and
 * {@see \App\Services\TaxonMediaReadService}.
 */
class Taxa extends BaseController
{
    /**
     * Display a paginated, sortable taxon checklist with live occurrence filters.
     *
     * Reporting ancestors can qualify through descendant records. Invalid criteria
     * remain visible with HTTP 400 rather than silently returning an unfiltered list.
     *
     * @return string Rendered HTML for the taxa index view.
     */
    public function index(): string
    {
        $page = (new TaxonSearchService())->page($this->request->getGet(), true);
        if ($page['errors'] !== []) {
            $this->response->setStatusCode(400);
        }

        return $this->renderPage('taxa/index', array_merge($page, [
            'pageTitle' => 'Taxa',
            'metaDescription' => 'Taxa list.',
            'bodyClass' => 'app-shell',
        ]));
    }

    /**
     * Suggest taxa by accepted, vernacular or synonymous names for staff searches.
     *
     * This staff-only endpoint can include blocked taxa, unlike the public name API.
     * Successful responses use a `data` suggestion list; invalid inputs use an `errors`
     * map with HTTP 400. Each suggestion identifies an accepted taxon, not a synonym row.
     *
     * @return ResponseInterface JSON suggestions or explicit validation errors.
     */
    public function lookup(): ResponseInterface
    {
        $search = new TaxonSearchService();
        $criteria = $search->criteria($this->request->getGet(), true);
        $term = $criteria->value('taxon_name');
        if (mb_strlen($term) < 2) {
            $criteria->errors['taxon_name'] = 'Enter at least two characters.';
        }
        if ($criteria->errors !== []) {
            return $this->response->setStatusCode(400)->setJSON(['errors' => $criteria->errors]);
        }

        return $this->response->setJSON(['data' => $search->lookup($term, $criteria)]);
    }

    /**
     * Show taxon details with associated taxon names.
     *
     * Also resolves human-readable labels for classification foreign keys,
     * loads associated media, occupancy measurements and scoped curated decisions, and computes the
     * current user's edit/moderate permissions for the view.
     *
     * @param int $id Taxon identifier.
     * @return string Rendered HTML for the taxon details view.
     * @throws PageNotFoundException If no taxon exists with the given ID.
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
        $hierarchy = $this->hierarchyDetails($taxon);
        $taxonMedia = service('taxonMediaReadService')->getByTaxonId($id);
        $keySpeciesRegions = $this->activeGeographicRegions();
        $keySpeciesByScope = service('taxonKeySpeciesService')->getForTaxon($id);
        $user = auth()->user();
        $canEditDetails = $user !== null && $user->inGroup('admin', 'manager');
        $canModerate = $user !== null && $user->inGroup('admin', 'manager');

        return $this->renderPage('taxa/details', [
            'pageTitle' => 'Taxon details',
            'metaDescription' => 'Taxon details and moderation settings.',
            'bodyClass' => 'app-shell',
            'taxon' => $taxon,
            'taxonNames' => $taxonNames,
            'taxonMedia' => $taxonMedia,
            'keySpeciesRegions' => $keySpeciesRegions,
            'keySpeciesByScope' => $keySpeciesByScope,
            'rarityByScope' => (new TaxonRarityStatusService())->getForTaxon($id),
            'isSpecies' => (int) ($taxon['species_id'] ?? 0) === $id,
            'referenceLabels' => $referenceLabels,
            'hierarchy' => $hierarchy,
            'canEditDetails' => $canEditDetails,
            'canModerate' => $canModerate,
            'classificationColumns' => $this->classificationColumns($taxon),
        ]);
    }

    /**
     * Upload media for the given taxon.
     *
     * Requires `admin` or `manager` group membership. Validates the shared
     * media metadata rules ({@see self::mediaMetadataRules()}) before
     * delegating storage and variant generation to the
     * `taxonMediaUploadService`. Upload/storage errors are logged and
     * reported to the user as a generic failure message rather than exposing
     * internal details.
     *
     * @param int $id Taxon identifier to attach the media to.
     * @return RedirectResponse Redirect back to the taxon details page.
     * @throws PageNotFoundException If no taxon exists with the given ID.
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

        $rules = $this->mediaMetadataRules();

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
     * Update metadata for an existing media file on a taxon.
     *
     * Requires `admin` or `manager` group membership and a `media_uuid`
     * identifying which media record to update. Validation/lookup failures
     * redirect back with the selected UUID preserved so the correct edit
     * form re-opens with errors.
     *
     * @param int $id Taxon identifier that owns the media.
     * @return RedirectResponse Redirect back to the taxon details page.
     * @throws PageNotFoundException If no taxon exists with the given ID.
     */
    public function updateMedia(int $id): RedirectResponse
    {
        $user = auth()->user();
        $canEditMedia = $user !== null && $user->inGroup('admin', 'manager');

        if (! $canEditMedia) {
            return redirect()->back()->with('error', 'You are not authorised to edit media for this taxon.');
        }

        /** @var TaxonModel $model */
        $model = model(TaxonModel::class);
        $taxon = $model->find($id);

        if ($taxon === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $mediaUuid = trim((string) $this->request->getPost('media_uuid'));

        if ($mediaUuid === '') {
            return redirect()->back()
                ->withInput()
                ->with('mediaEditErrors', ['media_uuid' => 'Please select a media file to edit.']);
        }

        $rules = [
            'edit_alt_text' => 'permit_empty|max_length[500]',
            'edit_caption' => 'permit_empty|max_length[65535]',
            'edit_attribution' => 'permit_empty|max_length[255]',
            'edit_license' => 'permit_empty|max_length[100]',
            'edit_sort_order' => 'permit_empty|is_natural',
            'edit_is_primary' => 'permit_empty|in_list[0,1]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()
                ->withInput()
                ->with('mediaEditSelectedUuid', $mediaUuid)
                ->with('mediaEditErrors', $this->validator->getErrors());
        }

        try {
            $metadata = [
                'alt_text' => $this->request->getPost('edit_alt_text'),
                'caption' => $this->request->getPost('edit_caption'),
                'attribution' => $this->request->getPost('edit_attribution'),
                'license' => $this->request->getPost('edit_license'),
                'sort_order' => $this->request->getPost('edit_sort_order'),
                'is_primary' => $this->request->getPost('edit_is_primary'),
            ];

            service('taxonMediaUploadService')->updateMetadataForTaxonMedia($id, $mediaUuid, $metadata);
        } catch (\InvalidArgumentException $exception) {
            return redirect()->back()
                ->withInput()
                ->with('mediaEditSelectedUuid', $mediaUuid)
                ->with('mediaEditErrors', ['media_uuid' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            log_message('error', 'Taxon media metadata update failed: ' . $exception->getMessage());

            return redirect()->back()
                ->withInput()
                ->with('mediaEditSelectedUuid', $mediaUuid)
                ->with('error', 'Media metadata update failed. Please try again.');
        }

        return redirect()->to(site_url('taxa/' . $id))->with('message', 'Taxon media metadata updated.');
    }

    /**
     * Update admin moderation fields for a taxon.
     *
     * Which fields are accepted depends on the current user's role: managers
     * (and admins) may update `rarity_group_name`, `taxon_remarks`, and
     * key-species and species rarity decisions; admins may additionally update `blocked` and
     * `blocked_reason`. Only fields present in the submitted data and
     * permitted for the user's role are validated and written.
     *
     * @param int $id Taxon identifier.
     * @return RedirectResponse Redirect back to the taxon details page, or with validation errors.
     * @throws PageNotFoundException If no taxon exists with the given ID.
     */
    public function update(int $id): RedirectResponse
    {
        $user = auth()->user();
        $canEditDetails = $user !== null && $user->inGroup('admin', 'manager');
        $canModerate = $user !== null && $user->inGroup('admin', 'manager');

        if (! $canEditDetails && ! $canModerate) {
            return redirect()->back()->with('error', 'You are not authorised to update this taxon.');
        }

        $postData = $this->request->getPost();

        $rules = [];
        $keySpeciesScopes = null;
        $keySpeciesError = null;
        $rarityError = null;
        $hasRarityInput = $canEditDetails && is_array($postData)
            && (array_key_exists('rarity_status', $postData) || array_key_exists('rarity_status_comment', $postData));
        if ($hasRarityInput) {
            try {
                (new TaxonRarityStatusService())->validateInput($id, $postData);
            } catch (\InvalidArgumentException $exception) {
                $rarityError = $exception->getMessage();
            }
        }

        if ($canEditDetails && is_array($postData) && array_key_exists('key_species', $postData)) {
            try {
                $keySpeciesScopes = $this->parseKeySpeciesScopes($postData, $this->activeGeographicRegions());
            } catch (\InvalidArgumentException $exception) {
                $keySpeciesError = $exception->getMessage();
            }
        }

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

        $valid = $rules === [] || $this->validate($rules);
        if (! $valid || $keySpeciesError !== null || $rarityError !== null) {
            $errors = $this->validator?->getErrors() ?? [];
            if ($keySpeciesError !== null) {
                $errors['key_species'] = $keySpeciesError;
            }
            if ($rarityError !== null) {
                $errors['rarity_status'] = $rarityError;
            }

            return redirect()->back()->withInput()->with('errors', $errors);
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

        if ($hasRarityInput) {
            try {
                (new TaxonRarityStatusService())->saveForTaxon($id, $postData);
            } catch (\InvalidArgumentException | \RuntimeException $exception) {
                return redirect()->back()->withInput()->with('error', $exception->getMessage());
            }
        }

        if ($keySpeciesScopes !== null) {
            try {
                service('taxonKeySpeciesService')->saveForTaxon($id, $keySpeciesScopes);
            } catch (\RuntimeException $exception) {
                return redirect()->back()->withInput()->with('error', $exception->getMessage());
            }
        }

        if ($updateData !== []) {
            service('statsDirtyScopeService')->enqueueTaxonOccurrenceScopes($id);
            $model->update($id, $updateData);
            service('statsDirtyScopeService')->enqueueTaxonOccurrenceScopes($id);
        }

        return redirect()->to(site_url('taxa/' . $id))->with('message', 'Taxon details updated.');
    }

    /**
     * Get the active geographic regions used for taxon-scoped editing.
     *
     * @return array<int, array{id: int, higher_geography_identifier: string, higher_geography: string}>
     */
    private function activeGeographicRegions(): array
    {
        return db_connect()
            ->table('geographic_regions')
            ->select('id, higher_geography_identifier, higher_geography')
            ->where('deleted_at', null)
            ->orderBy('higher_geography', 'ASC')
            ->orderBy('higher_geography_identifier', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Validate and normalize submitted key-species decisions.
     *
     * @param array<string, mixed> $postData Submitted form data.
     * @param array<int, array{id: int, higher_geography_identifier: string, higher_geography: string}> $regions
     *        Active geographic regions.
     * @return array<string|int, array{key_species: bool|null, comment: string|null}> Normalized scope values.
     * @throws \InvalidArgumentException If a scope or submitted value is invalid.
     */
    private function parseKeySpeciesScopes(array $postData, array $regions): array
    {
        $keySpecies = $postData['key_species'] ?? null;
        $comments = $postData['key_species_comment'] ?? [];
        if (! is_array($keySpecies) || ! is_array($comments)) {
            throw new \InvalidArgumentException('Enter a valid key-species decision for each scope.');
        }

        $allowedScopes = ['global' => true];
        foreach ($regions as $region) {
            $allowedScopes[(string) $region['id']] = true;
        }

        foreach (array_keys($comments) as $scopeKey) {
            if (! array_key_exists($scopeKey, $allowedScopes) || ! array_key_exists($scopeKey, $keySpecies)) {
                throw new \InvalidArgumentException('A comment was submitted for an invalid key-species scope.');
            }
        }

        $scopes = [];
        foreach ($keySpecies as $scopeKey => $value) {
            if (! array_key_exists($scopeKey, $allowedScopes)) {
                throw new \InvalidArgumentException('An invalid key-species scope was submitted.');
            }
            if (! is_string($value) || ! in_array($value, ['', '0', '1'], true)) {
                throw new \InvalidArgumentException('Choose Yes, No, or Not set for each key-species decision.');
            }

            $comment = $comments[$scopeKey] ?? '';
            if (! is_string($comment) || mb_strlen($comment) > 65535) {
                throw new \InvalidArgumentException('Key-species comments must be text no longer than 65535 characters.');
            }

            $scopes[$scopeKey] = [
                'key_species' => $value === '' ? null : $value === '1',
                'key_species_comment' => trim($comment) === '' ? null : trim($comment),
            ];
        }

        return $scopes;
    }

    /**
     * Determine the dynamic classification foreign-key columns for a taxon.
     *
     * The `taxa` table has a variable set of `*_id` columns representing
     * higher classification levels (kingdom/phylum/class/etc.); this returns
     * those column names while excluding the well-known FK columns that are
     * handled separately ({@see self::referenceLabels()}).
     *
     * @param array<string, mixed> $taxon Taxon row, keyed by column name.
     * @return array<int, string> Column names representing classification foreign keys.
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
     * Looks up display labels for `taxon_rank_id`, `taxon_group_id`,
     * `recording_scheme_id`, `rarity_category` (via the `Rarity` config's
     * label map), and any dynamic classification columns identified by
     * {@see self::classificationColumns()} (each resolved against `taxa`
     * itself, since classification parents are other taxa rows).
     *
     * @param array<string, mixed> $taxon Taxon row, keyed by column name.
     * @return array<string, string> Human-readable label keyed by the source column name.
     */
    private function referenceLabels(array $taxon): array
    {
        $labels = [];

        $taxonRankId = (int) ($taxon['taxon_rank_id'] ?? 0);
        $taxonGroupId = (int) ($taxon['taxon_group_id'] ?? 0);
        $recordingSchemeId = (int) ($taxon['recording_scheme_id'] ?? 0);
        $rarityCategory = (int) ($taxon['rarity_category']);

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

        if ($rarityCategory !== null) {
            $labels['rarity_category'] = Config('Rarity')->labels[$rarityCategory] ?? '';
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

    /**
     * Resolve the immediate accepted parent and current rank reporting status.
     *
     * @param array<string, mixed> $taxon Taxon row, keyed by column name.
     * @return array<string, mixed> Hierarchy values for the details view.
     */
    private function hierarchyDetails(array $taxon): array
    {
        $db = db_connect();
        $rank = $db->table('taxon_ranks')
            ->select('rank, abbr, is_reporting')
            ->where('id', (int) ($taxon['taxon_rank_id'] ?? 0))
            ->where('deleted_at', null)
            ->get()
            ->getRowArray() ?? [];

        $parent = null;
        $parentId = (int) ($taxon['parent_taxon_id'] ?? 0);

        if ($parentId > 0) {
            $parent = $db->table('taxa t')
                ->select('t.taxon_identifier, t.scientific_name, t.vernacular_name, tr.rank, tr.abbr')
                ->join('taxon_ranks tr', 'tr.id = t.taxon_rank_id', 'left')
                ->where('t.id', $parentId)
                ->where('t.deleted_at', null)
                ->where('t.blocked', 0)
                ->get()
                ->getRowArray();
        }

        return [
            'rank' => $rank,
            'parent' => $parent,
        ];
    }

    /**
     * Validation rules for shared media metadata fields.
     *
     * @return array<string, string>
     */
    private function mediaMetadataRules(): array
    {
        return [
            'alt_text' => 'permit_empty|max_length[500]',
            'caption' => 'permit_empty|max_length[65535]',
            'attribution' => 'permit_empty|max_length[255]',
            'license' => 'permit_empty|max_length[100]',
            'sort_order' => 'permit_empty|is_natural',
            'is_primary' => 'permit_empty|in_list[0,1]',
        ];
    }
}
