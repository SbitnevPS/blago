<?php
// app/auth/vk-auth.php - callback обработчик пользовательской VK авторизации
require_once dirname(__DIR__, 2) . '/config.php';

function vk_user_redirect_with_error(): void
{
    header('Location: /login?error=vk_auth');
    exit;
}

function vk_oauth_request(string $url, array $params): ?array
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

$state = (string) ($_GET['state'] ?? '');
$code = (string) ($_GET['code'] ?? '');
$expectedState = (string) ($_SESSION['vk_user_oauth_state'] ?? '');

if ($state === '' || $code === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
    vk_user_redirect_with_error();
}

unset($_SESSION['vk_user_oauth_state']);

$tokenData = vk_oauth_request('https://oauth.vk.com/access_token', [
    'client_id' => VK_CLIENT_ID,
    'client_secret' => VK_CLIENT_SECRET,
    'redirect_uri' => VK_USER_REDIRECT_URI,
    'code' => $code,
]);

if (!is_array($tokenData) || empty($tokenData['access_token']) || empty($tokenData['user_id'])) {
    vk_user_redirect_with_error();
}

$accessToken = (string) $tokenData['access_token'];
$vkUserId = (string) $tokenData['user_id'];
$vkEmail = trim((string) ($tokenData['email'] ?? ''));

$userData = vk_oauth_request('https://api.vk.com/method/users.get', [
    'access_token' => $accessToken,
    'v' => VK_API_VERSION,
    'user_ids' => $vkUserId,
    'fields' => 'photo_100',
]);

if (!is_array($userData) || !empty($userData['error']) || empty($userData['response'][0])) {
    vk_user_redirect_with_error();
}

$vkUser = $userData['response'][0];
$firstName = trim((string) ($vkUser['first_name'] ?? ''));
$lastName = trim((string) ($vkUser['last_name'] ?? ''));
$avatarUrl = trim((string) ($vkUser['photo_100'] ?? ''));

if ($firstName === '') {
    vk_user_redirect_with_error();
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
    vk_user_redirect_with_error();
}

$_SESSION['user_id'] = $userId;
$_SESSION['vk_token'] = $accessToken;

$target = sanitize_internal_redirect($_SESSION['user_auth_redirect'] ?? '/contests', '/contests');
unset($_SESSION['user_auth_redirect']);

header('Location: ' . $target);
exit;
