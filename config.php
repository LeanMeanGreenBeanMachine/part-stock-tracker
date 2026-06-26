<?php
// Load .env file if present
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\n\r\0\x0B\"'");
        if (!isset($_ENV[$k])) {
            $_ENV[$k] = $v;
            putenv("$k=$v");
        }
    }
}

define('APP_USER',    'homesteader');
define('APP_PASS',    'm4nksrwvav7m');
define('SECRET_KEY',  $_ENV['SECRET_KEY']  ?? 'dev-secret-CHANGE-IN-PRODUCTION');
define('CHECK_TOKEN', $_ENV['CHECK_TOKEN'] ?? '');
define('DB_PATH',     __DIR__ . '/data/stock.db');

define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com');
define('SMTP_PORT', (int)($_ENV['SMTP_PORT'] ?? 587));
define('SMTP_USER', $_ENV['SMTP_USER'] ?? '');
define('SMTP_PASS', $_ENV['SMTP_PASS'] ?? '');
define('SMTP_FROM', $_ENV['SMTP_FROM'] ?? SMTP_USER);
