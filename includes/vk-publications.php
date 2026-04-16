<?php

require_once __DIR__ . '/vk-api.php';

function ensureVkPublicationSchema(): void
{
    global $pdo;

    $publishWarningMessage = null;
    $publishWarningTechnical = null;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS vk_publication_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            contest_id INT NULL,
            created_by INT NOT NULL,
            task_status ENUM('draft','ready','publishing','published','partially_failed','failed','archived') NOT NULL DEFAULT 'draft',
            publication_mode ENUM('manual','immediate') NOT NULL DEFAULT 'manual',
            filters_json LONGTEXT NULL,
            summary_json LONGTEXT NULL,
            total_items INT NOT NULL DEFAULT 0,
            ready_items INT NOT NULL DEFAULT 0,
            published_items INT NOT NULL DEFAULT 0,
            failed_items INT NOT NULL DEFAULT 0,
            skipped_items INT NOT NULL DEFAULT 0,
            vk_group_id VARCHAR(64) NULL,
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_status (task_status),
            KEY idx_contest (contest_id),
            KEY idx_created_by (created_by),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS vk_publication_task_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            work_id INT NOT NULL,
            application_id INT NOT NULL,
            participant_id INT NOT NULL,
            contest_id INT NOT NULL,
            work_image_path VARCHAR(255) NULL,
            post_text TEXT NULL,
            item_status ENUM('pending','ready','published','failed','skipped') NOT NULL DEFAULT 'pending',
            skip_reason VARCHAR(255) NULL,
            vk_post_id VARCHAR(64) NULL,
            vk_post_url VARCHAR(255) NULL,
            error_message TEXT NULL,
            technical_error TEXT NULL,
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_task_work (task_id, work_id),
            KEY idx_task (task_id),
            KEY idx_work (work_id),
            KEY idx_status (item_status),
            KEY idx_vk_post (vk_post_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }

    try {
        $columns = $pdo->query("SHOW COLUMNS FROM works")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('vk_published_at', $columns, true)) {
            $pdo->exec("ALTER TABLE works ADD COLUMN vk_published_at DATETIME NULL");
        }
        if (!in_array('vk_post_id', $columns, true)) {
            $pdo->exec("ALTER TABLE works ADD COLUMN vk_post_id VARCHAR(64) NULL");
        }
        if (!in_array('vk_post_url', $columns, true)) {
            $pdo->exec("ALTER TABLE works ADD COLUMN vk_post_url VARCHAR(255) NULL");
        }
        if (!in_array('vk_publish_error', $columns, true)) {
            $pdo->exec("ALTER TABLE works ADD COLUMN vk_publish_error TEXT NULL");
        }
    } catch (Throwable $e) {
    }

    try {
        $itemColumns = $pdo->query("SHOW COLUMNS FROM vk_publication_task_items")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('technical_error', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN technical_error TEXT NULL AFTER error_message");
        }
    } catch (Throwable $e) {
    }

    try {
        $taskColumns = $pdo->query("SHOW COLUMNS FROM vk_publication_tasks")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
    }
}

function getTableColumnsCached(string $table): array
{
    static $cache = [];
    global $pdo;

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        $cache[$table] = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
    } catch (Throwable $e) {
        $cache[$table] = [];
    }

    return $cache[$table];
}

function getVkTaskStatusConfig(): array
{
    return [
        'draft' => ['label' => 'Черновик', 'badge_class' => 'badge--secondary'],
        'ready' => ['label' => 'Готово', 'badge_class' => 'badge--warning'],
        'publishing' => ['label' => 'Публикуется', 'badge_class' => 'badge--warning'],
        'published' => ['label' => 'Опубликовано', 'badge_class' => 'badge--success'],
        'partially_failed' => ['label' => 'Частично с ошибками', 'badge_class' => 'badge--warning'],
        'failed' => ['label' => 'Ошибка', 'badge_class' => 'badge--error'],
        'archived' => ['label' => 'Архив', 'badge_class' => 'badge--secondary'],
    ];
}

function getVkTaskStatusMeta(string $status): array
{
    $map = getVkTaskStatusConfig();
    return $map[$status] ?? ['label' => $status, 'badge_class' => 'badge--secondary'];
}

function getVkItemStatusMeta(string $status): array
{
    $map = [
        'pending' => ['label' => 'Ожидает', 'badge_class' => 'badge--secondary'],
        'ready' => ['label' => 'Готово', 'badge_class' => 'badge--warning'],
        'published' => ['label' => 'Опубликовано', 'badge_class' => 'badge--success'],
        'failed' => ['label' => 'Ошибка', 'badge_class' => 'badge--error'],
        'skipped' => ['label' => 'Пропущено', 'badge_class' => 'badge--secondary'],
    ];

    return $map[$status] ?? ['label' => $status, 'badge_class' => 'badge--secondary'];
}

