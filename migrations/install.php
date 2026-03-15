#!/usr/bin/env php
<?php
/**
 * Database installer.
 * Usage: php migrations/install.php
 * Reads DB credentials from .env, then runs all *.sql migration files in order.
 */

declare(strict_types=1);

define('ALPHA_ROOT', dirname(__DIR__));

// Load .env
$env = ALPHA_ROOT . '/.env';
if (!file_exists($env)) {
    die("ERROR: .env file not found. Copy .env.example to .env and fill in your credentials.\n");
}
foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    [$key, $val] = array_map('trim', explode('=', $line, 2));
    $_ENV[$key]  = trim($val, '"\'');
}

$host    = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port    = $_ENV['DB_PORT'] ?? '3306';
$name    = $_ENV['DB_NAME'] ?? 'alpha_manga';
$user    = $_ENV['DB_USER'] ?? 'root';
$pass    = $_ENV['DB_PASS'] ?? '';

try {
    // Create database if not exists
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✓ Database `{$name}` ready.\n";

    // Connect to the database
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Run all SQL files in order
    $sqlFiles = glob(__DIR__ . '/*.sql');
    sort($sqlFiles);

    foreach ($sqlFiles as $file) {
        echo "Running " . basename($file) . "... ";
        $sql = file_get_contents($file);
        // Split on semicolons, skip empty
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $pdo->exec($stmt);
        }
        echo "done.\n";
    }

    // Create admin user if not exists
    $admin = $pdo->prepare("SELECT id FROM alpha_users WHERE role = 'admin' LIMIT 1");
    $admin->execute();
    if (!$admin->fetch()) {
        $hash = password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare(
            "INSERT INTO alpha_users (username, email, password, display_name, role, status, created_at) VALUES (?, ?, ?, ?, 'admin', 'active', NOW())"
        )->execute(['admin', 'admin@example.com', $hash, 'Administrator']);
        echo "✓ Default admin user created: admin / admin123 — CHANGE THIS PASSWORD!\n";
    }

    echo "\n✓ Installation complete! Open your browser and visit your site.\n";
} catch (\PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
