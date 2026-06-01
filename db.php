<?php

declare(strict_types=1);

function main_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . MAIN_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function logs_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . LOG_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function ensure_schema(): void
{
    $db = main_db();
    $logs = logs_db();

    $db->exec(
        'CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            type TEXT NOT NULL CHECK(type IN ("url", "php")),
            url TEXT,
            php_file TEXT,
            interval_minutes INTEGER NOT NULL CHECK(interval_minutes >= 1),
            is_active INTEGER NOT NULL DEFAULT 1,
            last_run_at TEXT,
            next_run_at TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )'
    );

    $logs->exec(
        'CREATE TABLE IF NOT EXISTS cron_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER NOT NULL,
            job_name TEXT NOT NULL,
            job_type TEXT NOT NULL,
            started_at TEXT NOT NULL,
            finished_at TEXT NOT NULL,
            status TEXT NOT NULL,
            http_code INTEGER,
            duration_ms INTEGER NOT NULL,
            response_body TEXT,
            error_message TEXT
        )'
    );

    seed_default_admin($db);
    seed_scheduler_key($db);
}

function seed_default_admin(PDO $db): void
{
    $exists = (int) $db->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($exists > 0) {
        return;
    }

    $now = app_now();
    $stmt = $db->prepare(
        'INSERT INTO admins (username, password_hash, created_at, updated_at)
         VALUES (:username, :password_hash, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':username' => 'admin',
        ':password_hash' => password_hash('admin', PASSWORD_DEFAULT),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function seed_scheduler_key(PDO $db): void
{
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute([':key' => 'scheduler_key']);
    $value = $stmt->fetchColumn();
    if ($value !== false && $value !== '') {
        return;
    }

    $key = bin2hex(random_bytes(24));
    $insert = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)');
    $insert->execute([
        ':key' => 'scheduler_key',
        ':value' => $key,
    ]);
}

function get_setting(string $key, string $default = ''): string
{
    $stmt = main_db()->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}
