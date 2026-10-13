<?= $this->extend('layouts/default') ?>
<?= $this->section('content') ?>
<section class="page-section">
    <div class="auth-card p-4 p-lg-5">
        <h1 class="section-heading">My downloads</h1>
        <p>Resume unfinished exports here. Completed files expire after 24 hours by default.</p>
        <?php foreach (['configurationError', 'cleanupError'] as $key): ?>
            <?php if ($page[$key] !== null): ?><div class="alert alert-warning"><?= esc($page[$key]) ?></div><?php endif; ?>
        <?php endforeach; ?>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead><tr><th>Created (UTC)</th><th>Resource</th><th>Status</th><th>Captured</th><th>Written</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($page['jobs'] as $job): ?>
                    <tr>
                        <td><?= esc($job['created_at']) ?></td><td><?= esc($job['resource']) ?></td>
                        <td><?= esc($job['status']) ?></td><td><?= esc((string) $job['captured_rows']) ?></td>
                        <td><?= esc((string) $job['written_rows']) ?></td>
                        <td><a href="<?= esc(site_url('exports/' . $job['uuid'])) ?>">Progress and download</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($page['jobs'] === []): ?><tr><td colspan="6">No exports yet. Use Download CSV on a taxa or occurrences search.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
