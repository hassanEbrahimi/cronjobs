<?php

declare(strict_types=1);

function is_logged_in(): bool
{
    return isset($_SESSION['admin_id']) && is_numeric($_SESSION['admin_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('/index.php');
    }
}

function login_admin(string $username, string $password): bool
{
    $admin = find_admin_by_username($username);
    if (!$admin) {
        return false;
    }

    if (!password_verify($password, (string) $admin['password_hash'])) {
        return false;
    }

    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_username'] = (string) $admin['username'];
    session_regenerate_id(true);
    return true;
}

function find_admin_by_username(string $username): ?array
{
    $stmt = main_db()->prepare('SELECT * FROM admins WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $admin = $stmt->fetch();
    return $admin ?: null;
}

function verify_admin_password(string $username, string $password): bool
{
    $admin = find_admin_by_username($username);
    if (!$admin) {
        return false;
    }

    return password_verify($password, (string) $admin['password_hash']);
}

function current_admin(): ?array
{
    if (!is_logged_in()) {
        return null;
    }

    $stmt = main_db()->prepare('SELECT id, username, created_at, updated_at FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    return $admin ?: null;
}

function logout_admin(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function change_admin_password(int $adminId, string $newPassword): void
{
    $stmt = main_db()->prepare(
        'UPDATE admins
         SET password_hash = :password_hash, updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ':updated_at' => app_now(),
        ':id' => $adminId,
    ]);
}
