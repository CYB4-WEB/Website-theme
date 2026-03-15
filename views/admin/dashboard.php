<?php /** Variables: $stats, $recent, $newUsers */ ?>
<div class="admin-page">
    <h1>Dashboard</h1>

    <!-- Stats cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-card__value"><?= number_format($stats['manga']) ?></span>
            <span class="stat-card__label">Manga Series</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__value"><?= number_format($stats['chapters']) ?></span>
            <span class="stat-card__label">Chapters</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__value"><?= number_format($stats['users']) ?></span>
            <span class="stat-card__label">Users</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__value"><?= number_format($stats['views']) ?></span>
            <span class="stat-card__label">Total Views</span>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="quick-actions">
        <a href="<?= $url('admin/manga/create') ?>" class="btn btn--primary">+ New Manga</a>
        <a href="<?= $url('admin/chapters') ?>" class="btn btn--outline">Manage Chapters</a>
        <a href="<?= $url('admin/users') ?>" class="btn btn--outline">Manage Users</a>
        <a href="<?= $url('admin/settings') ?>" class="btn btn--outline">Settings</a>
    </div>

    <!-- Recent chapters -->
    <div class="admin-panel">
        <h2>Recent Chapters</h2>
        <table class="data-table">
            <thead><tr><th>Manga</th><th>Chapter</th><th>Type</th><th>Date</th></tr></thead>
            <tbody>
                <?php foreach ($recent as $ch): ?>
                <tr>
                    <td><a href="<?= $url("manga/{$ch['manga_slug']}") ?>"><?= $e($ch['manga_title']) ?></a></td>
                    <td><a href="<?= $url("manga/{$ch['manga_slug']}/chapter/{$ch['chapter_number']}") ?>">Ch. <?= $e($ch['chapter_number']) ?></a></td>
                    <td><?= $e($ch['chapter_type']) ?></td>
                    <td><?= date('M j, Y', strtotime($ch['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- New users -->
    <div class="admin-panel">
        <h2>New Users</h2>
        <table class="data-table">
            <thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Joined</th></tr></thead>
            <tbody>
                <?php foreach ($newUsers as $u): ?>
                <tr>
                    <td><?= $e($u['username']) ?></td>
                    <td><?= $e($u['email']) ?></td>
                    <td><?= $e($u['role']) ?></td>
                    <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
