<?php
require_once dirname(__DIR__, 5) . '/config.php';
require_once dirname(__DIR__, 5) . '/includes/init.php';

if (!isAdmin()) {
    $_SESSION['flash_error'] = 'Для подключения VK требуется вход в админку.';
    redirect('/admin/login');
}

$state = trim((string) ($_GET['state'] ?? ''));
$code = trim((string) ($_GET['code'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));

if ($error !== '' || $state === '' || $code === '') {
    $_SESSION['flash_error'] = 'VK вернул ошибку авторизации. Попробуйте подключить заново.';
    redirect('/admin/settings#vk-integration');
}

$flow = getVkPublicationOauthFlow();
$expectedState = trim((string) ($flow['state'] ?? ''));
$codeVerifier = trim((string) ($flow['code_verifier'] ?? ''));
$alreadyUsed = (int) ($flow['used'] ?? 0) === 1;

if (!$flow || $expectedState === '' || $codeVerifier === '' || $alreadyUsed || !hash_equals($expectedState, $state)) {
    clearVkPublicationOauthFlow();
    $_SESSION['flash_error'] = 'OAuth-сессия VK истекла или недействительна. Запустите подключение заново.';
    redirect('/admin/settings#vk-integration');
}

$_SESSION[getVkPublicationOauthSessionKey()]['used'] = 1;

$tokenResponse = vkPublicationHttpPostForm('https://oauth.vk.com/access_token', [
    'grant_type' => 'authorization_code',
    'client_id' => VK_CLIENT_ID,
    'client_secret' => VK_CLIENT_SECRET,
    'redirect_uri' => getVkPublicationRedirectUri(),
    'code' => $code,
    'code_verifier' => $codeVerifier,
]);
$tokenJson = is_array($tokenResponse['json']) ? $tokenResponse['json'] : [];

if (!$tokenResponse['ok'] || !empty($tokenJson['error']) || empty($tokenJson['access_token']) || empty($tokenJson['user_id'])) {
    clearVkPublicationOauthFlow();
    saveSystemSettings([
        'vk_publication_oauth_state' => 'error',
        'vk_publication_oauth_last_error' => 'Не удалось обменять authorization code на токен VK.',
    ]);
    $_SESSION['flash_error'] = 'Не удалось завершить подключение VK. Повторите попытку.';
    redirect('/admin/settings#vk-integration');
}

$profileName = '';
$userInfoResult = vkPublicationApiRequest('users.get', [
    'access_token' => (string) $tokenJson['access_token'],
    'v' => VK_API_VERSION,
]);
$userInfo = $userInfoResult['ok'] ? ($userInfoResult['response'] ?? []) : [];

if (!empty($userInfo[0])) {
    $profileName = trim((string) (($userInfo[0]['first_name'] ?? '') . ' ' . ($userInfo[0]['last_name'] ?? '')));
}

$userId = trim((string) ($tokenJson['user_id'] ?? ''));
$extra = [
    'vk_publication_oauth_user_name' => $profileName,
    'vk_publication_oauth_user_profile_url' => $userId !== '' ? ('https://vk.com/id' . $userId) : '',
    'vk_publication_oauth_state' => 'connected',
    'vk_publication_oauth_last_error' => '',
];

persistVkPublicationTokens($tokenJson, $extra);
clearVkPublicationOauthFlow();
$_SESSION['success_message'] = 'VK успешно подключён. Теперь публикация использует OAuth-токен.';
redirect('/admin/settings#vk-integration');
