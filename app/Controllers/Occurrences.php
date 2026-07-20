<?php

namespace App\Controllers;

use App\Models\OccurrenceModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin views for occurrences and moderation controls.
 */
class Occurrences extends BaseController
{
    /**
     * Display a paginated, sortable list of occurrences.
     *
     * @return string
     */
    public function index(): string
    {
        $sort = strtolower((string) $this->request->getGet('sort'));
        $direction = strtolower((string) $this->request->getGet('direction'));
        $taxonId = $this->nullableInt($this->request->getGet('taxon_id'));
        $dataSourceId = $this->nullableInt($this->request->getGet('data_source_id'));
        $blocked = trim((string) $this->request->getGet('blocked'));
        $fromDate = trim((string) $this->request->getGet('from_date'));
        $toDate = trim((string) $this->request->getGet('to_date'));

        $allowedSortColumns = ['id', 'unique_key', 'taxon_id', 'taxon_name_id', 'from_date', 'to_date', 'grid_ref', 'data_source_id', 'blocked'];

        if (! in_array($sort, $allowedSortColumns, true)) {
            $sort = 'from_date';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        /** @var OccurrenceModel $model */
        $model = model(OccurrenceModel::class);

        if ($taxonId !== null) {
            $model->where('taxon_id', $taxonId);
        }

        if ($dataSourceId !== null) {
            $model->where('data_source_id', $dataSourceId);
        }

        if (in_array($blocked, ['0', '1'], true)) {
            $model->where('blocked', (int) $blocked);
        }

        if ($fromDate !== '') {
            $model->where('from_date >=', $fromDate);
        }

        if ($toDate !== '') {
            $model->where('to_date <=', $toDate);
        }

        $occurrences = $model->orderBy($sort, $direction)->paginate(20);

        return $this->renderPage('occurrences/index', [
            'pageTitle' => 'Occurrences',
            'metaDescription' => 'Occurrences list.',
            'bodyClass' => 'app-shell',
            'occurrences' => $occurrences,
            'pager' => $model->pager,
            'sort' => $sort,
            'direction' => $direction,
            'filters' => [
                'taxon_id' => $taxonId,
                'data_source_id' => $dataSourceId,
                'blocked' => $blocked,
                'from_date' => $fromDate,
                'to_date' => $toDate,
            ],
            'dataSources' => $this->dataSourceOptions(),
        ]);
    }

    /**
     * Show one occurrence and moderation fields.
     *
     * @param int $id Occurrence identifier.
     * @return string
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
     * @param int $id Occurrence identifier.
     * @return RedirectResponse
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
        $model->update($id, [
            'blocked' => $blocked ? 1 : 0,
            'blocked_reason' => $blocked ? $blockedReason : null,
        ]);

        return redirect()->to(site_url('occurrences/' . $id))->with('message', 'Occurrence moderation settings updated.');
    }

    /**
     * Find an occurrence or throw a 404.
     *
     * @param int $id Occurrence identifier.
     * @return array<string, mixed>
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
