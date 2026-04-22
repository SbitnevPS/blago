<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/export-archive.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}


$initError = null;
if (!exportArchiveEnsureTable($pdo, $initError)) {
    http_response_code(500);
    echo json_encode(['error' => 'table_init_failed', 'message' => $initError], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string) ($_GET['action'] ?? '');

if ($action === 'status') {
    $jobId = max(0, (int) ($_GET['id'] ?? 0));
    $stmt = $pdo->prepare('SELECT status, progress_percent, progress_stage, error_message FROM export_archive_jobs WHERE id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        exit;
    }

    echo json_encode([
        'status' => (string) ($row['status'] ?? ''),
        'progress_percent' => (int) ($row['progress_percent'] ?? 0),
        'progress_stage' => (string) ($row['progress_stage'] ?? ''),
        'error_message' => (string) ($row['error_message'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'bad_request']);
