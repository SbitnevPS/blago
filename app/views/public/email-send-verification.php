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

if (isUserEmailVerified($user)) {
    jsonResponse(['success' => true, 'message' => 'Адрес уже подтверждён']);
}

$token = bin2hex(random_bytes(32));
$verificationUrl = buildEmailVerificationUrl($token, (int) $user['id']);
$userName = trim((string) ($user['name'] ?? ''));

$stmt = $pdo->prepare('UPDATE users SET email_verification_token = ?, email_verification_sent_at = NOW() WHERE id = ?');
$stmt->execute([$token, (int) $user['id']]);

$subject = 'Подтверждение адреса электронной почты';
$html = buildEmailVerificationTemplate([
    'user_name' => $userName,
    'verification_url' => $verificationUrl,
]);
$text = buildEmailVerificationText([
    'user_name' => $userName,
    'verification_url' => $verificationUrl,
]);

$sent = sendEmail((string) $user['email'], $subject, $html, ['alt_body' => $text]);
if (!$sent) {
    jsonResponse(['success' => false, 'message' => 'Не удалось отправить письмо. Попробуйте позже.'], 500);
}

jsonResponse([
    'success' => true,
    'message' => 'Письмо для подтверждения отправлено на ваш адрес электронной почты. Проверьте входящие сообщения.',
]);
