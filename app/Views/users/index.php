<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section">
    <div class="auth-card p-4 p-lg-5">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
            <div>
                <span class="eyebrow">Administration</span>
                <h1 class="section-heading mb-0">Users</h1>
            </div>
            <a class="btn btn-brand" href="<?= esc(site_url('users/create')) ?>">Create user</a>
        </div>

        <?php if (session()->getFlashdata('message')): ?>
            <div class="alert alert-success" role="alert"><?= esc(session()->getFlashdata('message')) ?></div>
        <?php endif; ?>

        <?php
        $sort = $page['sort'];
        $direction = $page['direction'];
        $q = (string) ($page['q'] ?? '');

        $sortUrl = static function (string $column) use ($sort, $direction, $q): string {
            $nextDirection = 'asc';

            if ($sort === $column && $direction === 'asc') {
                $nextDirection = 'desc';
            }

            return site_url('users') . '?' . http_build_query([
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

        <form class="row g-2 mb-4" method="get" action="<?= esc(site_url('users')) ?>">
            <div class="col-12 col-md-8 col-lg-6">
                <label class="visually-hidden" for="q">Search</label>
                <input class="form-control" id="q" name="q" type="search" value="<?= esc($q) ?>" placeholder="Search users...">
            </div>
            <div class="col-auto">
                <button class="btn btn-brand" type="submit">Search</button>
            </div>
            <?php if ($q !== ''): ?>
                <div class="col-auto">
                    <a class="btn btn-outline-secondary" href="<?= esc(site_url('users')) ?>">Clear</a>
                </div>
            <?php endif; ?>
        </form>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                <tr>
                    <th scope="col"><a href="<?= esc($sortUrl('id')) ?>">ID<?= esc($sortIndicator('id')) ?></a></th>
                    <th scope="col"><a href="<?= esc($sortUrl('username')) ?>">Username<?= esc($sortIndicator('username')) ?></a></th>
                    <th scope="col">Email</th>
                    <th scope="col"><a href="<?= esc($sortUrl('active')) ?>">Active<?= esc($sortIndicator('active')) ?></a></th>
                    <th scope="col">Groups</th>
                    <th scope="col"><a href="<?= esc($sortUrl('created_at')) ?>">Created<?= esc($sortIndicator('created_at')) ?></a></th>
                    <th scope="col">Links</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($page['users'] === []): ?>
                    <tr>
                        <td colspan="7" class="text-muted">No users found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($page['users'] as $user): ?>
                        <tr>
                            <td><?= esc((string) $user->id) ?></td>
                            <td><?= esc((string) $user->username) ?></td>
                            <td><?= esc((string) ($user->getEmail() ?? '')) ?></td>
                            <td><?= ! empty($user->active) ? 'Yes' : 'No' ?></td>
                            <td><?= esc(implode(', ', $user->getGroups() ?? [])) ?></td>
                            <td><?= esc((string) ($user->created_at ?? '')) ?></td>
                            <td><a class="btn btn-sm btn-outline-brand" href="<?= esc(site_url('users/' . $user->id)) ?>">Details</a></td>
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
