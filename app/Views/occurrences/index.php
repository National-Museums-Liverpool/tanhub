<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<?= $this->include('search/index') ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('js/taxon-search.js')) ?>" defer></script>
<script src="<?= esc(base_url('js/search-filters.js')) ?>" defer></script>
<?= $this->endSection() ?>
