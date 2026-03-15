<?php
$appName = \Alpha\Core\Config::get('APP_NAME', 'Project Alpha');
$baseUrl = rtrim(\Alpha\Core\Config::get('APP_URL', ''), '/');
?>
<header class="site-header" id="site-header">
    <div class="container">
        <div class="site-header__inner">
            <!-- Logo -->
            <div class="site-header__logo">
                <a href="<?= $url('') ?>" class="site-logo">
                    <?= $e($appName) ?>
                </a>
            </div>

            <!-- Nav -->
            <nav class="site-nav" id="site-nav" aria-label="Main navigation">
                <ul class="site-nav__list">
                    <li><a href="<?= $url('') ?>"     class="site-nav__link">Home</a></li>
                    <li><a href="<?= $url('manga') ?>" class="site-nav__link">Browse</a></li>
                    <?php if ($user): ?>
                    <li class="has-dropdown">
                        <a href="<?= $url('profile') ?>" class="site-nav__link">
                            <?php if ($user['avatar']): ?>
                                <img src="<?= $e($user['avatar']) ?>" alt="" class="avatar avatar--xs">
                            <?php endif; ?>
                            <?= $e($user['display_name']) ?>
                        </a>
                        <ul class="dropdown">
                            <li><a href="<?= $url('profile') ?>">My Profile</a></li>
                            <li><a href="<?= $url('profile/bookmarks') ?>">Bookmarks</a></li>
                            <li><a href="<?= $url('profile/history') ?>">History</a></li>
                            <?php if (\Alpha\Services\Auth::can('upload_manga')): ?>
                            <li><a href="<?= $url('manga/upload') ?>">Upload Manga</a></li>
                            <?php endif; ?>
                            <?php if (\Alpha\Services\Auth::isAdmin()): ?>
                            <li><a href="<?= $url('admin') ?>">Admin Panel</a></li>
                            <?php endif; ?>
                            <li><a href="<?= $url('logout') ?>">Logout</a></li>
                        </ul>
                    </li>
                    <?php else: ?>
                    <li><a href="<?= $url('login') ?>"    class="site-nav__link btn btn--sm">Login</a></li>
                    <li><a href="<?= $url('register') ?>" class="site-nav__link btn btn--sm btn--outline">Register</a></li>
                    <?php endif; ?>
                </ul>
            </nav>

            <!-- Search bar -->
            <div class="site-search">
                <form action="<?= $url('manga') ?>" method="get" class="search-form search-form--compact">
                    <input type="search" name="q" class="search-input" placeholder="Search manga…" autocomplete="off" id="live-search-input">
                    <button type="submit" class="search-btn" aria-label="Search">&#128269;</button>
                    <div class="search-autocomplete" id="search-autocomplete" hidden></div>
                </form>
            </div>

            <!-- Dark mode toggle -->
            <button class="dark-toggle" id="dark-toggle" aria-label="Toggle dark mode" title="Toggle theme">
                <span class="dark-toggle__icon">&#9788;</span>
            </button>

            <!-- Hamburger -->
            <button class="hamburger" id="hamburger" aria-label="Open menu" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
</header>
