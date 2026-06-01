<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$providedKey = (string) ($_GET['key'] ?? '');
$schedulerKey = get_setting('scheduler_key');

if ($providedKey === '' || !hash_equals($schedulerKey, $providedKey)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'message' => 'Forbidden: invalid scheduler key.',
        'timezone' => APP_TIMEZONE,
        'time' => app_now(),
    ], JSON_PRETTY_PRINT);
    exit;
}

try {
    $results = run_due_jobs();
    echo json_encode([
        'ok' => true,
        'timezone' => APP_TIMEZONE,
        'time' => app_now(),
        'ran_jobs' => count($results),
        'results' => $results,
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'timezone' => APP_TIMEZONE,
        'time' => app_now(),
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
