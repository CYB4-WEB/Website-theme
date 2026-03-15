<?php
$cfg = fn($k, $d = '') => \Alpha\Core\Config::get($k, $d);
?>
<div class="admin-page">
    <h1>Settings</h1>

    <form method="post" action="<?= $url('admin/settings') ?>" class="settings-form settings-form--admin">
        <?= $csrf ?>

        <div class="settings-tabs" id="settings-tabs">
            <?php foreach (['General', 'Storage', 'Coins', 'Security', 'API Keys'] as $tab): ?>
            <button type="button" class="tab-btn" data-tab="<?= strtolower(str_replace(' ', '-', $tab)) ?>"><?= $tab ?></button>
            <?php endforeach; ?>
        </div>

        <!-- General -->
        <div class="tab-content tab-content--active" id="tab-general">
            <div class="form-group"><label>Site Name</label>
                <input type="text" name="APP_NAME" value="<?= $e($cfg('APP_NAME', 'Project Alpha')) ?>" class="form-input">
            </div>
            <div class="form-group"><label>User Registration</label>
                <select name="FEATURE_REGISTRATION" class="form-input">
                    <option value="true"  <?= $cfg('FEATURE_REGISTRATION', 'true') === 'true' ? 'selected' : '' ?>>Enabled</option>
                    <option value="false" <?= $cfg('FEATURE_REGISTRATION', 'true') === 'false' ? 'selected' : '' ?>>Disabled</option>
                </select>
            </div>
            <div class="form-group"><label>Default Theme</label>
                <select name="FEATURE_DARK_MODE_DEFAULT" class="form-input">
                    <option value="auto"  <?= $cfg('FEATURE_DARK_MODE_DEFAULT', 'auto') === 'auto'  ? 'selected' : '' ?>>Auto</option>
                    <option value="dark"  <?= $cfg('FEATURE_DARK_MODE_DEFAULT', 'auto') === 'dark'  ? 'selected' : '' ?>>Dark</option>
                    <option value="light" <?= $cfg('FEATURE_DARK_MODE_DEFAULT', 'auto') === 'light' ? 'selected' : '' ?>>Light</option>
                </select>
            </div>
        </div>

        <!-- Storage -->
        <div class="tab-content" id="tab-storage">
            <div class="form-group"><label>Storage Driver</label>
                <select name="STORAGE_DRIVER" class="form-input">
                    <option value="local" <?= $cfg('STORAGE_DRIVER', 'local') === 'local' ? 'selected' : '' ?>>Local</option>
                    <option value="s3"    <?= $cfg('STORAGE_DRIVER', 'local') === 's3'    ? 'selected' : '' ?>>AWS S3</option>
                    <option value="ftp"   <?= $cfg('STORAGE_DRIVER', 'local') === 'ftp'   ? 'selected' : '' ?>>FTP</option>
                </select>
            </div>
            <div class="form-group"><label>Image Encryption</label>
                <select name="FEATURE_IMAGE_ENCRYPTION" class="form-input">
                    <option value="false" <?= $cfg('FEATURE_IMAGE_ENCRYPTION', 'false') === 'false' ? 'selected' : '' ?>>Disabled</option>
                    <option value="true"  <?= $cfg('FEATURE_IMAGE_ENCRYPTION', 'false') === 'true'  ? 'selected' : '' ?>>Enabled</option>
                </select>
            </div>
        </div>

        <!-- Coins -->
        <div class="tab-content" id="tab-coins">
            <div class="form-group"><label>Coins per $1 (exchange rate)</label>
                <input type="number" name="COIN_EXCHANGE_RATE" value="<?= $e($cfg('COIN_EXCHANGE_RATE', '100')) ?>" class="form-input" min="1">
            </div>
            <div class="form-group"><label>Minimum Withdrawal ($)</label>
                <input type="number" name="MIN_WITHDRAWAL" value="<?= $e($cfg('MIN_WITHDRAWAL', '10')) ?>" class="form-input" min="1">
            </div>
            <div class="form-group"><label>Revenue Share % (author cut)</label>
                <input type="number" name="REVENUE_SHARE_PCT" value="<?= $e($cfg('REVENUE_SHARE_PCT', '70')) ?>" class="form-input" min="0" max="100">
            </div>
        </div>

        <!-- Security -->
        <div class="tab-content" id="tab-security">
            <div class="form-group"><label>Path Obfuscation</label>
                <select name="FEATURE_PATH_OBFUSCATION" class="form-input">
                    <option value="false">Disabled</option>
                    <option value="true"  <?= $cfg('FEATURE_PATH_OBFUSCATION', 'false') === 'true' ? 'selected' : '' ?>>Enabled</option>
                </select>
            </div>
        </div>

        <!-- API Keys -->
        <div class="tab-content" id="tab-api-keys">
            <div class="form-group"><label>reCAPTCHA Site Key</label>
                <input type="text" name="RECAPTCHA_SITE_KEY" value="<?= $e($cfg('STARTER_RECAPTCHA_SITE_KEY', '')) ?>" class="form-input">
            </div>
            <div class="form-group"><label>reCAPTCHA Secret Key</label>
                <input type="text" name="RECAPTCHA_SECRET_KEY" value="" class="form-input" placeholder="(leave blank to keep current)">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Save Settings</button>
        </div>
    </form>
</div>
