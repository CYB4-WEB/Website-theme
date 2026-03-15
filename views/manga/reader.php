<?php
/**
 * Image-based chapter reader.
 * Variables: $manga, $chapter, $prev, $next
 */
$images = $chapter['chapter_data'] ?? [];
if (!is_array($images)) { $images = []; }
?>
<div class="reader-container" id="reader" data-chapter-id="<?= (int)$chapter['id'] ?>" data-manga-id="<?= (int)$manga['id'] ?>">

    <!-- Reader toolbar -->
    <div class="reader-toolbar" id="reader-toolbar">
        <div class="reader-toolbar__left">
            <a href="<?= $url("manga/{$manga['slug']}") ?>" class="reader-back" title="Back to manga">
                &#8592; <?= $e($manga['title']) ?>
            </a>
        </div>
        <div class="reader-toolbar__center">
            <?php if ($prev): ?>
            <a href="<?= $url("manga/{$manga['slug']}/chapter/{$prev['chapter_number']}") ?>" class="reader-nav__prev btn btn--sm">
                &laquo; Ch. <?= $e($prev['chapter_number']) ?>
            </a>
            <?php endif; ?>

            <span class="reader-chapter-num">Chapter <?= $e($chapter['chapter_number']) ?></span>
            <?php if (!empty($chapter['title'])): ?>
                <span class="reader-chapter-title"> — <?= $e($chapter['title']) ?></span>
            <?php endif; ?>

            <?php if ($next): ?>
            <a href="<?= $url("manga/{$manga['slug']}/chapter/{$next['chapter_number']}") ?>" class="reader-nav__next btn btn--sm">
                Ch. <?= $e($next['chapter_number']) ?> &raquo;
            </a>
            <?php endif; ?>
        </div>
        <div class="reader-toolbar__right">
            <button class="reader-mode-toggle" id="reader-mode-toggle" title="Toggle long/page mode">&#9776;</button>
            <button class="reader-fullscreen" id="reader-fullscreen" title="Fullscreen">&#9974;</button>
        </div>
    </div>

    <!-- Image pages -->
    <div class="reader-pages" id="reader-pages">
        <?php if (empty($images)): ?>
            <p class="reader-empty">No images for this chapter.</p>
        <?php else: ?>
            <?php foreach ($images as $i => $img): ?>
            <div class="reader-page" data-page="<?= $i + 1 ?>">
                <img src="<?= $e($img['url'] ?? '') ?>"
                     alt="<?= $e($img['alt'] ?? "Page " . ($i + 1)) ?>"
                     class="reader-page__img"
                     loading="lazy">
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Bottom navigation -->
    <div class="reader-nav-bottom">
        <?php if ($prev): ?>
        <a href="<?= $url("manga/{$manga['slug']}/chapter/{$prev['chapter_number']}") ?>" class="btn btn--outline">
            &laquo; Previous Chapter
        </a>
        <?php endif; ?>
        <a href="<?= $url("manga/{$manga['slug']}") ?>" class="btn btn--outline">Chapter List</a>
        <?php if ($next): ?>
        <a href="<?= $url("manga/{$manga['slug']}/chapter/{$next['chapter_number']}") ?>" class="btn btn--primary">
            Next Chapter &raquo;
        </a>
        <?php endif; ?>
    </div>
</div>

<script>
const ALPHA = {
    ajaxUrl:   '<?= $url('api') ?>',
    userId:    <?= json_encode($user['id'] ?? null) ?>,
    chapterId: <?= (int)$chapter['id'] ?>,
    mangaId:   <?= (int)$manga['id'] ?>,
    nonce:     '<?= \Alpha\Services\Security::createNonce('reader') ?>',
    totalPages: <?= count($images) ?>
};
</script>
