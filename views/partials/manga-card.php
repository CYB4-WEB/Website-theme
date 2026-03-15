<?php
/**
 * Manga grid card partial.
 * Variables: $manga (array)
 */
$cover  = $manga['cover']  ?? '';
$title  = $manga['title']  ?? '';
$slug   = $manga['slug']   ?? '';
$type   = $manga['type']   ?? 'manga';
$status = $manga['status'] ?? 'ongoing';
$badge  = $manga['badge']  ?? '';
$rating = number_format((float)($manga['rating_avg'] ?? 0), 1);
$chCount = (int)($manga['chapter_count'] ?? 0);
?>
<article class="manga-card">
    <a href="<?= $url("manga/{$slug}") ?>" class="manga-card__cover-link" tabindex="-1" aria-hidden="true">
        <?php if ($cover): ?>
            <img src="<?= $e($cover) ?>" alt="<?= $e($title) ?>" class="manga-card__cover" loading="lazy" width="160" height="220">
        <?php else: ?>
            <div class="manga-card__cover manga-card__cover--placeholder"></div>
        <?php endif; ?>

        <?php if ($badge): ?>
            <span class="manga-card__badge manga-card__badge--<?= $e($badge) ?>"><?= strtoupper($e($badge)) ?></span>
        <?php endif; ?>

        <span class="manga-card__type manga-card__type--<?= $e($type) ?>"><?= strtoupper($e($type)) ?></span>
    </a>

    <div class="manga-card__body">
        <h3 class="manga-card__title">
            <a href="<?= $url("manga/{$slug}") ?>"><?= $e($title) ?></a>
        </h3>

        <div class="manga-card__meta">
            <?php if ($rating > 0): ?>
            <span class="manga-card__rating" title="Rating: <?= $e($rating) ?>">&#9733; <?= $e($rating) ?></span>
            <?php endif; ?>
            <span class="manga-card__chapters"><?= $chCount ?> ch.</span>
            <span class="manga-card__status manga-card__status--<?= $e($status) ?>"><?= ucfirst($e($status)) ?></span>
        </div>
    </div>
</article>
