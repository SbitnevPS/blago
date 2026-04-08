<?php

const VK_OAUTH_SESSION_KEY = 'vk_oauth_flows';
const VK_OAUTH_FLOW_TTL = 900;

function vk_auth_log(string $event, array $context = []): void
{
    $safeContext = $context;
    if (isset($safeContext['client_secret'])) {
        unset($safeContext['client_secret']);
    }
    if (isset($safeContext['access_token'])) {
        $safeContext['access_token'] = vk_mask_secret((string) $safeContext['access_token']);
    }
    error_log('[VK_AUTH] ' . $event . ' ' . json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function vk_mask_secret(string $value): string
{
    $length = strlen($value);
    if ($length <= 8) {
        return str_repeat('*', $length);
    }

    return substr($value, 0, 4) . str_repeat('*', $length - 8) . substr($value, -4);
}

function vk_flow_redirect_uri(string $flow): string
{
    if ($flow === 'admin') {
        return VK_ADMIN_REDIRECT_URI;
    }

    return VK_USER_REDIRECT_URI;
}

function vk_flow_default_redirect(string $flow): string
{
    return $flow === 'admin' ? '/admin' : '/contests';
}

function vk_flow_session_get(string $flow): ?array
{
    $container = $_SESSION[VK_OAUTH_SESSION_KEY] ?? null;
    if (!is_array($container) || !isset($container[$flow]) || !is_array($container[$flow])) {
        return null;
    }

    return $container[$flow];
}

function vk_flow_session_clear(string $flow): void
{
    if (!isset($_SESSION[VK_OAUTH_SESSION_KEY]) || !is_array($_SESSION[VK_OAUTH_SESSION_KEY])) {
        return;
    }

    unset($_SESSION[VK_OAUTH_SESSION_KEY][$flow]);
    if (empty($_SESSION[VK_OAUTH_SESSION_KEY])) {
        unset($_SESSION[VK_OAUTH_SESSION_KEY]);
    }
}

function vk_flow_session_is_valid(string $flow, ?array $sessionFlow): bool
{
    if (!is_array($sessionFlow)) {
        return false;
    }

    $createdAt = (int) ($sessionFlow['created_at'] ?? 0);
    if ($createdAt <= 0 || (time() - $createdAt) > VK_OAUTH_FLOW_TTL) {
        return false;
    }

    return (
        ($sessionFlow['flow'] ?? '') === $flow
        && trim((string) ($sessionFlow['state'] ?? '')) !== ''
        && trim((string) ($sessionFlow['code_verifier'] ?? '')) !== ''
        && trim((string) ($sessionFlow['redirect_uri'] ?? '')) === vk_flow_redirect_uri($flow)
    );
}

function vk_build_auth_url(array $sessionFlow): string
{
    $verifier = (string) $sessionFlow['code_verifier'];
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return 'https://oauth.vk.com/authorize?' . http_build_query([
        'client_id' => VK_CLIENT_ID,
        'redirect_uri' => (string) $sessionFlow['redirect_uri'],
        'response_type' => 'code',
        'scope' => 'email',
        'state' => (string) $sessionFlow['state'],
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'v' => VK_API_VERSION,
    ]);
}

function vk_flow_prepare(string $flow, string $rawRedirect): array
{
    $defaultRedirect = vk_flow_default_redirect($flow);
    $safeRedirect = sanitize_internal_redirect($rawRedirect, $defaultRedirect);

    if ($flow === 'admin' && strpos($safeRedirect, '/admin') !== 0) {
        $safeRedirect = '/admin';
    }

    $sessionFlow = vk_flow_session_get($flow);
    if (vk_flow_session_is_valid($flow, $sessionFlow)) {
        vk_auth_log('start_flow_reused', [
            'flow' => $flow,
            'redirect_uri' => $sessionFlow['redirect_uri'],
            'created_at' => $sessionFlow['created_at'],
        ]);
        return $sessionFlow;
    }

    $sessionFlow = [
        'state' => bin2hex(random_bytes(16)),
        'code_verifier' => rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '='),
        'redirect_uri' => vk_flow_redirect_uri($flow),
        'created_at' => time(),
        'flow' => $flow,
        'post_login_redirect' => $safeRedirect,
    ];

    if (!isset($_SESSION[VK_OAUTH_SESSION_KEY]) || !is_array($_SESSION[VK_OAUTH_SESSION_KEY])) {
        $_SESSION[VK_OAUTH_SESSION_KEY] = [];
    }

    $_SESSION[VK_OAUTH_SESSION_KEY][$flow] = $sessionFlow;

    vk_auth_log('start_flow_created', [
        'flow' => $flow,
        'redirect_uri' => $sessionFlow['redirect_uri'],
        'created_at' => $sessionFlow['created_at'],
        'has_state' => true,
        'has_code_verifier' => true,
    ]);

    return $sessionFlow;
}

function vk_http_get(string $url): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'http_code' => 0, 'raw' => '', 'json' => null, 'curl_error' => 'curl_init_failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = $response === false ? (string) curl_error($ch) : '';
    curl_close($ch);

    $raw = $response === false ? '' : (string) $response;
    $json = json_decode($raw, true);

    return [
        'ok' => $curlError === '' && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'raw' => $raw,
        'json' => is_array($json) ? $json : null,
        'curl_error' => $curlError,
    ];
}

