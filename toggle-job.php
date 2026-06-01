<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($id > 0) {
        toggle_job($id);
        set_flash('Job status updated.');
    }
}

redirect('/dashboard.php');
