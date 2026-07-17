<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section auth-page">
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-5">
            <div class="auth-panel p-4 p-lg-5 h-100">
                <span class="eyebrow mb-3">Authentication starter</span>
                <h1 class="section-heading mb-3"><?= esc($page['authTitle']) ?></h1>
                <p class="section-copy mb-4"><?= esc($page['authCopy']) ?></p>
                <ul class="auth-benefits mb-0 ps-3">
                    <?php foreach ($page['benefits'] as $benefit): ?>
                        <li><?= esc($benefit) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="auth-card p-4 p-lg-5">
                <?php if (session()->getFlashdata('success')): ?>
                    <div class="alert alert-success" role="alert"><?= esc(session()->getFlashdata('success')) ?></div>
                <?php endif; ?>
                <form action="<?= esc(site_url('login')) ?>" method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="email">Email address</label>
                        <input class="form-control<?= validation_show_error('email') ? ' is-invalid' : '' ?>" id="email" name="email" type="email" value="<?= esc(old('email')) ?>" autocomplete="email" placeholder="name@example.com">
                        <?php if (validation_show_error('email')): ?>
                            <div class="invalid-feedback d-block"><?= validation_show_error('email') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control<?= validation_show_error('password') ? ' is-invalid' : '' ?>" id="password" name="password" type="password" autocomplete="current-password" placeholder="Minimum 8 characters">
                        <?php if (validation_show_error('password')): ?>
                            <div class="invalid-feedback d-block"><?= validation_show_error('password') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between">
                        <button class="btn btn-brand btn-lg px-4" type="submit"><?= esc($page['authSubmitLabel']) ?></button>
                        <p class="mb-0 section-copy">User accounts are managed by administrators.</p>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>