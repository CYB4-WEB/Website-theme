<?php
/**
 * Manga browse / archive page.
 * Variables: $result, $filters, $genres
 */
?>
<div class="browse-page">
    <div class="container">

        <!-- Search + Filters -->
        <section class="browse-filters">
            <form method="get" action="<?= $url('manga') ?>" class="filters-form" id="filters-form">
                <div class="filters-row">
                    <input type="search" name="q" value="<?= $e($filters['q'] ?? '') ?>" placeholder="Search title, author…" class="filter-input filter-input--search">

                    <select name="genre" class="filter-select">
                        <option value="">All Genres</option>
                        <?php foreach ($genres as $g): ?>
                        <option value="<?= $e($g['slug']) ?>" <?= ($filters['genre'] ?? '') === $g['slug'] ? 'selected' : '' ?>>
                            <?= $e($g['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="type" class="filter-select">
                        <option value="">All Types</option>
                        <?php foreach (['manga', 'novel', 'video', 'comic', 'manhua', 'manhwa'] as $t): ?>
                        <option value="<?= $t ?>" <?= ($filters['type'] ?? '') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="status" class="filter-select">
                        <option value="">All Statuses</option>
                        <?php foreach (['ongoing', 'completed', 'hiatus', 'cancelled'] as $s): ?>
                        <option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="sort" class="filter-select">
                        <option value="updated"  <?= ($filters['sort'] ?? '') === 'updated'  ? 'selected' : '' ?>>Recently Updated</option>
                        <option value="popular"  <?= ($filters['sort'] ?? '') === 'popular'  ? 'selected' : '' ?>>Most Popular</option>
                        <option value="rating"   <?= ($filters['sort'] ?? '') === 'rating'   ? 'selected' : '' ?>>Highest Rated</option>
                        <option value="new"      <?= ($filters['sort'] ?? '') === 'new'      ? 'selected' : '' ?>>Newest Added</option>
                    </select>

                    <button type="submit" class="btn btn--primary">Filter</button>
                    <a href="<?= $url('manga') ?>" class="btn btn--outline">Reset</a>
                </div>
            </form>
        </section>

        <!-- Results -->
        <section class="browse-results">
            <div class="browse-results__header">
                <span class="results-count">
                    <?= number_format($result['total']) ?> results
                    <?php if (!empty($filters['q'])): ?>
                        for "<strong><?= $e($filters['q']) ?></strong>"
                    <?php endif; ?>
                </span>
            </div>

            <?php if (empty($result['items'])): ?>
            <div class="no-results">
                <p>No manga found. Try different filters.</p>
                <a href="<?= $url('manga') ?>" class="btn btn--primary">Browse All</a>
            </div>
            <?php else: ?>
            <div class="manga-grid manga-grid--6col">
                <?php foreach ($result['items'] as $manga): ?>
                    <?php include ALPHA_ROOT . '/views/partials/manga-card.php'; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php
            $baseUrl = $url('manga');
            include ALPHA_ROOT . '/views/partials/pagination.php';
            ?>
        </section>
    </div>
</div>
