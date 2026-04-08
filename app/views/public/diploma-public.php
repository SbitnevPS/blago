<?php
require_once dirname(__DIR__, 3) . '/config.php';

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(404);
    echo 'Диплом не найден';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM participant_diplomas WHERE public_token = ? LIMIT 1');
$stmt->execute([$token]);
$diploma = $stmt->fetch();
if (!$diploma) {
    http_response_code(404);
    echo 'Диплом не найден';
    exit;
}

if (empty($diploma['file_path']) || !is_file(ROOT_PATH . '/' . $diploma['file_path'])) {
    if (!empty($diploma['work_id'])) {
        $diploma = generateWorkDiploma((int)$diploma['work_id'], true);
    } else {
        $diploma = generateParticipantDiploma((int)$diploma['participant_id'], true);
    }
}

$file = ROOT_PATH . '/' . $diploma['file_path'];
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="diploma.pdf"');
readfile($file);
exit;
