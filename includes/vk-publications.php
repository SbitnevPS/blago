<?php

require_once __DIR__ . '/vk-api.php';
require_once __DIR__ . '/auth/vk-oauth.php';

final class VkPublicationAuthContext
{
    public function __construct(
        public string $mode,
        public string $accessToken,
        public int $groupId,
        public string $apiVersion,
        public bool $fromGroup,
        public ?string $refreshToken = null,
        public ?string $deviceId = null,
        public ?int $expiresAt = null,
        public ?int $adminUserId = null,
    ) {
    }

    public function canUploadLocalImage(): bool
    {
        return $this->mode === 'admin_user_token';
    }
}

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
        $itemColumnSql = [
            'publication_type' => "ALTER TABLE vk_publication_task_items ADD COLUMN publication_type VARCHAR(32) NOT NULL DEFAULT 'standard'",
            'resolved_mode' => "ALTER TABLE vk_publication_task_items ADD COLUMN resolved_mode VARCHAR(32) NULL",
            'capability_status' => "ALTER TABLE vk_publication_task_items ADD COLUMN capability_status VARCHAR(32) NOT NULL DEFAULT 'not_checked'",
            'failure_stage' => "ALTER TABLE vk_publication_task_items ADD COLUMN failure_stage VARCHAR(64) NULL",
            'request_payload_json' => "ALTER TABLE vk_publication_task_items ADD COLUMN request_payload_json LONGTEXT NULL",
            'response_payload_json' => "ALTER TABLE vk_publication_task_items ADD COLUMN response_payload_json LONGTEXT NULL",
            'verification_status' => "ALTER TABLE vk_publication_task_items ADD COLUMN verification_status VARCHAR(64) NULL",
            'verification_message' => "ALTER TABLE vk_publication_task_items ADD COLUMN verification_message TEXT NULL",
            'detected_mode' => "ALTER TABLE vk_publication_task_items ADD COLUMN detected_mode VARCHAR(64) NULL",
            'detected_features_json' => "ALTER TABLE vk_publication_task_items ADD COLUMN detected_features_json LONGTEXT NULL",
            'vk_post_readback_json' => "ALTER TABLE vk_publication_task_items ADD COLUMN vk_post_readback_json LONGTEXT NULL",
        ];
        foreach ($itemColumnSql as $column => $sql) {
            if (!in_array($column, $itemColumns, true)) {
                $pdo->exec($sql);
            }
        }
    } catch (Throwable $e) {
    }

    try {
        $taskColumns = $pdo->query("SHOW COLUMNS FROM vk_publication_tasks")->fetchAll(PDO::FETCH_COLUMN);
        $taskColumnSql = [
            'publication_type' => "ALTER TABLE vk_publication_tasks ADD COLUMN publication_type VARCHAR(32) NOT NULL DEFAULT 'standard'",
            'resolved_mode' => "ALTER TABLE vk_publication_tasks ADD COLUMN resolved_mode VARCHAR(32) NULL",
            'capability_status' => "ALTER TABLE vk_publication_tasks ADD COLUMN capability_status VARCHAR(32) NOT NULL DEFAULT 'not_checked'",
            'failure_stage' => "ALTER TABLE vk_publication_tasks ADD COLUMN failure_stage VARCHAR(64) NULL",
            'response_payload_json' => "ALTER TABLE vk_publication_tasks ADD COLUMN response_payload_json LONGTEXT NULL",
        ];
        foreach ($taskColumnSql as $column => $sql) {
            if (!in_array($column, $taskColumns, true)) {
                $pdo->exec($sql);
            }
        }
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

    return [
        'auth_mode' => in_array((string) ($settings['vk_publication_auth_mode'] ?? 'community_token'), ['community_token', 'admin_user_token'], true)
            ? (string) $settings['vk_publication_auth_mode']
            : 'community_token',
        'token_source_setting' => 'stored_group',
        'group_id' => trim((string) ($settings['vk_publication_group_id'] ?? '')),
        'group_token' => $groupToken,
        'api_version' => trim((string) ($settings['vk_publication_api_version'] ?? VK_API_VERSION)),
        'from_group' => (int) ($settings['vk_publication_from_group'] ?? 1) === 1,
        'post_template' => trim((string) ($settings['vk_publication_post_template'] ?? defaultVkPostTemplate())),
        'group_name' => trim((string) ($settings['vk_publication_group_name'] ?? '')),
        'token_type' => 'group',
        'confirmed_permissions' => trim((string) ($settings['vk_publication_confirmed_permissions'] ?? '')),
        'status' => trim((string) ($settings['vk_publication_status'] ?? 'disconnected')),
        'last_error' => trim((string) ($settings['vk_publication_last_error'] ?? '')),
        'technical_diagnostics' => trim((string) ($settings['vk_publication_technical_diagnostics'] ?? '')),
        'last_checked_at' => $lastCheckedAt,
        'last_success_checked_at' => $lastSuccessfulCheckAt,
        'last_check_status' => trim((string) ($settings['vk_publication_last_check_status'] ?? '')),
        'last_check_message' => trim((string) ($settings['vk_publication_last_check_message'] ?? '')),
        'token_masked' => $groupToken !== '' ? maskVkPublicationToken($groupToken) : '',
        'admin_token_masked' => trim((string) ($settings['vk_publication_admin_access_token_encrypted'] ?? '')) !== ''
            ? 'stored_encrypted'
            : '',
        'admin_user_id' => (int) ($settings['vk_publication_admin_user_id'] ?? 0),
    ];
}

