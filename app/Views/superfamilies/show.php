<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section auth-page">
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-5">
            <div class="auth-panel p-4 p-lg-5 h-100">
                <span class="eyebrow mb-3">Taxonomy</span>
                <h1 class="section-heading mb-3">Superfamily details</h1>
                <p class="section-copy mb-4">Read-only values from the superfamilies reference table.</p>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="auth-card p-4 p-lg-5">
                <div class="row g-3">
                    <?php
                    $fields = [
                        'id' => 'ID',
                        'taxon_identifier' => 'Taxon identifier',
                        'scientific_name_identifier' => 'Scientific name identifier',
                        'scientific_name' => 'Scientific name',
                        'scientific_name_authorship' => 'Scientific name authorship',
                        'vernacular_name' => 'Vernacular name',
                    ];
                    ?>

                    <?php foreach ($fields as $field => $label): ?>
                        <div class="col-12">
                            <label class="form-label" for="<?= esc($field) ?>">
                                <?= esc($label) ?>
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="<?= esc($field) ?>" type="text" value="<?= esc((string) ($page['superfamily'][$field] ?? '')) ?>" disabled>
                        </div>
                    <?php endforeach; ?>

                    <div class="col-12">
                        <label class="form-label" for="taxa_count">
                            Taxa count
                            <span class="badge bg-secondary ms-2">Read-only</span>
                        </label>
                        <input class="form-control" id="taxa_count" type="text" value="<?= esc((string) $page['taxaCount']) ?>" disabled>
                    </div>
                </div>

                <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between mt-4">
                    <a class="btn btn-outline-brand" href="<?= esc(site_url('superfamilies')) ?>">Back to list</a>
                </div>
            </div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
