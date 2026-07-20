<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section auth-page">
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-5">
            <div class="auth-panel p-4 p-lg-5 h-100">
                <span class="eyebrow mb-3">Occurrences</span>
                <h1 class="section-heading mb-3">Occurrence details</h1>
                <p class="section-copy mb-4">Source identity and biological identity fields are read-only. Moderation fields can be updated by staff.</p>
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

                <form action="<?= esc(site_url('occurrences/' . $page['occurrence']['id'])) ?>" method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label" for="id">
                                ID
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="id" type="text" value="<?= esc((string) $page['occurrence']['id']) ?>" disabled>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label" for="unique_key">
                                Unique key
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="unique_key" type="text" value="<?= esc((string) $page['occurrence']['unique_key']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="taxon_id">
                                Taxon
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="taxon_id" type="text" value="<?= esc((string) ($page['referenceLabels']['taxon_id'] ?? $page['occurrence']['taxon_id'])) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="taxon_name_id">
                                Taxon name
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="taxon_name_id" type="text" value="<?= esc((string) ($page['referenceLabels']['taxon_name_id'] ?? $page['occurrence']['taxon_name_id'])) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="data_source_id">
                                Data source
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="data_source_id" type="text" value="<?= esc((string) ($page['referenceLabels']['data_source_id'] ?? $page['occurrence']['data_source_id'])) ?>" disabled>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="from_date">
                                From date
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="from_date" type="text" value="<?= esc((string) ($page['occurrence']['from_date'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="to_date">
                                To date
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="to_date" type="text" value="<?= esc((string) ($page['occurrence']['to_date'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="grid_ref">
                                Grid ref
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="grid_ref" type="text" value="<?= esc((string) ($page['occurrence']['grid_ref'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="grid_ref_2km">
                                2km grid ref
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="grid_ref_2km" type="text" value="<?= esc((string) ($page['occurrence']['grid_ref_2km'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="identification_verification_status">
                                Verification status
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="identification_verification_status" type="text" value="<?= esc((string) ($page['occurrence']['identification_verification_status'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="locality">
                                Locality
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="locality" type="text" value="<?= esc((string) ($page['occurrence']['locality'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="recorded_by">
                                Recorded by
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="recorded_by" type="text" value="<?= esc((string) ($page['occurrence']['recorded_by'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="identified_by">
                                Identified by
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="identified_by" type="text" value="<?= esc((string) ($page['occurrence']['identified_by'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="sex">
                                Sex
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="sex" type="text" value="<?= esc((string) ($page['occurrence']['sex'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="life_stage">
                                Life stage
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="life_stage" type="text" value="<?= esc((string) ($page['occurrence']['life_stage'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="organism_quantity">
                                Quantity
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="organism_quantity" type="text" value="<?= esc((string) ($page['occurrence']['organism_quantity'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="blocked">Blocked</label>
                            <select class="form-select<?= isset($errors['blocked']) ? ' is-invalid' : '' ?>" id="blocked" name="blocked">
                                <option value="0" <?= old('blocked', (string) ($page['occurrence']['blocked'] ?? 0)) === '0' ? 'selected' : '' ?>>No</option>
                                <option value="1" <?= old('blocked', (string) ($page['occurrence']['blocked'] ?? 0)) === '1' ? 'selected' : '' ?>>Yes</option>
                            </select>
                            <?php if (isset($errors['blocked'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['blocked']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="blocked_reason">Blocked reason</label>
                            <textarea class="form-control<?= isset($errors['blocked_reason']) ? ' is-invalid' : '' ?>" id="blocked_reason" name="blocked_reason" rows="3"><?= esc(old('blocked_reason', (string) ($page['occurrence']['blocked_reason'] ?? ''))) ?></textarea>
                            <?php if (isset($errors['blocked_reason'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['blocked_reason']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between mt-4">
                        <button class="btn btn-brand btn-lg px-4" type="submit">Save</button>
                        <a class="btn btn-outline-brand" href="<?= esc(site_url('occurrences')) ?>">Back to list</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
