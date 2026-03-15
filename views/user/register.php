<?php
$appName = \Alpha\Core\Config::get('APP_NAME', 'Project Alpha');
?>
<div class="auth-card">
    <div class="auth-card__header">
        <a href="<?= $url('') ?>" class="auth-logo"><?= $e($appName) ?></a>
        <div class="auth-tabs">
            <a href="<?= $url('login') ?>"    class="auth-tab">Login</a>
            <a href="<?= $url('register') ?>" class="auth-tab auth-tab--active">Register</a>
        </div>
    </div>

    <?php if (!empty($flash['errors'])): ?>
        <ul class="alert alert--error alert--list">
            <?php foreach ((array)$flash['errors'] as $err): ?>
                <li><?= $e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="<?= $url('register') ?>" class="auth-form" autocomplete="on">
        <?= $csrf ?>
        <input type="text" name="website" value="" style="display:none!important" tabindex="-1" autocomplete="off">

        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autofocus
                   pattern="[a-zA-Z0-9_]{3,30}" title="3–30 chars, letters/numbers/underscore"
                   class="form-input" autocomplete="username">
        </div>

        <div class="form-group">
            <label for="display_name">Display Name</label>
            <input type="text" id="display_name" name="display_name"
                   class="form-input" autocomplete="nickname">
        </div>

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required
                   class="form-input" autocomplete="email">
        </div>

        <div class="form-group">
            <label for="password">Password <span class="hint">(min. 8 characters)</span></label>
            <input type="password" id="password" name="password" required minlength="8"
                   class="form-input" autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn--primary btn--full">Create Account</button>
    </form>

    <p class="auth-card__footer">
        Already have an account? <a href="<?= $url('login') ?>">Log in</a>
    </p>
</div>
