<?php

namespace App\Controllers;

use App\Models\OccurrenceModel;
use App\Services\Search\TaxonSearchService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin views for occurrences and moderation controls.
 *
 * Occurrences are imported from external sources (Indicia, NBN Atlas; see
 * {@see \App\Services\Import}) and are read-only apart from the moderation
 * fields (`blocked`/`blocked_reason`) exposed by {@see self::update()}.
 */
class Occurrences extends BaseController
{
    /**
     * Display a paginated, sortable list of occurrences.
     *
     * The shared search service keeps original record rows and includes blocked data
     * by default. Invalid filters render validation messages with HTTP 400, not an
     * apparently successful unfiltered list.
     *
     * @return string Rendered occurrence index with current search state.
     */
    public function index(): string
    {
        $page = (new TaxonSearchService())->page($this->request->getGet(), false);
        if ($page['errors'] !== []) {
            $this->response->setStatusCode(400);
        }

        return $this->renderPage('occurrences/index', array_merge($page, [
            'pageTitle' => 'Occurrences',
            'metaDescription' => 'Occurrences list.',
            'bodyClass' => 'app-shell',
        ]));
    }

    /**
     * Show one occurrence and moderation fields.
     *
     * @param int $id Occurrence identifier.
     * @return string
     * @throws PageNotFoundException If no occurrence exists with the given ID.
     */
    public function details(int $id): string
    {
        $occurrence = $this->findOccurrence($id);

        return $this->renderPage('occurrences/details', [
            'pageTitle' => 'Occurrence details',
            'metaDescription' => 'Occurrence details and moderation settings.',
            'bodyClass' => 'app-shell auth-page',
            'occurrence' => $occurrence,
            'referenceLabels' => $this->referenceLabels($occurrence),
        ]);
    }

    /**
     * Update occurrence moderation fields.
     *
     * When `blocked` is set to `1`, `blocked_reason` is required (enforced
     * manually rather than via a validation rule so the message can be
     * attached to the reason field); when unblocked, any stored reason is
     * cleared to `null`.
     *
     * @param int $id Occurrence identifier.
     * @return RedirectResponse
     * @throws PageNotFoundException If no occurrence exists with the given ID.
     */
    public function update(int $id): RedirectResponse
    {
        $occurrence = $this->findOccurrence($id);

        $rules = [
            'blocked' => 'required|in_list[0,1]',
            'blocked_reason' => 'permit_empty|max_length[2000]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $blocked = (int) $this->request->getPost('blocked') === 1;
        $blockedReason = trim((string) $this->request->getPost('blocked_reason'));

        if ($blocked && $blockedReason === '') {
            return redirect()->back()->withInput()->with('errors', ['blocked_reason' => 'Blocked reason is required when blocked is Yes.']);
        }

        /** @var OccurrenceModel $model */
        $model = model(OccurrenceModel::class);
        service('statsDirtyScopeService')->enqueueCurrentOccurrenceScopes([$id]);
        $model->update($id, [
            'blocked' => $blocked ? 1 : 0,
            'blocked_reason' => $blocked ? $blockedReason : null,
        ]);
        service('statsDirtyScopeService')->enqueueCurrentOccurrenceScopes([$id]);

        return redirect()->to(site_url('occurrences/' . $id))->with('message', 'Occurrence moderation settings updated.');
    }

    /**
     * Find an occurrence or throw a 404.
     *
     * @param int $id Occurrence identifier.
     * @return array<string, mixed>
     * @throws PageNotFoundException If no occurrence exists with the given ID.
     */
    private function findOccurrence(int $id): array
    {
        /** @var OccurrenceModel $model */
        $model = model(OccurrenceModel::class);
        $occurrence = $model->find($id);

        if (! is_array($occurrence)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $occurrence;
    }

    /**
     * Resolve human-readable labels for occurrence foreign keys.
     *
     * @param array<string, mixed> $occurrence Occurrence row.
     * @return array<string, string>
     */
    private function referenceLabels(array $occurrence): array
    {
        $labels = [];
        $db = db_connect();

        $taxonId = (int) ($occurrence['taxon_id'] ?? 0);
        if ($taxonId > 0) {
            $row = $db->table('taxa')->select('scientific_name')->where('id', $taxonId)->where('deleted_at', null)->get()->getRowArray();
            $labels['taxon_id'] = (string) ($row['scientific_name'] ?? '');
        }

        $taxonNameId = (int) ($occurrence['taxon_name_id'] ?? 0);
        if ($taxonNameId > 0) {
            $row = $db->table('taxon_names')->select('name')->where('id', $taxonNameId)->where('deleted_at', null)->get()->getRowArray();
            $labels['taxon_name_id'] = (string) ($row['name'] ?? '');
        }

        $dataSourceId = (int) ($occurrence['data_source_id'] ?? 0);
        if ($dataSourceId > 0) {
            $row = $db->table('data_sources')->select('abbr, title')->where('id', $dataSourceId)->get()->getRowArray();
            $labels['data_source_id'] = trim((string) (($row['abbr'] ?? '') . ' ' . ($row['title'] ?? '')));
        }

        return $labels;
    }

    /**
     * Return data source options for list filters.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dataSourceOptions(): array
    {
        return db_connect()
            ->table('data_sources')
            ->select('id, abbr, title')
            ->orderBy('title', 'asc')
            ->get()
            ->getResultArray();
    }

    /**
     * Convert a value to an integer when possible.
     *
     * @param mixed $value Input value.
     * @return int|null
     */
    private function nullableInt($value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '' || ! ctype_digit($string)) {
            return null;
        }

        return (int) $string;
    }
}
