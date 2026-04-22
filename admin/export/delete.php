<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/export-archive.php';

if (!isAdmin()) {
    redirect('/admin/login');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Метод не поддерживается');
}

check_csrf();

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Ошибка безопасности (CSRF).');
}

$initError = null;
if (!exportArchiveEnsureTable($pdo, $initError)) {
    http_response_code(500);
    exit((string) $initError);
}

$jobId = max(0, (int) ($_POST['id'] ?? 0));
if ($jobId <= 0) {
    http_response_code(400);
    exit('Некорректный ID задания.');
}

try {
    exportArchiveDeleteJob($pdo, $jobId);
    redirect('/admin/export');
} catch (Throwable $e) {
    http_response_code(400);
    exit($e->getMessage());
}
