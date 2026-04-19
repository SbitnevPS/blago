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

$tokenPayload = $vkPayload;
if (isset($vkPayload['payload']) && is_array($vkPayload['payload'])) {
    $tokenPayload = $vkPayload['payload'];
}
if (isset($tokenPayload['tokens']) && is_array($tokenPayload['tokens'])) {
    $tokenPayload = array_merge($tokenPayload, $tokenPayload['tokens']);
}

$code = trim((string) ($tokenPayload['code'] ?? ''));
$deviceId = trim((string) ($tokenPayload['device_id'] ?? $tokenPayload['deviceId'] ?? ''));
$state = trim((string) ($tokenPayload['state'] ?? ''));

$flowData = vkid_sdk_flow_consume('user', $state);
$codeVerifier = (string) ($flowData['code_verifier'] ?? '');
if ($code === '' || $deviceId === '' || $state === '' || $codeVerifier === '') {
    jsonResponse(['success' => false, 'error' => 'Не удалось завершить вход через VK ID. Повторите попытку.'], 400);
}

$exchange = vkid_exchange_sdk_code_for_tokens($code, $deviceId, $codeVerifier, VK_USER_REDIRECT_URI, $state);
if (empty($exchange['ok'])) {
    jsonResponse([
        'success' => false,
        'error' => vk_error_message((string) ($exchange['error'] ?? 'exchange_failed')),
    ], 400);
}

$tokenJson = is_array($exchange['payload'] ?? null) ? $exchange['payload'] : [];
$accessToken = trim((string) ($tokenJson['access_token'] ?? ''));
$idToken = trim((string) ($tokenJson['id_token'] ?? ''));
$refreshToken = trim((string) ($tokenJson['refresh_token'] ?? ''));
$vkUserId = trim((string) ($tokenJson['user_id'] ?? $tokenJson['sub'] ?? ''));
$vkEmail = trim((string) ($tokenJson['email'] ?? ''));

$claims = $tokenJson;
$claims['device_id'] = $deviceId;
$claims['state'] = $state;

vk_auth_log('sdk_login_request_payload', [
    'has_access_token' => $accessToken !== '',
    'has_id_token' => $idToken !== '',
    'has_refresh_token' => $refreshToken !== '',
    'has_user_id' => $vkUserId !== '',
    'has_email' => $vkEmail !== '',
    'scope' => $claims['scope'] ?? null,
    'state' => $state !== '' ? vk_mask_state($state) : null,
    'exchange_endpoint' => 'https://id.vk.ru/oauth2/auth',
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
