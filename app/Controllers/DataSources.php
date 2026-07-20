<?php

namespace App\Controllers;

use App\Models\DataSourceModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin views for data sources.
 */
class DataSources extends BaseController
{
    /**
     * Display a paginated, sortable list of data sources.
     *
     * @return string
     */
    public function index(): string
    {
        $sort = strtolower((string) $this->request->getGet('sort'));
        $direction = strtolower((string) $this->request->getGet('direction'));
        $q = trim((string) $this->request->getGet('q'));

        $allowedSortColumns = ['id', 'abbr', 'title', 'url'];

        if (! in_array($sort, $allowedSortColumns, true)) {
            $sort = 'title';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        /** @var DataSourceModel $model */
        $model = model(DataSourceModel::class);

        if ($q !== '') {
            $model->groupStart()
                ->like('abbr', $q)
                ->orLike('title', $q)
                ->orLike('url', $q)
                ->groupEnd();
        }

        $dataSources = $model->orderBy($sort, $direction)->paginate(20);

        return $this->renderPage('data-sources/index', [
            'pageTitle' => 'Data sources',
            'metaDescription' => 'Data sources list.',
            'bodyClass' => 'app-shell',
            'dataSources' => $dataSources,
            'pager' => $model->pager,
            'sort' => $sort,
            'direction' => $direction,
            'q' => $q,
        ]);
    }

    /**
     * Show a data source detail/edit page.
     *
     * @param int $id Data source identifier.
     * @return string
     */
    public function details(int $id): string
    {
        $dataSource = $this->findDataSource($id);

        return $this->renderPage('data-sources/details', [
            'pageTitle' => 'Data source details',
            'metaDescription' => 'Data source details.',
            'bodyClass' => 'app-shell auth-page',
            'dataSource' => $dataSource,
        ]);
    }

    /**
     * Find a data source or throw a 404.
     *
     * @param int $id Data source identifier.
     * @return array<string, mixed>
     */
    private function findDataSource(int $id): array
    {
        /** @var DataSourceModel $model */
        $model = model(DataSourceModel::class);
        $dataSource = $model->find($id);

        if (! is_array($dataSource)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $dataSource;
    }

    /**
     * Determine whether an abbreviation is already taken.
     *
     * @param string $abbr Abbreviation to check.
     * @param int|null $excludeId Optional record to exclude.
     * @return bool
     */
    private function isAbbrTaken(string $abbr, ?int $excludeId = null): bool
    {
        /** @var DataSourceModel $model */
        $model = model(DataSourceModel::class);
        $builder = $model->where('UPPER(abbr)', strtoupper($abbr));

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->first() !== null;
    }

    /**
     * Determine whether a title is already taken.
     *
     * @param string $title Title to check.
     * @param int|null $excludeId Optional record to exclude.
     * @return bool
     */
    private function isTitleTaken(string $title, ?int $excludeId = null): bool
    {
        /** @var DataSourceModel $model */
        $model = model(DataSourceModel::class);
        $builder = $model->where('LOWER(title)', strtolower($title));

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->first() !== null;
    }
}
