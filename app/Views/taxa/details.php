<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section auth-page">
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-5">
            <div class="auth-panel p-4 p-lg-5 h-100">
                <span class="eyebrow mb-3">Taxonomy</span>
                <h1 class="section-heading mb-3">Taxon details</h1>
                <p class="section-copy mb-4">Most fields are read-only. Manager and admin users can edit rarity group name and taxon remarks. Admin users can also set blocking controls.</p>
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

                <form action="<?= esc(site_url('taxa/' . $page['taxon']['id'])) ?>" method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="id">
                                ID
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="id" type="text" value="<?= esc((string) $page['taxon']['id']) ?>" disabled>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="taxon_identifier">
                                Taxon identifier
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="taxon_identifier" type="text" value="<?= esc((string) $page['taxon']['taxon_identifier']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="scientific_name_identifier">
                                Scientific name identifier
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="scientific_name_identifier" type="text" value="<?= esc((string) $page['taxon']['scientific_name_identifier']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="scientific_name">
                                Scientific name
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="scientific_name" type="text" value="<?= esc((string) $page['taxon']['scientific_name']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="vernacular_name">
                                Vernacular name
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="vernacular_name" type="text" value="<?= esc((string) ($page['taxon']['vernacular_name'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="taxon_rank_id">
                                Taxon rank
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="taxon_rank_id" type="text" value="<?= esc((string) ($page['referenceLabels']['taxon_rank_id'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="taxon_group_id">
                                Taxon group
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="taxon_group_id" type="text" value="<?= esc((string) ($page['referenceLabels']['taxon_group_id'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="recording_scheme_id">
                                Recording scheme
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="recording_scheme_id" type="text" value="<?= esc((string) ($page['referenceLabels']['recording_scheme_id'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="rarity_group_name">
                                Rarity group name
                                <?php if (! $page['canEditDetails']): ?>
                                    <span class="badge bg-secondary ms-2">Read-only</span>
                                <?php endif; ?>
                            </label>
                            <input class="form-control<?= isset($errors['rarity_group_name']) ? ' is-invalid' : '' ?>" id="rarity_group_name" name="rarity_group_name" type="text" maxlength="100" value="<?= esc(old('rarity_group_name', (string) ($page['taxon']['rarity_group_name'] ?? ''))) ?>" <?= $page['canEditDetails'] ? '' : 'disabled' ?>>
                            <?php if (isset($errors['rarity_group_name'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['rarity_group_name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <?php foreach ($page['classificationColumns'] as $column): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="<?= esc($column) ?>">
                                    <?= esc(ucwords(preg_replace(['/_/', '/ id$/'], [' ', ''], $column))) ?>
                                    <span class="badge bg-secondary ms-2">Read-only</span>
                                </label>
                                <input class="form-control" id="<?= esc($column) ?>" type="text" value="<?= esc((string) ($page['referenceLabels'][$column] ?? '')) ?>" disabled>
                            </div>
                        <?php endforeach; ?>

                        <div class="col-12">
                            <label class="form-label" for="taxon_remarks">
                                Remarks
                                <?php if (! $page['canEditDetails']): ?>
                                    <span class="badge bg-secondary ms-2">Read-only</span>
                                <?php endif; ?>
                            </label>
                            <textarea class="form-control<?= isset($errors['taxon_remarks']) ? ' is-invalid' : '' ?>" id="taxon_remarks" name="taxon_remarks" rows="3" <?= $page['canEditDetails'] ? '' : 'disabled' ?>><?= esc(old('taxon_remarks', (string) ($page['taxon']['taxon_remarks'] ?? ''))) ?></textarea>
                            <?php if (isset($errors['taxon_remarks'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['taxon_remarks']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="blocked">
                                Blocked
                                <?php if (! $page['canModerate']): ?>
                                    <span class="badge bg-secondary ms-2">Read-only</span>
                                <?php endif; ?>
                            </label>
                            <select class="form-select<?= isset($errors['blocked']) ? ' is-invalid' : '' ?>" id="blocked" name="blocked" <?= $page['canModerate'] ? '' : 'disabled' ?>>
                                <option value="0" <?= old('blocked', (string) ($page['taxon']['blocked'] ?? 0)) === '0' ? 'selected' : '' ?>>No</option>
                                <option value="1" <?= old('blocked', (string) ($page['taxon']['blocked'] ?? 0)) === '1' ? 'selected' : '' ?>>Yes</option>
                            </select>
                            <?php if (isset($errors['blocked'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['blocked']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="blocked_reason">
                                Blocked reason
                                <?php if (! $page['canModerate']): ?>
                                    <span class="badge bg-secondary ms-2">Read-only</span>
                                <?php endif; ?>
                            </label>
                            <textarea class="form-control<?= isset($errors['blocked_reason']) ? ' is-invalid' : '' ?>" id="blocked_reason" name="blocked_reason" rows="3" <?= $page['canModerate'] ? '' : 'disabled' ?>><?= esc(old('blocked_reason', (string) ($page['taxon']['blocked_reason'] ?? ''))) ?></textarea>
                            <?php if (isset($errors['blocked_reason'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['blocked_reason']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($page['canEditDetails'] || $page['canModerate']): ?>
                        <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between mt-4">
                            <button class="btn btn-brand btn-lg px-4" type="submit">Save</button>
                            <a class="btn btn-outline-brand" href="<?= esc(site_url('taxa')) ?>">Back to list</a>
                        </div>
                    <?php else: ?>
                        <div class="mt-4">
                            <a class="btn btn-outline-brand" href="<?= esc(site_url('taxa')) ?>">Back to list</a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</section>

<section class="page-section pt-0">
    <div class="auth-card p-4 p-lg-5">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
            <div>
                <span class="eyebrow">Taxonomy</span>
                <h2 class="section-heading mb-0">Associated taxon names</h2>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Given name identifier</th>
                    <th scope="col">Accepted</th>
                    <th scope="col">Scientific</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($page['taxonNames'] === []): ?>
                    <tr>
                        <td colspan="4" class="text-muted">No taxon names found for this taxon.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($page['taxonNames'] as $taxonName): ?>
                        <tr>
                            <td><?= esc((string) $taxonName['name']) ?></td>
                            <td><?= esc((string) $taxonName['given_name_identifier']) ?></td>
                            <td><?= ! empty($taxonName['accepted']) ? 'Yes' : 'No' ?></td>
                            <td><?= ! empty($taxonName['scientific']) ? 'Yes' : 'No' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
