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
            <?php if ($page['taskQueue'] === []): ?>
                <p class="text-muted mb-0">No tasks queued.</p>
            <?php else: ?>
                <ol class="mb-0">
                    <?php foreach ($page['taskQueue'] as $queuedTask): ?>
                        <li>
                            <code><?= esc((string) ($queuedTask['task_key'] ?? '')) ?></code>
                            <span class="badge text-bg-light ms-1"><?= esc((string) ($queuedTask['status'] ?? '')) ?></span>
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
                            <?php if ($task['queue_status'] === 'running'): ?>
                                <span class="badge text-bg-primary">Running</span>
                            <?php elseif ($task['queue_status'] === 'queued'): ?>
                                <span class="badge text-bg-info">Queued</span>
                            <?php elseif (! $task['supports_run']): ?>
                                <span class="badge text-bg-light">Not implemented</span>
                            <?php elseif ($task['blocked_by'] !== []): ?>
                                <span class="badge text-bg-warning">Blocked by <?= esc(implode(', ', $task['blocked_by'])) ?></span>
                            <?php else: ?>
                                <form method="post" action="<?= esc(site_url('imports/run')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="task_key" value="<?= esc((string) $task['task_key']) ?>">
                                    <button class="btn btn-sm btn-brand" type="submit">Go</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
