<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/init.php';

if (!isAdmin()) {
    http_response_code(403);
    exit('Доступ запрещен');
}

$jobId = max(0, (int) ($_GET['id'] ?? 0));
$stmt = $pdo->prepare("SELECT * FROM export_archive_jobs WHERE id = ? AND status = 'done' LIMIT 1");
$stmt->execute([$jobId]);
$job = $stmt->fetch();

if (!$job) {
    http_response_code(404);
    exit('Архив недоступен');
}

$path = (string) ($job['archive_file_path'] ?? '');
$realPath = realpath($path);
$storageRoot = realpath(dirname(__DIR__, 2) . '/storage/export');

if ($realPath === false || $storageRoot === false || strpos($realPath, $storageRoot) !== 0 || !is_file($realPath)) {
    http_response_code(404);
    exit('Файл не найден');
}

$fileName = trim((string) ($job['archive_file_name'] ?? 'archive.zip'));
if ($fileName === '') {
    $fileName = 'archive.zip';
}

header('Content-Description: File Transfer');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . addslashes($fileName) . '"');
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: no-cache, must-revalidate');
readfile($realPath);
exit;
