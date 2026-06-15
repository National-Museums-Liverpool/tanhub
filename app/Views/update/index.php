<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section auth-page">
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-5">
            <div class="auth-panel p-4 p-lg-5 h-100">
                <span class="eyebrow mb-3">Update database</span>
                <h1 class="section-heading mb-3">Run database updates</h1>
                <p class="section-copy mb-4">Use this page to run database updates for initial installation or after deploying a new version of the application.</p>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="auth-card p-4 p-lg-5">
                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-danger" role="alert"><?= esc(session()->getFlashdata('error')) ?></div>
                <?php endif; ?>
                <?php if (session()->getFlashdata('message')): ?>
                    <div class="alert alert-success" role="alert"><?= session()->getFlashdata('message') ?></div>
                <?php endif; ?>
                <?php if ($page['migrationCount'] > 0): ?>
                    <div class="alert alert-info" role="alert"><?= esc($page['migrationCount']) ?> database update<?= $page['migrationCount'] === 1 ? '' : 's' ?> available.</div>
                <?php else: ?>
                    <div class="alert alert-success" role="alert">No database updates available.</div>
                <?php endif; ?>
                <form action="<?= esc(site_url('update')) ?>" method="post" novalidate>
                    <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between mt-4">
                        <button class="btn btn-brand btn-lg px-4" type="submit">Run updates</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>