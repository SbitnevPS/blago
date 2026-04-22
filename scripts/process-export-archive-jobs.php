<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/export-archive.php';

ignore_user_abort(true);
set_time_limit(0);

while (exportArchiveProcessNextJob($pdo)) {
    // Проходим последовательно по очереди задач.
}
