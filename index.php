<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (is_logged_in()) {
    redirect('/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (login_admin($username, $password)) {
        set_flash('Welcome back. You are logged in.');
        redirect('/dashboard.php');
    }

    set_flash('Invalid username or password.', 'error');
    redirect('/index.php');
}

render_header('Login');
?>
<div class="card" style="max-width: 440px; margin: 40px auto;">
    <h2 style="margin-top: 0;">Admin Login</h2>
    <p class="muted">Default credentials: <strong>admin / admin</strong></p>
    <form method="post">
        <div style="margin-bottom: 12px;">
            <label for="username">Username</label>
            <input id="username" name="username" autocomplete="username" required>
        </div>
        <div style="margin-bottom: 14px;">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary" type="submit" style="width: 100%;">Sign in</button>
    </form>
</div>
<?php render_footer(); ?>
