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
if (!is_array($user) || empty($user['email'])) {
    jsonResponse(['success' => false, 'message' => 'Укажите email в профиле'], 422);
}

$result = sendEmailVerificationForUserId((int) $user['id']);
if (!$result['ok']) {
    jsonResponse(['success' => false, 'message' => $result['message']], 500);
}

jsonResponse(['success' => true, 'message' => $result['message']]);
