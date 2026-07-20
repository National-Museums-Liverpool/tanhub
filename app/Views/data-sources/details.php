<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section auth-page">
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-5">
            <div class="auth-panel p-4 p-lg-5 h-100">
                <span class="eyebrow mb-3">Lookups</span>
                <h1 class="section-heading mb-3">Data source details</h1>
                <p class="section-copy mb-4">Manage the lookup entry used to label imported records by source.</p>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="auth-card p-4 p-lg-5">
                <?php if (session()->getFlashdata('message')): ?>
                    <div class="alert alert-success" role="alert"><?= esc(session()->getFlashdata('message')) ?></div>
                <?php endif; ?>
                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-danger" role="alert"><?= esc(session()->getFlashdata('error')) ?></div>
                <?php endif; ?>

                <?php $errors = session('errors') ?? []; ?>

                <form action="<?= esc(site_url('data-sources/' . $page['dataSource']['id'])) ?>" method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label" for="id">
                                ID
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="id" type="text" value="<?= esc((string) $page['dataSource']['id']) ?>" disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="abbr">Abbreviation <span class="badge bg-secondary ms-2">Read-only</span></label>
                            <input class="form-control<?= isset($errors['abbr']) ? ' is-invalid' : '' ?>" id="abbr" name="abbr" type="text" maxlength="10" value="<?= esc(old('abbr', (string) $page['dataSource']['abbr'])) ?>" disabled>
                            <?php if (isset($errors['abbr'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['abbr']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="title">Title <span class="badge bg-secondary ms-2">Read-only</span></label>
                            <input class="form-control<?= isset($errors['title']) ? ' is-invalid' : '' ?>" id="title" name="title" type="text" maxlength="100" value="<?= esc(old('title', (string) $page['dataSource']['title'])) ?>" disabled>
                            <?php if (isset($errors['title'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['title']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="url">URL <span class="badge bg-secondary ms-2">Read-only</span></label>
                            <input class="form-control<?= isset($errors['url']) ? ' is-invalid' : '' ?>" id="url" name="url" type="url" maxlength="100" value="<?= esc(old('url', (string) $page['dataSource']['url'])) ?>" disabled>
                            <?php if (isset($errors['url'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['url']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-4">
                        <a class="btn btn-outline-brand" href="<?= esc(site_url('data-sources')) ?>">Back to list</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
