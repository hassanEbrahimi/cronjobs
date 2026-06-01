<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

logout_admin();
session_start();
set_flash('You are logged out.');
redirect('/index.php');
