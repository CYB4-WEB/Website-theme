<?php
/**
 * Single manga detail page.
 * Variables: $manga, $chapters, $isBookmarked, $userRating
 */
$cover     = $manga['cover']        ?? '';
$genres    = $manga['genres']       ?? [];
$ratingAvg = number_format((float)($manga['rating_avg'] ?? 0), 1);
$ratingCnt = (int)($manga['rating_count'] ?? 0);
?>
<div class="manga-single container">

    <!-- Manga detail panel -->
    <div class="manga-detail">
        <div class="manga-detail__cover-col">
            <?php if ($cover): ?>
                <img src="<?= $e($cover) ?>" alt="<?= $e($manga['title']) ?>" class="manga-detail__cover">
            <?php else: ?>
                <div class="manga-detail__cover manga-detail__cover--placeholder"></div>
            <?php endif; ?>

            <!-- Bookmark -->
            <button class="btn btn--full bookmark-btn <?= $isBookmarked ? 'bookmarked' : '' ?>"
                    data-manga-id="<?= (int)$manga['id'] ?>"
                    data-url="<?= $url('api/bookmark') ?>">
                <?= $isBookmarked ? '&#10084; Bookmarked' : '&#9825; Add to List' ?>
            </button>

            <!-- First Chapter CTA -->
            <?php if (!empty($chapters['items'])): ?>
            <?php $first = end($chapters['items']); $last = reset($chapters['items']); ?>
            <a href="<?= $url("manga/{$manga['slug']}/chapter/{$first['chapter_number']}") ?>" class="btn btn--primary btn--full">
                Read First Chapter
            </a>
            <a href="<?= $url("manga/{$manga['slug']}/chapter/{$last['chapter_number']}") ?>" class="btn btn--outline btn--full">
                Read Latest (Ch. <?= $e($last['chapter_number']) ?>)
            </a>
            <?php endif; ?>
        </div>

        <div class="manga-detail__info-col">
            <h1 class="manga-detail__title"><?= $e($manga['title']) ?></h1>
            <?php if (!empty($manga['alt_names'])): ?>
                <p class="manga-detail__alt"><?= $e($manga['alt_names']) ?></p>
            <?php endif; ?>

            <!-- Meta -->
            <table class="manga-meta">
                <tr><th>Author</th><td><?= $e($manga['author'] ?? '—') ?></td></tr>
                <tr><th>Artist</th><td><?= $e($manga['artist'] ?? '—') ?></td></tr>
                <tr><th>Status</th><td><span class="status-badge status-badge--<?= $e($manga['status'] ?? 'ongoing') ?>"><?= ucfirst($e($manga['status'] ?? 'Ongoing')) ?></span></td></tr>
                <tr><th>Type</th>  <td><?= ucfirst($e($manga['type'] ?? 'Manga')) ?></td></tr>
                <?php if (!empty($manga['release_year'])): ?>
                <tr><th>Year</th>  <td><?= (int)$manga['release_year'] ?></td></tr>
                <?php endif; ?>
            </table>

            <!-- Genres -->
            <?php if ($genres): ?>
            <div class="manga-detail__genres">
                <?php foreach ($genres as $g): ?>
                    <a href="<?= $url("manga?genre={$g['slug']}") ?>" class="genre-chip"><?= $e($g['name']) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Rating -->
            <div class="manga-rating" id="manga-rating" data-manga-id="<?= (int)$manga['id'] ?>" data-url="<?= $url('api/rating') ?>">
                <div class="star-rating" data-user-rating="<?= $userRating ? (int)$userRating : 0 ?>">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <button class="star <?= $userRating >= $i ? 'star--filled' : '' ?>" data-value="<?= $i ?>" aria-label="<?= $i ?> stars">&#9733;</button>
                    <?php endfor; ?>
                </div>
                <span class="rating-text">
                    <?= $ratingAvg ?> / 5 &nbsp; (<?= number_format($ratingCnt) ?> votes)
                </span>
            </div>

            <!-- Synopsis -->
            <?php if (!empty($manga['synopsis'])): ?>
            <div class="manga-detail__synopsis">
                <h3>Synopsis</h3>
                <div class="synopsis-text expandable" id="synopsis-text">
                    <?= nl2br($e($manga['synopsis'])) ?>
                </div>
                <button class="synopsis-toggle" id="synopsis-toggle">Show more</button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chapter list -->
    <section class="chapter-list-section">
        <div class="chapter-list-header">
            <h2>Chapters (<?= number_format($chapters['total']) ?>)</h2>
        </div>

        <?php if (empty($chapters['items'])): ?>
            <p class="no-chapters">No chapters yet.</p>
        <?php else: ?>
        <table class="chapter-table">
            <thead>
                <tr>
                    <th>Chapter</th>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Views</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($chapters['items'] as $ch): ?>
                <tr>
                    <td>
                        <a href="<?= $url("manga/{$manga['slug']}/chapter/{$ch['chapter_number']}") ?>" class="chapter-link">
                            Ch. <?= $e($ch['chapter_number']) ?>
                        </a>
                    </td>
                    <td><?= $e($ch['title'] ?? '') ?></td>
                    <td><?= ucfirst($e($ch['chapter_type'])) ?></td>
                    <td>
                        <?php if ($ch['is_premium'] || $ch['coin_price'] > 0): ?>
                            <span class="premium-badge" title="Premium chapter">
                                <?= $ch['coin_price'] > 0 ? "&#128179; {$ch['coin_price']}" : '&#11088; VIP' ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format((int)$ch['views']) ?></td>
                    <td><time><?= date('M j, Y', strtotime($ch['created_at'])) ?></time></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        $baseUrl = $url("manga/{$manga['slug']}");
        include ALPHA_ROOT . '/views/partials/pagination.php';
        ?>
        <?php endif; ?>
    </section>

</div>

<script>
const ALPHA = {
    ajaxUrl:  '<?= $url('api') ?>',
    userId:   <?= json_encode($user['id'] ?? null) ?>,
    mangaId:  <?= (int)$manga['id'] ?>,
    nonce:    '<?= \Alpha\Services\Security::createNonce('manga-actions') ?>'
};
</script>
