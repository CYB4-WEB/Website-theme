<?php /** Variables: $result */ ?>
<div class="admin-page">
    <div class="admin-page__header">
        <h1>Manga</h1>
        <a href="<?= $url('manga/upload') ?>" class="btn btn--primary">+ New Manga</a>
    </div>
    <table class="data-table">
        <thead>
            <tr><th>Cover</th><th>Title</th><th>Type</th><th>Status</th><th>Chapters</th><th>Views</th><th>Actions</th></tr>
        </thead>
        <tbody>
            <?php foreach ($result['items'] as $m): ?>
            <tr>
                <td><?php if ($m['cover']): ?><img src="<?= $e($m['cover']) ?>" width="40" height="55" loading="lazy" alt=""><?php endif; ?></td>
                <td><a href="<?= $url("manga/{$m['slug']}") ?>"><?= $e($m['title']) ?></a></td>
                <td><?= $e($m['type']) ?></td>
                <td><?= $e($m['status']) ?></td>
                <td><?= number_format((int)($m['chapter_count'] ?? 0)) ?></td>
                <td><?= number_format((int)$m['views']) ?></td>
                <td>
                    <a href="<?= $url("admin/manga/{$m['id']}/edit") ?>" class="btn btn--sm">Edit</a>
                    <a href="<?= $url("admin/manga/{$m['id']}/chapters") ?>" class="btn btn--sm">Chapters</a>
                    <button class="btn btn--sm btn--danger delete-manga-btn"
                            data-id="<?= (int)$m['id'] ?>"
                            data-url="<?= $url("admin/manga/{$m['id']}") ?>">Delete</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php $baseUrl = $url('admin/manga'); include ALPHA_ROOT . '/views/partials/pagination.php'; ?>
</div>
