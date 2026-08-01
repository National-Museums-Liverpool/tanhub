<?php

namespace App\Controllers;

use App\Models\RecordingSchemeModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Admin read-only views for the `recording_schemes` lookup table.
 *
 * Recording schemes are populated via import (see {@see \App\Services\Import})
 * and only viewed (not edited) here. Each listing/detail row is enriched
 * with a count of taxa referencing the scheme via `taxa.recording_scheme_id`.
 */
class RecordingSchemes extends BaseController
{
    /**
     * Display a paginated, sortable list of recording schemes with related taxa counts.
     *
     * @return string Rendered HTML for the recording schemes index view.
     */
    public function index(): string
    {
        $sort = strtolower((string) $this->request->getGet('sort'));
        $direction = strtolower((string) $this->request->getGet('direction'));
        $q = trim((string) $this->request->getGet('q'));

        $allowedSortColumns = ['id', 'external_key', 'title'];

        if (! in_array($sort, $allowedSortColumns, true)) {
            $sort = 'title';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        /** @var RecordingSchemeModel $model */
        $model = model(RecordingSchemeModel::class);

        if ($q !== '') {
            $model->groupStart()
                ->like('external_key', $q)
                ->orLike('title', $q)
                ->groupEnd();
        }

        $schemes = $model->orderBy($sort, $direction)->paginate(20);

        $countsBySchemeId = $this->getTaxaCountsByForeignKey('recording_scheme_id', array_column($schemes, 'id'));

        foreach ($schemes as &$scheme) {
            $scheme['taxa_count'] = $countsBySchemeId[(int) $scheme['id']] ?? 0;
        }
        unset($scheme);

        return $this->renderPage('recording-schemes/index', [
            'pageTitle' => 'Recording schemes',
            'metaDescription' => 'Recording schemes list.',
            'bodyClass' => 'app-shell',
            'recordingSchemes' => $schemes,
            'pager' => $model->pager,
            'sort' => $sort,
            'direction' => $direction,
            'q' => $q,
        ]);
    }

    /**
     * Show read-only details for a single recording scheme.
     *
     * @param int $id Recording scheme identifier.
     * @return string Rendered HTML for the recording scheme details view.
     * @throws PageNotFoundException If no recording scheme exists with the given ID.
     */
    public function details(int $id): string
    {
        /** @var RecordingSchemeModel $model */
        $model = model(RecordingSchemeModel::class);
        $scheme = $model->find($id);

        if ($scheme === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $taxaCount = $this->getTaxaCountForForeignKey('recording_scheme_id', $id);

        return $this->renderPage('recording-schemes/details', [
            'pageTitle' => 'Recording scheme details',
            'metaDescription' => 'Read-only recording scheme details.',
            'bodyClass' => 'app-shell',
            'recordingScheme' => $scheme,
            'taxaCount' => $taxaCount,
        ]);
    }

    /**
     * Return taxa counts keyed by a foreign key for the provided IDs.
     *
     * Only counts taxa that are not soft-deleted (`deleted_at IS NULL`).
     *
     * @param string             $foreignKey Column on `taxa` to group by (e.g. `recording_scheme_id`).
     * @param array<int, int|string> $ids   Related IDs to count taxa for.
     * @return array<int, int> Taxa count keyed by the related ID.
     */
    private function getTaxaCountsByForeignKey(string $foreignKey, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = db_connect()
            ->table('taxa')
            ->select($foreignKey . ' AS related_id, COUNT(*) AS taxa_count')
            ->whereIn($foreignKey, $ids)
            ->where('deleted_at', null)
            ->groupBy($foreignKey)
            ->get()
            ->getResultArray();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['related_id']] = (int) $row['taxa_count'];
        }

        return $counts;
    }

    /**
     * Return the number of taxa for a single foreign key value.
     *
     * @param string $foreignKey Column on `taxa` to filter by.
     * @param int    $id         Related ID to count taxa for.
     * @return int Number of matching, non-soft-deleted taxa.
     */
    private function getTaxaCountForForeignKey(string $foreignKey, int $id): int
    {
        return (int) db_connect()
            ->table('taxa')
            ->where($foreignKey, $id)
            ->where('deleted_at', null)
            ->countAllResults();
    }
}
