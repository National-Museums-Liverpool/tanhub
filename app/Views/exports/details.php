<?= $this->extend('layouts/default') ?>
<?= $this->section('content') ?>
<?php
$job = $page['job'];
$contract = $page['contract'];
$estimatedTotal = $contract['estimated_total'] ?? null;
$writing = $job['phase'] === 'writing';
$total = $writing ? (int) $job['captured_rows'] : $estimatedTotal;
$count = (int) ($writing ? $job['written_rows'] : $job['captured_rows']);
$percent = $total === null ? 100 : ($total > 0 ? min($writing ? 100 : 99, (int) floor($count / $total * 100)) : ($writing ? 100 : 0));
?>
<section class="page-section">
    <div class="auth-card p-4 p-lg-5" data-export
        data-auto-start="<?= $page['autoStart'] ? 'true' : 'false' ?>"
        data-status-url="<?= esc(site_url('exports/' . $job['uuid'] . '/status')) ?>"
        data-phase="<?= esc($job['phase']) ?>" data-state="<?= esc($job['status']) ?>">
        <h1 class="section-heading">Download progress</h1>
        <div class="alert alert-info">This is a rolling capture. Data can change between capture batches.
            Captured values are frozen and the CSV uses their original grid sorting.
            Closing the tab pauses automatic processing; return here to Resume.</div>
        <?php if (session('error')): ?><div class="alert alert-danger" role="alert"><?= esc(session('error')) ?></div><?php endif; ?>
        <p role="status" aria-live="polite" data-progress-text>
            <?= esc($job['status']) ?>: <?= esc((string) $job['captured_rows']) ?> captured;
            <?= esc((string) $job['written_rows']) ?> written; <?= esc((string) $job['file_offset']) ?> CSV bytes.
            <?php if ($estimatedTotal !== null): ?>
                Captured <?= esc((string) $job['captured_rows']) ?> out of approximately <?= esc((string) $estimatedTotal) ?> rows.
            <?php endif; ?>
        </p>
        <div class="progress mb-3" role="progressbar" aria-label="Export progress"
            <?php if ($total !== null): ?>aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"<?php endif; ?>>
            <div class="progress-bar progress-bar-striped" data-progress-bar style="width: <?= $percent ?>%">
                <?= $writing ? $percent . '% CSV written' : ($total === null ? 'Capturing rows...' : $percent . '% captured (estimated)') ?>
            </div>
        </div>
        <div class="alert alert-danger" role="alert" data-export-error <?= empty($job['error']) ? 'hidden' : '' ?>><?= esc($job['error'] ?? '') ?></div>
        <?php if (in_array($job['status'], ['capturing', 'writing', 'paused'], true)): ?>
            <form method="post" action="<?= esc(site_url('exports/' . $job['uuid'] . '/process')) ?>" data-process-form class="d-inline">
                <?= csrf_field() ?>
                <button class="btn btn-brand" data-resume>Process next batch</button>
            </form>
            <button type="button" class="btn btn-outline-secondary" data-pause hidden>Pause</button>
            <form method="post" action="<?= esc(site_url('exports/' . $job['uuid'] . '/action')) ?>" class="d-inline" data-action-form>
                <?= csrf_field() ?>
                <button class="btn btn-outline-danger" name="action" value="cancel">Cancel</button>
                <button class="btn btn-outline-secondary" name="action" value="regenerate" data-regenerate
                    <?= $job['phase'] !== 'writing' ? 'hidden' : '' ?>>Regenerate CSV</button>
            </form>
        <?php endif; ?>
        <a class="btn btn-brand" data-download href="<?= esc(site_url('exports/' . $job['uuid'] . '/download')) ?>"
            <?= $job['status'] !== 'completed' ? 'hidden' : '' ?>>Download CSV</a>
        <a class="btn btn-outline-secondary" href="<?= esc(site_url('exports')) ?>">My downloads</a>
        <a class="btn btn-outline-secondary" href="<?= esc(site_url($job['resource']) . '?' . http_build_query(
            $contract['filters'] + ['sort' => $contract['ordering']['sort'], 'direction' => $contract['ordering']['direction']]
        )) ?>">Back to search</a>
        <h2 class="h5 mt-4">Export metadata</h2>
        <dl class="row">
            <?php foreach (['created_at' => 'Created / ID ceiling set (UTC)', 'capture_started_at' => 'Capture started (UTC)',
                'capture_finished_at' => 'Capture ended (UTC)',
                'expires_at' => 'File expires (UTC)'] as $key => $label): ?>
                <dt class="col-sm-4"><?= esc($label) ?></dt><dd class="col-sm-8"><?= esc($job[$key] ?? 'Not yet') ?></dd>
            <?php endforeach; ?>
            <dt class="col-sm-4">Column template</dt><dd class="col-sm-8"><?= esc($page['templateLabel']) ?></dd>
            <dt class="col-sm-4">Template version</dt><dd class="col-sm-8"><?= esc((string) $contract['version']) ?>
                <span class="text-muted">(identifies the column layout used for this export)</span></dd>
            <?php if (isset($contract['statistics_scope'])): ?>
                <dt class="col-sm-4">Statistics scope</dt><dd class="col-sm-8"><?= esc($contract['statistics_scope']) ?>
                    <span class="text-muted">(stored all-date statistics)</span></dd>
            <?php endif; ?>
        </dl>
        <details><summary>Technical export details</summary><pre class="text-wrap"><?= esc(json_encode(
            $contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        )) ?></pre></details>
    </div>
</section>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('js/exports.js')) ?>" defer></script>
<?= $this->endSection() ?>
