<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($page['pageTitle'] === $page['siteName'] ? $page['siteName'] : $page['pageTitle'] . ' | ' . $page['siteName']) ?></title>
    <meta name="description" content="<?= esc($page['metaDescription']) ?>">
    <link rel="icon" href="<?= esc(base_url('favicon.ico')) ?>" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= esc(base_url('css/site.css')) ?>" rel="stylesheet">
    <?= $this->renderSection('head') ?>
</head>
<body class="<?= esc($page['bodyClass']) ?>">
<div class="container pb-5">
    <?= $this->include('partials/header') ?>
    <main class="app-main">
        <?= $this->renderSection('content') ?>
    </main>
    <?= $this->include('partials/footer') ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>