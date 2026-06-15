<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
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


<?= $this->endSection() ?>