<?php

const VK_OAUTH_SESSION_KEY = 'vk_oauth_flows';
const VK_OAUTH_FLOW_TTL = 900;
const VK_AUTH_LOG_FILE = '/storage/logs/vk-auth.log';

function vk_auth_log_file_path(): string
{
    return dirname(__DIR__, 2) . VK_AUTH_LOG_FILE;
}

function vk_mask_state(?string $value): string
{
    $state = trim((string) $value);
    if ($state === '') {
        return '';
    }

    $length = strlen($state);
    if ($length <= 10) {
        return substr($state, 0, 2) . str_repeat('*', max(0, $length - 4)) . substr($state, -2);
    }

    return substr($state, 0, 6) . '***' . substr($state, -4);
}

function vk_sanitize_for_log($value, ?string $key = null)
{
    $secretKeys = [
        'access_token',
        'refresh_token',
        'id_token',
        'token',
        'client_secret',
    ];

    if (is_array($value)) {
        $result = [];
        foreach ($value as $childKey => $childValue) {
            $result[$childKey] = vk_sanitize_for_log($childValue, is_string($childKey) ? $childKey : null);
        }
        return $result;
    }

    if (!is_scalar($value) && $value !== null) {
        return $value;
    }

    $normalized = (string) $value;
    if ($key !== null) {
        $lowerKey = strtolower($key);
        if (in_array($lowerKey, $secretKeys, true)) {
            return vk_mask_secret($normalized);
        }
        if ($lowerKey === 'state') {
            return vk_mask_state($normalized);
        }
    }

    return $value;
}

