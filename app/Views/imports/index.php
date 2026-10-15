<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section">
    <div class="auth-card p-4 p-lg-5">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
            <div>
                <span class="eyebrow">Admin</span>
                <h1 class="section-heading mb-0">Imports</h1>
            </div>
        </div>

        <?php if (session()->getFlashdata('message')): ?>
            <div class="alert alert-success" role="alert"><?= esc((string) session()->getFlashdata('message')) ?></div>
        <?php endif; ?>

        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger" role="alert"><?= esc((string) session()->getFlashdata('error')) ?></div>
        <?php endif; ?>

        <?php if (session()->getFlashdata('warning')): ?>
            <div class="alert alert-warning" role="alert"><?= esc((string) session()->getFlashdata('warning')) ?></div>
        <?php endif; ?>

        <div class="mb-4">
            <h2 class="h5">Current queue</h2>
            <p class="text-muted">
                Run starts one batch now if no import is running or queues the request otherwise. Queued
                requests take priority at the next scheduled <code>import:auto</code> invocation and stay
                queued until complete. Without a schedule, use Process next batch now. Refreshing this page
                does not run imports.
            </p>
            <?php if ($page['taskQueue'] === []): ?>
                <p class="text-muted mb-0">No tasks queued.</p>
            <?php else: ?>
                <form method="post" action="<?= esc(site_url('imports/process')) ?>" class="mb-3">
                    <?= csrf_field() ?>
                    <button class="btn btn-brand" type="submit">Process next batch now</button>
                </form>
                <ol class="mb-0">
                    <?php foreach ($page['taskQueue'] as $queuedTask): ?>
                        <li>
                            <code><?= esc((string) ($queuedTask['source_key'] ?? '')) ?></code>
                            <span class="badge text-bg-light ms-1"><?= esc((string) ($queuedTask['status'] ?? '')) ?></span>
                            <span class="text-muted">Queued <?= esc((string) $queuedTask['queued_at']) ?></span>
                            <?php if ($queuedTask['status'] === 'queued'): ?>
                                <span class="text-muted">Waiting for scheduled runner or manual processing.</span>
                            <?php elseif ($queuedTask['status'] === 'running'): ?>
                                <span class="text-muted">Started <?= esc((string) $queuedTask['started_at']) ?></span>
                            <?php endif; ?>
                            <?php if ($queuedTask['blocked_by'] !== []): ?>
                                <div class="text-warning-emphasis">Prerequisites: <?= esc(implode(', ', $queuedTask['blocked_by'])) ?>.
                                    Processing advances these first.</div>
                            <?php endif; ?>
                            <?php if ($queuedTask['status'] === 'failed'): ?>
                                <div class="text-danger"><?= esc((string) $queuedTask['message']) ?></div>
                                <?php if (! empty($queuedTask['run_id'])): ?>
                                    <div>Run ID: <?= esc((string) $queuedTask['run_id']) ?></div>
                                <?php endif; ?>
                                <form method="post" action="<?= esc(site_url('imports/queue')) ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="queue_id" value="<?= esc((string) $queuedTask['id']) ?>">
                                    <button class="btn btn-sm btn-outline-primary" name="action" value="retry" type="submit">Retry</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($queuedTask['status'] !== 'running'): ?>
                                <form method="post" action="<?= esc(site_url('imports/queue')) ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="queue_id" value="<?= esc((string) $queuedTask['id']) ?>">
                                    <button class="btn btn-sm btn-outline-secondary" name="action" value="remove" type="submit">Remove from queue</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                <tr>
                    <th scope="col">Category</th>
                    <th scope="col">Task</th>
                    <th scope="col">Source</th>
                    <th scope="col">Next offset</th>
                    <th scope="col">Checkpoint</th>
                    <th scope="col">Complete</th>
                    <th scope="col">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($page['taskStates'] as $task): ?>
                    <tr>
                        <td><?= esc((string) $task['category']) ?></td>
                        <td><code><?= esc((string) $task['label']) ?></code></td>
                        <td><?= esc((string) ($task['source'] ?? '-')) ?></td>
                        <td><?= esc((string) ($task['next_offset'] ?? '-')) ?></td>
                        <td><?= esc((string) ($task['next_checkpoint'] ?? '-')) ?></td>
                        <td>
                            <?php if ($task['is_complete']): ?>
                                <span class="badge text-bg-success">Yes</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">No</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                            <?php if ($task['queue_status'] === 'running'): ?>
                                <span class="badge text-bg-primary">Running</span>
                            <?php elseif ($task['queue_status'] === 'queued'): ?>
                                <span class="badge text-bg-info">Queued</span>
                            <?php elseif ($task['queue_status'] === 'failed'): ?>
                                <span class="badge text-bg-danger">Failed - retry above</span>
                            <?php elseif (! $task['supports_run']): ?>
                                <span class="badge text-bg-light">Not implemented</span>
                            <?php else: ?>
                                <?php if ($task['blocked_by'] !== []): ?>
                                    <span class="badge text-bg-warning">Blocked by <?= esc(implode(', ', $task['blocked_by'])) ?></span>
                                <?php endif; ?>
                                <form method="post" action="<?= esc(site_url('imports/run')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="source_key" value="<?= esc((string) $task['source_key']) ?>">
                                    <button class="btn btn-sm btn-brand" type="submit">Run</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($task['queue_status'] === null && $task['supports_run']): ?>
                                <form method="post" action="<?= esc(site_url('imports/reset')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="source_key" value="<?= esc((string) $task['source_key']) ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit">Restart</button>
                                </form>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