function getVkPublicationRuntimeSettings(bool $preferSessionToken = true, bool $attemptRefresh = true): array
{
    $settings = getVkPublicationSettings();
    $token = trim((string) ($settings['group_token'] ?? ''));

    return [
        'auth_mode' => (string) ($settings['auth_mode'] ?? 'community_token'),
        'group_token' => $token,
        'token_masked' => $token !== '' ? maskVkPublicationToken($token) : '',
        'token_id' => $token !== '' ? substr(hash('sha256', $token), 0, 10) : '',
        'token_type' => (string) ($settings['auth_mode'] ?? 'community_token') === 'admin_user_token' ? 'user' : 'group',
        'token_source' => $token !== '' ? 'stored_group' : 'none',
        'scope_required_for_publication' => ['wall', 'photos'],
        'vk_device_id' => '',
        'group_id' => (string) ($settings['group_id'] ?? ''),
        'api_version' => (string) ($settings['api_version'] ?? VK_API_VERSION),
        'from_group' => !empty($settings['from_group']),
        'post_template' => (string) ($settings['post_template'] ?? defaultVkPostTemplate()),
        'group_name' => (string) ($settings['group_name'] ?? ''),
        'admin_access_token' => '',
        'admin_refresh_token' => '',
        'admin_token_expires_at' => (int) (getSystemSettings()['vk_publication_admin_token_expires_at'] ?? 0),
        'admin_device_id' => (string) (getSystemSettings()['vk_publication_admin_device_id'] ?? ''),
        'admin_user_id' => (int) (getSystemSettings()['vk_publication_admin_user_id'] ?? 0),
        'confirmed_permissions' => trim((string) (getSystemSettings()['vk_publication_confirmed_permissions'] ?? '')),
        'last_error' => trim((string) ($settings['last_error'] ?? '')),
        'last_check_message' => trim((string) ($settings['last_check_message'] ?? '')),
    ];
}

function vkPublicationEncryptValue(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $key = hash('sha256', VK_CLIENT_SECRET . '|vk_publication', true);
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        return '';
    }
    return base64_encode($iv . $encrypted);
}

function vkPublicationDecryptValue(string $encrypted): string
{
    $encoded = trim($encrypted);
    if ($encoded === '') {
        return '';
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) <= 16) {
        return '';
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $key = hash('sha256', VK_CLIENT_SECRET . '|vk_publication', true);
    $decrypted = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return is_string($decrypted) ? $decrypted : '';
}

