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
$idToken = trim((string) ($vkPayload['id_token'] ?? $vkPayload['idToken'] ?? ''));
$refreshToken = trim((string) ($vkPayload['refresh_token'] ?? $vkPayload['refreshToken'] ?? ''));
$vkUserId = trim((string) ($vkPayload['user_id'] ?? $vkPayload['userId'] ?? ''));
$vkEmail = trim((string) ($vkPayload['email'] ?? ''));
$claims = [];
foreach ($vkPayload as $key => $value) {
    if (is_scalar($value) || $value === null) {
        $claims[$key] = $value;
    }
}

if (isset($vkPayload['user']) && is_array($vkPayload['user'])) {
    foreach ($vkPayload['user'] as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $claims[$key] = $value;
        }
    }
}

vk_auth_log('sdk_login_request_payload', [
    'has_access_token' => $accessToken !== '',
    'has_id_token' => $idToken !== '',
    'has_refresh_token' => $refreshToken !== '',
    'has_user_id' => $vkUserId !== '',
    'has_email' => $vkEmail !== '',
    'scope' => $vkPayload['scope'] ?? null,
    'state' => $vkPayload['state'] ?? null,
    'payload_keys' => array_keys($vkPayload),
    'claims_keys' => array_keys($claims),
]);

$result = vk_user_login_by_access_token($pdo, $accessToken, $redirect, $vkUserId, $vkEmail, $idToken, $refreshToken, $claims);
if (empty($result['ok'])) {
    vk_auth_log('sdk_login_failed', [
        'error_code' => (string) ($result['error_code'] ?? 'exchange_failed'),
    ]);
    jsonResponse([
        'success' => false,
        'error' => vk_error_message((string) ($result['error_code'] ?? 'exchange_failed')),
    ], 400);
}

jsonResponse([
    'success' => true,
    'redirect_to' => $result['redirect_to'] ?? '/contests',
]);
