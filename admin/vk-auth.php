<?php
// admin/vk-auth.php - callback обработчик VK OAuth только для админской части
require_once __DIR__ . '/../config.php';

function vk_admin_error_redirect(string $error = 'vk_auth'): void
{
    header('Location: /admin/login?error=' . urlencode($error));
    exit;
}

function vk_admin_request(string $url, array $params): ?array
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
$expectedState = (string) ($_SESSION['vk_admin_oauth_state'] ?? '');

if ($state === '' || $code === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
    vk_admin_error_redirect('vk_auth');
}

unset($_SESSION['vk_admin_oauth_state']);

$tokenData = vk_admin_request('https://oauth.vk.com/access_token', [
    'client_id' => VK_CLIENT_ID,
    'client_secret' => VK_CLIENT_SECRET,
    'redirect_uri' => VK_ADMIN_REDIRECT_URI,
    'code' => $code,
]);

if (!is_array($tokenData) || empty($tokenData['access_token']) || empty($tokenData['user_id'])) {
    vk_admin_error_redirect('vk_auth');
}

$accessToken = (string) $tokenData['access_token'];
$vkUserId = (string) $tokenData['user_id'];

$stmt = $pdo->prepare('SELECT * FROM users WHERE vk_id = ? LIMIT 1');
$stmt->execute([$vkUserId]);
$adminUser = $stmt->fetch();

if (!$adminUser || (int) ($adminUser['is_admin'] ?? 0) !== 1) {
    vk_admin_error_redirect('access_denied');
}

$userData = vk_admin_request('https://api.vk.com/method/users.get', [
    'access_token' => $accessToken,
    'v' => VK_API_VERSION,
    'user_ids' => $vkUserId,
    'fields' => 'photo_100',
]);

$firstName = trim((string) ($adminUser['name'] ?? ''));
$lastName = trim((string) ($adminUser['surname'] ?? ''));
$avatarUrl = trim((string) ($adminUser['avatar_url'] ?? ''));

if (is_array($userData) && empty($userData['error']) && !empty($userData['response'][0])) {
    $vkUser = $userData['response'][0];
    $firstName = trim((string) ($vkUser['first_name'] ?? $firstName));
    $lastName = trim((string) ($vkUser['last_name'] ?? $lastName));
    $avatarUrl = trim((string) ($vkUser['photo_100'] ?? $avatarUrl));
}

$emailToSave = trim((string) ($adminUser['email'] ?? ''));
if (!empty($tokenData['email'])) {
    $emailToSave = trim((string) $tokenData['email']);
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
    $adminUser['id'],
]);

$_SESSION['admin_user_id'] = (int) $adminUser['id'];
$_SESSION['is_admin'] = true;

$target = sanitize_internal_redirect($_SESSION['admin_auth_redirect'] ?? '/admin', '/admin');
unset($_SESSION['admin_auth_redirect']);

header('Location: ' . $target);
exit;
