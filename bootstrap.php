<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!is_dir(STORAGE_PATH)) {
    mkdir(STORAGE_PATH, 0775, true);
}

if (!is_dir(PHP_JOBS_PATH)) {
    mkdir(PHP_JOBS_PATH, 0775, true);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/jobs_lib.php';
require_once __DIR__ . '/ui.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function app_now(): string
{
    return date('Y-m-d H:i:s');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function set_flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

ensure_schema();
