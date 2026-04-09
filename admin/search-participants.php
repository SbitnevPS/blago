<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

if (!isAdmin()) {
    echo json_encode([]);
    exit;
}

try {
    $query = trim((string) ($_GET['q'] ?? ''));
    $isNumericQuery = ctype_digit($query);
    if (!$isNumericQuery && mb_strlen($query) < 2) {
        echo json_encode([]);
        exit;
    }

    $limit = (int) ($_GET['limit'] ?? 8);
    $limit = max(1, min(12, $limit));
    $searchTerm = '%' . $query . '%';
    $exactParticipantId = $isNumericQuery ? (int) $query : 0;

    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.fio,
            p.region,
            u.email,
            a.id AS application_id
        FROM participants p
        JOIN applications a ON a.id = p.application_id
        JOIN users u ON u.id = a.user_id
        WHERE (p.id = ? AND ? > 0)
           OR p.fio LIKE ?
           OR p.region LIKE ?
           OR u.email LIKE ?
           OR CAST(p.id AS CHAR) LIKE ?
        ORDER BY p.fio ASC, p.id ASC
        LIMIT $limit
    ");
    $stmt->execute([$exactParticipantId, $exactParticipantId, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);

    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([]);
}
