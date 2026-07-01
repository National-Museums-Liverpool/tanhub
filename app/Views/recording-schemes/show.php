<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section auth-page">
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-5">
            <div class="auth-panel p-4 p-lg-5 h-100">
                <span class="eyebrow mb-3">Taxonomy</span>
                <h1 class="section-heading mb-3">Recording scheme details</h1>
                <p class="section-copy mb-4">Read-only values from the recording schemes reference table.</p>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="auth-card p-4 p-lg-5">
                <div class="row g-3">
                    <?php
                    $fields = [
                        'id' => 'ID',
                        'external_key' => 'External key',
                        'title' => 'Title',
                    ];
                    ?>

                    <?php foreach ($fields as $field => $label): ?>
                        <div class="col-12">
                            <label class="form-label" for="<?= esc($field) ?>">
                                <?= esc($label) ?>
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="<?= esc($field) ?>" type="text" value="<?= esc((string) ($page['recordingScheme'][$field] ?? '')) ?>" disabled>
                        </div>
                    <?php endforeach; ?>
                    <div class="col-12">
                        <label class="form-label" for="description">
                            Description
                            <span class="badge bg-secondary ms-2">Read-only</span>
                        </label>
                        <textarea class="form-control" id="description" rows="3" disabled><?= esc((string) ($page['recordingScheme']['description'] ?? '')) ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="taxa_count">
                            Taxa count
                            <span class="badge bg-secondary ms-2">Read-only</span>
                        </label>
                        <input class="form-control" id="taxa_count" type="text" value="<?= esc((string) $page['taxaCount']) ?>" disabled>
                    </div>
                </div>

                <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between mt-4">
                    <a class="btn btn-outline-brand" href="<?= esc(site_url('recording-schemes')) ?>">Back to list</a>
                </div>
            </div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
