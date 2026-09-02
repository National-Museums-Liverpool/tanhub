<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section">
    <div
        class="bulk-media-import"
        data-create-url="<?= esc(site_url('taxon-media-imports/create')) ?>"
        data-import-base="<?= esc(site_url('taxon-media-imports')) ?>"
        data-csrf-name="<?= esc(csrf_token()) ?>"
        data-csrf-hash="<?= esc(csrf_hash()) ?>"
    >
        <div class="mb-4">
            <span class="eyebrow">Taxonomy</span>
            <h1 class="section-heading mb-2">Bulk taxon media import</h1>
            <p class="section-copy mb-0">Choose a CSV and its photos, upload them, then start the import.</p>
        </div>

        <?php if (session()->getFlashdata('message')): ?>
            <div class="alert alert-success" role="alert"><?= esc((string) session()->getFlashdata('message')) ?></div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger" role="alert"><?= esc((string) session()->getFlashdata('error')) ?></div>
        <?php endif; ?>

        <form class="bulk-media-import-form" action="<?= esc(site_url('taxon-media-imports')) ?>" method="post" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-12">
                    <h2 class="h5 mb-2">1. Choose the CSV file</h2>
                    <label class="form-label visually-hidden" for="csv_file">CSV file</label>
                    <input class="form-control" id="csv_file" name="csv_file" type="file" accept=".csv,text/csv" required>
                    <div class="form-text">Use <code>photo_filename</code> and exactly one of <code>taxon_identifier</code>, <code>scientific_name_identifier</code>, or <code>scientific_name</code>.</div>
                </div>
                <div class="col-12">
                    <h2 class="h5 mb-2">2. Add the photos</h2>
                    <label class="form-label visually-hidden" for="photos">Photo files</label>
                    <input class="form-control" id="photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                    <div id="photo-dropzone" class="bulk-media-dropzone mt-2" tabindex="0" role="button" aria-controls="photos">
                        <div id="photo-dropzone-prompt">Drop photos or a folder here, or click to choose photos</div>
                        <div id="photo-dropzone-selection" class="bulk-media-selection d-none" aria-live="polite"></div>
                    </div>
                    <div class="form-text">Basenames must match exactly, including case. Duplicate, missing, extra, and non-photo files are rejected.</div>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-4">
                <button class="btn btn-brand" id="create-draft" type="submit">Upload files</button>
                <button class="btn btn-outline-brand" id="queue-import" type="button" disabled>Start import</button>
                <button class="btn btn-outline-danger" id="cancel-import" type="button" disabled>Cancel</button>
                <button class="btn btn-outline-secondary" id="retry-import" type="button" disabled>Retry failed files</button>
            </div>
        </form>

        <div id="bulk-media-feedback" class="alert d-none mt-4" role="status"></div>
        <div id="bulk-media-progress" class="d-none mt-4" aria-live="polite">
            <div class="d-flex justify-content-between mb-1"><strong id="bulk-media-status">Ready</strong><span id="bulk-media-count">0/0</span></div>
            <div class="progress" role="progressbar" aria-label="Import progress"><div id="bulk-media-progress-bar" class="progress-bar" style="width: 0%"></div></div>
            <ul id="bulk-media-files" class="list-group list-group-flush mt-3"></ul>
        </div>

        <div class="bulk-media-rules mt-5">
            <h2 class="h5">CSV and publication rules</h2>
            <p>Optional columns are <code>alt_text</code>, <code>caption</code>, <code>attribution</code>, <code>license</code>, <code>sort_order</code>, and <code>is_primary</code>. Blank sort order and primary values become <code>0</code>; primary accepts only <code>0</code> or <code>1</code>.</p>
            <p>Taxa are looked up directly first, then through accepted taxon names. Ambiguous fallback names must have exactly one accepted match. Photo paths are not accepted: every CSV basename must have one matching JPEG, PNG, GIF, or WebP.</p>
            <p>Nothing becomes public until every file is prepared. Existing primary media is replaced only when a CSV row is primary; the last primary row for each taxon wins.</p>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-sm btn-outline-secondary csv-template" type="button" data-identifier="taxon_identifier">Download taxon identifier template</button>
                <button class="btn btn-sm btn-outline-secondary csv-template" type="button" data-identifier="scientific_name_identifier">Download scientific identifier template</button>
                <button class="btn btn-sm btn-outline-secondary csv-template" type="button" data-identifier="scientific_name">Download scientific name template</button>
            </div>
        </div>

        <hr class="my-5">
        <h2 class="h5 mb-3">Your recent imports</h2>
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead><tr><th>Import</th><th>Status</th><th>Progress</th><th>Error</th></tr></thead>
                <tbody>
                <?php if ($page['imports'] === []): ?>
                    <tr><td colspan="4" class="text-muted">No bulk imports yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($page['imports'] as $import): ?>
                        <tr>
                            <td>#<?= esc((string) $import['id']) ?><br><small class="text-muted"><code><?= esc((string) $import['uuid']) ?></code></small></td>
                            <td><span class="badge text-bg-secondary"><?= esc((string) $import['status']) ?></span></td>
                            <td><?= esc((string) $import['processed_rows']) ?>/<?= esc((string) $import['total_rows']) ?></td>
                            <td><?= esc((string) ($import['error_message'] ?? '')) ?>
                                <button class="btn btn-sm btn-link p-0 resume-import" type="button" data-uuid="<?= esc((string) $import['uuid']) ?>">Resume</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('js/taxon-media-import.js')) ?>"></script>
<?= $this->endSection() ?>
