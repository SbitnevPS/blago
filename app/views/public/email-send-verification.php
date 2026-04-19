<?php
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

if (!isAuthenticated()) {
    jsonResponse(['success' => false, 'message' => 'Требуется авторизация'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Ошибка безопасности'], 422);
}

$user = getCurrentUser();
if (!is_array($user)) {
    jsonResponse(['success' => false, 'message' => 'Пользователь не найден'], 422);
}

$postedEmail = trim((string) ($_POST['email'] ?? ''));
if (empty($user['email']) && $postedEmail !== '') {
    if (!filter_var($postedEmail, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'Введите корректный email'], 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
    $stmt->execute([$postedEmail, (int) $user['id']]);
    if ($stmt->fetch()) {
        jsonResponse(['success' => false, 'message' => 'Этот email уже используется другим пользователем'], 422);
    }

    $stmt = $pdo->prepare('UPDATE users SET email = ?, email_verified = 0, email_verified_at = NULL, email_verification_token = NULL, email_verification_sent_at = NULL, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$postedEmail, (int) $user['id']]);
    $user = getCurrentUser();
}

if (!is_array($user) || empty($user['email'])) {
    jsonResponse(['success' => false, 'message' => 'Укажите email в профиле'], 422);
}

$result = sendEmailVerificationForUserId((int) $user['id']);
if (!$result['ok']) {
    jsonResponse(['success' => false, 'message' => $result['message']], 500);
}

jsonResponse(['success' => true, 'message' => $result['message']]);
