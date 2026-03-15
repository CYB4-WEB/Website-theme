<?php $appName = \Alpha\Core\Config::get('APP_NAME', 'Project Alpha'); ?>
<footer class="site-footer">
    <div class="container">
        <div class="site-footer__grid">
            <div class="site-footer__brand">
                <h3><?= $e($appName) ?></h3>
                <p>Read manga, novels, and watch anime for free.</p>
            </div>
            <div class="site-footer__links">
                <h4>Browse</h4>
                <ul>
                    <li><a href="<?= $url('manga') ?>">All Manga</a></li>
                    <li><a href="<?= $url('manga?type=novel') ?>">Novels</a></li>
                    <li><a href="<?= $url('manga?type=video') ?>">Videos</a></li>
                    <li><a href="<?= $url('manga?sort=popular') ?>">Popular</a></li>
                </ul>
            </div>
            <div class="site-footer__links">
                <h4>Account</h4>
                <ul>
                    <li><a href="<?= $url('login') ?>">Login</a></li>
                    <li><a href="<?= $url('register') ?>">Register</a></li>
                    <li><a href="<?= $url('profile') ?>">Profile</a></li>
                    <li><a href="<?= $url('manga/upload') ?>">Upload</a></li>
                </ul>
            </div>
            <div class="site-footer__links">
                <h4>Info</h4>
                <ul>
                    <li><a href="<?= $url('sitemap.xml') ?>">Sitemap</a></li>
                    <li><a href="<?= $url('api/search?q=') ?>">Search API</a></li>
                </ul>
            </div>
        </div>
        <div class="site-footer__bottom">
            <p>&copy; <?= date('Y') ?> <?= $e($appName) ?>. All rights reserved.</p>
        </div>
    </div>
</footer>

<div id="back-to-top" class="back-to-top" title="Back to top" aria-label="Back to top">&#8679;</div>
