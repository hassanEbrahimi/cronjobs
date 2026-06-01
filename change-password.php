<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

$admin = current_admin();
if (!$admin) {
    redirect('/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!verify_admin_password((string) $admin['username'], $currentPassword)) {
        set_flash('Current password is incorrect.', 'error');
        redirect('/change-password.php');
    }

    if (strlen($newPassword) < 6) {
        set_flash('New password must be at least 6 characters.', 'error');
        redirect('/change-password.php');
    }

    if ($newPassword !== $confirmPassword) {
        set_flash('New password and confirmation do not match.', 'error');
        redirect('/change-password.php');
    }

    change_admin_password((int) $admin['id'], $newPassword);
    set_flash('Password changed successfully.');
    redirect('/dashboard.php');
}

render_header('Change Password');
?>
<div class="card" style="max-width: 500px; margin: 20px auto;">
    <h2 style="margin-top: 0;">Change Password</h2>
    <form method="post">
        <div style="margin-bottom:12px;">
            <label for="current_password">Current Password</label>
            <input id="current_password" name="current_password" type="password" required>
        </div>
        <div style="margin-bottom:12px;">
            <label for="new_password">New Password</label>
            <input id="new_password" name="new_password" type="password" minlength="6" required>
        </div>
        <div style="margin-bottom:14px;">
            <label for="confirm_password">Confirm New Password</label>
            <input id="confirm_password" name="confirm_password" type="password" minlength="6" required>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Update Password</button>
            <a class="btn btn-muted" href="/dashboard.php">Cancel</a>
        </div>
    </form>
</div>
<?php render_footer(); ?>
