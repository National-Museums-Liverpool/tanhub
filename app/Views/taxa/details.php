<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="page-section auth-page">
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-5">
            <div class="auth-panel p-4 p-lg-5 h-100">
                <span class="eyebrow mb-3">Taxonomy</span>
                <h1 class="section-heading mb-3">Taxon details</h1>
                <p class="section-copy mb-4">Most fields are read-only. Manager and admin users can edit rarity group name and taxon remarks. Admin users can also set blocking controls.</p>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="auth-card p-4 p-lg-5">
                <?php if (session()->getFlashdata('message')): ?>
                    <div class="alert alert-success" role="alert"><?= esc(session()->getFlashdata('message')) ?></div>
                <?php endif; ?>
                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-danger" role="alert"><?= esc(session()->getFlashdata('error')) ?></div>
                <?php endif; ?>

                <?php $errors = session('errors') ?? []; ?>

                <form action="<?= esc(site_url('taxa/' . $page['taxon']['id'])) ?>" method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="id">
                                ID
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="id" type="text" value="<?= esc((string) $page['taxon']['id']) ?>" disabled>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="taxon_identifier">
                                Taxon identifier
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="taxon_identifier" type="text" value="<?= esc((string) $page['taxon']['taxon_identifier']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="scientific_name_identifier">
                                Scientific name identifier
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="scientific_name_identifier" type="text" value="<?= esc((string) $page['taxon']['scientific_name_identifier']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="scientific_name">
                                Scientific name
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="scientific_name" type="text" value="<?= esc((string) $page['taxon']['scientific_name']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="vernacular_name">
                                Vernacular name
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="vernacular_name" type="text" value="<?= esc((string) ($page['taxon']['vernacular_name'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="taxon_rank_id">
                                Taxon rank
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="taxon_rank_id" type="text" value="<?= esc((string) ($page['referenceLabels']['taxon_rank_id'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="taxon_group_id">
                                Taxon group
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="taxon_group_id" type="text" value="<?= esc((string) ($page['referenceLabels']['taxon_group_id'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="recording_scheme_id">
                                Recording scheme
                                <span class="badge bg-secondary ms-2">Read-only</span>
                            </label>
                            <input class="form-control" id="recording_scheme_id" type="text" value="<?= esc((string) ($page['referenceLabels']['recording_scheme_id'] ?? '')) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="rarity_group_name">
                                Rarity group name
                                <?php if (! $page['canEditDetails']): ?>
                                    <span class="badge bg-secondary ms-2">Read-only</span>
                                <?php endif; ?>
                            </label>
                            <input class="form-control<?= isset($errors['rarity_group_name']) ? ' is-invalid' : '' ?>" id="rarity_group_name" name="rarity_group_name" type="text" maxlength="100" value="<?= esc(old('rarity_group_name', (string) ($page['taxon']['rarity_group_name'] ?? ''))) ?>" <?= $page['canEditDetails'] ? '' : 'disabled' ?>>
                            <?php if (isset($errors['rarity_group_name'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['rarity_group_name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <?php foreach ($page['classificationColumns'] as $column): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="<?= esc($column) ?>">
                                    <?= esc(ucwords(preg_replace(['/_/', '/ id$/'], [' ', ''], $column))) ?>
                                    <span class="badge bg-secondary ms-2">Read-only</span>
                                </label>
                                <input class="form-control" id="<?= esc($column) ?>" type="text" value="<?= esc((string) ($page['referenceLabels'][$column] ?? '')) ?>" disabled>
                            </div>
                        <?php endforeach; ?>

                        <div class="col-12">
                            <label class="form-label" for="taxon_remarks">
                                Remarks
                                <?php if (! $page['canEditDetails']): ?>
                                    <span class="badge bg-secondary ms-2">Read-only</span>
                                <?php endif; ?>
                            </label>
                            <textarea class="form-control<?= isset($errors['taxon_remarks']) ? ' is-invalid' : '' ?>" id="taxon_remarks" name="taxon_remarks" rows="3" <?= $page['canEditDetails'] ? '' : 'disabled' ?>><?= esc(old('taxon_remarks', (string) ($page['taxon']['taxon_remarks'] ?? ''))) ?></textarea>
                            <?php if (isset($errors['taxon_remarks'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['taxon_remarks']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="blocked">
                                Blocked
                                <?php if (! $page['canModerate']): ?>
                                    <span class="badge bg-secondary ms-2">Read-only</span>
                                <?php endif; ?>
                            </label>
                            <select class="form-select<?= isset($errors['blocked']) ? ' is-invalid' : '' ?>" id="blocked" name="blocked" <?= $page['canModerate'] ? '' : 'disabled' ?>>
                                <option value="0" <?= old('blocked', (string) ($page['taxon']['blocked'] ?? 0)) === '0' ? 'selected' : '' ?>>No</option>
                                <option value="1" <?= old('blocked', (string) ($page['taxon']['blocked'] ?? 0)) === '1' ? 'selected' : '' ?>>Yes</option>
                            </select>
                            <?php if (isset($errors['blocked'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['blocked']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="blocked_reason">
                                Blocked reason
                                <?php if (! $page['canModerate']): ?>
                                    <span class="badge bg-secondary ms-2">Read-only</span>
                                <?php endif; ?>
                            </label>
                            <textarea class="form-control<?= isset($errors['blocked_reason']) ? ' is-invalid' : '' ?>" id="blocked_reason" name="blocked_reason" rows="3" <?= $page['canModerate'] ? '' : 'disabled' ?>><?= esc(old('blocked_reason', (string) ($page['taxon']['blocked_reason'] ?? ''))) ?></textarea>
                            <?php if (isset($errors['blocked_reason'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($errors['blocked_reason']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($page['canEditDetails'] || $page['canModerate']): ?>
                        <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between mt-4">
                            <button class="btn btn-brand btn-lg px-4" type="submit">Save</button>
                            <a class="btn btn-outline-brand" href="<?= esc(site_url('taxa')) ?>">Back to list</a>
                        </div>
                    <?php else: ?>
                        <div class="mt-4">
                            <a class="btn btn-outline-brand" href="<?= esc(site_url('taxa')) ?>">Back to list</a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</section>

<section class="page-section pt-0">
    <div class="auth-card p-4 p-lg-5 mb-4">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
            <div>
                <span class="eyebrow">Taxonomy</span>
                <h2 class="section-heading mb-0">Taxon media</h2>
            </div>
        </div>

        <?php $mediaErrors = session('mediaErrors') ?? []; ?>
        <?php $mediaEditErrors = session('mediaEditErrors') ?? []; ?>

        <?php
        $mediaByUuid = [];
        foreach ($page['taxonMedia'] as $mediaItem) {
            $mediaByUuid[(string) $mediaItem['uuid']] = $mediaItem;
        }

        $defaultMediaUuid = $page['taxonMedia'][0]['uuid'] ?? '';
        $selectedMediaUuid = old('media_uuid', (string) (session('mediaEditSelectedUuid') ?? $defaultMediaUuid));
        $selectedMedia = $mediaByUuid[$selectedMediaUuid] ?? null;
        ?>

        <?php if ($page['canEditDetails']): ?>
            <form action="<?= esc(site_url('taxa/' . $page['taxon']['id'] . '/media')) ?>" method="post" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="media_file">Image file</label>
                        <input class="form-control<?= isset($mediaErrors['media_file']) ? ' is-invalid' : '' ?>" id="media_file" name="media_file" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
                        <?php if (isset($mediaErrors['media_file'])): ?>
                            <div class="invalid-feedback d-block"><?= esc($mediaErrors['media_file']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="alt_text">Alt text</label>
                        <input class="form-control<?= isset($mediaErrors['alt_text']) ? ' is-invalid' : '' ?>" id="alt_text" name="alt_text" type="text" maxlength="500" value="<?= esc(old('alt_text', '')) ?>">
                        <?php if (isset($mediaErrors['alt_text'])): ?>
                            <div class="invalid-feedback d-block"><?= esc($mediaErrors['alt_text']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="attribution">Attribution</label>
                        <input class="form-control<?= isset($mediaErrors['attribution']) ? ' is-invalid' : '' ?>" id="attribution" name="attribution" type="text" maxlength="255" value="<?= esc(old('attribution', '')) ?>">
                        <?php if (isset($mediaErrors['attribution'])): ?>
                            <div class="invalid-feedback d-block"><?= esc($mediaErrors['attribution']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="license">License</label>
                        <input class="form-control<?= isset($mediaErrors['license']) ? ' is-invalid' : '' ?>" id="license" name="license" type="text" maxlength="100" value="<?= esc(old('license', '')) ?>">
                        <?php if (isset($mediaErrors['license'])): ?>
                            <div class="invalid-feedback d-block"><?= esc($mediaErrors['license']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="sort_order">Sort order</label>
                        <input class="form-control<?= isset($mediaErrors['sort_order']) ? ' is-invalid' : '' ?>" id="sort_order" name="sort_order" type="number" min="0" step="1" value="<?= esc(old('sort_order', '0')) ?>">
                        <?php if (isset($mediaErrors['sort_order'])): ?>
                            <div class="invalid-feedback d-block"><?= esc($mediaErrors['sort_order']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="is_primary">Primary image</label>
                        <select class="form-select<?= isset($mediaErrors['is_primary']) ? ' is-invalid' : '' ?>" id="is_primary" name="is_primary">
                            <option value="0" <?= old('is_primary', '0') === '0' ? 'selected' : '' ?>>No</option>
                            <option value="1" <?= old('is_primary', '0') === '1' ? 'selected' : '' ?>>Yes</option>
                        </select>
                        <?php if (isset($mediaErrors['is_primary'])): ?>
                            <div class="invalid-feedback d-block"><?= esc($mediaErrors['is_primary']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="caption">Caption</label>
                        <textarea class="form-control<?= isset($mediaErrors['caption']) ? ' is-invalid' : '' ?>" id="caption" name="caption" rows="3"><?= esc(old('caption', '')) ?></textarea>
                        <?php if (isset($mediaErrors['caption'])): ?>
                            <div class="invalid-feedback d-block"><?= esc($mediaErrors['caption']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between mt-4">
                    <button class="btn btn-brand" type="submit">Upload media</button>
                    <span class="text-muted small">Allowed: JPEG, PNG, GIF, WEBP.</span>
                </div>
            </form>

            <?php if ($page['taxonMedia'] !== []): ?>
                <hr class="my-4">
                <h3 class="h5 mb-3">Edit existing media metadata</h3>
                <form action="<?= esc(site_url('taxa/' . $page['taxon']['id'] . '/media/update')) ?>" method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="media_uuid">Select media file</label>
                            <select class="form-select<?= isset($mediaEditErrors['media_uuid']) ? ' is-invalid' : '' ?>" id="media_uuid" name="media_uuid">
                                <?php foreach ($page['taxonMedia'] as $media): ?>
                                    <?php
                                    $mediaUuid = (string) $media['uuid'];
                                    $filename = (string) $media['original_filename'];
                                    ?>
                                    <option
                                        value="<?= esc($mediaUuid) ?>"
                                        data-alt-text="<?= esc((string) ($media['alt_text'] ?? '')) ?>"
                                        data-caption="<?= esc((string) ($media['caption'] ?? '')) ?>"
                                        data-attribution="<?= esc((string) ($media['attribution'] ?? '')) ?>"
                                        data-license="<?= esc((string) ($media['license'] ?? '')) ?>"
                                        data-sort-order="<?= esc((string) ($media['sort_order'] ?? 0)) ?>"
                                        data-is-primary="<?= ! empty($media['is_primary']) ? '1' : '0' ?>"
                                        <?= $selectedMediaUuid === $mediaUuid ? 'selected' : '' ?>
                                    >
                                        <?= esc($filename) ?> (<?= esc($mediaUuid) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($mediaEditErrors['media_uuid'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($mediaEditErrors['media_uuid']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="edit_alt_text">Alt text</label>
                            <input class="form-control<?= isset($mediaEditErrors['edit_alt_text']) ? ' is-invalid' : '' ?>" id="edit_alt_text" name="edit_alt_text" type="text" maxlength="500" value="<?= esc(old('edit_alt_text', (string) ($selectedMedia['alt_text'] ?? ''))) ?>">
                            <?php if (isset($mediaEditErrors['edit_alt_text'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($mediaEditErrors['edit_alt_text']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="edit_attribution">Attribution</label>
                            <input class="form-control<?= isset($mediaEditErrors['edit_attribution']) ? ' is-invalid' : '' ?>" id="edit_attribution" name="edit_attribution" type="text" maxlength="255" value="<?= esc(old('edit_attribution', (string) ($selectedMedia['attribution'] ?? ''))) ?>">
                            <?php if (isset($mediaEditErrors['edit_attribution'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($mediaEditErrors['edit_attribution']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="edit_license">License</label>
                            <input class="form-control<?= isset($mediaEditErrors['edit_license']) ? ' is-invalid' : '' ?>" id="edit_license" name="edit_license" type="text" maxlength="100" value="<?= esc(old('edit_license', (string) ($selectedMedia['license'] ?? ''))) ?>">
                            <?php if (isset($mediaEditErrors['edit_license'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($mediaEditErrors['edit_license']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="edit_sort_order">Sort order</label>
                            <input class="form-control<?= isset($mediaEditErrors['edit_sort_order']) ? ' is-invalid' : '' ?>" id="edit_sort_order" name="edit_sort_order" type="number" min="0" step="1" value="<?= esc(old('edit_sort_order', (string) ($selectedMedia['sort_order'] ?? 0))) ?>">
                            <?php if (isset($mediaEditErrors['edit_sort_order'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($mediaEditErrors['edit_sort_order']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="edit_is_primary">Primary image</label>
                            <?php $editIsPrimary = old('edit_is_primary', ! empty($selectedMedia['is_primary']) ? '1' : '0'); ?>
                            <select class="form-select<?= isset($mediaEditErrors['edit_is_primary']) ? ' is-invalid' : '' ?>" id="edit_is_primary" name="edit_is_primary">
                                <option value="0" <?= $editIsPrimary === '0' ? 'selected' : '' ?>>No</option>
                                <option value="1" <?= $editIsPrimary === '1' ? 'selected' : '' ?>>Yes</option>
                            </select>
                            <?php if (isset($mediaEditErrors['edit_is_primary'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($mediaEditErrors['edit_is_primary']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="edit_caption">Caption</label>
                            <textarea class="form-control<?= isset($mediaEditErrors['edit_caption']) ? ' is-invalid' : '' ?>" id="edit_caption" name="edit_caption" rows="3"><?= esc(old('edit_caption', (string) ($selectedMedia['caption'] ?? ''))) ?></textarea>
                            <?php if (isset($mediaEditErrors['edit_caption'])): ?>
                                <div class="invalid-feedback d-block"><?= esc($mediaEditErrors['edit_caption']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between mt-4">
                        <button class="btn btn-outline-brand" type="submit">Update metadata</button>
                    </div>
                </form>

                <script>
                    (() => {
                        const select = document.getElementById('media_uuid');
                        if (!select) {
                            return;
                        }

                        const setValue = (id, value) => {
                            const field = document.getElementById(id);
                            if (field) {
                                field.value = value;
                            }
                        };

                        select.addEventListener('change', () => {
                            const option = select.options[select.selectedIndex];
                            if (!option) {
                                return;
                            }

                            setValue('edit_alt_text', option.dataset.altText || '');
                            setValue('edit_caption', option.dataset.caption || '');
                            setValue('edit_attribution', option.dataset.attribution || '');
                            setValue('edit_license', option.dataset.license || '');
                            setValue('edit_sort_order', option.dataset.sortOrder || '0');
                            setValue('edit_is_primary', option.dataset.isPrimary || '0');
                        });
                    })();
                </script>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($page['taxonMedia'] === []): ?>
            <p class="text-muted mb-0 mt-4">No media uploaded for this taxon.</p>
        <?php else: ?>
            <div class="taxon-media-grid mt-4">
                <?php foreach ($page['taxonMedia'] as $media): ?>
                    <?php
                    $thumbUrl = $media['variants']['thumbnail']['url'] ?? $media['url'];
                    $dimension = $media['width'] !== null && $media['height'] !== null
                        ? $media['width'] . ' x ' . $media['height']
                        : 'Unknown dimensions';
                    ?>
                    <article class="taxon-media-card">
                        <a href="<?= esc((string) $media['url']) ?>" target="_blank" rel="noopener noreferrer" class="taxon-media-image-link">
                            <img src="<?= esc((string) $thumbUrl) ?>" class="taxon-media-image" alt="<?= esc((string) ($media['alt_text'] ?? $media['original_filename'])) ?>">
                        </a>
                        <div class="taxon-media-body">
                            <p class="taxon-media-title mb-1"><?= esc((string) $media['original_filename']) ?></p>
                            <p class="taxon-media-meta mb-1"><?= esc((string) $dimension) ?>, <?= esc((string) $media['bytes']) ?> bytes</p>
                            <?php if (! empty($media['caption'])): ?>
                                <p class="taxon-media-caption mb-1"><?= esc((string) $media['caption']) ?></p>
                            <?php endif; ?>
                            <p class="taxon-media-meta mb-0">Primary: <?= ! empty($media['is_primary']) ? 'Yes' : 'No' ?> | Sort: <?= esc((string) $media['sort_order']) ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="auth-card p-4 p-lg-5">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
            <div>
                <span class="eyebrow">Taxonomy</span>
                <h2 class="section-heading mb-0">Associated taxon names</h2>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Given name identifier</th>
                    <th scope="col">Accepted</th>
                    <th scope="col">Scientific</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($page['taxonNames'] === []): ?>
                    <tr>
                        <td colspan="4" class="text-muted">No taxon names found for this taxon.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($page['taxonNames'] as $taxonName): ?>
                        <tr>
                            <td><?= esc((string) $taxonName['name']) ?></td>
                            <td><?= esc((string) $taxonName['given_name_identifier']) ?></td>
                            <td><?= ! empty($taxonName['accepted']) ? 'Yes' : 'No' ?></td>
                            <td><?= ! empty($taxonName['scientific']) ? 'Yes' : 'No' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
