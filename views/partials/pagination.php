<?php
/**
 * Pagination partial.
 * Variables: $result (array with page, total_pages), $baseUrl (string)
 */
if (!isset($result) || $result['total_pages'] <= 1) {
    return;
}
$currentPage = (int)$result['page'];
$totalPages  = (int)$result['total_pages'];
$base        = rtrim($baseUrl ?? (strtok($_SERVER['REQUEST_URI'], '?')), '/');
$query       = $_GET ?? [];

function buildPageUrl(string $base, array $query, int $page): string {
    $query['page'] = $page;
    return $base . '?' . http_build_query($query);
}
?>
<nav class="pagination" aria-label="Pagination">
    <?php if ($currentPage > 1): ?>
        <a href="<?= $e(buildPageUrl($base, $query, $currentPage - 1)) ?>" class="pagination__prev" aria-label="Previous">&laquo; Prev</a>
    <?php endif; ?>

    <?php
    $start = max(1, $currentPage - 2);
    $end   = min($totalPages, $currentPage + 2);
    if ($start > 1): ?>
        <a href="<?= $e(buildPageUrl($base, $query, 1)) ?>" class="pagination__item">1</a>
        <?php if ($start > 2): ?><span class="pagination__ellipsis">&hellip;</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
        <a href="<?= $e(buildPageUrl($base, $query, $i)) ?>"
           class="pagination__item <?= $i === $currentPage ? 'pagination__item--active' : '' ?>"
           <?= $i === $currentPage ? 'aria-current="page"' : '' ?>>
            <?= $i ?>
        </a>
    <?php endfor; ?>

    <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?><span class="pagination__ellipsis">&hellip;</span><?php endif; ?>
        <a href="<?= $e(buildPageUrl($base, $query, $totalPages)) ?>" class="pagination__item"><?= $totalPages ?></a>
    <?php endif; ?>

    <?php if ($currentPage < $totalPages): ?>
        <a href="<?= $e(buildPageUrl($base, $query, $currentPage + 1)) ?>" class="pagination__next" aria-label="Next">Next &raquo;</a>
    <?php endif; ?>
</nav>
