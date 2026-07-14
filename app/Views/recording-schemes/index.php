<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section">
    <div class="auth-card p-4 p-lg-5">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
            <div>
                <span class="eyebrow">Taxonomy</span>
                <h1 class="section-heading mb-0">Recording schemes</h1>
            </div>
        </div>

        <?php
        $sort = $page['sort'];
        $direction = $page['direction'];
        $q = (string) ($page['q'] ?? '');

        $sortUrl = static function (string $column) use ($sort, $direction, $q): string {
            $nextDirection = 'asc';

            if ($sort === $column && $direction === 'asc') {
                $nextDirection = 'desc';
            }

            return site_url('recording-schemes') . '?' . http_build_query([
                'sort' => $column,
                'direction' => $nextDirection,
                'q' => $q,
            ]);
        };

        $sortIndicator = static function (string $column) use ($sort, $direction): string {
            if ($sort !== $column) {
                return '';
            }

            return $direction === 'asc' ? ' ▲' : ' ▼';
        };
        ?>

        <form class="row g-2 mb-4" method="get" action="<?= esc(site_url('recording-schemes')) ?>">
            <div class="col-12 col-md-8 col-lg-6">
                <label class="visually-hidden" for="q">Search</label>
                <input class="form-control" id="q" name="q" type="search" value="<?= esc($q) ?>" placeholder="Search recording schemes...">
            </div>
            <div class="col-auto">
                <button class="btn btn-brand" type="submit">Search</button>
            </div>
            <?php if ($q !== ''): ?>
                <div class="col-auto">
                    <a class="btn btn-outline-secondary" href="<?= esc(site_url('recording-schemes')) ?>">Clear</a>
                </div>
            <?php endif; ?>
        </form>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                <tr>
                    <th scope="col"><a href="<?= esc($sortUrl('id')) ?>">ID<?= esc($sortIndicator('id')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('external_key')) ?>">External key<?= esc($sortIndicator('external_key')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('title')) ?>">Title<?= esc($sortIndicator('title')) ?></a></th>
                    <th scope="col">Taxa count</th>
                    <th scope="col">Links</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($page['recordingSchemes'] === []): ?>
                    <tr>
                        <td colspan="5" class="text-muted">No recording schemes found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($page['recordingSchemes'] as $recordingScheme): ?>
                        <tr>
                            <td><?= esc((string) $recordingScheme['id']) ?></td>
                            <td><?= esc($recordingScheme['external_key']) ?></td>
                            <td><?= esc($recordingScheme['title']) ?></td>
                            <td><?= esc((string) $recordingScheme['taxa_count']) ?></td>
                            <td><a class="btn btn-sm btn-outline-brand" href="<?= esc(site_url('recording-schemes/' . $recordingScheme['id'])) ?>">Details</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-3">
            <?= $page['pager']->only(['sort', 'direction', 'q'])->links() ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
