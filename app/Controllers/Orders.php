<?php

namespace App\Controllers;

use App\Models\OrderModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Admin read-only views for orders.
 */
class Orders extends BaseController
{
    /**
     * Display a paginated, sortable list of orders with related taxa counts.
     */
    public function index(): string
    {
        $sort = strtolower((string) $this->request->getGet('sort'));
        $direction = strtolower((string) $this->request->getGet('direction'));

        $allowedSortColumns = ['id', 'taxon_identifier', 'scientific_name', 'vernacular_name'];

        if (! in_array($sort, $allowedSortColumns, true)) {
            $sort = 'scientific_name';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        /** @var OrderModel $model */
        $model = model(OrderModel::class);
        $orders = $model->orderBy($sort, $direction)->paginate(20);

        $countsByOrderId = $this->getTaxaCountsByForeignKey('order_id', array_column($orders, 'id'));

        foreach ($orders as &$order) {
            $order['taxa_count'] = $countsByOrderId[(int) $order['id']] ?? 0;
        }
        unset($order);

        return $this->renderPage('orders/index', [
            'pageTitle' => 'Orders',
            'metaDescription' => 'Orders list.',
            'bodyClass' => 'app-shell',
            'orders' => $orders,
            'pager' => $model->pager,
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    /**
     * Show read-only details for a single order.
     */
    public function show(int $id): string
    {
        /** @var OrderModel $model */
        $model = model(OrderModel::class);
        $order = $model->find($id);

        if ($order === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $taxaCount = $this->getTaxaCountForForeignKey('order_id', $id);

        return $this->renderPage('orders/show', [
            'pageTitle' => 'Order details',
            'metaDescription' => 'Read-only order details.',
            'bodyClass' => 'app-shell',
            'order' => $order,
            'taxaCount' => $taxaCount,
        ]);
    }

    /**
     * Return taxa counts keyed by a foreign key for the provided IDs.
     *
     * @param array<int, int|string> $ids
     * @return array<int, int>
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
