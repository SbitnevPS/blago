<?php
// admin/search-users.php - AJAX поиск пользователей
error_reporting(0);
ini_set('display_errors',0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Для предотвращения проблем с CORS
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
 $searchTerm = "%$query%";
 $idQuery = $isNumericQuery ? (int) $query : 0;
 $searchConditions = build_user_search_conditions();
 $searchSql = implode("\n     OR ", $searchConditions);

 $stmt = $pdo->prepare("
 SELECT id, name, surname, email 
 FROM users 
 WHERE (
     {$searchSql}
     OR (? > 0 AND id = ?)
 )
 ORDER BY surname ASC, name ASC
 LIMIT $limit
 ");

 $params = array_fill(0, count($searchConditions), $searchTerm);
 $params[] = $idQuery;
 $params[] = $idQuery;
 $stmt->execute($params);
 $users = $stmt->fetchAll();

 echo json_encode($users, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
 echo json_encode(['error' => $e->getMessage()]);
}
