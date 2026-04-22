<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/export-archive.php';

ignore_user_abort(true);
set_time_limit(0);


$initError = null;
if (!exportArchiveEnsureTable($pdo, $initError)) {
    fwrite(STDERR, $initError . PHP_EOL);
    exit(1);
}

while (exportArchiveProcessNextJob($pdo)) {
    // Проходим последовательно по очереди задач.
}
