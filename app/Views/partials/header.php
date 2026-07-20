<?php $currentPath = trim(service('uri')->getPath(), '/'); ?>
<nav class="navbar navbar-expand-lg navbar-shell px-3 px-lg-4 py-3">
    <div class="container-fluid px-0">
        <a class="navbar-brand d-inline-flex align-items-center gap-2 fw-semibold" href="<?= esc(site_url('/')) ?>">
            <img class="site-logo" src="<?= base_url('images/logo.svg') ?>" alt="">
            <span class="visually-hidden"><?= esc($page['siteName']) ?></span>
        </a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <?php foreach ($page['navItems'] as $item): ?>
                    <?php
                    $itemPath = trim((string) ($item['path'] ?? ''), '/');
                    $style = $item['style'] ?? 'link';
                    $target = ! empty($item['external']) ? ' target="_blank" rel="noreferrer"' : '';
                    $children = is_array($item['items'] ?? null) ? $item['items'] : [];
                    $isActive = isset($item['path']) && $currentPath === $itemPath;

                    if ($children !== []) {
                        foreach ($children as $child) {
                            $childPath = trim((string) ($child['path'] ?? ''), '/');

                            if ($childPath !== '' && $currentPath === $childPath) {
                                $isActive = true;
                                break;
                            }
                        }
                    }
                    ?>
                    <li class="nav-item<?= $style === 'dropdown' ? ' dropdown' : '' ?>">
                        <?php if ($style === 'button'): ?>
                            <a class="btn btn-sm btn-brand ms-lg-2" href="<?= esc($item['url']) ?>"><?= esc($item['label']) ?></a>
                        <?php elseif ($style === 'dropdown'): ?>
                            <a class="nav-link dropdown-toggle<?= $isActive ? ' active fw-semibold' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <?= esc($item['label']) ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-lg-end">
                                <?php foreach ($children as $child): ?>
                                    <?php
                                    $childPath = trim((string) ($child['path'] ?? ''), '/');
                                    $childActive = $childPath !== '' && $currentPath === $childPath;
                                    $childTarget = ! empty($child['external']) ? ' target="_blank" rel="noreferrer"' : '';
                                    ?>
                                    <li>
                                        <a class="dropdown-item<?= $childActive ? ' active' : '' ?>" href="<?= esc((string) ($child['url'] ?? '#')) ?>"<?= $childTarget ?>><?= esc((string) ($child['label'] ?? '')) ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
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