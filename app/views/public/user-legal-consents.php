<?php
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

if (!isAuthenticated()) {
    jsonResponse(['success' => false, 'message' => 'Требуется авторизация.'], 401);
}

check_csrf();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Ошибка безопасности.'], 422);
}

$agreePersonalData = isset($_POST['agree_personal_data']);
$agreeTerms = isset($_POST['agree_terms']);
if (!$agreePersonalData || !$agreeTerms) {
    jsonResponse(['success' => false, 'message' => 'Подтвердите оба согласия.'], 422);
}

$userId = (int) (getCurrentUserId() ?? 0);
if ($userId <= 0) {
    jsonResponse(['success' => false, 'message' => 'Пользователь не найден.'], 404);
}

global $pdo;
$stmt = $pdo->prepare('UPDATE users SET agree_personal_data = 1, agree_personal_data_at = NOW(), agree_terms = 1, agree_terms_at = NOW(), updated_at = NOW() WHERE id = ?');
$stmt->execute([$userId]);

$_SESSION['user'] = null;
jsonResponse(['success' => true, 'message' => 'Согласия сохранены.']);
