<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');

define('APP_NAME', 'CronJobs Manager');
define('APP_TIMEZONE', 'Asia/Tehran');

define('BASE_PATH', __DIR__);
define('STORAGE_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'storage');
define('MAIN_DB_PATH', STORAGE_PATH . DIRECTORY_SEPARATOR . 'app.sqlite');
define('LOG_DB_PATH', STORAGE_PATH . DIRECTORY_SEPARATOR . 'logs.sqlite');
define('PHP_JOBS_PATH', STORAGE_PATH . DIRECTORY_SEPARATOR . 'php_jobs');

define('MAX_LOG_BODY_LENGTH', 10000);
