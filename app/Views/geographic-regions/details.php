<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section auth-page">
    <div class="mb-4">
        <span class="eyebrow mb-2">Geography</span>
        <h1 class="section-heading mb-2">Geographic region details</h1>
        <p class="section-copy mb-0">Read-only values from the geographic regions reference table.</p>
    </div>
    <div class="auth-card p-3 p-md-4">
                <div class="row g-3">
                    <?php
                    $fields = [
                        'id' => 'ID',
                        'higher_geography_identifier' => 'Identifier',
                        'higher_geography' => 'Region',
                        'location_type' => 'Location type',
                    ];
                    ?>

                    <?php foreach ($fields as $field => $label): ?>
                        <div class="col-12">
                            <label class="form-label" for="<?= esc($field) ?>">
                                <?= esc($label) ?>
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="<?= esc($field) ?>" type="text" value="<?= esc((string) ($page['geographicRegion'][$field] ?? '')) ?>" disabled>
                        </div>
                    <?php endforeach; ?>

                    <div class="col-12">
                        <label class="form-label" for="data_source">
                            Data source
                            <span class="badge bg-secondary ms-2">Read-only</span>
                        </label>
                        <input class="form-control" id="data_source" type="text" value="<?= esc((string) (($page['dataSource']['title'] ?? '') !== '' ? $page['dataSource']['title'] . ' (' . ($page['dataSource']['abbr'] ?? '') . ')' : '')) ?>" disabled>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="occurrence_count">
                            Linked occurrences
                            <span class="badge bg-secondary ms-2">Read-only</span>
                        </label>
                        <input class="form-control" id="occurrence_count" type="text" value="<?= esc((string) $page['occurrenceCount']) ?>" disabled>
                    </div>
                </div>

                <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between mt-4">
                    <a class="btn btn-outline-brand" href="<?= esc(site_url('geographic-regions')) ?>">Back to list</a>
                </div>
            </div>
</section>
<?= $this->endSection() ?>