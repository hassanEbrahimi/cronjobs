<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/dashboard.php');
}

$jobId = isset($_POST['id']) ? (int) $_POST['id'] : 0;

try {
    if (isset($_POST['queue_now']) && $jobId > 0) {
        queue_now($jobId);
        set_flash('Job queued for the next scheduler execution.');
        redirect('/dashboard.php');
    }

    save_job($_POST);
    set_flash($jobId > 0 ? 'Job updated successfully.' : 'Job created successfully.');
} catch (Throwable $e) {
    set_flash($e->getMessage(), 'error');
}

redirect('/dashboard.php');
