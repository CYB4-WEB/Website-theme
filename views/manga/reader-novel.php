<?php
/**
 * Text (novel) chapter reader.
 * Variables: $manga, $chapter, $prev, $next
 */
$data    = $chapter['chapter_data'] ?? [];
$content = is_array($data) ? ($data['content'] ?? '') : '';
?>
<div class="novel-reader" id="novel-reader" data-chapter-id="<?= (int)$chapter['id'] ?>" data-manga-id="<?= (int)$manga['id'] ?>">

    <!-- Toolbar -->
    <div class="novel-toolbar" id="novel-toolbar">
        <div class="novel-toolbar__left">
            <a href="<?= $url("manga/{$manga['slug']}") ?>">&#8592; <?= $e($manga['title']) ?></a>
        </div>
        <div class="novel-toolbar__center">
            <span>Chapter <?= $e($chapter['chapter_number']) ?><?= !empty($chapter['title']) ? ' — ' . $e($chapter['title']) : '' ?></span>
        </div>
        <div class="novel-toolbar__right">
            <!-- Reading tools toggle -->
            <button class="reading-tools-toggle" id="reading-tools-toggle" aria-expanded="false" title="Reading settings">
                <span>Aa</span>
            </button>
        </div>
    </div>

    <!-- Reading tools panel -->
    <div class="reading-tools-panel" id="reading-tools-panel" hidden>
        <label>Font:
            <select id="font-family-select" class="tools-select">
                <option value="serif">Serif</option>
                <option value="sans-serif" selected>Sans Serif</option>
                <option value="monospace">Monospace</option>
                <option value="Georgia">Georgia</option>
                <option value="'Open Sans'">Open Sans</option>
            </select>
        </label>
        <label>Size:
            <input type="range" id="font-size-range" min="12" max="28" value="16" class="tools-range">
            <output id="font-size-output">16px</output>
        </label>
        <label>Line Height:
            <input type="range" id="line-height-range" min="100" max="250" value="160" class="tools-range">
            <output id="line-height-output">1.6</output>
        </label>
        <label>Background:
            <?php foreach (['#fff', '#f5f0e8', '#1a1a1a', '#0d2137'] as $bg): ?>
            <button class="bg-preset" data-bg="<?= $bg ?>" style="background:<?= $bg ?>" aria-label="Set background <?= $bg ?>"></button>
            <?php endforeach; ?>
        </label>
    </div>

    <!-- Progress bar -->
    <div class="reading-progress" id="reading-progress">
        <div class="reading-progress__bar" id="reading-progress-bar" style="width:0%"></div>
    </div>

    <!-- Chapter content -->
    <article class="novel-content" id="novel-content">
        <div class="novel-text" id="novel-text">
            <?= nl2br($e($content)) ?>
        </div>
    </article>

    <!-- Navigation -->
    <div class="novel-nav-bottom">
        <?php if ($prev): ?>
        <a href="<?= $url("manga/{$manga['slug']}/chapter/{$prev['chapter_number']}") ?>" class="btn btn--outline">&laquo; Previous</a>
        <?php endif; ?>
        <a href="<?= $url("manga/{$manga['slug']}") ?>" class="btn btn--outline">Chapter List</a>
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
<script src="<?= $asset('js/novel-reader.js') ?>"></script>
