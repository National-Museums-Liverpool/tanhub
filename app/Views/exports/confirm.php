<?= $this->extend('layouts/default') ?>
<?= $this->section('content') ?>
<section class="page-section">
    <div class="auth-card p-4 p-lg-5">
        <h1 class="section-heading">Download <?= esc($page['resource']) ?></h1>
        <p>This downloads all results for the applied search, not just the current page.
            Apply any edited filters with Search before starting a download.</p>
        <div class="alert alert-info">Rows are captured in batches, not as a single snapshot.
            Keep this page open while processing, or return to My downloads to resume.
            CSV files can exceed spreadsheet row limits.</div>
        <?php if (session('error')): ?><div class="alert alert-danger" role="alert"><?= esc(session('error')) ?></div><?php endif; ?>
        <?php foreach ($page['errors'] as $error): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endforeach; ?>
        <?php if ($page['configurationError'] !== null): ?><div class="alert alert-warning"><?= esc($page['configurationError']) ?></div><?php endif; ?>
        <h2 class="h5">Applied filters and ordering</h2>
        <dl class="row">
            <?php foreach ($page['summary'] as $entry): ?>
                <dt class="col-sm-4"><?= esc($entry['label']) ?></dt><dd class="col-sm-8"><?= esc($entry['value']) ?></dd>
            <?php endforeach; ?>
        </dl>
        <form method="post" action="<?= esc(site_url('exports')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="resource" value="<?= esc($page['resource']) ?>">
            <input type="hidden" name="criteria" value="<?= esc(json_encode($page['criteria'], JSON_THROW_ON_ERROR)) ?>">
            <input type="hidden" name="estimated_total" value="<?= esc((string) $page['estimatedTotal']) ?>">
            <label class="form-label" for="template">Column template</label>
            <select class="form-select mb-3" id="template" name="template">
                <?php foreach ($page['templates'] as $key => $label): ?>
                    <option value="<?= esc($key) ?>"><?= esc($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-brand" <?= $page['errors'] !== [] || $page['configurationError'] !== null ? 'disabled' : '' ?>>Create CSV export</button>
            <a class="btn btn-outline-secondary" href="<?= esc(site_url($page['resource']) . '?' . http_build_query($page['criteria'])) ?>">Back to search</a>
        </form>
    </div>
</section>
<?= $this->endSection() ?>
