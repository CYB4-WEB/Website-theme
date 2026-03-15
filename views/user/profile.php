<?php
/**
 * Variables: $profile, $bookmarks, $history, $coinBalance, $transactions
 */
?>
<div class="profile-page container">
    <div class="profile-header">
        <?php if ($profile['avatar']): ?>
            <img src="<?= $e($profile['avatar']) ?>" alt="" class="avatar avatar--lg">
        <?php else: ?>
            <div class="avatar avatar--lg avatar--placeholder"><?= strtoupper(substr($profile['username'], 0, 1)) ?></div>
        <?php endif; ?>
        <div class="profile-header__info">
            <h1><?= $e($profile['display_name'] ?? $profile['username']) ?></h1>
            <span class="role-badge"><?= $e($profile['role']) ?></span>
            <span class="coin-balance">&#128179; <?= number_format($coinBalance) ?> coins</span>
        </div>
    </div>

    <div class="profile-tabs" id="profile-tabs">
        <button class="tab-btn tab-btn--active" data-tab="settings">Settings</button>
        <button class="tab-btn" data-tab="bookmarks">Bookmarks (<?= count($bookmarks) ?>)</button>
        <button class="tab-btn" data-tab="history">History</button>
        <button class="tab-btn" data-tab="coins">Coins</button>
    </div>

    <!-- Settings tab -->
    <div class="tab-content tab-content--active" id="tab-settings">
        <?php if (!empty($flash['success'])): ?>
            <div class="alert alert--success"><?= $e($flash['success']) ?></div>
        <?php endif; ?>
        <?php if (!empty($flash['error'])): ?>
            <div class="alert alert--error"><?= $e($flash['error']) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= $url('profile') ?>" enctype="multipart/form-data" class="settings-form">
            <?= $csrf ?>
            <div class="form-group">
                <label>Display Name</label>
                <input type="text" name="display_name" value="<?= $e($profile['display_name'] ?? '') ?>" class="form-input">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?= $e($profile['email']) ?>" class="form-input">
            </div>
            <div class="form-group">
                <label>Avatar</label>
                <input type="file" name="avatar" accept="image/*" class="form-input">
            </div>
            <hr>
            <h3>Change Password</h3>
            <div class="form-group">
                <label>Current Password</label>
                <input type="password" name="current_password" class="form-input">
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" minlength="8" class="form-input">
            </div>
            <button type="submit" class="btn btn--primary">Save Changes</button>
        </form>
    </div>

    <!-- Bookmarks tab -->
    <div class="tab-content" id="tab-bookmarks">
        <?php if (empty($bookmarks)): ?>
            <p class="empty-state">No bookmarks yet.</p>
        <?php else: ?>
        <div class="manga-grid manga-grid--6col">
            <?php foreach ($bookmarks as $manga): ?>
                <?php include ALPHA_ROOT . '/views/partials/manga-card.php'; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- History tab -->
    <div class="tab-content" id="tab-history">
        <?php if (empty($history)): ?>
            <p class="empty-state">No reading history.</p>
        <?php else: ?>
        <ul class="history-list">
            <?php foreach ($history as $h): ?>
            <li class="history-item">
                <img src="<?= $e($h['cover'] ?? '') ?>" alt="" class="history-item__cover">
                <div class="history-item__info">
                    <a href="<?= $url("manga/{$h['manga_slug']}") ?>"><?= $e($h['manga_title']) ?></a>
                    <span>Ch. <?= $e($h['chapter_number']) ?></span>
                    <time><?= date('M j, Y', strtotime($h['updated_at'])) ?></time>
                </div>
                <a href="<?= $url("manga/{$h['manga_slug']}/chapter/{$h['chapter_number']}") ?>" class="btn btn--sm btn--primary">Continue</a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <!-- Coins tab -->
    <div class="tab-content" id="tab-coins">
        <div class="coin-balance-card">
            <h3>Balance: <strong><?= number_format($coinBalance) ?> coins</strong></h3>
        </div>
        <h4>Recent Transactions</h4>
        <?php if (empty($transactions)): ?>
            <p class="empty-state">No transactions yet.</p>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Description</th></tr></thead>
            <tbody>
                <?php foreach ($transactions as $t): ?>
                <tr>
                    <td><?= date('M j, Y', strtotime($t['created_at'])) ?></td>
                    <td><?= $e($t['transaction_type']) ?></td>
                    <td class="<?= $t['amount'] > 0 ? 'text-green' : 'text-red' ?>"><?= ($t['amount'] > 0 ? '+' : '') . number_format($t['amount']) ?></td>
                    <td><?= $e($t['description']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
