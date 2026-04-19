<?php
// mark-message-read.php - Пометка сообщения как прочитанного
require_once __DIR__ . '/config.php';

if (!isAuthenticated()) {
    jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
}

if (!isPostRequest()) {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
}

$userId = getCurrentUserId();
$markAll = (string) ($_POST['mark_all'] ?? '') === '1';
$messageId = intval($_POST['id'] ?? 0);

if (!$markAll && $messageId <= 0) {
    jsonResponse(['success' => false, 'error' => 'Invalid message ID'], 400);
}

try {
    if ($markAll) {
        $stmt = $pdo->prepare("UPDATE admin_messages SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        jsonResponse(['success' => true]);
    }

    // Проверяем, что сообщение принадлежит пользователю
    $stmt = $pdo->prepare("SELECT id FROM admin_messages WHERE id = ? AND user_id = ?");
    $stmt->execute([$messageId, $userId]);
    $message = $stmt->fetch();
    
    if ($message) {
        $stmt = $pdo->prepare("UPDATE admin_messages SET is_read = 1 WHERE id = ?");
        $stmt->execute([$messageId]);
        jsonResponse(['success' => true]);
    } else {
        jsonResponse(['success' => false, 'error' => 'Message not found'], 404);
    }
} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
