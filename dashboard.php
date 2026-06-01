<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editingJob = $editId > 0 ? find_job($editId) : null;

$jobs = all_jobs();
$logs = recent_logs(40);
$schedulerKey = get_setting('scheduler_key');
$host = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$schedulerUrl = $host . '/cron.php?key=' . urlencode($schedulerKey);

render_header('Dashboard');
?>
<div class="card">
    <div class="split">
        <div>
            <h2 style="margin: 0 0 6px;">Scheduler Endpoint</h2>
            <p class="muted" style="margin: 0 0 8px;">Set this URL in DirectAdmin cron to run every 1 minute. The app handles all intervals internally.</p>
        </div>
    </div>
    <div class="mono"><?= h($schedulerUrl) ?></div>
</div>

<div class="card">
    <h2 style="margin-top: 0;"><?= $editingJob ? 'Edit Cron Job' : 'Create Cron Job' ?></h2>
    <form method="post" action="/save-job.php" class="grid">
        <input type="hidden" name="id" value="<?= $editingJob ? (int) $editingJob['id'] : '' ?>">
        <div class="grid grid-2">
            <div>
                <label for="name">Job Name</label>
                <input id="name" name="name" required value="<?= h((string) ($editingJob['name'] ?? '')) ?>" placeholder="Example: Sync orders">
            </div>
            <div>
                <label for="interval_minutes">Run every (minutes)</label>
                <input id="interval_minutes" name="interval_minutes" type="number" min="1" required value="<?= h((string) ($editingJob['interval_minutes'] ?? 1)) ?>">
            </div>
        </div>

        <div class="grid grid-2">
            <div>
                <label for="type">Type</label>
                <select id="type" name="type">
                    <?php $selectedType = (string) ($editingJob['type'] ?? 'url'); ?>
                    <option value="url" <?= $selectedType === 'url' ? 'selected' : '' ?>>URL Request</option>
                    <option value="php" <?= $selectedType === 'php' ? 'selected' : '' ?>>PHP Code</option>
                </select>
            </div>
            <div id="url_wrapper">
                <label for="url">URL</label>
                <input id="url" name="url" placeholder="https://example.com/cron/task" value="<?= h((string) ($editingJob['url'] ?? '')) ?>">
            </div>
        </div>

        <div id="php_wrapper" style="display:none;">
            <label for="php_code">PHP Code <?= $editingJob ? '(optional: fill only if replacing existing file)' : '' ?></label>
            <textarea id="php_code" name="php_code" placeholder="echo 'Hello from Cron';"></textarea>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit"><?= $editingJob ? 'Update Job' : 'Save Job' ?></button>
            <?php if ($editingJob): ?>
                <a class="btn btn-muted" href="/dashboard.php">Cancel Edit</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h2 style="margin-top: 0;">Cron Jobs</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Type</th>
                <th>Interval</th>
                <th>Status</th>
                <th>Last Run</th>
                <th>Next Run</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$jobs): ?>
            <tr><td colspan="8">No jobs yet.</td></tr>
        <?php else: ?>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td data-label="ID"><?= (int) $job['id'] ?></td>
                    <td data-label="Name"><?= h((string) $job['name']) ?></td>
                    <td data-label="Type"><?= strtoupper((string) $job['type']) ?></td>
                    <td data-label="Interval"><?= (int) $job['interval_minutes'] ?> min</td>
                    <td data-label="Status">
                        <?php if ((int) $job['is_active'] === 1): ?>
                            <span class="badge badge-success">Active</span>
                        <?php else: ?>
                            <span class="badge badge-muted">Paused</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Last Run"><?= h((string) ($job['last_run_at'] ?? '-')) ?></td>
                    <td data-label="Next Run"><?= h((string) $job['next_run_at']) ?></td>
                    <td data-label="Actions">
                        <div class="actions">
                            <a class="btn btn-sm btn-light" href="/dashboard.php?edit=<?= (int) $job['id'] ?>">Edit</a>
                            <form method="post" action="/toggle-job.php">
                                <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                <button class="btn btn-sm btn-muted" type="submit"><?= (int) $job['is_active'] === 1 ? 'Pause' : 'Activate' ?></button>
                            </form>
                            <form method="post" action="/save-job.php">
                                <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                <input type="hidden" name="queue_now" value="1">
                                <button class="btn btn-sm btn-muted" type="submit">Run ASAP</button>
                            </form>
                            <form method="post" action="/delete-job.php" onsubmit="return confirm('Delete this job?');">
                                <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2 style="margin-top: 0;">Recent Logs (from logs.sqlite)</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Job</th>
                <th>Status</th>
                <th>Started</th>
                <th>Duration</th>
                <th>HTTP</th>
                <th>Response</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$logs): ?>
            <tr><td colspan="7">No logs yet.</td></tr>
        <?php else: ?>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td data-label="ID"><?= (int) $log['id'] ?></td>
                    <td data-label="Job"><?= h((string) $log['job_name']) ?> (#<?= (int) $log['job_id'] ?>)</td>
                    <td data-label="Status">
                        <?php if ($log['status'] === 'success'): ?>
                            <span class="badge badge-success">Success</span>
                        <?php else: ?>
                            <span class="badge badge-muted">Failed</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Started"><?= h((string) $log['started_at']) ?></td>
                    <td data-label="Duration"><?= (int) $log['duration_ms'] ?> ms</td>
                    <td data-label="HTTP"><?= h((string) ($log['http_code'] ?? '-')) ?></td>
                    <td data-label="Response">
                        <div class="mono" style="max-height:80px; overflow:auto; font-size:0.82rem;">
                            <?= h((string) ($log['error_message'] ?: $log['response_body'])) ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
    (function () {
        const typeField = document.getElementById('type');
        const urlWrapper = document.getElementById('url_wrapper');
        const phpWrapper = document.getElementById('php_wrapper');
        const urlInput = document.getElementById('url');

        function syncType() {
            const isPhp = typeField.value === 'php';
            phpWrapper.style.display = isPhp ? 'block' : 'none';
            urlWrapper.style.display = isPhp ? 'none' : 'block';
            if (isPhp) {
                urlInput.removeAttribute('required');
            } else {
                urlInput.setAttribute('required', 'required');
            }
        }

        typeField.addEventListener('change', syncType);
        syncType();
    })();
</script>
<?php render_footer(); ?>
