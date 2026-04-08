<?php
require_once dirname(__DIR__, 2) . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verifyCSRFToken($csrfToken)) {
    jsonResponse(['success' => false, 'error' => 'CSRF token validation failed'], 403);
}

$rawBody = file_get_contents('php://input');
$body = json_decode((string) $rawBody, true);
if (!is_array($body)) {
    jsonResponse(['success' => false, 'error' => 'Некорректный JSON запроса.'], 400);
}

$code = trim((string) ($body['code'] ?? ''));
$deviceId = trim((string) ($body['device_id'] ?? ''));

if ($code === '' || $deviceId === '') {
    jsonResponse(['success' => false, 'error' => 'Отсутствуют обязательные параметры code/device_id.'], 400);
}

$rawRedirect = (string) ($body['redirect'] ?? ($_SESSION['user_auth_redirect'] ?? '/contests'));
$redirectAfterAuth = sanitize_internal_redirect($rawRedirect, '/contests');
$_SESSION['user_auth_redirect'] = $redirectAfterAuth;

function vkid_http_post(string $url, array $params): ?array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

$tokenData = vkid_http_post('https://id.vk.com/oauth2/auth', [
    'grant_type' => 'authorization_code',
    'client_id' => VK_CLIENT_ID,
    'client_secret' => VK_CLIENT_SECRET,
    'redirect_uri' => 'https://konkurs.tolkodobroe.info/vk-auth',
    'code' => $code,
    'device_id' => $deviceId,
]);

if (!is_array($tokenData) || empty($tokenData['access_token']) || empty($tokenData['user_id'])) {
    jsonResponse(['success' => false, 'error' => 'Не удалось обменять код VK ID.'], 400);
}

$accessToken = (string) $tokenData['access_token'];
$vkUserId = (string) $tokenData['user_id'];
$vkEmail = trim((string) ($tokenData['email'] ?? ''));

$userData = vkid_http_post('https://api.vk.com/method/users.get', [
    'access_token' => $accessToken,
    'v' => VK_API_VERSION,
    'user_ids' => $vkUserId,
    'fields' => 'photo_100',
]);

if (!is_array($userData) || !empty($userData['error']) || empty($userData['response'][0])) {
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

$target = sanitize_internal_redirect($_SESSION['user_auth_redirect'] ?? '/contests', '/contests');
unset($_SESSION['user_auth_redirect']);

jsonResponse([
    'success' => true,
    'redirect' => $target,
]);
