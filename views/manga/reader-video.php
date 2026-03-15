<?php
/**
 * Video chapter reader.
 * Variables: $manga, $chapter, $prev, $next
 */
$data     = $chapter['chapter_data'] ?? [];
$videoUrl = is_array($data) ? ($data['url'] ?? '') : '';
$provider = is_array($data) ? ($data['provider'] ?? 'direct') : 'direct';
?>
<div class="video-reader" data-chapter-id="<?= (int)$chapter['id'] ?>" data-manga-id="<?= (int)$manga['id'] ?>">

    <div class="video-reader__header">
        <a href="<?= $url("manga/{$manga['slug']}") ?>">&#8592; <?= $e($manga['title']) ?></a>
        <span>Episode <?= $e($chapter['chapter_number']) ?><?= !empty($chapter['title']) ? ' — ' . $e($chapter['title']) : '' ?></span>
    </div>

    <div class="video-reader__player">
        <?php if ($provider === 'iframe' || $provider === 'pixeldrain' || $provider === 'google_drive'): ?>
            <div class="video-embed-wrapper">
                <iframe src="<?= $e($videoUrl) ?>"
                        allowfullscreen
                        sandbox="allow-same-origin allow-scripts allow-forms"
                        class="video-embed"
                        loading="lazy"></iframe>
            </div>
        <?php else: ?>
            <video id="video-player" class="video-player" controls data-src="<?= $e($videoUrl) ?>">
                <source src="<?= $e($videoUrl) ?>" type="application/x-mpegURL">
                <source src="<?= $e($videoUrl) ?>" type="video/mp4">
                Your browser does not support video playback.
            </video>
        <?php endif; ?>
    </div>

    <div class="video-reader__nav">
        <?php if ($prev): ?>
        <a href="<?= $url("manga/{$manga['slug']}/chapter/{$prev['chapter_number']}") ?>" class="btn btn--outline">&laquo; Prev</a>
        <?php endif; ?>
        <a href="<?= $url("manga/{$manga['slug']}") ?>" class="btn btn--outline">Episode List</a>
        <?php if ($next): ?>
        <a href="<?= $url("manga/{$manga['slug']}/chapter/{$next['chapter_number']}") ?>" class="btn btn--primary">Next &raquo;</a>
        <?php endif; ?>
    </div>
</div>

<script>
const ALPHA = {
    ajaxUrl:   '<?= $url('api') ?>',
    userId:    <?= json_encode($user['id'] ?? null) ?>,
    chapterId: <?= (int)$chapter['id'] ?>,
    mangaId:   <?= (int)$manga['id'] ?>,
    nonce:     '<?= \Alpha\Services\Security::createNonce('reader') ?>'
};
</script>
<script src="<?= $asset('js/video-player.js') ?>"></script>
