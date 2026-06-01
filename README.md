# CronJobs Manager (PHP + SQLite)

Simple admin panel to manage cron jobs with:

- Default login: `admin / admin`
- Change password from panel
- Jobs as URL request or custom PHP code
- Automatic SQLite database creation
- Separate SQLite database for execution logs
- Timezone set to **Asia/Tehran**
- English UI (minimal and clean)

## Files

- Main DB: `storage/app.sqlite`
- Logs DB: `storage/logs.sqlite`
- Generated PHP jobs: `storage/php_jobs/*.php`
- Scheduler endpoint: `cron.php?key=...`

## How it works

1. Open the site in browser.
2. Login with `admin/admin`.
3. Create jobs:
   - Type `URL` -> enter target URL
   - Type `PHP Code` -> enter script code
4. Set interval in minutes (1, 5, 10, ...).
5. Put scheduler URL (shown in dashboard) into DirectAdmin cron and run it every 1 minute.

The system itself checks due jobs and executes them at their own intervals.

## DirectAdmin cron example

Use **every minute**:

```bash
wget -qO- "https://your-domain.com/cron.php?key=YOUR_SCHEDULER_KEY" >/dev/null 2>&1
```

Or:

```bash
curl -s "https://your-domain.com/cron.php?key=YOUR_SCHEDULER_KEY" >/dev/null 2>&1
```

## Notes

- Change the default admin password after first login.
- Keep scheduler URL secret (it contains your key).
- PHP code jobs are executed with server `php` CLI.
