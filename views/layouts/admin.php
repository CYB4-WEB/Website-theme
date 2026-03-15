<!DOCTYPE html>
<html lang="en" data-theme="auto">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — <?= $e(\Alpha\Core\Config::get('APP_NAME', 'Project Alpha')) ?></title>
    <link rel="stylesheet" href="<?= $asset('css/main.css') ?>">
    <link rel="stylesheet" href="<?= $asset('css/dark-mode.css') ?>">
    <link rel="stylesheet" href="<?= $asset('css/admin.css') ?>">
</head>
<body class="admin-body">

<div class="admin-wrapper">
    <!-- Sidebar -->
    <aside class="admin-sidebar">
        <div class="admin-sidebar__logo">
            <a href="<?= $url('admin') ?>"><?= $e(\Alpha\Core\Config::get('APP_NAME', 'Alpha')) ?></a>
        </div>
        <nav class="admin-nav">
            <a href="<?= $url('admin') ?>"          class="admin-nav__link">Dashboard</a>
            <a href="<?= $url('admin/manga') ?>"    class="admin-nav__link">Manga</a>
            <a href="<?= $url('admin/chapters') ?>" class="admin-nav__link">Chapters</a>
            <a href="<?= $url('admin/users') ?>"    class="admin-nav__link">Users</a>
            <a href="<?= $url('admin/withdrawals') ?>" class="admin-nav__link">Withdrawals</a>
            <a href="<?= $url('admin/settings') ?>" class="admin-nav__link">Settings</a>
            <hr>
            <a href="<?= $url('') ?>"               class="admin-nav__link">← View Site</a>
        </nav>
    </aside>

    <!-- Content -->
    <div class="admin-content">
        <header class="admin-topbar">
            <span>Welcome, <?= $e($user['display_name'] ?? 'Admin') ?></span>
            <a href="<?= $url('logout') ?>" class="btn btn--sm">Logout</a>
        </header>

        <?php if (!empty($flash['success'])): ?>
            <div class="alert alert--success"><?= $e($flash['success']) ?></div>
        <?php endif; ?>
        <?php if (!empty($flash['error'])): ?>
            <div class="alert alert--error"><?= $e($flash['error']) ?></div>
        <?php endif; ?>

        <?= $content ?>
    </div>
</div>

<script src="<?= $asset('js/main.js') ?>"></script>
</body>
</html>
