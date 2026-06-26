<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section">
    <div class="auth-card p-4 p-lg-5">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
            <div>
                <span class="eyebrow">Taxonomy</span>
                <h1 class="section-heading mb-0">Families</h1>
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

            return site_url('families') . '?' . http_build_query([
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
                    <th scope="col"><a href="<?= esc($sortUrl('taxon_identifier')) ?>">Taxon identifier<?= esc($sortIndicator('taxon_identifier')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('scientific_name')) ?>">Scientific name<?= esc($sortIndicator('scientific_name')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('vernacular_name')) ?>">Vernacular name<?= esc($sortIndicator('vernacular_name')) ?></a></th>
                    <th scope="col">Taxa count</th>
                    <th scope="col">View</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($page['families'] === []): ?>
                    <tr>
                        <td colspan="6" class="text-muted">No families found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($page['families'] as $family): ?>
                        <tr>
                            <td><?= esc((string) $family['id']) ?></td>
                            <td><?= esc($family['taxon_identifier']) ?></td>
                            <td><?= esc($family['scientific_name']) ?></td>
                            <td><?= esc($family['vernacular_name']) ?></td>
                            <td><?= esc((string) $family['taxa_count']) ?></td>
                            <td><a class="btn btn-sm btn-outline-brand" href="<?= esc(site_url('families/' . $family['id'])) ?>">View</a></td>
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
