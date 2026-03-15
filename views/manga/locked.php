<?php
/**
 * Locked chapter gate.
 * Variables: $manga, $chapter, $reason ('login'|'coins'|'premium')
 */
?>
<div class="locked-chapter container">
    <div class="locked-gate">
        <div class="locked-gate__icon">&#128274;</div>
        <h2>This chapter is locked</h2>

        <?php if ($reason === 'login'): ?>
            <p>Please log in to read this chapter.</p>
            <a href="<?= $url('login') ?>" class="btn btn--primary">Log In</a>
            <a href="<?= $url('register') ?>" class="btn btn--outline">Create Account</a>

        <?php elseif ($reason === 'coins'): ?>
            <p>This chapter costs <strong><?= (int)$chapter['coin_price'] ?> coins</strong> to unlock.</p>
            <?php if ($user): ?>
                <p>Your balance: <strong><?= (int)($user['coin_balance'] ?? 0) ?> coins</strong></p>
                <button class="btn btn--primary unlock-btn"
                        data-chapter-id="<?= (int)$chapter['id'] ?>"
                        data-price="<?= (int)$chapter['coin_price'] ?>"
                        data-url="<?= $url('api/unlock') ?>">
                    &#128179; Unlock for <?= (int)$chapter['coin_price'] ?> Coins
                </button>
            <?php else: ?>
                <a href="<?= $url('login') ?>" class="btn btn--primary">Log in to unlock</a>
            <?php endif; ?>

        <?php else: ?>
            <p>This is a VIP-only chapter. Upgrade your account to read it.</p>
            <a href="<?= $url('profile') ?>" class="btn btn--primary">Upgrade Account</a>
        <?php endif; ?>

        <a href="<?= $url("manga/{$manga['slug']}") ?>" class="btn btn--outline">&#8592; Back to Manga</a>
    </div>
</div>

<script>
const ALPHA = { ajaxUrl: '<?= $url('api') ?>', nonce: '<?= \Alpha\Services\Security::createNonce('unlock') ?>' };
</script>
