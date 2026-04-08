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
$codeVerifier = trim((string) ($flow['code_verifier'] ?? ''));
$alreadyUsed = (int) ($flow['used'] ?? 0) === 1;

if (!$flow || $expectedState === '' || $codeVerifier === '' || $alreadyUsed || !hash_equals($expectedState, $state)) {
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
    'code_verifier' => $codeVerifier,
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

if (!$tokenResponse['ok'] || !empty($tokenJson['error']) || empty($tokenJson['access_token']) || empty($tokenJson['user_id'])) {
    $vkError = (string) ($tokenJson['error'] ?? 'exchange_failed');
    $vkErrorDescription = trim((string) ($tokenJson['error_description'] ?? ''));
    failVkPublicationOauth(
        'Не удалось обменять authorization code на токен VK.',
        $vkError . ($vkErrorDescription !== '' ? (': ' . $vkErrorDescription) : ''),
        'Не удалось завершить подключение VK. Повторите попытку.'
    );
}

$profileName = '';
$userInfoResult = vkPublicationApiRequest('users.get', [
    'access_token' => (string) $tokenJson['access_token'],
    'v' => VK_API_VERSION,
]);
$userInfo = $userInfoResult['ok'] ? ($userInfoResult['response'] ?? []) : [];
if (!$userInfoResult['ok']) {
    failVkPublicationOauth(
        'Токен получен, но не удалось загрузить профиль пользователя VK.',
        'users.get failed',
        'VK подключение не завершено: не удалось получить профиль пользователя.'
    );
}

if (!empty($userInfo[0])) {
    $profileName = trim((string) (($userInfo[0]['first_name'] ?? '') . ' ' . ($userInfo[0]['last_name'] ?? '')));
}

$userId = trim((string) ($tokenJson['user_id'] ?? ''));
$extra = [
    'vk_publication_oauth_user_name' => $profileName,
    'vk_publication_oauth_user_profile_url' => $userId !== '' ? ('https://vk.com/id' . $userId) : '',
    'vk_publication_oauth_state' => 'connected',
    'vk_publication_oauth_last_error' => '',
    'vk_publication_oauth_last_error_technical' => '',
];

persistVkPublicationTokens($tokenJson, $extra);
vkPublicationLog('oauth_token_persisted', [
    'oauth_user_id' => $userId,
    'oauth_user_name' => $profileName,
]);

$readiness = verifyVkPublicationReadiness(false);
if (empty($readiness['ok'])) {
    $message = implode('; ', $readiness['issues'] ?? ['Ошибка проверки прав токена']);
    saveSystemSettings([
        'vk_publication_oauth_state' => 'attention',
        'vk_publication_oauth_last_error' => 'VK подключён, но проверка прав не пройдена.',
        'vk_publication_oauth_last_error_technical' => mb_substr($message, 0, 1000),
    ]);
    vkPublicationLog('oauth_connected_with_attention', [
        'issues' => $readiness['issues'] ?? [],
    ]);
    clearVkPublicationOauthFlow();
    $_SESSION['flash_error'] = 'VK подключён, но проверка прав не пройдена: ' . $message;
    redirect('/admin/settings#vk-integration');
}

clearVkPublicationOauthFlow();
$_SESSION['success_message'] = 'VK успешно подключён. Теперь публикация использует OAuth-токен.';
redirect('/admin/settings#vk-integration');
