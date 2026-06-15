<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section auth-page">
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-5">
            <div class="auth-panel p-4 p-lg-5 h-100">
                <span class="eyebrow mb-3">One-time setup</span>
                <h1 class="section-heading mb-3">Create the first administrator</h1>
                <p class="section-copy mb-4">Use this page once after deployment to initialize the application and create the first Tanhub administrator account. The route disables itself after success.</p>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="auth-card p-4 p-lg-5">
                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-danger" role="alert"><?= esc(session()->getFlashdata('error')) ?></div>
                <?php endif; ?>
                <?php $errors = session('errors') ?? []; ?>
                <form action="<?= esc(site_url('setup-admin-user')) ?>" method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="name">Administrator name</label>
                            <input class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>" id="name" name="name" type="text" value="<?= esc(old('name')) ?>" autocomplete="name" placeholder="Jane Doe">
                            <?php if (isset($errors['name'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['name']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">Administrator email</label>
                            <input class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>" id="email" name="email" type="email" value="<?= esc(old('email')) ?>" autocomplete="email" placeholder="admin@example.com">
                            <?php if (isset($errors['email'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['email']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password">Password</label>
                            <input class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>" id="password" name="password" type="password" autocomplete="new-password" placeholder="Minimum 8 characters">
                            <?php if (isset($errors['password'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['password']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password_confirm">Confirm password</label>
                            <input class="form-control<?= isset($errors['password_confirm']) ? ' is-invalid' : '' ?>" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" placeholder="Repeat password">
                            <?php if (isset($errors['password_confirm'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['password_confirm']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between mt-4">
                        <button class="btn btn-brand btn-lg px-4" type="submit">Complete setup</button>
                        <p class="mb-0 section-copy">After success, use <a href="<?= esc(site_url('login')) ?>">the login page</a> for future access.</p>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>