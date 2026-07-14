<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section auth-page">
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-5">
            <div class="auth-panel p-4 p-lg-5 h-100">
                <span class="eyebrow mb-3">Taxonomy</span>
                <h1 class="section-heading mb-3">Edit taxon group</h1>
                <p class="section-copy mb-4">Update the friendly display name for this taxon group.</p>
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

                <form action="<?= esc(site_url('taxon-groups/' . $page['taxonGroup']['id'])) ?>" method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="id">
                                ID
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="id" type="text" value="<?= esc((string) $page['taxonGroup']['id']) ?>" disabled>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="title">
                                Title
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="title" type="text" value="<?= esc($page['taxonGroup']['title']) ?>" disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="external_key">
                                External key
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="external_key" type="text" value="<?= esc($page['taxonGroup']['external_key']) ?>" disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="indicia_taxon_group_id">
                                Indicia taxon group ID
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="indicia_taxon_group_id" type="text" value="<?= esc($page['taxonGroup']['indicia_taxon_group_id']) ?>" disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="implied">
                                Implied
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="implied" type="text" value="<?= ! empty($page['taxonGroup']['implied']) ? 'Yes' : 'No' ?>" disabled>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="friendly">Friendly</label>
                            <input class="form-control<?= isset($errors['friendly']) ? ' is-invalid' : '' ?>" id="friendly" name="friendly" type="text" maxlength="200" value="<?= esc(old('friendly', (string) ($page['taxonGroup']['friendly'] ?? ''))) ?>">
                            <?php if (isset($errors['friendly'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['friendly']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between mt-4">
                        <button class="btn btn-brand btn-lg px-4" type="submit">Save</button>
                        <a class="btn btn-outline-brand" href="<?= esc(site_url('taxon-groups')) ?>">Back to list</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
