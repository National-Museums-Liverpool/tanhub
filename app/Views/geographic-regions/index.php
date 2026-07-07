<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section">
    <div class="auth-card p-4 p-lg-5">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
            <div>
                <span class="eyebrow">Geography</span>
                <h1 class="section-heading mb-0">Geographic regions</h1>
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

            return site_url('geographic-regions') . '?' . http_build_query([
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
                    <th scope="col"><a href="<?= esc($sortUrl('higher_geography_identifier')) ?>">Identifier<?= esc($sortIndicator('higher_geography_identifier')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('higher_geography')) ?>">Region<?= esc($sortIndicator('higher_geography')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('location_type')) ?>">Location type<?= esc($sortIndicator('location_type')) ?></a></th>
                    <th scope="col">Occurrences</th>
                    <th scope="col">View</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($page['geographicRegions'] === []): ?>
                    <tr>
                        <td colspan="6" class="text-muted">No geographic regions found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($page['geographicRegions'] as $geographicRegion): ?>
                        <tr>
                            <td><?= esc((string) $geographicRegion['id']) ?></td>
                            <td><?= esc((string) $geographicRegion['higher_geography_identifier']) ?></td>
                            <td><?= esc($geographicRegion['higher_geography']) ?></td>
                            <td><?= esc($geographicRegion['location_type']) ?></td>
                            <td><?= esc((string) $geographicRegion['occurrence_count']) ?></td>
                            <td><a class="btn btn-sm btn-outline-brand" href="<?= esc(site_url('geographic-regions/' . $geographicRegion['id'])) ?>">View</a></td>
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