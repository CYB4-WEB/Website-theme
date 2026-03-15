<?php
/**
 * Homepage
 * Variables: $latest, $popular, $new, $updates
 */
?>

<!-- Hero / Latest Updates -->
<section class="hero-section">
    <div class="container">
        <h2 class="section-title">Latest Updates</h2>
        <div class="manga-grid manga-grid--updates">
            <?php foreach ($updates as $ch): ?>
            <a href="<?= $url("manga/{$ch['manga_slug']}/chapter/{$ch['chapter_number']}") ?>" class="update-card">
                <img src="<?= $e($ch['manga_cover'] ?? '') ?>" alt="<?= $e($ch['manga_title']) ?>" class="update-card__cover" loading="lazy">
                <div class="update-card__info">
                    <span class="update-card__title"><?= $e($ch['manga_title']) ?></span>
                    <span class="update-card__chapter">Ch. <?= $e($ch['chapter_number']) ?></span>
                    <time class="update-card__date"><?= date('M j', strtotime($ch['created_at'])) ?></time>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Popular this week -->
<section class="popular-section">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">Popular This Week</h2>
            <a href="<?= $url('manga?sort=popular') ?>" class="section-more">See all &rarr;</a>
        </div>
        <div class="manga-grid manga-grid--6col">
            <?php foreach ($popular as $manga): ?>
                <?php include ALPHA_ROOT . '/views/partials/manga-card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- New Additions -->
<section class="new-section">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">New Additions</h2>
            <a href="<?= $url('manga?sort=new') ?>" class="section-more">See all &rarr;</a>
        </div>
        <div class="manga-grid manga-grid--6col">
            <?php foreach ($new as $manga): ?>
                <?php include ALPHA_ROOT . '/views/partials/manga-card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Latest Updated series -->
<section class="latest-section">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">Recently Updated</h2>
            <a href="<?= $url('manga') ?>" class="section-more">Browse all &rarr;</a>
        </div>
        <div class="manga-grid manga-grid--6col">
            <?php foreach ($latest as $manga): ?>
                <?php include ALPHA_ROOT . '/views/partials/manga-card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
