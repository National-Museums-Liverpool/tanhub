<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section auth-page">
    <div class="mb-4">
        <span class="eyebrow mb-2">Taxonomy</span>
        <h1 class="section-heading mb-2">Taxon rank details</h1>
        <p class="section-copy mb-0">Read-only values from the taxon ranks reference table.</p>
    </div>
    <div class="auth-card p-3 p-md-4">
                <div class="row g-3">
                    <?php
                    $fields = [
                        'id' => 'ID',
                        'rank' => 'Rank',
                        'abbr' => 'Abbreviation',
                        'sort_order' => 'Sort order',
                        'is_reporting' => 'Reporting rank',
                    ];
                    ?>

                    <?php foreach ($fields as $field => $label): ?>
                        <div class="col-12">
                            <label class="form-label" for="<?= esc($field) ?>">
                                <?= esc($label) ?>
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <?php $value = $field === 'is_reporting'
                                ? ((int) ($page['taxonRank'][$field] ?? 0) === 1 ? 'Yes' : 'No')
                                : (string) ($page['taxonRank'][$field] ?? ''); ?>
                            <input class="form-control" id="<?= esc($field) ?>" type="text" value="<?= esc($value) ?>" disabled>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between mt-4">
                    <a class="btn btn-outline-brand" href="<?= esc(site_url('taxon-ranks')) ?>">Back to list</a>
                </div>
            </div>
</section>
<?= $this->endSection() ?>