function getApplicationVkPublicationStatus(int $applicationId): array
{
    global $pdo;
    ensureVkPublicationSchema();

    $base = [
        'status_code' => 'not_published',
        'status_label' => 'Не опубликована',
        'badge_class' => 'badge--secondary',
        'published_count' => 0,
        'failed_count' => 0,
        'total_count' => 0,
        'last_task_id' => null,
        'last_post_url' => '',
        'last_error' => '',
        'last_attempt_at' => null,
        'remaining_count' => 0,
    ];

    if ($applicationId <= 0) {
        return $base;
    }

    $workStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_works,
            SUM(CASE WHEN w.status = 'accepted' THEN 1 ELSE 0 END) AS accepted_works,
            SUM(CASE WHEN w.vk_published_at IS NOT NULL THEN 1 ELSE 0 END) AS published_works
        FROM works w
        WHERE w.application_id = ?
    ");
    $workStmt->execute([$applicationId]);
    $workCounts = $workStmt->fetch() ?: [];

    $totalWorks = (int) ($workCounts['total_works'] ?? 0);
    $acceptedWorks = (int) ($workCounts['accepted_works'] ?? 0);
    $publishedWorks = (int) ($workCounts['published_works'] ?? 0);
    $readyTotal = $acceptedWorks > 0 ? $acceptedWorks : $totalWorks;

    $taskStmt = $pdo->prepare("
        SELECT
            t.id AS task_id,
            t.task_status,
            t.vk_post_url,
            t.created_at AS task_created_at,
            t.updated_at AS task_updated_at,
            MAX(i.published_at) AS item_published_at,
            MAX(CASE WHEN i.item_status = 'failed' THEN i.error_message ELSE NULL END) AS last_error,
            SUM(i.item_status = 'published') AS published_items,
            SUM(i.item_status = 'failed') AS failed_items
        FROM vk_publication_tasks t
        INNER JOIN vk_publication_task_items i ON i.task_id = t.id
        WHERE i.application_id = ?
        GROUP BY t.id
        ORDER BY COALESCE(MAX(i.published_at), t.updated_at, t.created_at) DESC, t.id DESC
        LIMIT 1
    ");
    $taskStmt->execute([$applicationId]);
    $lastTask = $taskStmt->fetch() ?: null;

    $base['total_count'] = $readyTotal;
    $base['published_count'] = $publishedWorks;
    $base['remaining_count'] = max(0, $readyTotal - $publishedWorks);

    if (!$lastTask) {
        if ($publishedWorks > 0) {
            $base['status_code'] = $publishedWorks >= $readyTotal && $readyTotal > 0 ? 'published' : 'partial';
            $base['status_label'] = $base['status_code'] === 'published' ? 'Опубликована' : 'Частично опубликована';
            $base['badge_class'] = $base['status_code'] === 'published' ? 'badge--success' : 'badge--warning';
        }
        return $base;
    }

    $base['last_task_id'] = (int) ($lastTask['task_id'] ?? 0) ?: null;
    $base['last_post_url'] = trim((string) ($lastTask['vk_post_url'] ?? ''));
    $base['last_error'] = trim((string) ($lastTask['last_error'] ?? ''));
    $base['failed_count'] = max(0, (int) ($lastTask['failed_items'] ?? 0));
    $base['last_attempt_at'] = (string) ($lastTask['item_published_at'] ?: ($lastTask['task_updated_at'] ?: $lastTask['task_created_at']));

    if ($readyTotal <= 0) {
        $base['status_code'] = 'not_published';
        $base['status_label'] = 'Не опубликована';
        return $base;
    }

    if ($publishedWorks >= $readyTotal && $readyTotal > 0) {
        $base['status_code'] = 'published';
        $base['status_label'] = 'Опубликована';
        $base['badge_class'] = 'badge--success';
    } elseif ($publishedWorks > 0) {
        $base['status_code'] = 'partial';
        $base['status_label'] = 'Частично опубликована';
        $base['badge_class'] = 'badge--warning';
    } else {
        $taskStatus = (string) ($lastTask['task_status'] ?? '');
        if (in_array($taskStatus, ['draft', 'ready', 'publishing'], true)) {
            $base['status_code'] = 'pending_publish';
            $base['status_label'] = 'Ожидает публикации';
            $base['badge_class'] = 'badge--warning';
        } else {
            $base['status_code'] = 'failed';
            $base['status_label'] = 'Ошибка публикации';
            $base['badge_class'] = 'badge--error';
        }
    }

    return $base;
}

function vkPublicationLog(string $event, array $context = []): void
{
    $sanitize = static function ($value, ?string $key = null) use (&$sanitize) {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $item) {
                $out[$k] = $sanitize($item, is_string($k) ? $k : null);
            }
            return $out;
        }

        if (!is_string($value)) {
            return $value;
        }

        $keyLower = mb_strtolower((string) $key);
        if (
            $keyLower !== ''
            && (
                str_contains($keyLower, 'token')
                || str_contains($keyLower, 'secret')
                || str_contains($keyLower, 'verifier')
                || str_contains($keyLower, 'authorization')
            )
        ) {
            return maskVkPublicationToken($value);
        }

        return mb_substr($value, 0, 500);
    };

    $safeContext = $sanitize($context);
    error_log('[VK_PUBLICATION] ' . $event . ' ' . json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function getVkPublicationSettings(): array
{
    $settings = getSystemSettings();
    $lastCheckedAt = trim((string) ($settings['vk_publication_last_checked_at'] ?? ''));
    $lastSuccessfulCheckAt = trim((string) ($settings['vk_publication_last_success_checked_at'] ?? ''));
    $groupToken = trim((string) ($settings['vk_publication_group_token'] ?? ''));
    $tokenSource = trim((string) ($settings['vk_publication_token_source'] ?? 'session_vkid'));
    if (!in_array($tokenSource, ['session_vkid', 'stored_group'], true)) {
        $tokenSource = 'session_vkid';
    }

    $authMode = trim((string) ($settings['vk_publication_auth_mode'] ?? ''));
    if (!in_array($authMode, ['user_session', 'community_token'], true)) {
        $authMode = $tokenSource === 'stored_group' ? 'community_token' : 'user_session';
    }

    return [
        'publication_token' => '',
        'auth_mode' => $authMode,
        'token_source_setting' => $tokenSource,
        'group_id' => trim((string) ($settings['vk_publication_group_id'] ?? '')),
        'group_token' => $groupToken,
        'api_version' => trim((string) ($settings['vk_publication_api_version'] ?? VK_API_VERSION)),
        'from_group' => (int) ($settings['vk_publication_from_group'] ?? 1) === 1,
        'post_template' => trim((string) ($settings['vk_publication_post_template'] ?? defaultVkPostTemplate())),
        'group_name' => trim((string) ($settings['vk_publication_group_name'] ?? '')),
        'token_expires_at' => '',
        'token_scope' => '',
        'token_scope_items' => [],
        'vk_user_id' => '',
        'vk_user_name' => '',
        'token_type' => 'user',
        'confirmed_permissions' => trim((string) ($settings['vk_publication_confirmed_permissions'] ?? '')),
        'status' => trim((string) ($settings['vk_publication_status'] ?? 'disconnected')),
        'last_error' => trim((string) ($settings['vk_publication_last_error'] ?? '')),
        'technical_diagnostics' => trim((string) ($settings['vk_publication_technical_diagnostics'] ?? '')),
        'last_checked_at' => $lastCheckedAt,
        'last_success_checked_at' => $lastSuccessfulCheckAt,
        'last_check_status' => trim((string) ($settings['vk_publication_last_check_status'] ?? '')),
        'last_check_message' => trim((string) ($settings['vk_publication_last_check_message'] ?? '')),
        'token_masked' => $groupToken !== '' ? maskVkPublicationToken($groupToken) : '',
    ];
}

function getVkPublicationRuntimeSettings(bool $preferSessionToken = true, bool $attemptRefresh = true): array
{
    $settings = getVkPublicationSettings();
    $rawSystemSettings = getSystemSettings();
    $authMode = trim((string) ($rawSystemSettings['vk_publication_auth_mode'] ?? ''));
    if (!in_array($authMode, ['user_session', 'community_token'], true)) {
        $tokenSource = trim((string) ($rawSystemSettings['vk_publication_token_source'] ?? 'session_vkid'));
        $authMode = $tokenSource === 'stored_group' ? 'community_token' : 'user_session';
    }

    if ($authMode === 'user_session' && $preferSessionToken) {
        $sessionTokens = vkid_session_get_tokens('admin');
        $sessionAccessToken = vkid_session_get_access_token('admin', $attemptRefresh);
        if ($sessionAccessToken !== '') {
            $sessionTokens = vkid_session_get_tokens('admin');
        }

        $scopeRaw = vk_scope_normalize((string) ($sessionTokens['scope'] ?? ''));
        $scopeItems = vk_scope_items($scopeRaw);
        $expiresAtTs = (int) ($sessionTokens['expires_at_ts'] ?? 0);
        $requiresReauth = false;
        foreach (vk_admin_publication_required_scopes() as $requiredScope) {
            if (!in_array($requiredScope, $scopeItems, true)) {
                $requiresReauth = true;
                break;
            }
        }

        return [
            'auth_mode' => 'user_session',
            'publication_token' => $sessionAccessToken,
            'token_masked' => $sessionAccessToken !== '' ? maskVkPublicationToken($sessionAccessToken) : '',
            'token_id' => $sessionAccessToken !== '' ? substr(hash('sha256', $sessionAccessToken), 0, 10) : '',
            'token_type' => trim((string) ($sessionTokens['token_type'] ?? 'user')),
            'token_source' => $sessionAccessToken !== '' ? 'session_vkid' : 'none',
            'token_scope' => $scopeRaw,
            'token_scope_items' => $scopeItems,
            'token_scope_known' => $scopeRaw !== '',
            'scope_required_for_publication' => vk_admin_publication_required_scopes(),
            'scope_requested_for_login' => vk_scope_normalize(VKID_ADMIN_SCOPE),
            'requires_reauth' => $requiresReauth,
            'reauth_reason' => $requiresReauth
                ? 'Для публикации в сообщество нужен user token с правами wall, photos, groups. Выйдите из VK-авторизации на сайте и войдите через VK снова.'
                : '',
            'vk_user_id' => trim((string) ($sessionTokens['user_id'] ?? '')),
            'vk_device_id' => trim((string) ($sessionTokens['device_id'] ?? '')),
            'vk_obtained_at_ts' => (int) ($sessionTokens['obtained_at_ts'] ?? 0),
            'vk_refreshed_at_ts' => (int) ($sessionTokens['refreshed_at_ts'] ?? 0),
            'vk_expires_at_ts' => $expiresAtTs,
            'token_expires_at' => $expiresAtTs > 0 ? date('Y-m-d H:i:s', $expiresAtTs) : '',
            'group_id' => (string) ($settings['group_id'] ?? ''),
            'api_version' => (string) ($settings['api_version'] ?? VK_API_VERSION),
            'from_group' => !empty($settings['from_group']),
            'post_template' => (string) ($settings['post_template'] ?? defaultVkPostTemplate()),
            'group_name' => (string) ($settings['group_name'] ?? ''),
            'confirmed_permissions' => trim((string) ($rawSystemSettings['vk_publication_confirmed_permissions'] ?? '')),
            'last_error' => trim((string) ($settings['last_error'] ?? '')),
            'last_check_message' => trim((string) ($settings['last_check_message'] ?? '')),
        ];
    }

    $token = trim((string) ($settings['group_token'] ?? ''));

    return [
        'auth_mode' => 'community_token',
        'publication_token' => $token,
        'token_masked' => $token !== '' ? maskVkPublicationToken($token) : '',
        'token_id' => $token !== '' ? substr(hash('sha256', $token), 0, 10) : '',
        'token_type' => 'group',
        'token_source' => $token !== '' ? 'stored_group' : 'none',
        'token_scope' => '',
        'token_scope_items' => [],
        'token_scope_known' => false,
        'scope_required_for_publication' => ['wall', 'photos'],
        'scope_requested_for_login' => '',
        'requires_reauth' => false,
        'reauth_reason' => '',
        'vk_user_id' => '',
        'vk_device_id' => '',
        'vk_obtained_at_ts' => 0,
        'vk_refreshed_at_ts' => 0,
        'vk_expires_at_ts' => 0,
        'token_expires_at' => '',
        'group_id' => (string) ($settings['group_id'] ?? ''),
        'api_version' => (string) ($settings['api_version'] ?? VK_API_VERSION),
        'from_group' => !empty($settings['from_group']),
        'post_template' => (string) ($settings['post_template'] ?? defaultVkPostTemplate()),
        'group_name' => (string) ($settings['group_name'] ?? ''),
        'confirmed_permissions' => trim((string) ($rawSystemSettings['vk_publication_confirmed_permissions'] ?? '')),
        'last_error' => trim((string) ($settings['last_error'] ?? '')),
        'last_check_message' => trim((string) ($settings['last_check_message'] ?? '')),
    ];
}

function maskVkPublicationToken(string $token): string
{
    $token = trim($token);
    if ($token === '') {
        return '';
    }

    if (strlen($token) <= 10) {
        return substr($token, 0, 2) . str_repeat('*', max(0, strlen($token) - 4)) . substr($token, -2);
    }

    return substr($token, 0, 5) . str_repeat('*', max(0, strlen($token) - 10)) . substr($token, -5);
}

function normalizeVkScopeItems(string $scopeRaw): array
{
    $scopeRaw = trim($scopeRaw);
    if ($scopeRaw === '') {
        return [];
    }

    $items = array_values(array_filter(array_map('trim', preg_split('/[\\s,]+/', $scopeRaw) ?: [])));
    $unique = [];
    foreach ($items as $item) {
        $key = mb_strtolower($item);
        if ($key === '') {
            continue;
        }
        $unique[$key] = $key;
    }
    return array_values($unique);
}

function extractVkCurrentScopesFromTechnical(string $technical): array
{
    $technical = trim($technical);
    if ($technical === '') {
        return [];
    }

    // Example: "VK API photos.getWallUploadServer error #15: Access denied ... current scopes: email wall groups"
    if (!preg_match('/current\\s+scopes\\s*:\\s*([^;\\n\\r]+)/iu', $technical, $m)) {
        return [];
    }

    $raw = trim((string) ($m[1] ?? ''));
    if ($raw === '') {
        return [];
    }

    // Split by commas/spaces, normalize to lower-case.
    $items = array_values(array_filter(array_map('trim', preg_split('/[\\s,]+/u', $raw) ?: [])));
    $unique = [];
    foreach ($items as $item) {
        $key = mb_strtolower($item);
        if ($key === '') {
            continue;
        }
        $unique[$key] = $key;
    }
    return array_values($unique);
}

function vkScopeHas(array $scopeItems, string $required): bool
{
    return vk_scope_has($scopeItems, $required);
}

function getVkPublicationRequiredScopes(bool $isGroupToken = false): array
{
    return ['wall', 'photos', 'groups'];
}

function getVkPublicationTokenDiagnostics(array $settings): array
{
    $nowTs = time();
    $expiresAt = trim((string) ($settings['token_expires_at'] ?? ''));
    $expiresTs = $expiresAt !== '' ? strtotime($expiresAt) : false;
    $expiresIn = $expiresTs ? ($expiresTs - $nowTs) : null;

    $status = 'ok';
    $message = 'Пользовательский токен из текущей VK-сессии готов к работе.';

    if (trim((string) ($settings['publication_token'] ?? '')) === '') {
        $status = 'not_connected';
        $message = 'VK ID токен для публикации не найден в текущей сессии.';
    } elseif (!empty($settings['requires_reauth'])) {
        $status = 'reauthorization_required';
        $message = (string) ($settings['reauth_reason'] ?? 'Для публикации в сообщество нужен user token с правами wall, photos, groups. Требуется повторная авторизация через VK.');
    } elseif ($expiresTs && $expiresIn !== null && $expiresIn <= 0) {
        $status = 'expired';
        $message = 'Срок действия пользовательского VK токена в сессии истёк.';
    }

    return [
        'status' => $status,
        'message' => $message,
        'expires_at' => $expiresAt,
        'expires_in_seconds' => $expiresIn,
        'expires_known' => $expiresAt !== '',
    ];
}

function vkPublicationCapabilityMatrixUserSession(): array
{
    return [
        'text_post' => ['supported' => true, 'note' => 'supported'],
        'upload_local_image_to_wall' => ['supported' => true, 'note' => 'supported'],
        'donut_donation' => ['supported' => true, 'note' => 'as implemented'],
    ];
}

function vkPublicationCapabilityMatrixCommunityToken(): array
{
    return [
        'text_post' => ['supported' => null, 'note' => 'requires separate probe (disabled by default)'],
        'upload_local_image_to_wall' => ['supported' => false, 'note' => 'unsupported: community token cannot run user-only upload chain'],
        'donut_donation' => ['supported' => null, 'note' => 'not checked by default'],
    ];
}

function verifyVkPublicationReadiness(bool $attemptRefresh = true, bool $preferSessionToken = false, string $scenario = 'diagnostic'): array
{
    $settings = getVkPublicationRuntimeSettings($preferSessionToken, $attemptRefresh);
    return verifyVkPublicationReadinessUserToken($settings, $scenario);
}

function verifyVkPublicationReadinessUserToken(array $settings, string $scenario = 'diagnostic'): array
{
    $issues = [];
    $checks = [];
    $groupName = '';
    $steps = [];
    $isGroupToken = false;

    $runtime = [
        'auth_mode' => 'user_session',
        'token_source' => (string) ($settings['token_source'] ?? 'session_vkid'),
        'token_masked' => (string) ($settings['token_masked'] ?? ''),
        'token_id' => (string) ($settings['token_id'] ?? ''),
        'token_type' => (string) ($settings['token_type'] ?? ''),
        'token_scope_raw' => (string) ($settings['token_scope'] ?? ''),
        'token_scope_items' => (array) ($settings['token_scope_items'] ?? []),
        'token_scope_known' => (bool) ($settings['token_scope_known'] ?? false),
        'requires_reauth' => !empty($settings['requires_reauth']),
        'reauth_reason' => (string) ($settings['reauth_reason'] ?? ''),
        'vk_user_id_claim' => (string) ($settings['vk_user_id'] ?? ''),
        'vk_device_id' => (string) ($settings['vk_device_id'] ?? ''),
        'token_expires_at' => (string) ($settings['token_expires_at'] ?? ''),
        'vk_expires_at_ts' => (int) ($settings['vk_expires_at_ts'] ?? 0),
        'vk_obtained_at_ts' => (int) ($settings['vk_obtained_at_ts'] ?? 0),
        'vk_refreshed_at_ts' => (int) ($settings['vk_refreshed_at_ts'] ?? 0),
    ];

    // Some steps are unsafe to run in "test" mode (would upload/post content).
    $steps['photos_saveWallPhoto'] = ['ok' => null, 'message' => 'NOT TESTED: требует загрузки файла'];
    $steps['wall_post'] = ['ok' => null, 'message' => 'NOT TESTED: создаёт пост'];
    $steps['capabilities'] = ['ok' => true, 'matrix' => vkPublicationCapabilityMatrixUserSession()];

    if ($settings['publication_token'] === '') {
        $issues[] = 'Не найден VK ID токен для публикации в текущей сессии админа.';
        $checks[] = 'Токен отсутствует';
        $steps['token_present'] = ['ok' => false, 'message' => 'Токен отсутствует'];
    } else {
        $checks[] = 'Токен присутствует';
        $steps['token_present'] = ['ok' => true, 'message' => 'Токен найден'];
    }

    if ($settings['group_id'] === '' || (int) $settings['group_id'] <= 0) {
        $issues[] = 'Не указан ID сообщества VK.';
        $checks[] = 'ID сообщества не задан';
        $steps['group_id'] = ['ok' => false, 'message' => 'group_id не задан'];
    } else {
        $checks[] = 'ID сообщества задан';
        $steps['group_id'] = ['ok' => true, 'message' => 'group_id задан', 'group_id' => (int) $settings['group_id']];
    }

    $diagnostics = getVkPublicationTokenDiagnostics($settings);
    if ($diagnostics['status'] === 'expired') {
        $issues[] = 'Токен VK ID из текущей сессии истёк. Перезайдите в админку через VK ID.';
        $checks[] = 'Токен истёк';
        $steps['token_expired'] = ['ok' => false, 'message' => 'Токен истёк'];
    } elseif (!empty($diagnostics['expires_known'])) {
        $steps['token_expired'] = ['ok' => true, 'message' => 'Токен не истёк'];
    } else {
        $steps['token_expired'] = ['ok' => null, 'message' => 'Срок действия токена неизвестен'];
    }

    $scopeItems = (array) ($settings['token_scope_items'] ?? []);
    $requiredScopes = $scenario === 'publish_text_links' ? ['wall', 'groups'] : getVkPublicationRequiredScopes($isGroupToken);
    $scopeCheck = [];
    foreach ($requiredScopes as $scopeName) {
        $scopeCheck[$scopeName] = vkScopeHas($scopeItems, $scopeName);
    }
    $steps['token_scope'] = [
        'ok' => true,
        'message' => $runtime['token_scope_raw'] !== '' ? $runtime['token_scope_raw'] : 'scope неизвестен',
        'scope_known' => $runtime['token_scope_raw'] !== '',
        'required' => $requiredScopes,
        'has' => $scopeCheck,
    ];

    if ($settings['publication_token'] !== '' && $runtime['token_scope_raw'] !== '' && !$isGroupToken) {
        foreach ($requiredScopes as $scopeName) {
            if (!$scopeCheck[$scopeName]) {
                $issues[] = 'У текущего access token отсутствует scope ' . $scopeName . '.';
            }
        }
    }

    if (!empty($settings['requires_reauth'])) {
        $issues[] = (string) ($settings['reauth_reason'] ?? 'Для публикации в сообщество нужен user token с правами wall, photos, groups. Требуется повторная авторизация через VK.');
        $steps['reauthorization_required'] = [
            'ok' => false,
            'message' => (string) ($settings['reauth_reason'] ?? 'Требуется повторная авторизация через VK.'),
        ];
    }

    if ($settings['publication_token'] !== '' && (int) $settings['group_id'] > 0) {
        try {
            $client = new VkApiClient(
                $settings['publication_token'],
                (int) $settings['group_id'],
                (string) $settings['api_version'],
                'user'
            );

            $vkUserId = '0';
            $userResponse = $client->apiRequestForDiagnostics('users.get', []);
            $vkUserId = (string) ((int) ($userResponse[0]['id'] ?? 0));
            if ($vkUserId === '0') {
                $issues[] = 'users.get не вернул VK user ID владельца токена.';
                $steps['users_get'] = ['ok' => false, 'message' => 'users.get не вернул id'];
            } else {
                $settings['vk_user_id'] = $vkUserId;
                $settings['vk_user_name'] = trim((string) (($userResponse[0]['first_name'] ?? '') . ' ' . ($userResponse[0]['last_name'] ?? '')));
                $checks[] = 'users.get выполнен';
                $steps['users_get'] = ['ok' => true, 'message' => 'OK', 'vk_user_id' => (int) $vkUserId, 'vk_user_name' => $settings['vk_user_name']];
            }

            if ($runtime['token_scope_raw'] !== '' && empty($scopeCheck['groups'])) {
                $steps['groups_getById'] = ['ok' => false, 'skipped' => true, 'message' => 'SKIPPED: нет scope groups'];
                $steps['group_role'] = ['ok' => false, 'skipped' => true, 'message' => 'SKIPPED: нет scope groups'];
                $steps['photos_getWallUploadServer'] = ['ok' => false, 'skipped' => true, 'message' => 'SKIPPED: нет scope groups/photos'];
            } else {
                $groupResponse = $client->apiRequestForDiagnostics('groups.getById', [
                    'group_id' => (int) $settings['group_id'],
                ]);
                if (!empty($groupResponse[0]['name'])) {
                    $groupName = trim((string) $groupResponse[0]['name']);
                }
                $checks[] = 'groups.getById выполнен';
                $steps['groups_getById'] = ['ok' => true, 'message' => 'OK', 'group_name' => $groupName];

                // Check that token owner is a manager of the group (owner/admin/editor).
                if ($vkUserId !== '0') {
                    $managersResponse = $client->apiRequestForDiagnostics('groups.getMembers', [
                        'group_id' => (int) $settings['group_id'],
                        'filter' => 'managers',
                        'count' => 1000,
                        'offset' => 0,
                    ]);
                    $items = [];
                    if (isset($managersResponse['items']) && is_array($managersResponse['items'])) {
                        $items = $managersResponse['items'];
                    } elseif (is_array($managersResponse)) {
                        $items = $managersResponse;
                    }

                    $isManager = false;
                    $role = '';
                    foreach ($items as $item) {
                        if (is_array($item) && (string) ((int) ($item['id'] ?? 0)) === $vkUserId) {
                            $isManager = true;
                            $role = (string) ($item['role'] ?? '');
                            break;
                        }
                        if (is_int($item) && (string) $item === $vkUserId) {
                            $isManager = true;
                            break;
                        }
                    }
                    if ($isManager) {
                        $checks[] = 'groups.getMembers(managers) выполнен' . ($role !== '' ? ' (role=' . $role . ')' : '');
                        $steps['group_role'] = ['ok' => true, 'message' => 'OK', 'role' => $role !== '' ? $role : 'manager'];
                    } else {
                        $issues[] = 'Владелец токена (VK user_id=' . $vkUserId . ') не является администратором/редактором сообщества ' . (int) $settings['group_id'] . '.';
                        $steps['group_role'] = ['ok' => false, 'message' => 'Пользователь не менеджер сообщества'];
                    }
                }
            }

            // Photo upload probe is needed only when publication scenario requires image upload.
            if ($scenario === 'publish_text_links') {
                $steps['photos_getWallUploadServer'] = ['ok' => null, 'message' => 'NOT NEEDED: publish_text_links'];
            } else {
                if (!array_key_exists('photos_getWallUploadServer', $steps)) {
                    if ($runtime['token_scope_raw'] !== '' && empty($scopeCheck['photos'])) {
                        $steps['photos_getWallUploadServer'] = ['ok' => false, 'skipped' => true, 'message' => 'SKIPPED: нет scope photos', 'token_id' => $runtime['token_id'] ?? ''];
                    } else {
                        $uploadResponse = $client->apiRequestForDiagnostics('photos.getWallUploadServer', [
                            'group_id' => (int) $settings['group_id'],
                        ]);
                        if (empty($uploadResponse['upload_url'])) {
                            $issues[] = 'photos.getWallUploadServer не вернул upload_url.';
                            $steps['photos_getWallUploadServer'] = ['ok' => false, 'message' => 'upload_url пустой', 'token_id' => $runtime['token_id'] ?? ''];
                        } else {
                            $checks[] = 'photos.getWallUploadServer выполнен';
                            $steps['photos_getWallUploadServer'] = ['ok' => true, 'message' => 'OK', 'token_id' => $runtime['token_id'] ?? ''];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $normalized = normalizeVkPublicationError($e);
            $checks[] = 'VK API check failed: ' . $normalized['technical'];

            if ($e instanceof VkApiException) {
                $technical = $e->getTechnicalMessage();
                $code = (int) $e->getCode();

                $scopesFromError = extractVkCurrentScopesFromTechnical($technical);
                if (!empty($scopesFromError)) {
                    $steps['token_scope_from_vk_error'] = [
                        'ok' => true,
                        'message' => implode(' ', $scopesFromError),
                        'items' => $scopesFromError,
                        'token_id' => $runtime['token_id'] ?? '',
                    ];
                }

                $steps['vk_api_error'] = [
                    'ok' => false,
                    'message' => $normalized['message'],
                    'error_code' => $code,
                    'technical' => $technical,
                    'token_id' => $runtime['token_id'] ?? '',
                ];

                // More precise classification for photo upload permissions.
                $technicalLower = mb_strtolower($technical);
                $roleOk = !empty($steps['group_role']['ok']);
                $isPhotoUploadServerError = str_contains($technicalLower, 'photos.getwalluploadserver')
                    || str_contains(mb_strtolower($normalized['message']), 'photos.getwalluploadserver');

                // If VK explicitly reports current scopes, trust it even if session metadata is stale.
                $missingPhotosBySessionScope = ($runtime['token_scope_raw'] !== '' && !vkScopeHas($scopeItems, 'photos'));
                $missingPhotosByVkScopes = (!empty($scopesFromError) && !in_array('photos', $scopesFromError, true));
                $missingPhotos = $missingPhotosBySessionScope || $missingPhotosByVkScopes;

                if ($roleOk && $isPhotoUploadServerError && ($code === 15 || $code === 7) && $missingPhotos) {
                    $issues[] = 'Роль в сообществе подтверждена, но текущий access token не имеет права photos. Получите новый токен с правами wall, photos, groups.';
                    $steps['photos_getWallUploadServer'] = [
                        'ok' => false,
                        'message' => 'ERROR #' . $code . ': нет права photos (scope)',
                        'error_code' => $code,
                        'token_id' => $runtime['token_id'] ?? '',
                    ];
                } else {
                    // Generic normalized message (may still be useful for other error types).
                    $issues[] = $normalized['message'];
                }

                if (!empty($scopesFromError) && $runtime['token_scope_raw'] !== '') {
                    $sessionScope = normalizeVkScopeItems($runtime['token_scope_raw']);
                    sort($sessionScope);
                    $errorScope = $scopesFromError;
                    sort($errorScope);
                    if ($sessionScope !== $errorScope) {
                        $issues[] = 'VK сообщил current scopes, которые отличаются от scope, сохранённого в сессии. Возможна потеря прав после refresh: перезайдите в админку через VK ID.';
                        $steps['token_scope_mismatch'] = [
                            'ok' => false,
                            'message' => 'scope в сессии не совпадает с VK current scopes',
                            'session_scope' => implode(' ', $sessionScope),
                            'vk_scope' => implode(' ', $errorScope),
                            'token_id' => $runtime['token_id'] ?? '',
                        ];
                    }
                }
            } else {
                $issues[] = $normalized['message'];
            }
        }
    }

    // Refine messaging: if group role is confirmed but photos scope is missing, do not mislead.
    $roleOk = !empty($steps['group_role']['ok']);
    if ($settings['publication_token'] !== '' && $runtime['token_scope_raw'] !== '' && $roleOk && empty($scopeCheck['photos'])) {
        $issues = array_values(array_filter($issues, static fn ($item) => $item !== 'У текущего access token отсутствует scope photos.'));
        $issues[] = 'Роль в сообществе подтверждена, но текущий access token не имеет права photos. Получите новый токен с правами wall, photos, groups.';
    }

    // Deduplicate issues to avoid confusing double messages in UI.
    if (!empty($issues)) {
        $issues = array_values(array_unique($issues));
    }

    $status = empty($issues) ? 'connected' : ($settings['publication_token'] !== '' ? 'attention' : 'disconnected');
    $confirmedPermissions = empty($issues) ? implode(', ', $requiredScopes) : '';

    saveSystemSettings([
        // Manual/stored tokens are deprecated: keep settings clean.
        'vk_publication_manual_token' => '',
        'vk_publication_access_token' => '',
        'vk_publication_user_token' => '',
        'vk_publication_refresh_token' => '',
        'vk_publication_status' => $status,
        'vk_publication_last_checked_at' => date('Y-m-d H:i:s'),
        'vk_publication_last_success_checked_at' => empty($issues)
            ? date('Y-m-d H:i:s')
            : trim((string) (getSystemSettings()['vk_publication_last_success_checked_at'] ?? '')),
        'vk_publication_last_check_status' => empty($issues) ? 'ok' : 'error',
        'vk_publication_last_check_message' => empty($issues)
            ? 'VK ID токен из текущей сессии прошёл проверку.'
            : implode('; ', $issues),
        'vk_publication_confirmed_permissions' => $confirmedPermissions,
        'vk_publication_last_error' => empty($issues) ? '' : implode('; ', $issues),
        'vk_publication_technical_diagnostics' => empty($issues) ? implode('; ', $checks) : implode('; ', $checks),
        'vk_publication_vk_user_id' => (string) ($settings['vk_user_id'] ?? ''),
        'vk_publication_vk_user_name' => (string) ($settings['vk_user_name'] ?? ''),
        'vk_publication_group_name' => $groupName,
        'vk_publication_token_type' => 'user_session',
    ]);

    vkPublicationLog('publication_token_check', [
        'ok' => empty($issues),
        'issues' => $issues,
        'checks' => $checks,
        'group_id' => $settings['group_id'],
        'token_masked' => maskVkPublicationToken($settings['publication_token']),
    ]);

    $settings['group_name'] = $groupName !== '' ? $groupName : trim((string) (getSystemSettings()['vk_publication_group_name'] ?? ''));

    return [
        'ok' => empty($issues),
        'issues' => $issues,
        'checks' => $checks,
        'settings' => $settings,
        'diagnostics' => $diagnostics,
        'runtime' => $runtime,
        'steps' => $steps,
    ];
}

function verifyVkPublicationReadinessCommunityToken(array $settings, string $scenario = 'diagnostic'): array
{
    $issues = [];
    $checks = [];
    $groupName = '';
    $steps = [];

    $runtime = [
        'auth_mode' => 'community_token',
        'token_source' => 'stored',
        'token_masked' => (string) ($settings['token_masked'] ?? ''),
        'token_id' => (string) ($settings['token_id'] ?? ''),
        'token_type' => 'group',
        'token_scope_raw' => '',
        'token_scope_items' => [],
        'token_scope_known' => false,
    ];

    $steps['capabilities'] = ['ok' => true, 'matrix' => ['text_post' => ['supported' => true], 'upload_local_image_to_wall' => ['supported' => true]]];

    if (trim((string) ($settings['publication_token'] ?? '')) === '') {
        $issues[] = 'В текущей сессии не найден пользовательский VK токен. Войдите на сайт через VK-аккаунт и повторите.';
        $steps['token_present'] = ['ok' => false, 'message' => 'Токен отсутствует в текущей сессии'];
    } else {
        $steps['token_present'] = ['ok' => true, 'message' => 'Токен найден в текущей сессии'];
    }

    if (trim((string) ($settings['group_id'] ?? '')) === '' || (int) ($settings['group_id'] ?? 0) <= 0) {
        $issues[] = 'Не указан ID сообщества VK.';
        $steps['group_id'] = ['ok' => false, 'message' => 'group_id не задан'];
    } else {
        $steps['group_id'] = ['ok' => true, 'message' => 'group_id задан', 'group_id' => (int) $settings['group_id']];
    }

    $steps['token_expired'] = ['ok' => null, 'message' => 'Срок действия токена определяется VK ID сессией'];
    $steps['users_get'] = ['ok' => null, 'message' => 'ожидается проверка через users.get'];
    $steps['group_role'] = ['ok' => null, 'message' => 'ожидается проверка роли в сообществе'];

    if (!empty($steps['token_present']['ok']) && !empty($steps['group_id']['ok'])) {
        try {
            $client = new VkApiClient((string) $settings['publication_token'], (int) $settings['group_id'], (string) $settings['api_version'], 'user');

            $client->validatePublicationAccess();
            $steps['users_get'] = ['ok' => true, 'message' => 'OK'];
            $steps['group_role'] = ['ok' => true, 'message' => 'OK'];
            $checks[] = 'users.get + groups.getMembers выполнены';

            $groupResponse = $client->apiRequestForDiagnostics('groups.getById', ['group_id' => (int) $settings['group_id']]);
            if (!empty($groupResponse[0]['name'])) {
                $groupName = trim((string) $groupResponse[0]['name']);
            }
            $steps['groups_getById'] = ['ok' => true, 'message' => 'OK', 'group_name' => $groupName];
            $checks[] = 'groups.getById выполнен';

            $steps['token_scope'] = [
                'ok' => true,
                'message' => 'Права подтверждаются результатами users.get/groups.getById/photos.getWallUploadServer и публикацией.',
                'scope_known' => false,
                'required' => getVkPublicationRequiredScopes(),
                'has' => [
                    'wall' => null,
                    'photos' => null,
                    'groups' => true,
                ],
            ];

            $uploadResponse = $client->apiRequestForDiagnostics('photos.getWallUploadServer', [
                'group_id' => (int) $settings['group_id'],
            ]);
            $steps['photos_getWallUploadServer'] = [
                'ok' => !empty($uploadResponse['upload_url']),
                'message' => !empty($uploadResponse['upload_url']) ? 'OK' : 'upload_url пустой',
            ];
            $steps['token_scope']['has']['photos'] = !empty($uploadResponse['upload_url']);
            if (empty($uploadResponse['upload_url'])) {
                $issues[] = 'Не удалось загрузить изображение для публикации';
            }
            $steps['photos_saveWallPhoto'] = ['ok' => null, 'message' => 'Проверяется при фактической публикации изображения из заявки'];
            $steps['wall_post'] = ['ok' => null, 'message' => 'Проверяется при фактической публикации изображения из заявки'];
            $steps['token_scope']['has']['wall'] = null;

        } catch (Throwable $e) {
            $normalized = normalizeVkPublicationError($e);
            $issues[] = $normalized['message'];
            $steps['vk_api_error'] = [
                'ok' => false,
                'message' => $normalized['message'],
                'technical' => $normalized['technical'],
                'token_id' => (string) ($settings['token_id'] ?? ''),
            ];
        }
    }

    if (!empty($issues)) {
        $issues = array_values(array_unique($issues));
    }

    $ok = empty($issues);
    $status = $ok ? 'connected' : (!empty($steps['token_present']['ok']) ? 'attention' : 'disconnected');
    $confirmedPermissions = $ok ? 'wall, photos, groups' : '';

    saveSystemSettings([
        'vk_publication_status' => $status,
        'vk_publication_last_checked_at' => date('Y-m-d H:i:s'),
        'vk_publication_last_check_status' => $ok ? 'ok' : 'error',
        'vk_publication_last_check_message' => $ok
            ? 'Пользовательский токен из текущей сессии прошёл проверку.'
            : implode('; ', $issues),
        'vk_publication_last_error' => $ok ? '' : implode('; ', $issues),
        'vk_publication_group_name' => $groupName,
        'vk_publication_token_type' => 'user_session',
        'vk_publication_confirmed_permissions' => $confirmedPermissions,
    ]);

    return [
        'ok' => $ok,
        'issues' => $issues,
        'checks' => $checks,
        'settings' => $settings,
        'diagnostics' => getVkPublicationTokenDiagnostics($settings),
        'runtime' => $runtime,
        'steps' => $steps,
    ];
}

function defaultVkPostTemplate(): string
{
    return "🎨 Работа участника конкурса детского рисунка\n\n"
        . "Представляем рисунок участника:\n"
        . "👤 {participant_full_name}\n\n"
        . "🏆 Конкурс: {contest_title}\n\n"
        . "📍 {region_name}\n"
        . "🏫 {organization_name}\n"
        . "🎂 Возраст: {participant_age}\n"
        . "👥 Возрастная категория: {age_category}\n\n"
        . "✨ Благодарим за участие, творчество и вдохновение!\n"
        . "Поддержите юного художника добрым словом и реакцией ❤️";
}

function compactVkPostTemplate(): string
{
    return "🎨 Юный участник конкурса\n\n"
        . "👤 {participant_full_name}\n"
        . "🏆 {contest_title}\n\n"
        . "📍 {region_name}\n"
        . "🏫 {organization_name}\n"
        . "🎂 {participant_age} лет\n"
        . "👥 {age_category}\n\n"
        . "✨ Поддержите участника реакцией и добрым комментарием!";
}

function normalizeVkTaskFilters(array $input): array
{
    $workIdsRaw = trim((string) ($input['work_ids_raw'] ?? ''));
    $workIds = [];
    if ($workIdsRaw !== '') {
        foreach (preg_split('/[\s,;]+/', $workIdsRaw) as $token) {
            $id = (int) $token;
            if ($id > 0) {
                $workIds[$id] = $id;
            }
        }
    }

    return [
        'contest_id' => max(0, (int) ($input['contest_id'] ?? 0)),
        'application_status' => trim((string) ($input['application_status'] ?? '')),
        'work_status' => 'accepted',
        'region' => trim((string) ($input['region'] ?? '')),
        'organization_name' => trim((string) ($input['organization_name'] ?? '')),
        'drawing_title' => trim((string) ($input['drawing_title'] ?? '')),
        'submitted_from' => trim((string) ($input['submitted_from'] ?? '')),
        'submitted_to' => trim((string) ($input['submitted_to'] ?? '')),
        'reviewed_from' => trim((string) ($input['reviewed_from'] ?? '')),
        'reviewed_to' => trim((string) ($input['reviewed_to'] ?? '')),
        'has_image' => (string) ($input['has_image'] ?? ''),
        'required_data_only' => !empty($input['required_data_only']) ? 1 : 0,
        'exclude_vk_published' => !isset($input['exclude_vk_published']) || (int) $input['exclude_vk_published'] === 1 ? 1 : 0,
        'only_without_vk_errors' => !empty($input['only_without_vk_errors']) ? 1 : 0,
        'work_ids' => array_values($workIds),
        'work_ids_raw' => $workIdsRaw,
    ];
}

function getVkWorksByFilters(array $filters): array
{
    global $pdo;

    $where = [];
    $params = [];

    if ((int) ($filters['contest_id'] ?? 0) > 0) {
        $where[] = 'w.contest_id = ?';
        $params[] = (int) $filters['contest_id'];
    }

    if (($filters['application_status'] ?? '') !== '') {
        $where[] = 'a.status = ?';
        $params[] = (string) $filters['application_status'];
    }

    $where[] = "w.status = 'accepted'";

    if (($filters['region'] ?? '') !== '') {
        $where[] = 'p.region LIKE ?';
        $params[] = '%' . $filters['region'] . '%';
    }

    if (($filters['organization_name'] ?? '') !== '') {
        $where[] = 'p.organization_name LIKE ?';
        $params[] = '%' . $filters['organization_name'] . '%';
    }

    if (($filters['drawing_title'] ?? '') !== '') {
        $where[] = 'w.title LIKE ?';
        $params[] = '%' . $filters['drawing_title'] . '%';
    }

    if (($filters['submitted_from'] ?? '') !== '') {
        $where[] = 'DATE(a.created_at) >= ?';
        $params[] = $filters['submitted_from'];
    }

    if (($filters['submitted_to'] ?? '') !== '') {
        $where[] = 'DATE(a.created_at) <= ?';
        $params[] = $filters['submitted_to'];
    }

    if (($filters['reviewed_from'] ?? '') !== '') {
        $where[] = 'DATE(w.reviewed_at) >= ?';
        $params[] = $filters['reviewed_from'];
    }

    if (($filters['reviewed_to'] ?? '') !== '') {
        $where[] = 'DATE(w.reviewed_at) <= ?';
        $params[] = $filters['reviewed_to'];
    }

    if (($filters['has_image'] ?? '') === 'yes') {
        $where[] = "COALESCE(NULLIF(w.image_path, ''), NULLIF(p.drawing_file, '')) IS NOT NULL";
    } elseif (($filters['has_image'] ?? '') === 'no') {
        $where[] = "COALESCE(NULLIF(w.image_path, ''), NULLIF(p.drawing_file, '')) IS NULL";
    }

    if (!empty($filters['exclude_vk_published'])) {
        $where[] = 'w.vk_published_at IS NULL';
    }

    if (!empty($filters['only_without_vk_errors'])) {
        $where[] = '(w.vk_publish_error IS NULL OR w.vk_publish_error = "")';
    }

    if (!empty($filters['work_ids']) && is_array($filters['work_ids'])) {
        $placeholders = implode(',', array_fill(0, count($filters['work_ids']), '?'));
        $where[] = 'w.id IN (' . $placeholders . ')';
        foreach ($filters['work_ids'] as $workId) {
            $params[] = (int) $workId;
        }
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT
            w.id AS work_id,
            w.application_id,
            w.participant_id,
            w.contest_id,
            w.title AS work_title,
            w.image_path,
            w.status AS work_status,
            w.reviewed_at,
            w.vk_published_at,
            w.vk_post_id,
            w.vk_post_url,
            w.vk_publish_error,
            a.status AS application_status,
            a.created_at AS application_created_at,
            a.updated_at AS application_updated_at,
            c.title AS contest_title,
            p.fio AS participant_fio,
            p.age AS participant_age,
            p.region,
            p.organization_name,
            p.organization_address,
            p.organization_email,
            p.drawing_file,
            u.email AS applicant_email,
            u.name AS applicant_name,
            u.surname AS applicant_surname
        FROM works w
        INNER JOIN applications a ON a.id = w.application_id
        INNER JOIN participants p ON p.id = w.participant_id
        LEFT JOIN contests c ON c.id = w.contest_id
        LEFT JOIN users u ON u.id = a.user_id
        $whereSql
        ORDER BY w.created_at DESC, w.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    foreach ($rows as &$row) {
        $row['work_image_file'] = trim((string) ($row['image_path'] ?: $row['drawing_file']));
        $row['work_image_web_path'] = getParticipantDrawingWebPath((string) ($row['applicant_email'] ?? ''), $row['work_image_file']);
        $row['work_image_preview_web_path'] = getParticipantDrawingPreviewWebPath((string) ($row['applicant_email'] ?? ''), $row['work_image_file']);
        $row['work_image_fs_path'] = getParticipantDrawingFsPath((string) ($row['applicant_email'] ?? ''), $row['work_image_file']);
    }

    return $rows;
}

function buildVkPostText(array $workRow, string $template): string
{
    $participantFullName = trim((string) ($workRow['participant_fio'] ?? ''));
    $parts = preg_split('/\s+/u', $participantFullName) ?: [];

    $vars = [
        'participant_name' => trim((string) ($parts[1] ?? $parts[0] ?? '')),
        'participant_full_name' => $participantFullName,
        'organization_name' => trim((string) ($workRow['organization_name'] ?? '')),
        'region_name' => trim((string) ($workRow['region'] ?? '')),
        'work_title' => trim((string) ($workRow['work_title'] ?? '')),
        'drawing_title' => trim((string) ($workRow['work_title'] ?? '')),
        'contest_title' => trim((string) ($workRow['contest_title'] ?? '')),
        'nomination' => '',
        'participant_age' => (int) ($workRow['participant_age'] ?? 0) > 0 ? (string) ((int) $workRow['participant_age']) : '',
        'age_category' => resolveAgeCategory((int) ($workRow['participant_age'] ?? 0)),
    ];

    $replaceMap = [];
    foreach ($vars as $key => $value) {
        $replaceMap['{' . $key . '}'] = $value;
    }

    $lines = preg_split('/\R/u', $template) ?: [];
    $cleanLines = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            $cleanLines[] = '';
            continue;
        }

        preg_match_all('/\{([a-z0-9_]+)\}/iu', $line, $matches);
        $tokens = $matches[1] ?? [];
        if (!empty($tokens)) {
            $allEmpty = true;
            foreach ($tokens as $token) {
                if (trim((string) ($vars[$token] ?? '')) !== '') {
                    $allEmpty = false;
                    break;
                }
            }
            if ($allEmpty) {
                continue;
            }
        }

        $line = strtr($line, $replaceMap);
        if (preg_match('/\{[a-z0-9_]+\}/iu', $line)) {
            continue;
        }

        $line = trim($line);
        if ($line === '' || preg_match('/[:：]\s*$/u', $line)) {
            continue;
        }
        $lineCore = preg_replace('/[\p{Z}\p{P}\p{S}]+/u', '', $line);
        if ($lineCore === '') {
            continue;
        }

        $cleanLines[] = $line;
    }

    $collapsed = [];
    $prevEmpty = true;
    foreach ($cleanLines as $line) {
        $isEmpty = trim($line) === '';
        if ($isEmpty) {
            if ($prevEmpty) {
                continue;
            }
            $collapsed[] = '';
            $prevEmpty = true;
            continue;
        }

        $collapsed[] = $line;
        $prevEmpty = false;
    }

    return trim(implode("\n", $collapsed));
}

function resolveAgeCategory(int $age): string
{
    return getParticipantAgeCategoryLabel($age);
}

function buildVkTaskPreview(array $filters, ?string $template = null): array
{
    $settings = getVkPublicationSettings();
    $template = trim((string) ($template ?? ''));
    if ($template === '') {
        $template = $settings['post_template'];
    }

    $rows = getVkWorksByFilters($filters);

    $items = [];
    $reasons = [];
    $ready = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        $itemStatus = 'ready';
        $skipReason = null;

        if (empty($row['work_image_file'])) {
            $itemStatus = 'skipped';
            $skipReason = 'Нет изображения работы';
        } elseif (empty($row['work_image_fs_path']) || !is_file((string) $row['work_image_fs_path'])) {
            $itemStatus = 'skipped';
            $skipReason = 'Файл изображения не найден на диске';
        } elseif (!is_readable((string) $row['work_image_fs_path'])) {
            $itemStatus = 'failed';
            $skipReason = 'Файл изображения недоступен для чтения';
        } elseif ((int) @filesize((string) $row['work_image_fs_path']) <= 0) {
            $itemStatus = 'failed';
            $skipReason = 'Файл изображения пустой';
        } elseif (!empty($filters['required_data_only']) && trim((string) ($row['participant_fio'] ?? '')) === '') {
            $itemStatus = 'skipped';
            $skipReason = 'Не заполнено ФИО участника';
        } elseif (!empty($row['vk_published_at']) && !empty($filters['exclude_vk_published'])) {
            $itemStatus = 'skipped';
            $skipReason = 'Работа уже опубликована в VK';
        }

        $postText = '';
        if (trim($template) === '') {
            $itemStatus = 'failed';
            $skipReason = 'Не заполнен шаблон текста публикации в настройках';
        } else {
            $postText = buildVkPostText($row, $template);
            if ($postText === '') {
                $itemStatus = 'skipped';
                $skipReason = 'Не удалось сформировать текст поста';
            }
        }

        if ($itemStatus === 'ready') {
            $ready++;
        } else {
            $skipped++;
            $reasons[$skipReason] = ($reasons[$skipReason] ?? 0) + 1;
        }

        $items[] = [
            'work_id' => (int) $row['work_id'],
            'application_id' => (int) $row['application_id'],
            'participant_id' => (int) $row['participant_id'],
            'contest_id' => (int) $row['contest_id'],
            'work_image_path' => (string) ($row['work_image_file'] ?? ''),
            'post_text' => $postText,
            'item_status' => $itemStatus,
            'skip_reason' => $skipReason,
            'source' => $row,
        ];
    }

    return [
        'filters' => $filters,
        'total_items' => count($items),
        'ready_items' => $ready,
        'skipped_items' => $skipped,
        'skip_reasons' => $reasons,
        'items' => $items,
        'sample_post_text' => $items[0]['post_text'] ?? '',
    ];
}

function createVkTaskFromPreview(string $title, int $createdBy, array $preview, string $publicationMode = 'manual', array $publicationMeta = []): int
{
    global $pdo;
    ensureVkPublicationSchema();

    $filters = $preview['filters'] ?? [];
    $items = $preview['items'] ?? [];
    $contestId = (int) ($filters['contest_id'] ?? 0);
    $status = $publicationMode === 'immediate' ? 'ready' : 'draft';
    $settings = getVkPublicationSettings();
    $taskColumns = getTableColumnsCached('vk_publication_tasks');
    $itemColumns = getTableColumnsCached('vk_publication_task_items');

    $taskInsertColumns = [
        'title',
        'contest_id',
        'created_by',
        'task_status',
        'publication_mode',
        'filters_json',
        'summary_json',
        'total_items',
        'ready_items',
        'skipped_items',
        'vk_group_id',
    ];
    $taskInsertValues = [
        trim($title) !== '' ? trim($title) : 'Публикация от ' . date('d.m.Y H:i'),
        $contestId > 0 ? $contestId : null,
        $createdBy,
        $status,
        $publicationMode,
        json_encode($filters, JSON_UNESCAPED_UNICODE),
        json_encode([
            'skip_reasons' => $preview['skip_reasons'] ?? [],
            'sample_post_text' => $preview['sample_post_text'] ?? '',
        ], JSON_UNESCAPED_UNICODE),
        (int) ($preview['total_items'] ?? 0),
        (int) ($preview['ready_items'] ?? 0),
        (int) ($preview['skipped_items'] ?? 0),
        $settings['group_id'] !== '' ? $settings['group_id'] : null,
    ];

    if (in_array('publication_type', $taskColumns, true)) {
        $taskInsertColumns[] = 'publication_type';
        $taskInsertValues[] = 'standard';
    }
    if (in_array('resolved_mode', $taskColumns, true)) {
        $taskInsertColumns[] = 'resolved_mode';
        $taskInsertValues[] = 'standard';
    }
    if (in_array('capability_status', $taskColumns, true)) {
        $taskInsertColumns[] = 'capability_status';
        $taskInsertValues[] = 'not_checked';
    }

    $taskInsertColumns[] = 'created_at';
    $taskInsertColumns[] = 'updated_at';
    $taskInsertSql = "INSERT INTO vk_publication_tasks (" . implode(', ', $taskInsertColumns) . ")
        VALUES (" . implode(', ', array_fill(0, count($taskInsertColumns) - 2, '?')) . ", NOW(), NOW())";
    $stmt = $pdo->prepare($taskInsertSql);
    $stmt->execute($taskInsertValues);
    $taskId = (int) $pdo->lastInsertId();

    $itemInsertColumns = [
        'task_id',
        'work_id',
        'application_id',
        'participant_id',
        'contest_id',
        'work_image_path',
        'post_text',
        'item_status',
        'skip_reason',
    ];
    $supportsItemPublicationType = in_array('publication_type', $itemColumns, true);
    $supportsItemResolvedMode = in_array('resolved_mode', $itemColumns, true);
    $supportsItemCapabilityStatus = in_array('capability_status', $itemColumns, true);
    if ($supportsItemPublicationType) {
        $itemInsertColumns[] = 'publication_type';
    }
    if ($supportsItemResolvedMode) {
        $itemInsertColumns[] = 'resolved_mode';
    }
    if ($supportsItemCapabilityStatus) {
        $itemInsertColumns[] = 'capability_status';
    }

    $itemInsertColumns[] = 'created_at';
    $itemInsertColumns[] = 'updated_at';
    $itemInsertSql = "INSERT INTO vk_publication_task_items (" . implode(', ', $itemInsertColumns) . ")
        VALUES (" . implode(', ', array_fill(0, count($itemInsertColumns) - 2, '?')) . ", NOW(), NOW())";
    $ins = $pdo->prepare($itemInsertSql);

    foreach ($items as $item) {
        $itemInsertValues = [
            $taskId,
            (int) ($item['work_id'] ?? 0),
            (int) ($item['application_id'] ?? 0),
            (int) ($item['participant_id'] ?? 0),
            (int) ($item['contest_id'] ?? 0),
            (string) ($item['work_image_path'] ?? ''),
            (string) ($item['post_text'] ?? ''),
            (string) ($item['item_status'] ?? 'pending'),
            $item['skip_reason'] ?? null,
        ];
        if ($supportsItemPublicationType) {
            $itemInsertValues[] = 'standard';
        }
        if ($supportsItemResolvedMode) {
            $itemInsertValues[] = 'standard';
        }
        if ($supportsItemCapabilityStatus) {
            $itemInsertValues[] = 'not_checked';
        }
        $ins->execute($itemInsertValues);
    }

    refreshVkTaskCounters($taskId);

    return $taskId;
}

function getVkTaskById(int $taskId): ?array
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT t.*, c.title AS contest_title, u.name AS creator_name, u.surname AS creator_surname
        FROM vk_publication_tasks t
        LEFT JOIN contests c ON c.id = t.contest_id
        LEFT JOIN users u ON u.id = t.created_by
        WHERE t.id = ?");
    $stmt->execute([$taskId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $row['filters'] = !empty($row['filters_json']) ? (json_decode((string) $row['filters_json'], true) ?: []) : [];
    $row['summary'] = !empty($row['summary_json']) ? (json_decode((string) $row['summary_json'], true) ?: []) : [];
    $row['vk_donut_settings'] = !empty($row['vk_donut_settings_snapshot'])
        ? (json_decode((string) $row['vk_donut_settings_snapshot'], true) ?: [])
        : [];

    return $row;
}

function getVkTaskItems(int $taskId): array
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT
            i.*,
            w.status AS work_status,
            w.vk_post_url AS work_vk_post_url,
            p.fio AS participant_fio,
            p.age,
            p.region,
            p.organization_name,
            c.title AS contest_title,
            u.email AS applicant_email
        FROM vk_publication_task_items i
        LEFT JOIN works w ON w.id = i.work_id
        LEFT JOIN participants p ON p.id = i.participant_id
        LEFT JOIN contests c ON c.id = i.contest_id
        LEFT JOIN applications a ON a.id = i.application_id
        LEFT JOIN users u ON u.id = a.user_id
        WHERE i.task_id = ?
        ORDER BY i.id ASC");
    $stmt->execute([$taskId]);
    $rows = $stmt->fetchAll() ?: [];

    foreach ($rows as &$row) {
        $row['work_image_web_path'] = getParticipantDrawingWebPath((string) ($row['applicant_email'] ?? ''), (string) ($row['work_image_path'] ?? ''));
        $row['work_image_preview_web_path'] = getParticipantDrawingPreviewWebPath((string) ($row['applicant_email'] ?? ''), (string) ($row['work_image_path'] ?? ''));
        $row['work_image_fs_path'] = getParticipantDrawingFsPath((string) ($row['applicant_email'] ?? ''), (string) ($row['work_image_path'] ?? ''));
    }

    return $rows;
}

function refreshVkTaskCounters(int $taskId): void
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT
            COUNT(*) AS total_items,
            SUM(item_status = 'ready') AS ready_items,
            SUM(item_status = 'published') AS published_items,
            SUM(item_status = 'failed') AS failed_items,
            SUM(item_status = 'skipped') AS skipped_items
        FROM vk_publication_task_items WHERE task_id = ?");
    $stmt->execute([$taskId]);
    $counts = $stmt->fetch() ?: [];

    $totalItems = (int) ($counts['total_items'] ?? 0);
    $readyItems = (int) ($counts['ready_items'] ?? 0);
    $publishedItems = (int) ($counts['published_items'] ?? 0);
    $failedItems = (int) ($counts['failed_items'] ?? 0);
    $skippedItems = (int) ($counts['skipped_items'] ?? 0);

    $taskStatus = 'draft';
    if ($publishedItems > 0 && $failedItems === 0 && $readyItems === 0) {
        $taskStatus = 'published';
    } elseif ($publishedItems > 0 && $failedItems > 0) {
        $taskStatus = 'partially_failed';
    } elseif ($failedItems > 0 && $publishedItems === 0 && $readyItems === 0) {
        $taskStatus = 'failed';
    } elseif ($readyItems > 0) {
        $taskStatus = 'ready';
    }

    $publishedAt = $publishedItems > 0 ? date('Y-m-d H:i:s') : null;

    $pdo->prepare("UPDATE vk_publication_tasks
        SET total_items = ?, ready_items = ?, published_items = ?, failed_items = ?, skipped_items = ?,
            task_status = CASE WHEN task_status = 'archived' THEN 'archived' ELSE ? END,
            published_at = CASE WHEN ? IS NULL THEN published_at ELSE ? END,
            updated_at = NOW()
        WHERE id = ?")
        ->execute([
            $totalItems,
            $readyItems,
            $publishedItems,
            $failedItems,
            $skippedItems,
            $taskStatus,
            $publishedAt,
            $publishedAt,
            $taskId,
        ]);
}

function publishVkTaskItem(int $itemId, array $options = []): array
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT i.*, a.user_id, u.email AS applicant_email
        FROM vk_publication_task_items i
        LEFT JOIN applications a ON a.id = i.application_id
        LEFT JOIN users u ON u.id = a.user_id
        WHERE i.id = ?");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();

    if (!$item) {
        return ['success' => false, 'error' => 'Элемент задания не найден'];
    }

    if ($item['item_status'] === 'published') {
        return ['success' => true, 'already' => true, 'post_url' => $item['vk_post_url']];
    }

    $failureStage = null;
    $requestPayload = [];
    $responsePayload = [];
    $verificationStatus = 'confirmed_standard';
    $verificationMessage = 'Пост опубликован.';
    $detectedMode = 'standard';
    $detectedFeatures = [];
    $vkPostReadbackJson = null;

    $readiness = verifyVkPublicationReadiness(true, true, 'publish_local_image');
    if (empty($readiness['ok'])) {
        $failureStage = 'validation';
        $error = (string) ($readiness['issues'][0] ?? 'VK не готов к публикации');
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $error, implode('; ', $readiness['issues'] ?? []));
        return ['success' => false, 'error' => $error];
    }

    $settings = $readiness['settings'];
    try {
        $client = new VkApiClient($settings['publication_token'], (int) $settings['group_id'], (string) $settings['api_version'], 'user');
    } catch (Throwable $e) {
        $failureStage = 'validation';
        $normalized = normalizeVkPublicationError($e);
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $normalized['message'], $normalized['technical']);
        return ['success' => false, 'error' => $normalized['message']];
    }

    $imageFsPath = getParticipantDrawingFsPath((string) ($item['applicant_email'] ?? ''), (string) ($item['work_image_path'] ?? ''));
    if (!$imageFsPath || !is_file($imageFsPath) || !is_readable($imageFsPath)) {
        $failureStage = 'validation';
        $error = 'Не удалось найти изображение в заявке или файл недоступен для чтения.';
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $error, $error);
        return ['success' => false, 'error' => $error];
    }

    $requestPayload = [
        'item_id' => (int) $item['id'],
        'task_id' => (int) $item['task_id'],
        'publication_type' => 'standard',
        'message' => (string) ($item['post_text'] ?? ''),
        'image_path' => (string) ($item['work_image_path'] ?? ''),
        'strategy' => 'photo_upload',
    ];

    try {
        $failureStage = 'wall_post';
        $published = $client->publishPhotoPost(
            (string) $imageFsPath,
            (string) ($item['post_text'] ?? ''),
            (bool) $settings['from_group']
        );
        $responsePayload = $published;
        $vkPostReadbackJson = json_encode($published['readback_raw'] ?? [], JSON_UNESCAPED_UNICODE);

        $pdo->prepare("UPDATE vk_publication_task_items
            SET item_status = 'published', vk_post_id = ?, vk_post_url = ?, error_message = ?, technical_error = ?, published_at = NOW(), updated_at = NOW(),
                resolved_mode = ?, capability_status = ?, failure_stage = NULL, request_payload_json = ?, response_payload_json = ?,
                verification_status = ?, verification_message = ?, detected_mode = ?, detected_features_json = ?, vk_post_readback_json = ?
            WHERE id = ?")
            ->execute([
                (string) $published['post_id'],
                (string) $published['post_url'],
                null,
                null,
                'standard',
                'supported',
                json_encode($requestPayload, JSON_UNESCAPED_UNICODE),
                json_encode($responsePayload, JSON_UNESCAPED_UNICODE),
                $verificationStatus,
                $verificationMessage,
                $detectedMode,
                json_encode($detectedFeatures, JSON_UNESCAPED_UNICODE),
                $vkPostReadbackJson,
                (int) $item['id'],
            ]);

        $pdo->prepare("UPDATE vk_publication_tasks
            SET vk_post_url = COALESCE(vk_post_url, ?), updated_at = NOW()
            WHERE id = ?")
            ->execute([(string) $published['post_url'], (int) $item['task_id']]);

        $pdo->prepare("UPDATE works
            SET vk_published_at = NOW(), vk_post_id = ?, vk_post_url = ?, vk_publish_error = NULL, updated_at = NOW()
            WHERE id = ?")
            ->execute([(string) $published['post_id'], (string) $published['post_url'], (int) $item['work_id']]);

        refreshVkTaskCounters((int) $item['task_id']);
        vkPublicationLog('publish_item_success', [
            'item_id' => (int) $item['id'],
            'task_id' => (int) $item['task_id'],
            'work_id' => (int) $item['work_id'],
            'publication_type' => 'standard',
            'post_id' => (string) $published['post_id'],
            'verification_status' => $verificationStatus,
            'verification_message' => $verificationMessage,
            'detected_mode' => $detectedMode,
            'detected_features' => $detectedFeatures,
        ]);

        return [
            'success' => true,
            'post_id' => (string) $published['post_id'],
            'post_url' => (string) $published['post_url'],
        ];
    } catch (Throwable $e) {
        $normalized = normalizeVkPublicationError($e);
        $errorMessage = $normalized['message'];
        vkPublicationLog('publish_item_failed', [
            'item_id' => (int) $item['id'],
            'task_id' => (int) $item['task_id'],
            'work_id' => (int) $item['work_id'],
            'publication_type' => 'standard',
            'failure_stage' => $failureStage ?? 'wall_post',
            'error' => $errorMessage,
            'technical' => $normalized['technical'],
            'post_id' => (string) ($responsePayload['post_id'] ?? ''),
            'post_url' => (string) ($responsePayload['post_url'] ?? ''),
        ]);
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $errorMessage, $normalized['technical']);
        $pdo->prepare("UPDATE vk_publication_task_items
            SET resolved_mode = ?, capability_status = ?, failure_stage = ?, request_payload_json = ?, response_payload_json = ?,
                verification_status = COALESCE(verification_status, ?), verification_message = COALESCE(verification_message, ?),
                detected_mode = COALESCE(detected_mode, ?), detected_features_json = COALESCE(detected_features_json, ?),
                vk_post_readback_json = COALESCE(vk_post_readback_json, ?), updated_at = NOW()
            WHERE id = ?")
            ->execute([
                'standard',
                'unsupported',
                $failureStage ?? 'wall_post',
                json_encode($requestPayload, JSON_UNESCAPED_UNICODE),
                json_encode(array_filter([
                    'published_payload' => !empty($responsePayload) ? $responsePayload : null,
                    'error' => $errorMessage,
                    'technical' => $normalized['technical'],
                ], static fn($value) => $value !== null), JSON_UNESCAPED_UNICODE),
                $verificationStatus ?? 'verification_failed',
                $verificationMessage ?? $errorMessage,
                $detectedMode,
                !empty($detectedFeatures) ? json_encode($detectedFeatures, JSON_UNESCAPED_UNICODE) : null,
                $vkPostReadbackJson,
                (int) $item['id'],
            ]);
        return ['success' => false, 'error' => $errorMessage];
    }
}

function markVkTaskItemFailed(int $itemId, int $taskId, string $error, string $technicalError = ''): void
{
    global $pdo;

    $pdo->prepare("UPDATE vk_publication_task_items
        SET item_status = 'failed', error_message = ?, technical_error = ?, updated_at = NOW()
        WHERE id = ?")
        ->execute([
            mb_substr($error, 0, 2000),
            mb_substr($technicalError !== '' ? $technicalError : $error, 0, 4000),
            $itemId,
        ]);

    $pdo->prepare("UPDATE works w
        INNER JOIN vk_publication_task_items i ON i.work_id = w.id
        SET w.vk_publish_error = ?, w.updated_at = NOW()
        WHERE i.id = ?")
        ->execute([mb_substr($error, 0, 2000), $itemId]);

    refreshVkTaskCounters($taskId);
}

function normalizeVkPublicationError(Throwable $e): array
{
    $message = trim((string) $e->getMessage());
    $technical = $message;

    if ($e instanceof VkApiException) {
        $technical = $e->getTechnicalMessage();
    }

    if ($message === '') {
        $message = 'Неизвестная ошибка публикации в VK.';
    }

    $lower = mb_strtolower($message . ' ' . $technical);
    if (str_contains($lower, 'invalid access token') || str_contains($lower, 'access token is invalid')) {
        $message = 'Пользовательский VK токен в текущей сессии недействителен. Перезайдите на сайт через VK.';
    } elseif (str_contains($lower, 'access denied') || str_contains($lower, 'not enough rights') || str_contains($lower, 'permission')) {
        $message = 'Недостаточно прав для публикации: текущий VK-аккаунт должен иметь права wall/photos/groups и роль в сообществе.';
    } elseif (str_contains($lower, 'upload') || str_contains($lower, 'photo')) {
        $message = 'Не удалось загрузить изображение из заявки в VK.';
    } else {
        $message = 'Публикация через пользовательский VK токен завершилась ошибкой.';
    }

    return [
        'message' => $message,
        'technical' => $technical,
    ];
}

function publishVkTask(int $taskId, array $options = []): array
{
    global $pdo;
    $readiness = verifyVkPublicationReadiness(true, true, 'publish_local_image');
    if (empty($readiness['ok'])) {
        return [
            'total' => 0,
            'published' => 0,
            'failed' => 0,
            'error' => implode('; ', $readiness['issues'] ?? ['VK не готов к публикации']),
        ];
    }

    $pdo->prepare("UPDATE vk_publication_tasks SET task_status = 'publishing', resolved_mode = 'standard', capability_status = 'not_checked', updated_at = NOW() WHERE id = ?")
        ->execute([$taskId]);

    $stmt = $pdo->prepare("SELECT id FROM vk_publication_task_items
        WHERE task_id = ? AND item_status IN ('ready','failed','pending')
        ORDER BY id ASC");
    $stmt->execute([$taskId]);
    $itemIds = array_map('intval', array_column($stmt->fetchAll() ?: [], 'id'));

    $result = ['total' => count($itemIds), 'published' => 0, 'failed' => 0];

    foreach ($itemIds as $itemId) {
        $itemResult = publishVkTaskItem($itemId, $options);
        if (!empty($itemResult['success'])) {
            $result['published']++;
        } else {
            $result['failed']++;
        }
    }

    refreshVkTaskCounters($taskId);
    $pdo->prepare("UPDATE vk_publication_tasks
        SET capability_status = ?, failure_stage = ?, response_payload_json = ?, updated_at = NOW()
        WHERE id = ?")
        ->execute([
            $result['failed'] > 0 ? 'unsupported' : 'supported',
            $result['failed'] > 0 ? 'wall_post' : null,
            json_encode($result, JSON_UNESCAPED_UNICODE),
            $taskId,
        ]);

    return $result;
}

function retryFailedVkTaskItems(int $taskId): array
{
    global $pdo;
    $pdo->prepare("UPDATE vk_publication_task_items SET item_status = 'ready', updated_at = NOW() WHERE task_id = ? AND item_status = 'failed'")
        ->execute([$taskId]);

    return publishVkTask($taskId);
}
