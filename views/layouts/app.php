<!DOCTYPE html>
<html lang="en" data-theme="<?= $user['dark_mode'] ?? 'auto' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= isset($seo) ? $seo->renderHead() : '' ?>
    <link rel="stylesheet" href="<?= $asset('css/main.css') ?>">
    <link rel="stylesheet" href="<?= $asset('css/dark-mode.css') ?>">
    <link rel="stylesheet" href="<?= $asset('css/glassmorphism.css') ?>">
    <link rel="stylesheet" href="<?= $asset('css/responsive.css') ?>">
</head>
<body>

<?= include_once ALPHA_ROOT . '/views/partials/header.php' ?: '' ?>

<main class="site-main">
    <?php if (!empty($flash['success'])): ?>
        <div class="alert alert--success"><?= $e($flash['success']) ?></div>
    <?php endif; ?>
    <?php if (!empty($flash['error'])): ?>
        <div class="alert alert--error"><?= $e($flash['error']) ?></div>
    <?php endif; ?>

    <?= $content ?>
</main>

<?= include_once ALPHA_ROOT . '/views/partials/footer.php' ?: '' ?>

<script src="<?= $asset('js/main.js') ?>"></script>
<script src="<?= $asset('js/dark-toggle.js') ?>"></script>
<script src="<?= $asset('js/ajax-search.js') ?>"></script>
<script src="<?= $asset('js/bookmark.js') ?>"></script>
</body>
</html>
