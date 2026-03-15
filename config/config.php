<?php

/**
 * Default configuration values.
 * These are overridden by .env file values.
 */

return [
    // Application
    'APP_NAME'    => 'Project Alpha',
    'APP_URL'     => 'http://localhost',
    'APP_ENV'     => 'production',
    'APP_DEBUG'   => false,
    'APP_SECRET'  => '',

    // Database
    'DB_HOST'     => '127.0.0.1',
    'DB_PORT'     => '3306',
    'DB_NAME'     => 'alpha_manga',
    'DB_USER'     => 'root',
    'DB_PASS'     => '',
    'DB_CHARSET'  => 'utf8mb4',
    'DB_PREFIX'   => 'alpha_',

    // Storage
    'STORAGE_DRIVER'     => 'local',
    'STORAGE_LOCAL_PATH' => ALPHA_ROOT . '/uploads',

    // Features
    'FEATURE_REGISTRATION'       => true,
    'FEATURE_COINS'              => true,
    'FEATURE_IMAGE_ENCRYPTION'   => false,
    'FEATURE_PATH_OBFUSCATION'   => false,
    'FEATURE_EMAIL_VERIFY'       => false,
    'FEATURE_DARK_MODE_DEFAULT'  => 'auto',

    // Reader
    'READER_STYLE'          => 'vertical',
    'CHAPTERS_PER_PAGE'     => 50,
    'READER_PRELOAD_PAGES'  => 3,

    // Coins & revenue
    'COIN_EXCHANGE_RATE' => 100,
    'MIN_WITHDRAWAL'     => 10,
    'REVENUE_SHARE_PCT'  => 70,
];
