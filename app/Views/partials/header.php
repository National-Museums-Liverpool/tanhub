<?php $currentPath = trim(service('uri')->getPath(), '/'); ?>
<nav class="navbar navbar-expand-lg navbar-shell px-3 px-lg-4 py-3">
    <div class="container-fluid px-0">
        <a class="navbar-brand fw-semibold" href="<?= esc(site_url('/')) ?>"><?= esc($page['siteName']) ?></a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <?php foreach ($page['navItems'] as $item): ?>
                    <?php
                    $itemPath = trim((string) ($item['path'] ?? ''), '/');
                    $isActive = isset($item['path']) && $currentPath === $itemPath;
                    $style = $item['style'] ?? 'link';
                    $target = ! empty($item['external']) ? ' target="_blank" rel="noreferrer"' : '';
                    ?>
                    <li class="nav-item">
                        <?php if ($style === 'button'): ?>
                            <a class="btn btn-sm btn-brand ms-lg-2" href="<?= esc($item['url']) ?>"><?= esc($item['label']) ?></a>
                        <?php elseif ($style === 'outline'): ?>
                            <a class="btn btn-sm btn-outline-brand ms-lg-2" href="<?= esc($item['url']) ?>"<?= $target ?>><?= esc($item['label']) ?></a>
                        <?php else: ?>
                            <a class="nav-link<?= $isActive ? ' active fw-semibold' : '' ?>" href="<?= esc($item['url']) ?>"><?= esc($item['label']) ?></a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</nav>