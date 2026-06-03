<?php

declare(strict_types=1);

function all_jobs(): array
{
    $stmt = main_db()->query('SELECT * FROM jobs ORDER BY id DESC');
    return $stmt->fetchAll();
}

function floor_to_minute_timestamp(int $timestamp): int
{
    return (int) (floor($timestamp / 60) * 60);
}

function minute_datetime_from_timestamp(int $timestamp): string
{
    return date('Y-m-d H:i:00', floor_to_minute_timestamp($timestamp));
}

function immediate_next_run_at(): string
{
    return minute_datetime_from_timestamp(time());
}

function calculate_next_run_at(array $job, int $nowTimestamp): string
{
    $intervalSeconds = max(1, (int) $job['interval_minutes']) * 60;
    $scheduledTs = strtotime((string) ($job['next_run_at'] ?? ''));
    if ($scheduledTs === false) {
        $scheduledTs = $nowTimestamp;
    }
    $scheduledTs = floor_to_minute_timestamp($scheduledTs);

    do {
        $scheduledTs += $intervalSeconds;
    } while ($scheduledTs <= $nowTimestamp);

    return minute_datetime_from_timestamp($scheduledTs);
}

function find_job(int $jobId): ?array
{
    $stmt = main_db()->prepare('SELECT * FROM jobs WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $jobId]);
    $job = $stmt->fetch();
    return $job ?: null;
}

function save_job(array $payload): int
{
    $db = main_db();
    $now = app_now();

    $id = isset($payload['id']) ? (int) $payload['id'] : 0;
    $name = trim((string) ($payload['name'] ?? ''));
    $type = (string) ($payload['type'] ?? 'url');
    $url = trim((string) ($payload['url'] ?? ''));
    $phpCode = (string) ($payload['php_code'] ?? '');
    $interval = max(1, (int) ($payload['interval_minutes'] ?? 1));

    if ($name === '') {
        throw new InvalidArgumentException('Job name is required.');
    }

    if ($type === 'url' && $url === '') {
        throw new InvalidArgumentException('URL is required for URL jobs.');
    }

    if ($type !== 'url' && $type !== 'php') {
        throw new InvalidArgumentException('Invalid job type.');
    }

    $db->beginTransaction();
    try {
        if ($id > 0) {
            $existing = find_job($id);
            if (!$existing) {
                throw new InvalidArgumentException('Job not found.');
            }

            $phpFile = $existing['php_file'];
            if ($type === 'php' && trim($phpCode) !== '') {
                $phpFile = write_php_job_file($id, $phpCode);
            }

            if ($type === 'url') {
                $phpFile = null;
            }

            $stmt = $db->prepare(
                'UPDATE jobs
                 SET name = :name, type = :type, url = :url, php_file = :php_file,
                     interval_minutes = :interval_minutes, updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                ':name' => $name,
                ':type' => $type,
                ':url' => $type === 'url' ? $url : null,
                ':php_file' => $type === 'php' ? $phpFile : null,
                ':interval_minutes' => $interval,
                ':updated_at' => $now,
                ':id' => $id,
            ]);
            $db->commit();
            return $id;
        }

        $stmt = $db->prepare(
            'INSERT INTO jobs (name, type, url, php_file, interval_minutes, is_active, last_run_at, next_run_at, created_at, updated_at)
             VALUES (:name, :type, :url, :php_file, :interval_minutes, 1, NULL, :next_run_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':name' => $name,
            ':type' => $type,
            ':url' => $type === 'url' ? $url : null,
            ':php_file' => null,
            ':interval_minutes' => $interval,
            ':next_run_at' => immediate_next_run_at(),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $id = (int) $db->lastInsertId();

        if ($type === 'php') {
            if (trim($phpCode) === '') {
                throw new InvalidArgumentException('PHP code is required for PHP jobs.');
            }

            $phpFile = write_php_job_file($id, $phpCode);
            $update = $db->prepare('UPDATE jobs SET php_file = :php_file WHERE id = :id');
            $update->execute([
                ':php_file' => $phpFile,
                ':id' => $id,
            ]);
        }

        $db->commit();
        return $id;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function delete_job(int $id): void
{
    $job = find_job($id);
    if (!$job) {
        return;
    }

    $stmt = main_db()->prepare('DELETE FROM jobs WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

function toggle_job(int $id): void
{
    $job = find_job($id);
    if (!$job) {
        return;
    }

    $active = ((int) $job['is_active']) === 1 ? 0 : 1;
    $stmt = main_db()->prepare(
        'UPDATE jobs
         SET is_active = :is_active, updated_at = :updated_at, next_run_at = CASE WHEN :is_active = 1 THEN :next_run_at ELSE next_run_at END
         WHERE id = :id'
    );
    $stmt->execute([
        ':is_active' => $active,
        ':updated_at' => app_now(),
        ':next_run_at' => immediate_next_run_at(),
        ':id' => $id,
    ]);
}

function queue_now(int $id): void
{
    $stmt = main_db()->prepare('UPDATE jobs SET next_run_at = :next_run_at, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':next_run_at' => immediate_next_run_at(),
        ':updated_at' => app_now(),
        ':id' => $id,
    ]);
}

function recent_logs(int $limit = 50): array
{
    $stmt = logs_db()->prepare('SELECT * FROM cron_logs ORDER BY id DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function write_php_job_file(int $jobId, string $code): string
{
    $trimmed = trim($code);
    if (!str_starts_with($trimmed, '<?php')) {
        $trimmed = "<?php\n" . $trimmed;
    }

    $fileName = sprintf('job_%d_%s.php', $jobId, date('Ymd_His'));
    $fullPath = PHP_JOBS_PATH . DIRECTORY_SEPARATOR . $fileName;
    file_put_contents($fullPath, $trimmed);
    return $fullPath;
}

function run_due_jobs(): array
{
    $db = main_db();
    $now = app_now();

    $stmt = $db->prepare(
        'SELECT * FROM jobs
         WHERE is_active = 1 AND datetime(next_run_at) <= datetime(:now)
         ORDER BY id ASC'
    );
    $stmt->execute([':now' => $now]);
    $jobs = $stmt->fetchAll();

    $results = [];
    foreach ($jobs as $job) {
        $results[] = run_single_job($job);
    }

    return $results;
}

function run_single_job(array $job): array
{
    $startedMicro = microtime(true);
    $startedAt = app_now();

    $status = 'success';
    $httpCode = null;
    $responseBody = '';
    $errorMessage = null;

    try {
        if ($job['type'] === 'url') {
            $response = execute_url_job((string) $job['url']);
            $httpCode = $response['http_code'];
            $responseBody = $response['body'];
            if ($httpCode >= 400) {
                $status = 'failed';
                $errorMessage = 'HTTP error code: ' . $httpCode;
            }
        } else {
            $response = execute_php_job((string) $job['php_file']);
            $responseBody = $response['output'];
            if ($response['exit_code'] !== 0) {
                $status = 'failed';
                $errorMessage = 'PHP script exit code: ' . $response['exit_code'];
            }
        }
    } catch (Throwable $e) {
        $status = 'failed';
        $errorMessage = $e->getMessage();
    }

    $durationMs = (int) round((microtime(true) - $startedMicro) * 1000);
    $finishedAt = app_now();
    $safeBody = mb_substr($responseBody, 0, MAX_LOG_BODY_LENGTH);

    $logStmt = logs_db()->prepare(
        'INSERT INTO cron_logs (job_id, job_name, job_type, started_at, finished_at, status, http_code, duration_ms, response_body, error_message)
         VALUES (:job_id, :job_name, :job_type, :started_at, :finished_at, :status, :http_code, :duration_ms, :response_body, :error_message)'
    );
    $logStmt->execute([
        ':job_id' => (int) $job['id'],
        ':job_name' => (string) $job['name'],
        ':job_type' => (string) $job['type'],
        ':started_at' => $startedAt,
        ':finished_at' => $finishedAt,
        ':status' => $status,
        ':http_code' => $httpCode,
        ':duration_ms' => $durationMs,
        ':response_body' => $safeBody,
        ':error_message' => $errorMessage,
    ]);

    $nowTimestamp = time();
    $nextRunAt = calculate_next_run_at($job, $nowTimestamp);
    $updateStmt = main_db()->prepare(
        'UPDATE jobs
         SET last_run_at = :last_run_at, next_run_at = :next_run_at, updated_at = :updated_at
         WHERE id = :id'
    );
    $updateStmt->execute([
        ':last_run_at' => $finishedAt,
        ':next_run_at' => $nextRunAt,
        ':updated_at' => $finishedAt,
        ':id' => (int) $job['id'],
    ]);

    return [
        'job_id' => (int) $job['id'],
        'job_name' => (string) $job['name'],
        'status' => $status,
        'duration_ms' => $durationMs,
        'error' => $errorMessage,
    ];
}

function execute_url_job(string $url): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT => APP_NAME . ' Scheduler',
    ]);

    $body = (string) curl_exec($ch);
    $curlError = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError !== '') {
        throw new RuntimeException('cURL error: ' . $curlError);
    }

    return [
        'http_code' => $code,
        'body' => $body,
    ];
}

function execute_php_job(string $phpFile): array
{
    if ($phpFile === '' || !is_file($phpFile)) {
        throw new RuntimeException('PHP job file does not exist.');
    }

    $command = 'php ' . escapeshellarg($phpFile);
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, BASE_PATH);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start PHP process.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $output = trim(((string) $stdout) . "\n" . ((string) $stderr));
    return [
        'exit_code' => (int) $exitCode,
        'output' => $output,
    ];
}
