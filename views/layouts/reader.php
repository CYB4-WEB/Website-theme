<!DOCTYPE html>
<html lang="en" data-theme="<?= $user['dark_mode'] ?? 'auto' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= isset($seo) ? $seo->renderHead() : '' ?>
    <link rel="stylesheet" href="<?= $asset('css/main.css') ?>">
    <link rel="stylesheet" href="<?= $asset('css/dark-mode.css') ?>">
    <link rel="stylesheet" href="<?= $asset('css/reader.css') ?>">
    <link rel="stylesheet" href="<?= $asset('css/responsive.css') ?>">
</head>
<body class="reader-body">
    <?= $content ?>

    <script>
        const ALPHA = {
            ajaxUrl: '<?= $url('api') ?>',
            userId:  <?= json_encode($user['id'] ?? null) ?>,
            nonce:   '<?= \Alpha\Services\Security::createNonce('reader') ?>'
        };
    </script>
    <script src="<?= $asset('js/main.js') ?>"></script>
    <script src="<?= $asset('js/reader.js') ?>"></script>
    <script src="<?= $asset('js/chapter-protector.js') ?>"></script>
</body>
</html>
