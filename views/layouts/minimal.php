<!DOCTYPE html>
<html lang="en" data-theme="auto">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= isset($seo) ? $seo->renderHead() : '' ?>
    <link rel="stylesheet" href="<?= $asset('css/main.css') ?>">
    <link rel="stylesheet" href="<?= $asset('css/dark-mode.css') ?>">
</head>
<body class="auth-body">
    <div class="auth-container">
        <?php if (!empty($flash['success'])): ?>
            <div class="alert alert--success"><?= $e($flash['success']) ?></div>
        <?php endif; ?>
        <?php if (!empty($flash['error'])): ?>
            <div class="alert alert--error"><?= $e($flash['error']) ?></div>
        <?php endif; ?>
        <?= $content ?>
    </div>
    <script src="<?= $asset('js/main.js') ?>"></script>
</body>
</html>
