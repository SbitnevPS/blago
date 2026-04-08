<?php
require_once dirname(__DIR__, 2) . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verifyCSRFToken($csrfToken)) {
    error_log('VK ID exchange CSRF validation failed: ' . json_encode([
        'has_header_token' => $csrfToken !== '',
        'session_has_csrf' => isset($_SESSION['csrf_token']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    jsonResponse(['success' => false, 'error' => 'Не удалось завершить вход через VK ID. Попробуйте ещё раз.'], 403);
}

$rawBody = file_get_contents('php://input');
$body = json_decode((string) $rawBody, true);
if (!is_array($body)) {
    jsonResponse(['success' => false, 'error' => 'Некорректный JSON запроса.'], 400);
}

$code = trim((string) ($body['code'] ?? ''));
$deviceId = trim((string) ($body['device_id'] ?? ''));
$state = trim((string) ($body['state'] ?? ''));
$codeVerifier = trim((string) ($body['code_verifier'] ?? ''));

if ($code === '' || $deviceId === '') {
    jsonResponse(['success' => false, 'error' => 'Отсутствуют обязательные параметры code/device_id.'], 400);
}

$sessionOauth = $_SESSION['vkid_oauth'] ?? null;
if (!is_array($sessionOauth)) {
    jsonResponse(['success' => false, 'error' => 'Сессия VK ID не найдена. Обновите страницу и попробуйте снова.'], 400);
}

$expectedState = trim((string) ($sessionOauth['state'] ?? ''));
$expectedCodeVerifier = trim((string) ($sessionOauth['code_verifier'] ?? ''));
$expectedRedirectUri = trim((string) ($sessionOauth['redirect_uri'] ?? ''));
$createdAt = (int) ($sessionOauth['created_at'] ?? 0);

if ($createdAt <= 0 || (time() - $createdAt) > 900) {
    unset($_SESSION['vkid_oauth']);
    jsonResponse(['success' => false, 'error' => 'Сессия VK ID истекла. Обновите страницу и попробуйте снова.'], 400);
}

if (
    $state === ''
    || $expectedState === ''
    || !hash_equals($expectedState, $state)
) {
    unset($_SESSION['vkid_oauth']);
    jsonResponse(['success' => false, 'error' => 'Проверка состояния VK ID не пройдена. Обновите страницу и попробуйте снова.'], 400);
}

if ($codeVerifier === '' || $expectedCodeVerifier === '' || !hash_equals($expectedCodeVerifier, $codeVerifier)) {
    unset($_SESSION['vkid_oauth']);
    jsonResponse(['success' => false, 'error' => 'Проверка PKCE для VK ID не пройдена. Обновите страницу и попробуйте снова.'], 400);
}

if ($expectedRedirectUri === '' || $expectedRedirectUri !== VK_USER_REDIRECT_URI) {
    unset($_SESSION['vkid_oauth']);
    jsonResponse(['success' => false, 'error' => 'Некорректная конфигурация redirect_uri VK ID.'], 500);
}

$rawRedirect = (string) ($body['redirect'] ?? ($_SESSION['user_auth_redirect'] ?? '/contests'));
$redirectAfterAuth = sanitize_internal_redirect($rawRedirect, '/contests');
$_SESSION['user_auth_redirect'] = $redirectAfterAuth;

function vkid_http_post(string $url, array $params): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return [
            'ok' => false,
            'http_code' => 0,
            'raw' => '',
            'json' => null,
            'curl_error' => 'curl_init_failed',
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $response = curl_exec($ch);
    $curlError = $response === false ? (string) curl_error($ch) : '';
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $raw = $response === false ? '' : (string) $response;
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        $json = null;
    }

    return [
        'ok' => $curlError === '' && $httpCode >= 200 && $httpCode < 300,
        'url' => $url,
        'http_code' => $httpCode,
        'raw' => $raw,
        'json' => $json,
        'curl_error' => $curlError,
    ];
}

function vkid_log(string $message, array $context = []): void
{
    error_log($message . ': ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$exchangeContext = [
    'request_url' => 'https://id.vk.com/oauth2/auth',
    'redirect_uri' => VK_USER_REDIRECT_URI,
    'has_code' => $code !== '',
    'has_device_id' => $deviceId !== '',
    'has_state' => $state !== '',
    'has_code_verifier' => $codeVerifier !== '',
];

$tokenResponse = vkid_http_post('https://id.vk.com/oauth2/auth', [
    'grant_type' => 'authorization_code',
    'client_id' => VK_CLIENT_ID,
    'client_secret' => VK_CLIENT_SECRET,
    'redirect_uri' => VK_USER_REDIRECT_URI,
    'code' => $code,
    'device_id' => $deviceId,
    'state' => $state,
    'code_verifier' => $codeVerifier,
]);

if (!empty($tokenResponse['curl_error'])) {
    vkid_log('VK ID code exchange curl error', array_merge($exchangeContext, [
        'http_code' => $tokenResponse['http_code'] ?? 0,
        'curl_error' => $tokenResponse['curl_error'] ?? '',
        'raw' => $tokenResponse['raw'] ?? '',
        'json' => $tokenResponse['json'] ?? null,
    ]));
    jsonResponse(['success' => false, 'error' => 'Временная ошибка соединения с VK ID. Попробуйте ещё раз.'], 400);
}

if (empty($tokenResponse['ok'])) {
    vkid_log('VK ID code exchange non-success HTTP', array_merge($exchangeContext, [
        'http_code' => $tokenResponse['http_code'] ?? 0,
        'curl_error' => $tokenResponse['curl_error'] ?? '',
        'raw' => $tokenResponse['raw'] ?? '',
        'json' => $tokenResponse['json'] ?? null,
    ]));
    jsonResponse(['success' => false, 'error' => 'Не удалось завершить вход через VK ID. Попробуйте ещё раз.'], 400);
}

if (!is_array($tokenResponse['json'] ?? null)) {
    vkid_log('VK ID code exchange invalid JSON', array_merge($exchangeContext, [
        'http_code' => $tokenResponse['http_code'] ?? 0,
        'raw' => $tokenResponse['raw'] ?? '',
        'json' => $tokenResponse['json'] ?? null,
    ]));
    jsonResponse(['success' => false, 'error' => 'VK ID вернул некорректные данные. Попробуйте ещё раз.'], 400);
}

$tokenData = $tokenResponse['json'];
if (empty($tokenData['access_token'])) {
    vkid_log('VK ID code exchange missing access_token', array_merge($exchangeContext, [
        'http_code' => $tokenResponse['http_code'] ?? 0,
        'raw' => $tokenResponse['raw'] ?? '',
        'json' => $tokenData,
    ]));
    jsonResponse(['success' => false, 'error' => 'Не удалось завершить вход через VK ID. Попробуйте ещё раз.'], 400);
}

if (empty($tokenData['user_id'])) {
    vkid_log('VK ID code exchange missing user_id', array_merge($exchangeContext, [
        'http_code' => $tokenResponse['http_code'] ?? 0,
        'raw' => $tokenResponse['raw'] ?? '',
        'json' => $tokenData,
    ]));
    jsonResponse(['success' => false, 'error' => 'VK ID вернул некорректные данные. Попробуйте ещё раз.'], 400);
}

$accessToken = (string) $tokenData['access_token'];
$vkUserId = (string) $tokenData['user_id'];
$vkEmail = trim((string) ($tokenData['email'] ?? ''));

$userResponse = vkid_http_post('https://api.vk.com/method/users.get', [
    'access_token' => $accessToken,
    'v' => VK_API_VERSION,
    'user_ids' => $vkUserId,
    'fields' => 'photo_100',
]);

$userData = is_array($userResponse['json'] ?? null) ? $userResponse['json'] : [];
if (empty($userResponse['ok']) || !empty($userData['error']) || empty($userData['response'][0])) {
    vkid_log('VK ID users.get failed', [
        'request_url' => 'https://api.vk.com/method/users.get',
        'http_code' => $userResponse['http_code'] ?? 0,
        'curl_error' => $userResponse['curl_error'] ?? '',
        'raw' => $userResponse['raw'] ?? '',
        'json' => $userData,
        'has_user_id' => $vkUserId !== '',
    ]);
    jsonResponse(['success' => false, 'error' => 'Не удалось получить профиль пользователя VK.'], 400);
}

$vkUser = $userData['response'][0];
$firstName = trim((string) ($vkUser['first_name'] ?? ''));
$lastName = trim((string) ($vkUser['last_name'] ?? ''));
$avatarUrl = trim((string) ($vkUser['photo_100'] ?? ''));

if ($firstName === '') {
    jsonResponse(['success' => false, 'error' => 'Профиль VK ID вернул некорректные данные.'], 400);
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE vk_id = ? LIMIT 1');
$stmt->execute([$vkUserId]);
$existingUser = $stmt->fetch();

if ($existingUser) {
    $emailToSave = $existingUser['email'] ?? '';
    if ($vkEmail !== '') {
        $emailToSave = $vkEmail;
    }

    $updateStmt = $pdo->prepare(
        'UPDATE users SET vk_access_token = ?, name = ?, surname = ?, avatar_url = ?, email = ?, updated_at = NOW() WHERE id = ?'
    );
    $updateStmt->execute([
        $accessToken,
        $firstName,
        $lastName,
        $avatarUrl,
        $emailToSave,
        $existingUser['id'],
    ]);

    $userId = (int) $existingUser['id'];
} else {
    $insertStmt = $pdo->prepare(
        'INSERT INTO users (vk_id, vk_access_token, name, surname, avatar_url, email) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insertStmt->execute([
        $vkUserId,
        $accessToken,
        $firstName,
        $lastName,
        $avatarUrl,
        $vkEmail,
    ]);

    $userId = (int) $pdo->lastInsertId();
}

if ($userId <= 0) {
    jsonResponse(['success' => false, 'error' => 'Не удалось авторизовать пользователя.'], 500);
}

$_SESSION['user_id'] = $userId;
$_SESSION['vk_token'] = $accessToken;
unset($_SESSION['vkid_oauth']);

$target = sanitize_internal_redirect($_SESSION['user_auth_redirect'] ?? '/contests', '/contests');
unset($_SESSION['user_auth_redirect']);

jsonResponse([
    'success' => true,
    'redirect' => $target,
]);
