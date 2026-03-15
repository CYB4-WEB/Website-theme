<?php /** Variables: $result */ ?>
<div class="admin-page">
    <h1>Users</h1>
    <table class="data-table">
        <thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th>Last Login</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($result['items'] as $u): ?>
            <tr>
                <td><?= $e($u['username']) ?></td>
                <td><?= $e($u['email']) ?></td>
                <td><?= $e($u['role']) ?></td>
                <td><?= $e($u['status']) ?></td>
                <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                <td><?= $u['last_login'] ? date('M j, Y', strtotime($u['last_login'])) : '—' ?></td>
                <td>
                    <button class="btn btn--sm edit-user-btn" data-id="<?= (int)$u['id'] ?>"
                            data-role="<?= $e($u['role']) ?>" data-status="<?= $e($u['status']) ?>">Edit</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php $baseUrl = $url('admin/users'); include ALPHA_ROOT . '/views/partials/pagination.php'; ?>
</div>
