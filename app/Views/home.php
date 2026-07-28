<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<?php if (! empty($page['migrationWarningMessage'])): ?>
    <div class="alert alert-warning" role="alert">
        <?= esc((string) $page['migrationWarningMessage']) ?>
        <a href="<?= esc((string) $page['migrationWarningUrl']) ?>">Run updates</a>.
    </div>
<?php endif; ?>
<section class="hero" id="top">
    <div class="hero-card p-4 p-lg-5">
        <div class="row align-items-center g-4 g-lg-5">
            <div class="col-lg-7 position-relative">
                <span class="eyebrow mb-3"><?= esc($page['tagline']) ?></span>
                <h1 class="display-4 mb-4"><?= esc($page['heroTitle']) ?></h1>
                <p class="hero-copy mb-4 pe-lg-4"><?= esc($page['heroCopy']) ?></p>
            </div>
            <div class="col-lg-5">
                <div class="row g-3">
                    <?php foreach ($page['features'] as $stat): ?>
                        <div class="col-12">
                            <div class="stat-card p-4">
                                <div class="stat-value mb-1"><?= esc($stat['value']) ?></div>
                                <p class="mb-0 section-copy"><?= esc($stat['label']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="mt-4" aria-labelledby="home-counts-title">
    <div class="hero-card p-4 p-lg-5">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <h2 class="h4 mb-0" id="home-counts-title">Database at a glance</h2>
            <p class="mb-0 section-copy">Active records only</p>
        </div>
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="stat-card p-4 h-100">
                    <div class="stat-value mb-1"><?= esc(number_format((int) ($page['homeCounts']['occurrences'] ?? 0))) ?></div>
                    <p class="mb-0 section-copy">Occurrences</p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="stat-card p-4 h-100">
                    <div class="stat-value mb-1"><?= esc(number_format((int) ($page['homeCounts']['taxa'] ?? 0))) ?></div>
                    <p class="mb-0 section-copy">Taxa</p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="stat-card p-4 h-100">
                    <div class="stat-value mb-1"><?= esc(number_format((int) ($page['homeCounts']['geographic_regions'] ?? 0))) ?></div>
                    <p class="mb-0 section-copy">Geographic regions</p>
                </div>
            </div>
        </div>
    </div>
</section>


<?= $this->endSection() ?>
