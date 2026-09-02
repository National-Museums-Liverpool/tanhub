<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section auth-page">
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-5">
            <div class="auth-panel p-4 p-lg-5 h-100">
                <span class="eyebrow mb-3">Account</span>
                <h1 class="section-heading mb-3">My account</h1>
                <p class="section-copy mb-4">Update your name, email address, or password.</p>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="auth-card p-4 p-lg-5">
                <?php if (session()->getFlashdata('message')): ?>
                    <div class="alert alert-success" role="alert"><?= esc(session()->getFlashdata('message')) ?></div>
                <?php endif; ?>
                <?php if ($page['magicLogin'] ?? false): ?>
                    <div class="alert alert-info" role="status">You signed in with an email link. Set a new password below.</div>
                <?php endif; ?>
                <?php $errors = session('errors') ?? []; ?>
                <form action="<?= esc(site_url('account')) ?>" method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <?php foreach (['first_name' => 'First name', 'last_name' => 'Last name'] as $field => $label): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="<?= esc($field) ?>"><?= esc($label) ?></label>
                                <input class="form-control<?= isset($errors[$field]) ? ' is-invalid' : '' ?>" id="<?= esc($field) ?>" name="<?= esc($field) ?>" type="text" value="<?= esc(old($field, (string) ($page['user']->{$field} ?? ''))) ?>" autocomplete="<?= $field === 'first_name' ? 'given-name' : 'family-name' ?>">
                                <?php if (isset($errors[$field])): ?><div class="invalid-feedback d-block"><?= esc($errors[$field]) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="col-12">
                            <label class="form-label" for="email">Email address</label>
                            <input class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>" id="email" name="email" type="email" value="<?= esc(old('email', (string) $page['user']->getEmail())) ?>" autocomplete="email">
                            <?php if (isset($errors['email'])): ?><div class="invalid-feedback d-block"><?= esc($errors['email']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-12"><hr><h2 class="h5">Change password</h2></div>
                        <?php foreach (['current_password' => 'Current password', 'new_password' => 'New password', 'new_password_confirm' => 'Confirm new password'] as $field => $label): ?>
                            <div class="col-md-4">
                                <label class="form-label" for="<?= esc($field) ?>"><?= esc($label) ?></label>
                                <input class="form-control<?= isset($errors[$field]) ? ' is-invalid' : '' ?>" id="<?= esc($field) ?>" name="<?= esc($field) ?>" type="password" autocomplete="<?= $field === 'current_password' ? 'current-password' : 'new-password' ?>"<?= $field === 'current_password' && ($page['magicLogin'] ?? false) ? ' disabled' : '' ?>>
                                <?php if (isset($errors[$field])): ?><div class="invalid-feedback d-block"><?= esc($errors[$field]) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-brand btn-lg px-4 mt-4" type="submit">Save changes</button>
                </form>
            </div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>