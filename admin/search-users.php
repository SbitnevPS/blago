<?php
// admin/search-users.php - AJAX поиск пользователей
error_reporting(0);
ini_set('display_errors',0);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Для предотвращения проблем с CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
 exit;
}

try {
 // Получаем поисковый запрос
 $query = $_GET['q'] ?? '';
 $searchTerm = "%$query%";
 
 $stmt = $pdo->prepare("
 SELECT id, name, surname, email 
 FROM users 
 WHERE is_admin =0
 AND (name LIKE ? OR surname LIKE ? OR email LIKE ?)
 ORDER BY name, surname
 LIMIT 10
 ");

 $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
 $users = $stmt->fetchAll();

 echo json_encode($users, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
 echo json_encode(['error' => $e->getMessage()]);
}
