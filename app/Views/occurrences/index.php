<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section">
    <div class="auth-card p-4 p-lg-5">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
            <div>
                <span class="eyebrow">Occurrences</span>
                <h1 class="section-heading mb-0">Occurrences</h1>
            </div>
        </div>

        <?php
        $sort = $page['sort'];
        $direction = $page['direction'];
        $filters = $page['filters'];

        $sortUrl = static function (string $column) use ($sort, $direction, $filters): string {
            $nextDirection = 'asc';

            if ($sort === $column && $direction === 'asc') {
                $nextDirection = 'desc';
            }

            return site_url('occurrences') . '?' . http_build_query([
                'sort' => $column,
                'direction' => $nextDirection,
                'taxon_id' => $filters['taxon_id'],
                'data_source_id' => $filters['data_source_id'],
                'blocked' => $filters['blocked'],
                'from_date' => $filters['from_date'],
                'to_date' => $filters['to_date'],
            ]);
        };

        $sortIndicator = static function (string $column) use ($sort, $direction): string {
            if ($sort !== $column) {
                return '';
            }

            return $direction === 'asc' ? ' ▲' : ' ▼';
        };
        ?>

        <form class="row g-2 mb-4" method="get" action="<?= esc(site_url('occurrences')) ?>">
            <div class="col-12 col-md-3">
                <label class="form-label" for="taxon_id">Taxon ID</label>
                <input class="form-control" id="taxon_id" name="taxon_id" type="number" min="1" value="<?= esc((string) ($filters['taxon_id'] ?? '')) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="data_source_id">Data source</label>
                <select class="form-select" id="data_source_id" name="data_source_id">
                    <option value="">All</option>
                    <?php foreach ($page['dataSources'] as $dataSource): ?>
                        <?php $selected = (string) ($filters['data_source_id'] ?? '') === (string) $dataSource['id']; ?>
                        <option value="<?= esc((string) $dataSource['id']) ?>" <?= $selected ? 'selected' : '' ?>><?= esc((string) $dataSource['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label" for="blocked">Blocked</label>
                <select class="form-select" id="blocked" name="blocked">
                    <option value="">All</option>
                    <option value="0" <?= (string) ($filters['blocked'] ?? '') === '0' ? 'selected' : '' ?>>No</option>
                    <option value="1" <?= (string) ($filters['blocked'] ?? '') === '1' ? 'selected' : '' ?>>Yes</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label" for="from_date">From date</label>
                <input class="form-control" id="from_date" name="from_date" type="date" value="<?= esc((string) ($filters['from_date'] ?? '')) ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label" for="to_date">To date</label>
                <input class="form-control" id="to_date" name="to_date" type="date" value="<?= esc((string) ($filters['to_date'] ?? '')) ?>">
            </div>
            <div class="col-12 d-flex gap-2 mt-2">
                <button class="btn btn-brand" type="submit">Filter</button>
                <a class="btn btn-outline-secondary" href="<?= esc(site_url('occurrences')) ?>">Clear</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                <tr>
                    <th scope="col"><a href="<?= esc($sortUrl('id')) ?>">ID<?= esc($sortIndicator('id')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('unique_key')) ?>">Unique key<?= esc($sortIndicator('unique_key')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('taxon_id')) ?>">Taxon ID<?= esc($sortIndicator('taxon_id')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('taxon_name_id')) ?>">Taxon name ID<?= esc($sortIndicator('taxon_name_id')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('from_date')) ?>">From<?= esc($sortIndicator('from_date')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('to_date')) ?>">To<?= esc($sortIndicator('to_date')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('grid_ref')) ?>">Grid ref<?= esc($sortIndicator('grid_ref')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('data_source_id')) ?>">Data source<?= esc($sortIndicator('data_source_id')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('blocked')) ?>">Blocked<?= esc($sortIndicator('blocked')) ?></a></th>
                    <th scope="col">Links</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($page['occurrences'] === []): ?>
                    <tr>
                        <td colspan="10" class="text-muted">No occurrences found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($page['occurrences'] as $occurrence): ?>
                        <tr>
                            <td><?= esc((string) $occurrence['id']) ?></td>
                            <td><?= esc((string) $occurrence['unique_key']) ?></td>
                            <td><?= esc((string) $occurrence['taxon_id']) ?></td>
                            <td><?= esc((string) $occurrence['taxon_name_id']) ?></td>
                            <td><?= esc((string) ($occurrence['from_date'] ?? '')) ?></td>
                            <td><?= esc((string) ($occurrence['to_date'] ?? '')) ?></td>
                            <td><?= esc((string) ($occurrence['grid_ref'] ?? '')) ?></td>
                            <td><?= esc((string) $occurrence['data_source_id']) ?></td>
                            <td><?= ! empty($occurrence['blocked']) ? 'Yes' : 'No' ?></td>
                            <td><a class="btn btn-sm btn-outline-brand" href="<?= esc(site_url('occurrences/' . $occurrence['id'])) ?>">Details</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-3">
            <?= $page['pager']->only(['sort', 'direction', 'taxon_id', 'data_source_id', 'blocked', 'from_date', 'to_date'])->links() ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
