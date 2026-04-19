<?php
require_once dirname(__DIR__, 4) . '/config.php';
require_once dirname(__DIR__, 4) . '/includes/init.php';

if (!isAdmin()) {
    redirect('/admin/login?auth_error=admin_access_denied');
}

$state = trim((string) ($_GET['state'] ?? ''));
$code = trim((string) ($_GET['code'] ?? ''));
$hasError = trim((string) ($_GET['error'] ?? '')) !== '';
$sessionFlow = vk_flow_session_get('publication');

if ($hasError || $code === '' || $state === '' || !vk_flow_session_is_valid('publication', $sessionFlow)) {
    vk_flow_session_clear('publication');
    $_SESSION['flash_error'] = 'Не удалось завершить подключение VK для публикации.';
    redirect('/admin/settings#vk-integration');
}

$expectedState = (string) ($sessionFlow['state'] ?? '');
$storedVerifier = (string) ($sessionFlow['code_verifier'] ?? '');
if ($expectedState === '' || $storedVerifier === '' || !hash_equals($expectedState, $state)) {
    vk_flow_session_clear('publication');
    $_SESSION['flash_error'] = 'Некорректный ответ VK OAuth для публикации.';
    redirect('/admin/settings#vk-integration');
}

$tokenResponse = vk_http_get('https://oauth.vk.com/access_token?' . http_build_query([
    'client_id' => VK_CLIENT_ID,
    'client_secret' => VK_CLIENT_SECRET,
    'redirect_uri' => VK_PUBLICATION_REDIRECT_URI,
    'code' => $code,
    'code_verifier' => $storedVerifier,
    'grant_type' => 'authorization_code',
]));
$tokenJson = is_array($tokenResponse['json']) ? $tokenResponse['json'] : [];

if (!$tokenResponse['ok'] || !empty($tokenJson['error']) || empty($tokenJson['access_token']) || empty($tokenJson['user_id'])) {
    vk_flow_session_clear('publication');
    $_SESSION['flash_error'] = 'VK OAuth не вернул рабочий access token для публикации.';
    redirect('/admin/settings#vk-integration');
}

$accessToken = trim((string) ($tokenJson['access_token'] ?? ''));
$refreshToken = trim((string) ($tokenJson['refresh_token'] ?? ''));
$expiresIn = (int) ($tokenJson['expires_in'] ?? 0);
$expiresAt = $expiresIn > 0 ? (time() + $expiresIn) : 0;
$userId = (int) ($tokenJson['user_id'] ?? 0);
$deviceId = trim((string) ($tokenJson['device_id'] ?? ''));

$scopes = vk_scope_normalize((string) ($tokenJson['scope'] ?? ''), ',');
$scopeList = array_filter(array_map('trim', explode(',', $scopes)));
$requiredScopes = ['wall', 'photos'];
$missingScopes = [];
foreach ($requiredScopes as $requiredScope) {
    if (!in_array($requiredScope, $scopeList, true)) {
        $missingScopes[] = $requiredScope;
    }
}
if (!empty($missingScopes)) {
    vk_flow_session_clear('publication');
    $_SESSION['flash_error'] = 'Недостаточно прав OAuth VK для публикации: отсутствуют ' . implode(', ', $missingScopes) . '.';
    redirect('/admin/settings#vk-integration');
}

$pending = [
    'vk_publication_admin_access_token_encrypted' => vkPublicationEncryptValue($accessToken),
    'vk_publication_admin_refresh_token_encrypted' => vkPublicationEncryptValue($refreshToken),
    'vk_publication_admin_token_expires_at' => $expiresAt,
    'vk_publication_admin_device_id' => $deviceId,
    'vk_publication_admin_user_id' => $userId,
];
saveSystemSettings($pending);

$readiness = verifyVkPublicationReadiness(false, false, 'diagnostic');
if (empty($readiness['ok'])) {
    saveSystemSettings([
        'vk_publication_admin_access_token_encrypted' => '',
        'vk_publication_admin_refresh_token_encrypted' => '',
        'vk_publication_admin_token_expires_at' => 0,
        'vk_publication_admin_device_id' => '',
        'vk_publication_admin_user_id' => 0,
    ]);

    $_SESSION['flash_error'] = !empty($readiness['issues'])
        ? implode('; ', (array) $readiness['issues'])
        : 'Токен VK не прошёл проверку прав публикации.';
    vk_flow_session_clear('publication');
    redirect('/admin/settings#vk-integration');
}

vk_flow_session_clear('publication');
$_SESSION['success_message'] = 'VK для публикации успешно подключён.';
redirect('/admin/settings#vk-integration');
