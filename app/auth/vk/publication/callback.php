<?php
require_once dirname(__DIR__, 4) . '/config.php';
require_once dirname(__DIR__, 4) . '/includes/init.php';

if (!isAdmin()) {
    $_SESSION['flash_error'] = 'Для подключения VK требуется вход в админку.';
    redirect('/admin/login');
}

function failVkPublicationOauth(string $userMessage, string $technicalMessage, string $flashMessage, string $state = 'error'): void
{
    clearVkPublicationOauthFlow();
    saveSystemSettings([
        'vk_publication_oauth_state' => $state,
        'vk_publication_oauth_last_error' => $userMessage,
        'vk_publication_oauth_last_error_technical' => mb_substr($technicalMessage, 0, 1000),
    ]);
    vkPublicationLog('oauth_callback_failed', [
        'state' => $state,
        'message' => $userMessage,
        'technical' => $technicalMessage,
    ]);
    $_SESSION['flash_error'] = $flashMessage;
    redirect('/admin/settings#vk-integration');
}

$state = trim((string) ($_GET['state'] ?? ''));
$code = trim((string) ($_GET['code'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));
$errorDescription = trim((string) ($_GET['error_description'] ?? ''));

vkPublicationLog('oauth_callback_received', [
    'has_code' => $code !== '',
    'has_state' => $state !== '',
    'error' => $error,
]);

if ($error !== '' || $state === '' || $code === '') {
    $details = $error !== '' ? ($error . ($errorDescription !== '' ? ': ' . $errorDescription : '')) : 'missing_code_or_state';
    failVkPublicationOauth(
        'VK вернул ошибку авторизации или callback пришёл без code/state.',
        $details,
        'VK вернул ошибку авторизации. Попробуйте подключить заново.'
    );
}

$flow = getVkPublicationOauthFlow();
$expectedState = trim((string) ($flow['state'] ?? ''));
$alreadyUsed = (int) ($flow['used'] ?? 0) === 1;

if (!$flow || $expectedState === '' || $alreadyUsed || !hash_equals($expectedState, $state)) {
    failVkPublicationOauth(
        'Состояние OAuth-сессии VK недействительно или истекло.',
        'state_mismatch_or_flow_missing',
        'OAuth-сессия VK истекла или недействительна. Запустите подключение заново.'
    );
}

$_SESSION[getVkPublicationOauthSessionKey()]['used'] = 1;
vkPublicationLog('oauth_state_validated', ['state_prefix' => mb_substr($state, 0, 10)]);

$tokenResponse = vkPublicationHttpPostForm(getVkPublicationOauthTokenEndpoint(), [
    'grant_type' => 'authorization_code',
    'client_id' => VK_CLIENT_ID,
    'client_secret' => VK_CLIENT_SECRET,
    'redirect_uri' => getVkPublicationRedirectUri(),
    'code' => $code,
]);
$tokenJson = is_array($tokenResponse['json']) ? $tokenResponse['json'] : [];
vkPublicationLog('oauth_token_exchange_result', [
    'http_code' => $tokenResponse['http_code'] ?? 0,
    'curl_error' => $tokenResponse['curl_error'] ?? '',
    'has_access_token' => !empty($tokenJson['access_token']),
    'has_user_id' => !empty($tokenJson['user_id']),
    'error' => $tokenJson['error'] ?? '',
    'error_description' => $tokenJson['error_description'] ?? '',
]);

if (!$tokenResponse['ok'] || !empty($tokenJson['error']) || empty($tokenJson['access_token'])) {
    $vkError = (string) ($tokenJson['error'] ?? 'exchange_failed');
    $vkErrorDescription = trim((string) ($tokenJson['error_description'] ?? ''));
    failVkPublicationOauth(
        'Не удалось обменять authorization code на токен VK.',
        $vkError . ($vkErrorDescription !== '' ? (': ' . $vkErrorDescription) : ''),
        'Не удалось завершить подключение VK. Повторите попытку.'
    );
}

$accessToken = trim((string) ($tokenJson['access_token'] ?? ''));
$tokenUserId = (int) ($tokenJson['user_id'] ?? 0);
$scopeRaw = isset($tokenJson['scope']) ? (is_array($tokenJson['scope']) ? implode(',', $tokenJson['scope']) : trim((string) $tokenJson['scope'])) : '';
$scopeItems = $scopeRaw !== '' ? array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $scopeRaw) ?: []))) : [];
$missingScopes = [];
foreach (getVkPublicationRequiredScopes() as $requiredScope) {
    if (!in_array($requiredScope, array_map('mb_strtolower', $scopeItems), true)) {
        $missingScopes[] = $requiredScope;
    }
}
if (!empty($missingScopes)) {
    failVkPublicationOauth(
        'Токен получен, но не содержит нужные права (scope).',
        'missing_scope: ' . implode(',', $missingScopes),
        'VK подключение не завершено: токен не содержит нужные права (' . implode(', ', $missingScopes) . ').'
    );
}