function vk_error_message(string $code): string
{
    $messages = [
        'session_expired' => 'Сессия входа через VK устарела. Попробуйте снова.',
        'invalid_callback' => 'VK вернул некорректные данные входа.',
        'exchange_failed' => 'Не удалось завершить вход через VK.',
        'profile_failed' => 'Не удалось получить профиль VK.',
        'admin_access_denied' => 'Доступ в админку запрещён.',
    ];

    return $messages[$code] ?? 'Не удалось выполнить вход через VK. Попробуйте снова.';
}

function vk_callback_fail(string $flow, string $errorCode): void
{
    vk_flow_session_clear($flow);
    $basePath = $flow === 'admin' ? '/admin/login' : '/login';
    header('Location: ' . $basePath . '?auth_error=' . urlencode($errorCode));
    exit;
}

function vk_callback_handle(string $flow, PDO $pdo): void
{
    $state = trim((string) ($_GET['state'] ?? ''));
    $code = trim((string) ($_GET['code'] ?? ''));
    $hasError = trim((string) ($_GET['error'] ?? '')) !== '';

    $sessionFlow = vk_flow_session_get($flow);
    vk_auth_log('callback_received', [
        'flow' => $flow,
        'has_code' => $code !== '',
        'has_state' => $state !== '',
        'has_error' => $hasError,
        'has_session_flow' => is_array($sessionFlow),
    ]);

    if ($hasError || $code === '' || $state === '') {
        vk_callback_fail($flow, 'invalid_callback');
    }

    if (!vk_flow_session_is_valid($flow, $sessionFlow)) {
        vk_auth_log('callback_session_invalid', [
            'flow' => $flow,
            'redirect_uri' => is_array($sessionFlow) ? ($sessionFlow['redirect_uri'] ?? '') : '',
            'created_at' => is_array($sessionFlow) ? ($sessionFlow['created_at'] ?? null) : null,
        ]);
        vk_callback_fail($flow, 'session_expired');
    }

    $expectedState = (string) ($sessionFlow['state'] ?? '');
    $storedVerifier = (string) ($sessionFlow['code_verifier'] ?? '');
    if ($expectedState === '' || !hash_equals($expectedState, $state) || $storedVerifier === '') {
        vk_auth_log('callback_state_or_verifier_failed', [
            'flow' => $flow,
            'state_valid' => ($expectedState !== '' && hash_equals($expectedState, $state)),
            'has_code_verifier' => $storedVerifier !== '',
        ]);
        vk_callback_fail($flow, 'invalid_callback');
    }

    $tokenUrl = 'https://oauth.vk.com/access_token?' . http_build_query([
        'client_id' => VK_CLIENT_ID,
        'client_secret' => VK_CLIENT_SECRET,
        'redirect_uri' => vk_flow_redirect_uri($flow),
        'code' => $code,
    ]);
    $tokenResponse = vk_http_get($tokenUrl);

    vk_auth_log('token_exchange_result', [
        'flow' => $flow,
        'redirect_uri' => vk_flow_redirect_uri($flow),
        'http_code' => $tokenResponse['http_code'],
        'curl_error' => $tokenResponse['curl_error'],
        'raw_response' => $tokenResponse['raw'],
        'json_response' => $tokenResponse['json'],
    ]);

    $tokenJson = is_array($tokenResponse['json']) ? $tokenResponse['json'] : [];
    if (!$tokenResponse['ok'] || !empty($tokenJson['error']) || empty($tokenJson['access_token']) || empty($tokenJson['user_id'])) {
        vk_callback_fail($flow, 'exchange_failed');
    }

    $accessToken = (string) $tokenJson['access_token'];
    $vkUserId = (string) $tokenJson['user_id'];
    $vkEmail = trim((string) ($tokenJson['email'] ?? ''));

    $profileUrl = 'https://api.vk.com/method/users.get?' . http_build_query([
        'access_token' => $accessToken,
        'v' => VK_API_VERSION,
        'user_ids' => $vkUserId,
        'fields' => 'photo_100',
    ]);
    $profileResponse = vk_http_get($profileUrl);

    vk_auth_log('profile_result', [
        'flow' => $flow,
        'http_code' => $profileResponse['http_code'],
        'curl_error' => $profileResponse['curl_error'],
        'raw_response' => $profileResponse['raw'],
        'json_response' => $profileResponse['json'],
    ]);

    $profileJson = is_array($profileResponse['json']) ? $profileResponse['json'] : [];
    if (!$profileResponse['ok'] || !empty($profileJson['error']) || empty($profileJson['response'][0])) {
        vk_callback_fail($flow, 'profile_failed');
    }

    $profile = $profileJson['response'][0];
    $firstName = trim((string) ($profile['first_name'] ?? ''));
    $lastName = trim((string) ($profile['last_name'] ?? ''));
    $avatarUrl = trim((string) ($profile['photo_100'] ?? ''));

    $stmt = $pdo->prepare('SELECT * FROM users WHERE vk_id = ? LIMIT 1');
    $stmt->execute([$vkUserId]);
    $existingUser = $stmt->fetch();

    vk_auth_log('user_lookup', [
        'flow' => $flow,
        'vk_user_id' => $vkUserId,
        'user_found' => (bool) $existingUser,
    ]);

    if ($flow === 'admin') {
        if (!$existingUser || (int) ($existingUser['is_admin'] ?? 0) !== 1) {
            vk_auth_log('admin_access_check_failed', [
                'flow' => $flow,
                'user_found' => (bool) $existingUser,
                'is_admin' => (int) ($existingUser['is_admin'] ?? 0),
            ]);
            vk_callback_fail($flow, 'admin_access_denied');
        }

        $emailToSave = trim((string) ($existingUser['email'] ?? ''));
        if ($vkEmail !== '') {
            $emailToSave = $vkEmail;
        }

        $updateStmt = $pdo->prepare(
            'UPDATE users SET vk_access_token = ?, name = ?, surname = ?, avatar_url = ?, email = ?, updated_at = NOW() WHERE id = ?'
        );
        $updateStmt->execute([
            $accessToken,
            $firstName !== '' ? $firstName : (string) ($existingUser['name'] ?? ''),
            $lastName,
            $avatarUrl,
            $emailToSave,
            $existingUser['id'],
        ]);

        $_SESSION['admin_user_id'] = (int) $existingUser['id'];
        $_SESSION['is_admin'] = true;
        $target = sanitize_internal_redirect((string) ($sessionFlow['post_login_redirect'] ?? '/admin'), '/admin');
        if (strpos($target, '/admin') !== 0) {
            $target = '/admin';
        }
    } else {
        if ($existingUser) {
            $emailToSave = trim((string) ($existingUser['email'] ?? ''));
            if ($vkEmail !== '') {
                $emailToSave = $vkEmail;
            }

            $updateStmt = $pdo->prepare(
                'UPDATE users SET vk_access_token = ?, name = ?, surname = ?, avatar_url = ?, email = ?, updated_at = NOW() WHERE id = ?'
            );
            $updateStmt->execute([
                $accessToken,
                $firstName !== '' ? $firstName : (string) ($existingUser['name'] ?? ''),
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
            vk_callback_fail($flow, 'exchange_failed');
        }

        $_SESSION['user_id'] = $userId;
        $_SESSION['vk_token'] = $accessToken;
        $target = sanitize_internal_redirect((string) ($sessionFlow['post_login_redirect'] ?? '/contests'), '/contests');
    }

    vk_flow_session_clear($flow);
    vk_auth_log('login_success', [
        'flow' => $flow,
        'redirect_to' => $target,
        'vk_user_id' => $vkUserId,
        'access_token' => $accessToken,
    ]);

    header('Location: ' . $target);
    exit;
}

function vk_user_login_by_access_token(PDO $pdo, string $accessToken, string $rawRedirect = '/', string $vkUserIdHint = '', string $vkEmailHint = ''): array
{
    $safeRedirect = sanitize_internal_redirect($rawRedirect, '/contests');

    if ($accessToken === '') {
        return ['ok' => false, 'error_code' => 'exchange_failed'];
    }

    $profileUrl = 'https://api.vk.com/method/users.get?' . http_build_query([
        'access_token' => $accessToken,
        'v' => VK_API_VERSION,
        'fields' => 'photo_100',
    ]);
    $profileResponse = vk_http_get($profileUrl);
    $profileJson = is_array($profileResponse['json']) ? $profileResponse['json'] : [];

    vk_auth_log('sdk_profile_result', [
        'http_code' => $profileResponse['http_code'],
        'curl_error' => $profileResponse['curl_error'],
        'raw_response' => $profileResponse['raw'],
        'json_response' => $profileJson,
    ]);

    if (!$profileResponse['ok'] || !empty($profileJson['error']) || empty($profileJson['response'][0])) {
        return ['ok' => false, 'error_code' => 'profile_failed'];
    }

    $profile = $profileJson['response'][0];
    $vkUserId = trim((string) ($profile['id'] ?? ''));
    if ($vkUserId === '') {
        return ['ok' => false, 'error_code' => 'profile_failed'];
    }

    if ($vkUserIdHint !== '' && $vkUserIdHint !== $vkUserId) {
        vk_auth_log('sdk_user_id_mismatch', [
            'provided_user_id' => $vkUserIdHint,
            'actual_user_id' => $vkUserId,
        ]);
        return ['ok' => false, 'error_code' => 'invalid_callback'];
    }

    $firstName = trim((string) ($profile['first_name'] ?? ''));
    $lastName = trim((string) ($profile['last_name'] ?? ''));
    $avatarUrl = trim((string) ($profile['photo_100'] ?? ''));
    $vkEmail = trim((string) $vkEmailHint);

    $stmt = $pdo->prepare('SELECT * FROM users WHERE vk_id = ? LIMIT 1');
    $stmt->execute([$vkUserId]);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        $emailToSave = trim((string) ($existingUser['email'] ?? ''));
        if ($vkEmail !== '') {
            $emailToSave = $vkEmail;
        }

        $updateStmt = $pdo->prepare(
            'UPDATE users SET vk_access_token = ?, name = ?, surname = ?, avatar_url = ?, email = ?, updated_at = NOW() WHERE id = ?'
        );
        $updateStmt->execute([
            $accessToken,
            $firstName !== '' ? $firstName : (string) ($existingUser['name'] ?? ''),
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
        return ['ok' => false, 'error_code' => 'exchange_failed'];
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['vk_token'] = $accessToken;
    unset($_SESSION['user_auth_redirect']);

    return ['ok' => true, 'redirect_to' => $safeRedirect];
}
