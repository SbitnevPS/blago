<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

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

    $limit = (int) ($_GET['limit'] ?? 7);
    $limit = max(1, min(7, $limit));
    $searchTerm = '%' . $query . '%';
    $idQuery = $isNumericQuery ? (int) $query : 0;
    $userSearchConditions = build_user_search_conditions('u');
    $userSearchSql = implode("\n                OR ", $userSearchConditions);

    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.status,
            c.title AS contest_title,
            u.name,
            u.surname,
            u.email
        FROM applications a
        INNER JOIN users u ON u.id = a.user_id
        INNER JOIN contests c ON c.id = a.contest_id
        WHERE (
            (? > 0 AND a.id = ?)
            OR {$userSearchSql}
            OR c.title LIKE ?
        )
        ORDER BY a.id DESC
        LIMIT $limit
    ");

    $params = [$idQuery, $idQuery];
    $params = array_merge($params, array_fill(0, count($userSearchConditions), $searchTerm));
    $params[] = $searchTerm;

    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