function vk_log_attempt(string $flow, string $step, array $payload = [], ?string $attemptId = null): void
{
    $entry = [
        'timestamp' => gmdate('c'),
        'flow_type' => $flow === 'user' ? 'vk_user_auth' : 'vk_admin_auth',
        'flow' => $flow,
        'step' => $step,
        'attempt_id' => $attemptId ?? '',
        'payload' => vk_sanitize_for_log($payload),
    ];

    $path = vk_auth_log_file_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function vk_auth_log(string $event, array $context = []): void
{
    $safeContext = vk_sanitize_for_log($context);
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

function vk_http_post_form(string $url, array $postFields = [], array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'http_code' => 0, 'raw' => '', 'json' => null, 'curl_error' => 'curl_init_failed'];
    }

    $defaultHeaders = ['Accept: application/json'];
    if (!empty($postFields)) {
        $defaultHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
        CURLOPT_POSTFIELDS => http_build_query($postFields),
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

function vk_admin_session_reset(): void
{
    unset($_SESSION['admin_user_id'], $_SESSION['is_admin'], $_SESSION['admin_auth_redirect']);
}

function vk_admin_access_denied_redirect(): void
{
    vk_admin_session_reset();
    vk_flow_session_clear('admin');
    $_SESSION['flash_error'] = 'У вас нет доступа к административному разделу.';
    header('Location: /');
    exit;
}

function vk_callback_handle(string $flow, PDO $pdo): void
{
    $attemptId = bin2hex(random_bytes(8));
    $state = trim((string) ($_GET['state'] ?? ''));
    $code = trim((string) ($_GET['code'] ?? ''));
    $hasError = trim((string) ($_GET['error'] ?? '')) !== '';

    $sessionFlow = vk_flow_session_get($flow);
    $expectedState = is_array($sessionFlow) ? (string) ($sessionFlow['state'] ?? '') : '';
    $storedVerifier = is_array($sessionFlow) ? (string) ($sessionFlow['code_verifier'] ?? '') : '';
    $stateMatches = $expectedState !== '' && $state !== '' && hash_equals($expectedState, $state);

    vk_log_attempt($flow, 'callback_received', [
        'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        'query' => $_GET,
        'has_state' => $state !== '',
        'has_code' => $code !== '',
        'has_error' => $hasError,
        'has_session_flow' => is_array($sessionFlow),
        'state_matches_session' => $stateMatches,
        'state_masked' => vk_mask_state($state),
        'session_state_masked' => vk_mask_state($expectedState),
        'has_code_verifier' => $storedVerifier !== '',
        'callback_handler' => __FILE__,
    ], $attemptId);

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
        'code_verifier' => $storedVerifier,
        'grant_type' => 'authorization_code',
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
    vk_log_attempt($flow, 'token_exchanged', [
        'http_status' => $tokenResponse['http_code'],
        'curl_error' => $tokenResponse['curl_error'],
        'raw_response' => $tokenResponse['raw'],
        'json_response' => $tokenResponse['json'],
        'has_access_token' => !empty($tokenResponse['json']['access_token']),
        'has_user_id' => !empty($tokenResponse['json']['user_id']),
        'has_email' => !empty($tokenResponse['json']['email']),
    ], $attemptId);

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
    vk_log_attempt($flow, 'profile_loaded', [
        'request' => [
            'endpoint' => 'https://api.vk.com/method/users.get',
            'user_ids' => $vkUserId,
            'fields' => 'photo_100',
            'version' => VK_API_VERSION,
        ],
        'http_status' => $profileResponse['http_code'],
        'curl_error' => $profileResponse['curl_error'],
        'raw_response' => $profileResponse['raw'],
        'json_response' => $profileResponse['json'],
        'has_first_name' => !empty($profileResponse['json']['response'][0]['first_name']),
        'has_last_name' => !empty($profileResponse['json']['response'][0]['last_name']),
        'has_photo_100' => !empty($profileResponse['json']['response'][0]['photo_100']),
    ], $attemptId);

    $profileJson = is_array($profileResponse['json']) ? $profileResponse['json'] : [];
    if (!$profileResponse['ok'] || !empty($profileJson['error']) || empty($profileJson['response'][0])) {
        vk_callback_fail($flow, 'profile_failed');
    }

    $profile = $profileJson['response'][0];
    $mapped = vk_map_profile_fields($profile, [], $vkEmail);
    if ($mapped['vk_id'] === '') {
        $mapped['vk_id'] = $vkUserId;
    }

    if ($flow === 'admin') {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE vk_id = ? LIMIT 1');
        $stmt->execute([$vkUserId]);
        $existingUser = $stmt->fetch();

        vk_auth_log('user_lookup', [
            'flow' => $flow,
            'vk_user_id' => $vkUserId,
            'user_found' => (bool) $existingUser,
        ]);

        if (!$existingUser || (int) ($existingUser['is_admin'] ?? 0) !== 1) {
            vk_auth_log('admin_access_check_failed', [
                'flow' => $flow,
                'user_found' => (bool) $existingUser,
                'is_admin' => (int) ($existingUser['is_admin'] ?? 0),
            ]);
            vk_admin_access_denied_redirect();
        }

        $_SESSION['admin_user_id'] = (int) $existingUser['id'];
        $_SESSION['is_admin'] = true;

        $redirectFromSession = (string) ($_SESSION['admin_auth_redirect'] ?? '');
        $target = sanitize_internal_redirect(
            $redirectFromSession !== '' ? $redirectFromSession : (string) ($sessionFlow['post_login_redirect'] ?? '/admin'),
            '/admin'
        );
        if (strpos($target, '/admin') !== 0) {
            $target = '/admin';
        }
        unset($_SESSION['admin_auth_redirect']);
    } else {
        vk_auth_log('callback_profile_mapping', [
            'flow' => $flow,
            'has_name' => $mapped['name'] !== '',
            'has_surname' => $mapped['surname'] !== '',
            'has_patronymic' => $mapped['patronymic'] !== '',
            'has_email' => $mapped['email'] !== '',
            'has_avatar_url' => $mapped['avatar_url'] !== '',
            'access_token_masked' => vk_mask_secret($accessToken),
        ]);

        $isNewVkUser = false;
        $userId = vk_save_user_profile($pdo, $mapped, $accessToken, [
            'flow' => $flow,
            'attempt_id' => $attemptId,
        ], $isNewVkUser);

        if ($userId <= 0) {
            vk_callback_fail($flow, 'exchange_failed');
        }

        $_SESSION['user_id'] = $userId;
        $_SESSION['vk_token'] = $accessToken;
        vk_log_attempt($flow, 'session_set', [
            'session_keys' => ['user_id', 'vk_token'],
            'user_id' => $userId,
            'vk_token_masked' => vk_mask_secret($accessToken),
        ], $attemptId);
        if ($isNewVkUser) {
            $target = '/profile?required=1';
        } else {
            $target = sanitize_internal_redirect((string) ($sessionFlow['post_login_redirect'] ?? '/contests'), '/contests');
        }
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

function vk_decode_jwt_payload(string $jwt): array
{
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return [];
    }

    $payload = strtr($parts[1], '-_', '+/');
    $padLength = strlen($payload) % 4;
    if ($padLength > 0) {
        $payload .= str_repeat('=', 4 - $padLength);
    }

    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        return [];
    }

    $json = json_decode($decoded, true);
    return is_array($json) ? $json : [];
}

function vk_pick_non_empty_string(...$values): string
{
    foreach ($values as $value) {
        $normalized = trim((string) $value);
        if ($normalized !== '') {
            return $normalized;
        }
    }

    return '';
}

function vk_extract_patronymic(array $profile): string
{
    return vk_pick_non_empty_string(
        $profile['patronymic'] ?? '',
        $profile['middle_name'] ?? '',
        $profile['middleName'] ?? '',
        $profile['first_name_patronymic'] ?? '',
        $profile['otchestvo'] ?? '',
        $profile['second_name'] ?? '',
        $profile['secondName'] ?? '',
        $profile['additional_name'] ?? '',
        $profile['additionalName'] ?? ''
    );
}

function vk_extract_avatar_url(array $profile): string
{
    return vk_pick_non_empty_string(
        $profile['avatar_url'] ?? '',
        $profile['avatarUrl'] ?? '',
        $profile['photo'] ?? '',
        $profile['image'] ?? '',
        $profile['photo_200_orig'] ?? '',
        $profile['photo_200'] ?? '',
        $profile['photo_100'] ?? '',
        $profile['photo_max_orig'] ?? '',
        $profile['photo_max'] ?? '',
        $profile['avatar'] ?? '',
        $profile['picture'] ?? ''
    );
}

function vk_is_valid_avatar_url(string $url): bool
{
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

function vk_merge_profile_data(array $base, array $extra): array
{
    foreach ($extra as $key => $value) {
        if (is_array($value)) {
            continue;
        }

        $normalized = trim((string) $value);
        if ($normalized !== '') {
            $base[$key] = $normalized;
        }
    }

    return $base;
}

function vk_map_profile_fields(array $profile, array $claims = [], string $vkEmailHint = ''): array
{
    $merged = vk_merge_profile_data($profile, $claims);

    return [
        'vk_id' => vk_pick_non_empty_string($merged['id'] ?? '', $merged['user_id'] ?? '', $merged['sub'] ?? ''),
        'name' => vk_pick_non_empty_string($merged['first_name'] ?? '', $merged['firstName'] ?? '', $merged['given_name'] ?? '', $merged['givenName'] ?? ''),
        'surname' => vk_pick_non_empty_string($merged['last_name'] ?? '', $merged['lastName'] ?? '', $merged['family_name'] ?? '', $merged['familyName'] ?? ''),
        'patronymic' => vk_extract_patronymic($merged),
        'avatar_url' => vk_extract_avatar_url($merged),
        'email' => vk_pick_non_empty_string($merged['email'] ?? '', $vkEmailHint),
    ];
}

function vk_save_user_profile(PDO $pdo, array $mapped, string $accessToken = '', array $debugContext = [], ?bool &$wasCreated = null): int
{
    $vkUserId = trim((string) ($mapped['vk_id'] ?? ''));
    if ($vkUserId === '') {
        $wasCreated = false;
        return 0;
    }

    $name = trim((string) ($mapped['name'] ?? ''));
    $surname = trim((string) ($mapped['surname'] ?? ''));
    $patronymic = trim((string) ($mapped['patronymic'] ?? ''));
    $email = trim((string) ($mapped['email'] ?? ''));
    $avatarUrl = trim((string) ($mapped['avatar_url'] ?? ''));

    $stmt = $pdo->prepare('SELECT * FROM users WHERE vk_id = ? LIMIT 1');
    $stmt->execute([$vkUserId]);
    $existingUser = $stmt->fetch();

    if (!$existingUser && $email !== '') {
        $fallbackStmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $fallbackStmt->execute([$email]);
        $existingUser = $fallbackStmt->fetch();
    }

    $flow = (string) ($debugContext['flow'] ?? 'user');
    $attemptId = (string) ($debugContext['attempt_id'] ?? '');

    if ($existingUser) {
        $wasCreated = false;
        $existingUserId = (int) ($existingUser['id'] ?? 0);
        $resolvedEmail = $email !== '' ? $email : (string) ($existingUser['email'] ?? '');
        vk_log_attempt($flow, 'db_update_prepare', [
            'operation' => 'update',
            'vk_id' => $vkUserId,
            'existing_user_found' => true,
            'existing_user_id' => $existingUserId,
            'email_to_save' => $resolvedEmail,
            'first_name' => $name,
            'last_name' => $surname,
            'avatar_url' => $avatarUrl,
            'has_access_token' => $accessToken !== '',
        ], $attemptId);

        $updates = ['updated_at = NOW()'];
        $params = [];

        if ((string) ($existingUser['vk_id'] ?? '') === '') {
            $updates[] = 'vk_id = ?';
            $params[] = $vkUserId;
        }

        if ($accessToken !== '') {
            $updates[] = 'vk_access_token = ?';
            $params[] = $accessToken;
        }

        if ($name !== '') {
            $updates[] = 'name = ?';
            $params[] = $name;
        }

        if ($surname !== '') {
            $updates[] = 'surname = ?';
            $params[] = $surname;
        }

        if ($patronymic !== '') {
            $updates[] = 'patronymic = ?';
            $params[] = $patronymic;
        }

        if ($email !== '') {
            $updates[] = 'email = ?';
            $params[] = $email;
        }

        if ($avatarUrl !== '' && vk_is_valid_avatar_url($avatarUrl)) {
            $updates[] = 'avatar_url = ?';
            $params[] = $avatarUrl;
        }

        $params[] = $existingUser['id'];
        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $updateStmt = $pdo->prepare($sql);
        $updateStmt->execute($params);
        vk_log_attempt($flow, 'db_update_result', [
            'operation' => 'update',
            'success' => true,
            'affected_rows' => $updateStmt->rowCount(),
            'user_id' => $existingUserId,
        ], $attemptId);

        return $existingUserId;
    }

    vk_log_attempt($flow, 'db_insert_prepare', [
        'operation' => 'insert',
        'vk_id' => $vkUserId,
        'existing_user_found' => false,
        'email_to_save' => $email,
        'first_name' => $name,
        'last_name' => $surname,
        'avatar_url' => $avatarUrl,
        'has_access_token' => $accessToken !== '',
    ], $attemptId);

    $insertStmt = $pdo->prepare(
        'INSERT INTO users (vk_id, vk_access_token, name, surname, patronymic, avatar_url, email) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insertStmt->execute([
        $vkUserId,
        $accessToken,
        $name,
        $surname,
        $patronymic,
        vk_is_valid_avatar_url($avatarUrl) ? $avatarUrl : '',
        $email,
    ]);
    $newUserId = (int) $pdo->lastInsertId();
    $wasCreated = true;
    vk_log_attempt($flow, 'db_insert_result', [
        'operation' => 'insert',
        'success' => true,
        'affected_rows' => $insertStmt->rowCount(),
        'user_id' => $newUserId,
    ], $attemptId);

    return $newUserId;
}

function vk_extract_sdk_profile(string $accessToken, string $idToken): array
{
    $profile = [];
    $profileSource = '';
    $profileDebug = [];

    if ($accessToken !== '') {
        $userinfoUrl = 'https://id.vk.com/oauth2/user_info';
        $request = vk_http_post_form(
            $userinfoUrl,
            ['client_id' => VK_CLIENT_ID],
            ['Authorization: Bearer ' . $accessToken]
        );
        $responseJson = is_array($request['json']) ? $request['json'] : [];
        $profileDebug['vk_id_user_info'] = [
            'request_method' => 'POST',
            'http_code' => $request['http_code'],
            'curl_error' => $request['curl_error'],
            'raw_response' => $request['raw'],
            'json_response' => $responseJson,
        ];

        if (
            $request['ok']
            && empty($responseJson['error'])
            && (
                (isset($responseJson['user']) && is_array($responseJson['user']))
                || (isset($responseJson['response']['user']) && is_array($responseJson['response']['user']))
                || (isset($responseJson['response']) && is_array($responseJson['response']) && isset($responseJson['response']['user_id']))
            )
        ) {
            if (isset($responseJson['user']) && is_array($responseJson['user'])) {
                $profile = $responseJson['user'];
            } elseif (isset($responseJson['response']['user']) && is_array($responseJson['response']['user'])) {
                $profile = $responseJson['response']['user'];
            } else {
                $profile = $responseJson['response'];
            }
            $profileSource = 'vk_id_user_info';
        } else {
            $legacyUserInfoUrl = $userinfoUrl . '?' . http_build_query([
                'client_id' => VK_CLIENT_ID,
                'access_token' => $accessToken,
            ]);
            $legacyUserInfoResponse = vk_http_get($legacyUserInfoUrl);
            $legacyUserInfoJson = is_array($legacyUserInfoResponse['json']) ? $legacyUserInfoResponse['json'] : [];
            $profileDebug['vk_id_user_info_legacy_get'] = [
                'request_method' => 'GET',
                'http_code' => $legacyUserInfoResponse['http_code'],
                'curl_error' => $legacyUserInfoResponse['curl_error'],
                'raw_response' => $legacyUserInfoResponse['raw'],
                'json_response' => $legacyUserInfoJson,
            ];

            if (
                $legacyUserInfoResponse['ok']
                && empty($legacyUserInfoJson['error'])
                && (
                    (isset($legacyUserInfoJson['user']) && is_array($legacyUserInfoJson['user']))
                    || (isset($legacyUserInfoJson['response']['user']) && is_array($legacyUserInfoJson['response']['user']))
                    || (isset($legacyUserInfoJson['response']) && is_array($legacyUserInfoJson['response']) && isset($legacyUserInfoJson['response']['user_id']))
                )
            ) {
                if (isset($legacyUserInfoJson['user']) && is_array($legacyUserInfoJson['user'])) {
                    $profile = $legacyUserInfoJson['user'];
                } elseif (isset($legacyUserInfoJson['response']['user']) && is_array($legacyUserInfoJson['response']['user'])) {
                    $profile = $legacyUserInfoJson['response']['user'];
                } else {
                    $profile = $legacyUserInfoJson['response'];
                }
                $profileSource = 'vk_id_user_info_legacy_get';
            }
        }

        if (empty($profile)) {
            $profileUrl = 'https://api.vk.com/method/users.get?' . http_build_query([
                'access_token' => $accessToken,
                'v' => VK_API_VERSION,
                'fields' => 'photo_100',
            ]);
            $legacyResponse = vk_http_get($profileUrl);
            $legacyJson = is_array($legacyResponse['json']) ? $legacyResponse['json'] : [];

            $profileDebug['legacy_users_get'] = [
                'http_code' => $legacyResponse['http_code'],
                'curl_error' => $legacyResponse['curl_error'],
                'raw_response' => $legacyResponse['raw'],
                'json_response' => $legacyJson,
            ];

            if ($legacyResponse['ok'] && empty($legacyJson['error']) && !empty($legacyJson['response'][0])) {
                $profile = $legacyJson['response'][0];
                $profileSource = 'legacy_users_get';
            }
        }
    }

    if (empty($profile) && $idToken !== '') {
        $idTokenPayload = vk_decode_jwt_payload($idToken);
        $profileDebug['id_token_payload'] = $idTokenPayload;
        if (!empty($idTokenPayload)) {
            $profile = $idTokenPayload;
            $profileSource = 'id_token_payload';
        }
    }

    return [
        'profile' => $profile,
        'source' => $profileSource,
        'debug' => $profileDebug,
    ];
}

function vk_user_login_by_access_token(PDO $pdo, string $accessToken, string $rawRedirect = '/', string $vkUserIdHint = '', string $vkEmailHint = '', string $idToken = '', string $refreshToken = '', array $claims = []): array
{
    $attemptId = bin2hex(random_bytes(8));
    $safeRedirect = sanitize_internal_redirect($rawRedirect, '/contests');

    vk_auth_log('sdk_login_received', [
        'has_access_token' => $accessToken !== '',
        'has_id_token' => $idToken !== '',
        'has_refresh_token' => $refreshToken !== '',
        'has_user_id' => $vkUserIdHint !== '',
        'has_email' => $vkEmailHint !== '',
        'claims_keys' => array_keys($claims),
    ]);
    vk_log_attempt('user', 'sdk_login_received', [
        'has_access_token' => $accessToken !== '',
        'has_id_token' => $idToken !== '',
        'has_refresh_token' => $refreshToken !== '',
        'has_user_id_hint' => $vkUserIdHint !== '',
        'has_email_hint' => $vkEmailHint !== '',
        'claims_keys' => array_keys($claims),
    ], $attemptId);

    if ($accessToken === '' && $idToken === '') {
        return ['ok' => false, 'error_code' => 'exchange_failed'];
    }

    $profileResult = vk_extract_sdk_profile($accessToken, $idToken);
    $profile = is_array($profileResult['profile']) ? $profileResult['profile'] : [];
    $profileSource = (string) ($profileResult['source'] ?? '');

    vk_auth_log('sdk_profile_result', [
        'profile_source' => $profileSource,
        'profile_debug' => $profileResult['debug'] ?? [],
        'profile_format_keys' => array_keys($profile),
    ]);

    if (empty($profile)) {
        vk_auth_log('sdk_profile_failed', [
            'reason' => 'profile_is_empty_after_vk_id_and_legacy_attempts',
            'has_access_token' => $accessToken !== '',
            'has_id_token' => $idToken !== '',
        ]);
        return ['ok' => false, 'error_code' => 'profile_failed'];
    }

    $mapped = vk_map_profile_fields($profile, $claims, $vkEmailHint);
    $vkUserId = $mapped['vk_id'];
    if ($vkUserId === '') {
        vk_auth_log('sdk_profile_failed', [
            'reason' => 'vk_user_id_missing_in_profile',
            'profile_source' => $profileSource,
            'profile_format_keys' => array_keys($profile),
        ]);
        return ['ok' => false, 'error_code' => 'profile_failed'];
    }

    if ($vkUserIdHint !== '' && $vkUserIdHint !== $vkUserId) {
        vk_auth_log('sdk_user_id_mismatch', [
            'provided_user_id' => $vkUserIdHint,
            'actual_user_id' => $vkUserId,
        ]);
        return ['ok' => false, 'error_code' => 'invalid_callback'];
    }

    vk_auth_log('sdk_profile_mapping', [
        'profile_source' => $profileSource,
        'has_name' => $mapped['name'] !== '',
        'has_surname' => $mapped['surname'] !== '',
        'has_patronymic' => $mapped['patronymic'] !== '',
        'has_email' => $mapped['email'] !== '',
        'has_avatar_url' => $mapped['avatar_url'] !== '',
        'access_token_masked' => vk_mask_secret($accessToken),
        'has_refresh_token' => $refreshToken !== '',
        'has_id_token' => $idToken !== '',
    ]);

    $isNewVkUser = false;
    $userId = vk_save_user_profile($pdo, $mapped, $accessToken, [
        'flow' => 'user',
        'attempt_id' => $attemptId,
    ], $isNewVkUser);

    if ($userId <= 0) {
        return ['ok' => false, 'error_code' => 'exchange_failed'];
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['vk_token'] = $accessToken;
    vk_log_attempt('user', 'session_set', [
        'session_keys' => ['user_id', 'vk_token'],
        'user_id' => $userId,
        'vk_token_masked' => vk_mask_secret($accessToken),
    ], $attemptId);
    unset($_SESSION['user_auth_redirect']);

    return ['ok' => true, 'redirect_to' => $isNewVkUser ? '/profile?required=1' : $safeRedirect];
}
