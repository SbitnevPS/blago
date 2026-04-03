<?php
// mark-message-read.php - Пометка сообщения как прочитанного
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAuthenticated()) {
 echo json_encode(['success' => false, 'error' => 'Not authenticated']);
 exit;
}

$messageId = $_GET['id'] ?? null;

if (!$messageId) {
 echo json_encode(['success' => false, 'error' => 'No message ID']);
 exit;
}

$userId = getCurrentUserId();

try {
 // Проверяем, что сообщение принадлежит пользователю
 $stmt = $pdo->prepare("SELECT id FROM admin_messages WHERE id = ? AND user_id = ?");
 $stmt->execute([$messageId, $userId]);
 $message = $stmt->fetch();
 
 if ($message) {
 $stmt = $pdo->prepare("UPDATE admin_messages SET is_read =1 WHERE id = ?");
 $stmt->execute([$messageId]);
 echo json_encode(['success' => true]);
 } else {
 echo json_encode(['success' => false, 'error' => 'Message not found']);
 }
} catch (Exception $e) {
 echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
