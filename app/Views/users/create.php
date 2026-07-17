<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section auth-page">
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-5">
            <div class="auth-panel p-4 p-lg-5 h-100">
                <span class="eyebrow mb-3">Administration</span>
                <h1 class="section-heading mb-3">Create user</h1>
                <p class="section-copy mb-4">Create a new account for a staff member or system user.</p>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="auth-card p-4 p-lg-5">
                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-danger" role="alert"><?= esc(session()->getFlashdata('error')) ?></div>
                <?php endif; ?>

                <?php $errors = session('errors') ?? []; ?>

                <form action="<?= esc(site_url('users/create')) ?>" method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="username">Username</label>
                            <input class="form-control<?= isset($errors['username']) ? ' is-invalid' : '' ?>" id="username" name="username" type="text" maxlength="30" value="<?= esc(old('username')) ?>" autocomplete="username">
                            <?php if (isset($errors['username'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['username']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">Email address</label>
                            <input class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>" id="email" name="email" type="email" maxlength="254" value="<?= esc(old('email')) ?>" autocomplete="email">
                            <?php if (isset($errors['email'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['email']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="active">Active</label>
                            <select class="form-select<?= isset($errors['active']) ? ' is-invalid' : '' ?>" id="active" name="active">
                                <option value="1" <?= old('active', '1') === '1' ? 'selected' : '' ?>>Yes</option>
                                <option value="0" <?= old('active', '1') === '0' ? 'selected' : '' ?>>No</option>
                            </select>
                            <?php if (isset($errors['active'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['active']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label d-block">Groups</label>
                            <?php $selectedGroups = old('groups'); ?>
                            <?php if (! is_array($selectedGroups)): ?>
                                <?php $selectedGroups = ['user']; ?>
                            <?php endif; ?>
                            <?php foreach ($page['groupOptions'] as $group): ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" id="group-<?= esc($group) ?>" name="groups[]" type="checkbox" value="<?= esc($group) ?>" <?= in_array($group, $selectedGroups, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="group-<?= esc($group) ?>"><?= esc(ucfirst($group)) ?></label>
                                </div>
                            <?php endforeach; ?>
                            <?php if (isset($errors['groups'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['groups']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password">Password</label>
                            <input class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>" id="password" name="password" type="password" autocomplete="new-password">
                            <?php if (isset($errors['password'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['password']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password_confirm">Confirm password</label>
                            <input class="form-control<?= isset($errors['password_confirm']) ? ' is-invalid' : '' ?>" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password">
                            <?php if (isset($errors['password_confirm'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['password_confirm']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between mt-4">
                        <button class="btn btn-brand btn-lg px-4" type="submit">Create user</button>
                        <a class="btn btn-outline-brand" href="<?= esc(site_url('users')) ?>">Back to list</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