function resolvePersistentAdminVkAccessToken(): array
{
    $settings = getSystemSettings();
    $accessToken = vkPublicationDecryptValue((string) ($settings['vk_publication_admin_access_token_encrypted'] ?? ''));
    $refreshToken = vkPublicationDecryptValue((string) ($settings['vk_publication_admin_refresh_token_encrypted'] ?? ''));
    $expiresAt = (int) ($settings['vk_publication_admin_token_expires_at'] ?? 0);
    $deviceId = trim((string) ($settings['vk_publication_admin_device_id'] ?? ''));
    $adminUserId = (int) ($settings['vk_publication_admin_user_id'] ?? 0);

    if ($accessToken === '') {
        return ['ok' => false, 'error' => 'admin token is missing'];
    }

    $now = time();
    if ($refreshToken !== '' && $deviceId !== '' && $expiresAt > 0 && ($expiresAt - $now) <= 300) {
        $refreshResult = vkid_refresh_token($refreshToken, $deviceId);
        if (!empty($refreshResult['ok'])) {
            $payload = (array) ($refreshResult['payload'] ?? []);
            $freshAccessToken = trim((string) ($payload['access_token'] ?? ''));
            $freshRefreshToken = trim((string) ($payload['refresh_token'] ?? $refreshToken));
            $expiresIn = (int) ($payload['expires_in'] ?? 0);
            $freshExpiresAt = $expiresIn > 0 ? ($now + $expiresIn) : $expiresAt;

            if ($freshAccessToken !== '') {
                saveSystemSettings([
                    'vk_publication_admin_access_token_encrypted' => vkPublicationEncryptValue($freshAccessToken),
                    'vk_publication_admin_refresh_token_encrypted' => vkPublicationEncryptValue($freshRefreshToken),
                    'vk_publication_admin_token_expires_at' => $freshExpiresAt,
                    'vk_publication_admin_device_id' => $deviceId,
                    'vk_publication_admin_user_id' => $adminUserId,
                ]);
                return [
                    'ok' => true,
                    'access_token' => $freshAccessToken,
                    'refresh_token' => $freshRefreshToken,
                    'expires_at' => $freshExpiresAt,
                    'device_id' => $deviceId,
                    'admin_user_id' => $adminUserId,
                    'refreshed' => true,
                ];
            }
        }
    }

    return [
        'ok' => true,
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_at' => $expiresAt,
        'device_id' => $deviceId,
        'admin_user_id' => $adminUserId,
        'refreshed' => false,
    ];
}

function resolveVkPublicationAuthContext(): VkPublicationAuthContext
{
    $settings = getVkPublicationRuntimeSettings(false, false);
    $mode = in_array((string) ($settings['auth_mode'] ?? ''), ['community_token', 'admin_user_token'], true)
        ? (string) $settings['auth_mode']
        : 'community_token';

    if ($mode === 'admin_user_token') {
        $tokenState = resolvePersistentAdminVkAccessToken();
        if (empty($tokenState['ok'])) {
            throw new VkApiException('Не задан admin user token для публикации.');
        }
        return new VkPublicationAuthContext(
            'admin_user_token',
            (string) $tokenState['access_token'],
            (int) $settings['group_id'],
            (string) $settings['api_version'],
            !empty($settings['from_group']),
            (string) ($tokenState['refresh_token'] ?? ''),
            (string) ($tokenState['device_id'] ?? ''),
            (int) ($tokenState['expires_at'] ?? 0),
            (int) ($tokenState['admin_user_id'] ?? 0),
        );
    }

    return new VkPublicationAuthContext(
        'community_token',
        (string) ($settings['group_token'] ?? ''),
        (int) $settings['group_id'],
        (string) $settings['api_version'],
        !empty($settings['from_group']),
    );
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

function getVkPublicationRequiredScopes(bool $isGroupToken = false): array
{
    return ['wall', 'photos'];
}

function getVkPublicationTokenDiagnostics(array $settings): array
{
    if (trim((string) ($settings['group_token'] ?? '')) === '') {
        $status = 'not_connected';
        $message = 'Ключ доступа сообщества VK не задан.';
    } else {
        $status = 'ok';
        $message = 'Ключ доступа сообщества VK готов к работе.';
    }

    return [
        'status' => $status,
        'message' => $message,
        'expires_at' => '',
        'expires_in_seconds' => null,
        'expires_known' => false,
    ];
}

function vkPublicationCapabilityMatrixCommunityToken(): array
{
    return [
        'text_post_supported' => ['supported' => true, 'note' => 'supported via wall.post'],
        'upload_local_image_supported' => ['supported' => false, 'note' => 'local upload is blocked for community_token'],
        'attach_existing_media_supported' => ['supported' => true, 'note' => 'supported when media id is known'],
    ];
}

function vkPublicationCapabilityMatrixAdminUserToken(): array
{
    return [
        'text_post_supported' => ['supported' => true, 'note' => 'supported via wall.post'],
        'upload_local_image_supported' => ['supported' => true, 'note' => 'photos.getWallUploadServer -> saveWallPhoto -> wall.post'],
        'attach_existing_media_supported' => ['supported' => true, 'note' => 'supported via wall.post attachments'],
    ];
}

function extractVkGroupTokenPermissionNames(array $permissionsResponse): array
{
    $result = [];
    $entries = $permissionsResponse['permissions'] ?? null;
    if (is_array($entries)) {
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $name = trim((string) ($entry['name'] ?? ''));
                if ($name !== '') {
                    $result[] = $name;
                }
                continue;
            }

            $name = trim((string) $entry);
            if ($name !== '') {
                $result[] = $name;
            }
        }
    }

    if (empty($result) && isset($permissionsResponse[0]) && is_array($permissionsResponse)) {
        foreach ($permissionsResponse as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $name = trim($entry);
            if ($name !== '') {
                $result[] = $name;
            }
        }
    }

    return array_values(array_unique($result));
}

