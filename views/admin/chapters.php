<?php /** Variables: $result, $mangaId, $mangas */ ?>
<div class="admin-page">
    <h1>Chapters</h1>
    <div class="admin-toolbar">
        <form method="get" class="inline-form">
            <select name="manga_id" class="form-input form-input--sm" onchange="this.form.submit()">
                <option value="">All Manga</option>
                <?php foreach ($mangas as $m): ?>
                <option value="<?= (int)$m['id'] ?>" <?= $mangaId === (int)$m['id'] ? 'selected' : '' ?>><?= $e($m['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($mangaId): ?>
        <a href="<?= $url("admin/manga/{$mangaId}/chapters/create") ?>" class="btn btn--primary btn--sm">+ Add Chapter</a>
        <?php endif; ?>
    </div>
    <table class="data-table">
        <thead><tr><th>Manga</th><th>Ch#</th><th>Title</th><th>Type</th><th>Status</th><th>Views</th><th>Date</th></tr></thead>
        <tbody>
            <?php foreach ($result['items'] as $ch): ?>
            <tr>
                <td><?= $e($ch['manga_title'] ?? '') ?></td>
                <td><?= $e($ch['chapter_number']) ?></td>
                <td><?= $e($ch['title'] ?? '') ?></td>
                <td><?= $e($ch['chapter_type']) ?></td>
                <td><?= $e($ch['status']) ?></td>
                <td><?= number_format((int)$ch['views']) ?></td>
                <td><?= date('M j', strtotime($ch['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php $baseUrl = $url('admin/chapters'); include ALPHA_ROOT . '/views/partials/pagination.php'; ?>
</div>
