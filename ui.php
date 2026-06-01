<?php

declare(strict_types=1);

function render_header(string $title): void
{
    $admin = current_admin();
    $flash = get_flash();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?= h($title) ?> - <?= h(APP_NAME) ?></title>
        <style>
            :root {
                color-scheme: light;
                --bg: #f5f7fb;
                --card: #ffffff;
                --text: #1f2937;
                --muted: #6b7280;
                --primary: #2563eb;
                --danger: #dc2626;
                --success: #16a34a;
                --border: #e5e7eb;
            }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                font-family: "Segoe UI", Tahoma, Arial, sans-serif;
                background: var(--bg);
                color: var(--text);
                line-height: 1.45;
            }
            .container { width: min(1100px, 94vw); margin: 24px auto; }
            .card {
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: 14px;
                padding: 18px;
                box-shadow: 0 8px 18px rgba(15, 23, 42, 0.04);
                margin-bottom: 16px;
            }
            .topbar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 14px;
                gap: 10px;
            }
            .title { margin: 0; font-size: 1.25rem; }
            .muted { color: var(--muted); font-size: 0.95rem; }
            .grid { display: grid; gap: 12px; }
            .grid-2 { grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); }
            label { display: block; margin-bottom: 6px; font-size: 0.9rem; color: #334155; }
            input, select, textarea {
                width: 100%;
                border: 1px solid #d1d5db;
                border-radius: 10px;
                padding: 10px 12px;
                font-size: 0.95rem;
                outline: none;
                background: #fff;
            }
            input:focus, select:focus, textarea:focus {
                border-color: var(--primary);
                box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
            }
            textarea { min-height: 170px; resize: vertical; font-family: Consolas, monospace; }
            .btn {
                border: 0;
                border-radius: 10px;
                padding: 10px 14px;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
            }
            .btn-primary { background: var(--primary); color: #fff; }
            .btn-danger { background: var(--danger); color: #fff; }
            .btn-light { background: #eef2ff; color: #1e40af; }
            .btn-muted { background: #f3f4f6; color: #111827; }
            .btn-sm { padding: 8px 10px; font-size: 0.85rem; border-radius: 9px; }
            .actions { display: flex; gap: 8px; flex-wrap: wrap; }
            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 0.93rem;
            }
            th, td {
                border-bottom: 1px solid var(--border);
                padding: 10px 8px;
                text-align: left;
                vertical-align: top;
            }
            th { color: #334155; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.03em; }
            .badge {
                padding: 4px 8px;
                border-radius: 999px;
                font-size: 0.78rem;
                display: inline-block;
                font-weight: 600;
            }
            .badge-success { background: #dcfce7; color: #166534; }
            .badge-muted { background: #e5e7eb; color: #374151; }
            .alert {
                border-radius: 10px;
                padding: 12px 14px;
                margin-bottom: 12px;
                font-size: 0.92rem;
            }
            .alert-success { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
            .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
            .split {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                flex-wrap: wrap;
            }
            .mono {
                font-family: Consolas, monospace;
                background: #f8fafc;
                border: 1px solid var(--border);
                padding: 8px 10px;
                border-radius: 8px;
                word-break: break-all;
            }
            @media (max-width: 720px) {
                table, thead, tbody, th, td, tr { display: block; }
                thead { display: none; }
                tr {
                    border: 1px solid var(--border);
                    border-radius: 10px;
                    margin-bottom: 12px;
                    padding: 4px;
                    background: #fff;
                }
                td { border-bottom: 0; padding: 7px; }
                td::before {
                    content: attr(data-label);
                    display: block;
                    color: #64748b;
                    font-size: 0.75rem;
                    margin-bottom: 4px;
                }
            }
        </style>
    </head>
    <body>
    <div class="container">
        <div class="topbar">
            <h1 class="title"><?= h(APP_NAME) ?></h1>
            <?php if ($admin): ?>
                <div class="actions">
                    <span class="muted">Signed in as <strong><?= h((string) $admin['username']) ?></strong></span>
                    <a class="btn btn-sm btn-light" href="/change-password.php">Change Password</a>
                    <a class="btn btn-sm btn-muted" href="/logout.php">Logout</a>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($flash): ?>
            <div class="alert <?= $flash['type'] === 'error' ? 'alert-error' : 'alert-success' ?>">
                <?= h((string) $flash['message']) ?>
            </div>
        <?php endif; ?>
    <?php
}

function render_footer(): void
{
    ?>
    </div>
    </body>
    </html>
    <?php
}