function isVkGroupAuthMethodUnavailableError(Throwable $e): bool
{
    $message = mb_strtolower(trim((string) $e->getMessage()));
    $technical = $e instanceof VkApiException ? mb_strtolower($e->getTechnicalMessage()) : '';
    return str_contains($message, 'method is unavailable with group auth')
        || str_contains($technical, 'method is unavailable with group auth')
        || ((int) $e->getCode() === 27 && str_contains($technical, 'photos.getwalluploadserver'));
}

function verifyVkPublicationReadiness(bool $attemptRefresh = true, bool $preferSessionToken = false, string $scenario = 'diagnostic', ?string $authMode = null): array
{
    $settings = getVkPublicationRuntimeSettings(false, false);
    $effectiveAuthMode = in_array((string) $authMode, ['community_token', 'admin_user_token'], true)
        ? (string) $authMode
        : (string) ($settings['auth_mode'] ?? 'community_token');
    $issues = [];
    $checks = [];
    $groupName = '';
    $steps = [];

    $runtime = [
        'auth_mode' => $effectiveAuthMode,
        'token_source' => (string) ($settings['token_source'] ?? 'none'),
        'token_masked' => (string) ($settings['token_masked'] ?? ''),
        'token_id' => (string) ($settings['token_id'] ?? ''),
        'token_type' => 'group',
    ];

    $steps['capabilities'] = ['ok' => true, 'matrix' => $effectiveAuthMode === 'admin_user_token'
        ? vkPublicationCapabilityMatrixAdminUserToken()
        : vkPublicationCapabilityMatrixCommunityToken()];

    if ($effectiveAuthMode === 'admin_user_token') {
        $adminTokenState = resolvePersistentAdminVkAccessToken();
        if (empty($adminTokenState['ok']) || trim((string) ($adminTokenState['access_token'] ?? '')) === '') {
            $issues[] = 'Admin user token не задан.';
            $steps['token_present'] = ['ok' => false, 'message' => 'admin user token отсутствует'];
        } else {
            $settings['group_token'] = (string) $adminTokenState['access_token'];
            $steps['token_present'] = ['ok' => true, 'message' => 'admin user token найден'];
        }
    } elseif (trim((string) ($settings['group_token'] ?? '')) === '') {
        $issues[] = 'Ключ доступа сообщества VK не задан.';
        $steps['token_present'] = ['ok' => false, 'message' => 'Ключ сообщества отсутствует'];
    } else {
        $steps['token_present'] = ['ok' => true, 'message' => 'Ключ сообщества найден'];
    }

    if ($settings['group_id'] === '' || (int) $settings['group_id'] <= 0) {
        $issues[] = 'Не указан ID сообщества VK.';
        $steps['group_id'] = ['ok' => false, 'message' => 'group_id не задан'];
    } else {
        $steps['group_id'] = ['ok' => true, 'message' => 'group_id задан', 'group_id' => (int) $settings['group_id']];
    }

    $diagnostics = getVkPublicationTokenDiagnostics($settings);
    $steps['token_expired'] = ['ok' => null, 'message' => 'Не применяется для ключа сообщества'];
    $steps['photos_saveWallPhoto'] = ['ok' => null, 'message' => 'Проверяется при фактической публикации изображения'];
    $steps['wall_post'] = ['ok' => null, 'message' => 'Проверяется при фактической публикации изображения'];

    if ($settings['group_token'] !== '' && (int) $settings['group_id'] > 0) {
        try {
            $ctx = resolveVkPublicationAuthContext();
            $client = new VkApiClient($ctx->accessToken, $ctx->mode, $ctx->groupId, $ctx->apiVersion);

            $groupResponse = $client->apiRequestForDiagnostics('groups.getById', [
                'group_id' => (int) $settings['group_id'],
            ]);
            if (!empty($groupResponse[0]['name'])) {
                $groupName = trim((string) $groupResponse[0]['name']);
            }
            $steps['groups_getById'] = ['ok' => true, 'message' => 'OK', 'group_name' => $groupName];
            $checks[] = 'groups.getById выполнен';

            $permissions = $client->apiRequestForDiagnostics('groups.getTokenPermissions', []);
            $permissionItems = extractVkGroupTokenPermissionNames($permissions);
            $requiredPermissions = getVkPublicationRequiredScopes(true);
            $missingPermissions = [];
            foreach ($requiredPermissions as $permission) {
                if (!in_array($permission, $permissionItems, true)) {
                    $missingPermissions[] = $permission;
                }
            }
            $steps['token_permissions'] = [
                'ok' => empty($missingPermissions),
                'list' => implode(', ', $permissionItems),
                'required' => $requiredPermissions,
                'missing' => $missingPermissions,
            ];
            if (!empty($missingPermissions)) {
                $issues[] = 'Недостаточно прав у ключа сообщества: отсутствуют ' . implode(', ', $missingPermissions) . '.';
            } else {
                $checks[] = 'groups.getTokenPermissions выполнен';
            }

            if ($effectiveAuthMode === 'community_token') {
                $steps['photos_getWallUploadServer'] = [
                    'ok' => false,
                    'skipped' => true,
                    'message' => 'Локальная загрузка файла недоступна для community_token.',
                ];
                if ($scenario === 'publish_local_image') {
                    $issues[] = 'Текущий режим авторизации не поддерживает загрузку локального рисунка в VK.';
                }
            } else {
                $uploadResponse = $client->apiRequestForDiagnostics('photos.getWallUploadServer', [
                    'group_id' => (int) $settings['group_id'],
                ]);
                if (empty($uploadResponse['upload_url'])) {
                    $issues[] = 'photos.getWallUploadServer не вернул upload_url.';
                    $steps['photos_getWallUploadServer'] = ['ok' => false, 'message' => 'upload_url пустой'];
                } else {
                    $checks[] = 'photos.getWallUploadServer выполнен';
                    $steps['photos_getWallUploadServer'] = ['ok' => true, 'message' => 'OK'];
                }
            }
        } catch (Throwable $e) {
            $normalized = normalizeVkPublicationError($e);
            $checks[] = 'VK API check failed: ' . $normalized['technical'];
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

    $status = empty($issues) ? 'connected' : ($settings['group_token'] !== '' ? 'attention' : 'disconnected');
    $confirmedPermissions = empty($issues)
        ? ((isset($steps['token_permissions']['list']) && trim((string) $steps['token_permissions']['list']) !== '')
            ? (string) $steps['token_permissions']['list']
            : 'не удалось определить')
        : '';

    saveSystemSettings([
        'vk_publication_status' => $status,
        'vk_publication_last_checked_at' => date('Y-m-d H:i:s'),
        'vk_publication_last_success_checked_at' => empty($issues)
            ? date('Y-m-d H:i:s')
            : trim((string) (getSystemSettings()['vk_publication_last_success_checked_at'] ?? '')),
        'vk_publication_last_check_status' => empty($issues) ? 'ok' : 'error',
        'vk_publication_last_check_message' => empty($issues)
            ? 'Ключ доступа сообщества VK прошёл проверку.'
            : implode('; ', $issues),
        'vk_publication_confirmed_permissions' => $confirmedPermissions,
        'vk_publication_last_error' => empty($issues) ? '' : implode('; ', $issues),
        'vk_publication_technical_diagnostics' => empty($issues) ? implode('; ', $checks) : implode('; ', $checks),
        'vk_publication_group_name' => $groupName,
        'vk_publication_token_type' => 'group',
    ]);

    vkPublicationLog('group_token_check', [
        'ok' => empty($issues),
        'issues' => $issues,
        'checks' => $checks,
        'group_id' => $settings['group_id'],
        'token_masked' => maskVkPublicationToken($settings['group_token']),
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

    $readiness = verifyVkPublicationReadiness(false, false, 'publish_local_image');
    if (empty($readiness['ok'])) {
        $failureStage = 'validation';
        $error = (string) ($readiness['issues'][0] ?? 'VK не готов к публикации');
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $error, implode('; ', $readiness['issues'] ?? []));
        return ['success' => false, 'error' => $error];
    }

    $settings = $readiness['settings'];
    try {
        $ctx = resolveVkPublicationAuthContext();
    } catch (Throwable $e) {
        $normalized = normalizeVkPublicationError($e);
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $normalized['message'], $normalized['technical']);
        return ['success' => false, 'error' => $normalized['message']];
    }
    try {
        $client = new VkApiClient($ctx->accessToken, $ctx->mode, $ctx->groupId, $ctx->apiVersion);
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
        'auth_mode' => $ctx->mode,
        'message' => (string) ($item['post_text'] ?? ''),
        'image_path' => (string) ($item['work_image_path'] ?? ''),
        'strategy' => 'photo_upload',
    ];

    $postMessage = trim((string) ($item['post_text'] ?? ''));
    if ($postMessage === '') {
        $failureStage = 'validation';
        $error = 'Не заполнен шаблон подписи для публикации в VK.';
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $error, $error);
        return ['success' => false, 'error' => $error];
    }

    try {
        $failureStage = 'wall_post';
        $published = $client->publishPhotoPost(
            (string) $imageFsPath,
            $postMessage,
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
                $ctx->mode,
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
                $ctx->mode,
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
        $message = 'Ключ доступа сообщества VK недействителен.';
    } elseif (str_contains($lower, 'method is unavailable with group auth')) {
        $message = 'Ключ сообщества валиден, но текущий VK API-сценарий загрузки изображения на стену недоступен для group auth.';
    } elseif (str_contains($lower, 'access denied') || str_contains($lower, 'not enough rights') || str_contains($lower, 'permission')) {
        $message = 'Недостаточно прав для публикации на стене сообщества.';
    } elseif (str_contains($lower, 'upload') || str_contains($lower, 'photo')) {
        $message = 'Не удалось загрузить изображение из заявки в VK.';
    } else {
        $message = 'Публикация в VK завершилась ошибкой.';
    }

    return [
        'message' => $message,
        'technical' => $technical,
    ];
}

function publishVkTask(int $taskId, array $options = []): array
{
    global $pdo;
    $readiness = verifyVkPublicationReadiness(false, false, 'publish_local_image');
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
    if (!$ctx->canUploadLocalImage()) {
        $failureStage = 'capability_check';
        $error = 'Текущий режим авторизации не поддерживает загрузку локального рисунка в VK.';
        markVkTaskItemFailed(
            (int) $item['id'],
            (int) $item['task_id'],
            $error,
            'community_token cannot use local wall photo upload chain'
        );
        return ['success' => false, 'error' => 'Неподдерживаемый режим авторизации'];
    }
