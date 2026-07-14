<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section">
    <div class="auth-card p-4 p-lg-5">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
            <div>
                <span class="eyebrow">Taxonomy</span>
                <h1 class="section-heading mb-0">Taxon ranks</h1>
            </div>
        </div>

        <?php
        $sort = $page['sort'];
        $direction = $page['direction'];

        $sortUrl = static function (string $column) use ($sort, $direction): string {
            $nextDirection = 'asc';

            if ($sort === $column && $direction === 'asc') {
                $nextDirection = 'desc';
            }

            return site_url('taxon-ranks') . '?' . http_build_query([
                'sort' => $column,
                'direction' => $nextDirection,
            ]);
        };

        $sortIndicator = static function (string $column) use ($sort, $direction): string {
            if ($sort !== $column) {
                return '';
            }

            return $direction === 'asc' ? ' ▲' : ' ▼';
        };
        ?>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                <tr>
                    <th scope="col"><a href="<?= esc($sortUrl('id')) ?>">ID<?= esc($sortIndicator('id')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('rank')) ?>">Rank<?= esc($sortIndicator('rank')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('abbr')) ?>">Abbreviation<?= esc($sortIndicator('abbr')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('sort_order')) ?>">Sort order<?= esc($sortIndicator('sort_order')) ?></a></th>
                    <th scope="col">Links</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($page['taxonRanks'] === []): ?>
                    <tr>
                        <td colspan="5" class="text-muted">No taxon ranks found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($page['taxonRanks'] as $taxonRank): ?>
                        <tr>
                            <td><?= esc((string) $taxonRank['id']) ?></td>
                            <td><?= esc($taxonRank['rank']) ?></td>
                            <td><?= esc($taxonRank['abbr']) ?></td>
                            <td><?= esc((string) $taxonRank['sort_order']) ?></td>
                            <td><a class="btn btn-sm btn-outline-brand" href="<?= esc(site_url('taxon-ranks/' . $taxonRank['id'])) ?>">Details</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-3">
            <?= $page['pager']->only(['sort', 'direction'])->links() ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
