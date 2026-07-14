<?php

use CodeIgniter\Pager\PagerRenderer;

/**
 * @var PagerRenderer $pager
 */
$pager->setSurroundCount(2);
?>

<nav aria-label="<?= lang('Pager.pageNavigation') ?>">
    <ul class="pagination justify-content-center gap-1 flex-wrap">
        <?php if ($pager->hasPrevious()) : ?>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getFirst() ?>" aria-label="<?= lang('Pager.first') ?>">
                    <?= lang('Pager.first') ?>
                </a>
            </li>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getPrevious() ?>" aria-label="<?= lang('Pager.previous') ?>">
                    <?= lang('Pager.previous') ?>
                </a>
            </li>
        <?php else : ?>
            <li class="page-item disabled" aria-disabled="true">
                <span class="page-link"><?= lang('Pager.first') ?></span>
            </li>
            <li class="page-item disabled" aria-disabled="true">
                <span class="page-link"><?= lang('Pager.previous') ?></span>
            </li>
        <?php endif ?>

        <?php foreach ($pager->links() as $link) : ?>
            <li class="page-item<?= $link['active'] ? ' active' : '' ?>">
                <?php if ($link['active']) : ?>
                    <span class="page-link" aria-current="page"><?= $link['title'] ?></span>
                <?php else : ?>
                    <a class="page-link" href="<?= $link['uri'] ?>"><?= $link['title'] ?></a>
                <?php endif ?>
            </li>
        <?php endforeach ?>

        <?php if ($pager->hasNext()) : ?>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getNext() ?>" aria-label="<?= lang('Pager.next') ?>">
                    <?= lang('Pager.next') ?>
                </a>
            </li>
            <li class="page-item">
                <a class="page-link" href="<?= $pager->getLast() ?>" aria-label="<?= lang('Pager.last') ?>">
                    <?= lang('Pager.last') ?>
                </a>
            </li>
        <?php else : ?>
            <li class="page-item disabled" aria-disabled="true">
                <span class="page-link"><?= lang('Pager.next') ?></span>
            </li>
            <li class="page-item disabled" aria-disabled="true">
                <span class="page-link"><?= lang('Pager.last') ?></span>
            </li>
        <?php endif ?>
    </ul>
</nav>
