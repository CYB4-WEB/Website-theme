<?php /** Variables: $list */ ?>
<div class="admin-page">
    <h1>Withdrawal Requests</h1>
    <table class="data-table">
        <thead><tr><th>User</th><th>Amount (coins)</th><th>Method</th><th>Status</th><th>Requested</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($list as $w): ?>
            <tr>
                <td><?= $e($w['username']) ?></td>
                <td><?= number_format((int)$w['amount']) ?></td>
                <td><?= $e($w['payment_method']) ?></td>
                <td class="status-<?= $e($w['status']) ?>"><?= ucfirst($e($w['status'])) ?></td>
                <td><?= date('M j, Y', strtotime($w['created_at'])) ?></td>
                <td>
                    <?php if ($w['status'] === 'pending'): ?>
                    <form method="post" action="<?= $url("admin/withdrawals/{$w['id']}") ?>" class="inline-form">
                        <?= \Alpha\Core\Session::csrfField() ?>
                        <button name="status" value="approved" class="btn btn--sm btn--primary">Approve</button>
                        <button name="status" value="rejected" class="btn btn--sm btn--danger">Reject</button>
                    </form>
                    <?php else: echo ucfirst($w['status']); endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
