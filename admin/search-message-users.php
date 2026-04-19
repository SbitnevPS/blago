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

    $limit = (int) ($_GET['limit'] ?? 7);
    $limit = max(1, min(7, $limit));
    $searchTerm = '%' . $query . '%';
    $idQuery = $isNumericQuery ? (int) $query : 0;
    $searchConditions = build_user_search_conditions('u');
    $searchSql = implode("\n            OR ", $searchConditions);

    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.name, u.surname, u.email
        FROM admin_messages am
        INNER JOIN users u ON u.id = am.user_id
        WHERE am.user_id IS NOT NULL
          AND (
            {$searchSql}
            OR (? > 0 AND u.id = ?)
          )
        ORDER BY u.surname ASC, u.name ASC
        LIMIT $limit
    ");

    $params = array_fill(0, count($searchConditions), $searchTerm);
    $params[] = $idQuery;
    $params[] = $idQuery;
    $stmt->execute($params);

    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([]);
}
