<?php
$appName = \Alpha\Core\Config::get('APP_NAME', 'Project Alpha');
?>
<div class="auth-card">
    <div class="auth-card__header">
        <a href="<?= $url('') ?>" class="auth-logo"><?= $e($appName) ?></a>
        <div class="auth-tabs">
            <a href="<?= $url('login') ?>"    class="auth-tab auth-tab--active">Login</a>
            <a href="<?= $url('register') ?>" class="auth-tab">Register</a>
        </div>
    </div>

    <?php if (!empty($flash['error'])): ?>
        <div class="alert alert--error"><?= $e($flash['error']) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $url('login') ?>" class="auth-form" autocomplete="on">
        <?= $csrf ?>
        <!-- Honeypot -->
        <input type="text" name="website" value="" style="display:none!important" tabindex="-1" autocomplete="off">

        <div class="form-group">
            <label for="identifier">Username or Email</label>
            <input type="text" id="identifier" name="identifier" required autofocus
                   class="form-input" autocomplete="username">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required
                   class="form-input" autocomplete="current-password">
        </div>

        <div class="form-row form-row--space-between">
            <label class="checkbox-label">
                <input type="checkbox" name="remember" value="1"> Remember me
            </label>
            <a href="<?= $url('forgot-password') ?>" class="form-link">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn--primary btn--full">Login</button>
    </form>

    <p class="auth-card__footer">
        Don't have an account? <a href="<?= $url('register') ?>">Register here</a>
    </p>
</div>
