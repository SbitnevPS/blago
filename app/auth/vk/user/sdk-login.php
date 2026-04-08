<?php
require_once dirname(__DIR__, 4) . '/config.php';
require_once dirname(__DIR__, 4) . '/includes/auth/vk-oauth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? ''));
if (!verifyCSRFToken($csrfToken)) {
    jsonResponse(['success' => false, 'error' => 'Не удалось инициализировать вход через VK ID.'], 403);
}

$raw = file_get_contents('php://input');
$body = json_decode((string) $raw, true);
if (!is_array($body)) {
    $body = [];
}

$redirect = (string) ($body['redirect'] ?? ($_POST['redirect'] ?? '/contests'));
$vkPayload = $body['vk'] ?? [];
$vkPayload = is_array($vkPayload) ? $vkPayload : [];

$accessToken = trim((string) ($vkPayload['access_token'] ?? $vkPayload['accessToken'] ?? ''));
$vkUserId = trim((string) ($vkPayload['user_id'] ?? $vkPayload['userId'] ?? ''));
$vkEmail = trim((string) ($vkPayload['email'] ?? ''));

$result = vk_user_login_by_access_token($pdo, $accessToken, $redirect, $vkUserId, $vkEmail);
if (empty($result['ok'])) {
    jsonResponse([
        'success' => false,
        'error' => vk_error_message((string) ($result['error_code'] ?? 'exchange_failed')),
    ], 400);
}

jsonResponse([
    'success' => true,
    'redirect_to' => $result['redirect_to'] ?? '/contests',
]);