$userInfoResult = vkPublicationApiRequest('users.get', [
    'access_token' => $accessToken,
    'v' => VK_API_VERSION,
]);
$userInfo = $userInfoResult['ok'] ? ($userInfoResult['response'] ?? []) : [];
vkPublicationLog('oauth_users_get_result', [
    'ok' => $userInfoResult['ok'] ?? false,
    'has_user' => !empty($userInfo[0]),
]);
if (!$userInfoResult['ok'] || empty($userInfo[0])) {
    failVkPublicationOauth(
        'Токен получен, но users.get не вернул данные пользователя.',
        'users.get_failed_or_empty',
        'VK подключение не завершено: users.get не вернул пользователя.'
    );
}

$resolvedUserId = (int) ($userInfo[0]['id'] ?? 0);
if ($resolvedUserId <= 0) {
    $resolvedUserId = $tokenUserId;
}
if ($resolvedUserId <= 0) {
    failVkPublicationOauth(
        'Токен получен, но VK user ID определить не удалось.',
        'missing_user_id_in_token_and_users_get',
        'VK подключение не завершено: не удалось определить VK user ID.'
    );
}

$profileName = trim((string) (($userInfo[0]['first_name'] ?? '') . ' ' . ($userInfo[0]['last_name'] ?? '')));
$tokenJson['user_id'] = $resolvedUserId;
persistVkPublicationTokens($tokenJson, [
    'vk_publication_oauth_user_name' => $profileName,
    'vk_publication_oauth_user_profile_url' => 'https://vk.com/id' . $resolvedUserId,
    'vk_publication_oauth_state' => 'attention',
    'vk_publication_oauth_last_error' => '',
    'vk_publication_oauth_last_error_technical' => '',
]);
vkPublicationLog('oauth_token_persisted', [
    'oauth_user_id' => $resolvedUserId,
    'oauth_user_name' => $profileName,
    'users_get_ok' => true,
]);

$readiness = verifyVkPublicationReadiness(false);
if (empty($readiness['ok'])) {
    $message = implode('; ', $readiness['issues'] ?? ['Ошибка проверки прав токена']);
    saveSystemSettings([
        'vk_publication_user_token' => '',
        'vk_publication_access_token' => '',
        'vk_publication_refresh_token' => '',
        'vk_publication_oauth_user_id' => '',
        'vk_publication_oauth_user_name' => '',
        'vk_publication_oauth_user_profile_url' => '',
        'vk_publication_token_scope' => '',
        'vk_publication_token_obtained_at' => '',
        'vk_publication_token_expires_at' => '',
        'vk_publication_oauth_connected_at' => '',
        'vk_publication_token_type' => '',
        'vk_publication_confirmed_permissions' => '',
        'vk_publication_oauth_state' => 'attention',
        'vk_publication_oauth_last_error' => 'VK подключение отклонено: токен не прошёл проверку.',
        'vk_publication_oauth_last_error_technical' => mb_substr($message, 0, 1000),
    ]);
    vkPublicationLog('oauth_connected_with_attention', [
        'issues' => $readiness['issues'] ?? [],
    ]);
    clearVkPublicationOauthFlow();
    $_SESSION['flash_error'] = 'VK подключение не завершено: ' . $message;
    redirect('/admin/settings#vk-integration');
}

clearVkPublicationOauthFlow();
$_SESSION['success_message'] = 'VK успешно подключён. Теперь публикация использует OAuth-токен.';
redirect('/admin/settings#vk-integration');
