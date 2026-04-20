<?php
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

$userId = (int) (getCurrentUserId() ?? 0);
$messageId = max(0, (int) ($_POST['id'] ?? 0));

if ($userId <= 0 || $messageId <= 0) {
    jsonResponse(['success' => false, 'error' => 'Invalid message ID'], 400);
}

try {
    $checkStmt = $pdo->prepare("
        SELECT id
        FROM admin_messages
        WHERE id = ?
          AND user_id = ?
        LIMIT 1
    ");
    $checkStmt->execute([$messageId, $userId]);
    if (!(int) $checkStmt->fetchColumn()) {
        jsonResponse(['success' => false, 'error' => 'Message not found'], 404);
    }

    $deleteStmt = $pdo->prepare("DELETE FROM admin_messages WHERE id = ? LIMIT 1");
    $deleteStmt->execute([$messageId]);

    jsonResponse(['success' => true]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
