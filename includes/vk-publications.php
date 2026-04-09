<?php

require_once __DIR__ . '/vk-api.php';

function ensureVkPublicationSchema(): void
{
    global $pdo;

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

function cleanupLegacyVkPublicationOauthData(): void
{
    saveSystemSettings([
        'vk_publication_oauth_state' => '',
        'vk_publication_oauth_last_error' => '',
        'vk_publication_oauth_last_error_technical' => '',
        'vk_publication_oauth_user_id' => '',
        'vk_publication_oauth_user_name' => '',
        'vk_publication_oauth_user_profile_url' => '',
        'vk_publication_oauth_connected_at' => '',
        'vk_publication_access_token' => '',
        'vk_publication_user_token' => '',
        'vk_publication_refresh_token' => '',
    ]);
}

function getVkPublicationSettings(): array
{
    $settings = getSystemSettings();
    $lastCheckedAt = trim((string) ($settings['vk_publication_last_checked_at'] ?? ''));
    $lastSuccessfulCheckAt = trim((string) ($settings['vk_publication_last_success_checked_at'] ?? ''));
    $scopeRaw = trim((string) ($settings['vk_publication_token_scope'] ?? ($settings['vk_publication_scope'] ?? '')));
    $scopeItems = $scopeRaw !== '' ? array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $scopeRaw) ?: []))) : [];

    return [
        'publication_token' => trim((string) ($settings['vk_publication_manual_token'] ?? ($settings['vk_publication_user_token'] ?? ($settings['vk_publication_access_token'] ?? '')))),
        'group_id' => trim((string) ($settings['vk_publication_group_id'] ?? '')),
        'api_version' => trim((string) ($settings['vk_publication_api_version'] ?? VK_API_VERSION)),
        'from_group' => (int) ($settings['vk_publication_from_group'] ?? 1) === 1,
        'post_template' => trim((string) ($settings['vk_publication_post_template'] ?? defaultVkPostTemplate())),
        'token_expires_at' => trim((string) ($settings['vk_publication_token_expires_at'] ?? '')),
        'token_scope' => $scopeRaw,
        'token_scope_items' => $scopeItems,
        'vk_user_id' => trim((string) ($settings['vk_publication_vk_user_id'] ?? ($settings['vk_publication_oauth_user_id'] ?? ''))),
        'vk_user_name' => trim((string) ($settings['vk_publication_vk_user_name'] ?? ($settings['vk_publication_oauth_user_name'] ?? ''))),
        'token_type' => trim((string) ($settings['vk_publication_token_type'] ?? 'user')),
        'confirmed_permissions' => trim((string) ($settings['vk_publication_confirmed_permissions'] ?? '')),
        'status' => trim((string) ($settings['vk_publication_status'] ?? ($settings['vk_publication_oauth_state'] ?? 'disconnected'))),
        'last_error' => trim((string) ($settings['vk_publication_last_error'] ?? ($settings['vk_publication_oauth_last_error'] ?? ''))),
        'technical_diagnostics' => trim((string) ($settings['vk_publication_technical_diagnostics'] ?? ($settings['vk_publication_oauth_last_error_technical'] ?? ''))),
        'last_checked_at' => $lastCheckedAt,
        'last_success_checked_at' => $lastSuccessfulCheckAt,
        'last_check_status' => trim((string) ($settings['vk_publication_last_check_status'] ?? '')),
        'last_check_message' => trim((string) ($settings['vk_publication_last_check_message'] ?? '')),
        'token_masked' => maskVkPublicationToken((string) ($settings['vk_publication_manual_token'] ?? ($settings['vk_publication_user_token'] ?? ($settings['vk_publication_access_token'] ?? '')))),
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

function getVkPublicationRequiredScopes(): array
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
    $message = 'Публикационный токен VK готов к работе.';

    if (trim((string) ($settings['publication_token'] ?? '')) === '') {
        $status = 'not_connected';
        $message = 'Публикационный токен VK не задан.';
    } elseif ($expiresTs && $expiresIn !== null && $expiresIn <= 0) {
        $status = 'expired';
        $message = 'Публикационный токен VK истёк.';
    }

    return [
        'status' => $status,
        'message' => $message,
        'expires_at' => $expiresAt,
        'expires_in_seconds' => $expiresIn,
    ];
}

function verifyVkPublicationReadiness(bool $attemptRefresh = true): array
{
    $settings = getVkPublicationSettings();
    $issues = [];
    $checks = [];
    $groupName = '';

    if ($settings['publication_token'] === '') {
        $issues[] = 'Не задан publication token VK.';
        $checks[] = 'Токен отсутствует';
    } else {
        $checks[] = 'Токен присутствует';
    }

    if ($settings['group_id'] === '' || (int) $settings['group_id'] <= 0) {
        $issues[] = 'Не указан ID сообщества VK.';
        $checks[] = 'ID сообщества не задан';
    } else {
        $checks[] = 'ID сообщества задан';
    }

    $diagnostics = getVkPublicationTokenDiagnostics($settings);
    if ($diagnostics['status'] === 'expired') {
        $issues[] = 'Публикационный токен VK истёк, обновите token вручную в настройках.';
        $checks[] = 'Токен истёк';
    }

    if ($settings['publication_token'] !== '' && (int) $settings['group_id'] > 0) {
        try {
            $client = new VkApiClient($settings['publication_token'], (int) $settings['group_id'], (string) $settings['api_version']);

            $userResponse = $client->apiRequestForDiagnostics('users.get', []);
            $vkUserId = (string) ((int) ($userResponse[0]['id'] ?? 0));
            if ($vkUserId === '0') {
                $issues[] = 'users.get не вернул VK user ID владельца токена.';
            } else {
                $settings['vk_user_id'] = $vkUserId;
                $settings['vk_user_name'] = trim((string) (($userResponse[0]['first_name'] ?? '') . ' ' . ($userResponse[0]['last_name'] ?? '')));
                $checks[] = 'users.get выполнен';
            }

            $groupResponse = $client->apiRequestForDiagnostics('groups.getById', [
                'group_id' => (int) $settings['group_id'],
            ]);
            if (!empty($groupResponse[0]['name'])) {
                $groupName = trim((string) $groupResponse[0]['name']);
            }
            $checks[] = 'groups.getById выполнен';

            $uploadResponse = $client->apiRequestForDiagnostics('photos.getWallUploadServer', [
                'group_id' => (int) $settings['group_id'],
            ]);
            if (empty($uploadResponse['upload_url'])) {
                $issues[] = 'photos.getWallUploadServer не вернул upload_url.';
            } else {
                $checks[] = 'photos.getWallUploadServer выполнен';
            }
        } catch (Throwable $e) {
            $normalized = normalizeVkPublicationError($e);
            $issues[] = $normalized['message'];
            $checks[] = 'VK API check failed: ' . $normalized['technical'];
        }
    }

    $status = empty($issues) ? 'connected' : ($settings['publication_token'] !== '' ? 'attention' : 'disconnected');
    $confirmedPermissions = empty($issues) ? implode(', ', getVkPublicationRequiredScopes()) : '';

    saveSystemSettings([
        'vk_publication_status' => $status,
        'vk_publication_last_checked_at' => date('Y-m-d H:i:s'),
        'vk_publication_last_success_checked_at' => empty($issues)
            ? date('Y-m-d H:i:s')
            : trim((string) (getSystemSettings()['vk_publication_last_success_checked_at'] ?? '')),
        'vk_publication_last_check_status' => empty($issues) ? 'ok' : 'error',
        'vk_publication_last_check_message' => empty($issues)
            ? 'Публикационный токен VK прошёл проверку.'
            : implode('; ', $issues),
        'vk_publication_confirmed_permissions' => $confirmedPermissions,
        'vk_publication_last_error' => empty($issues) ? '' : implode('; ', $issues),
        'vk_publication_technical_diagnostics' => empty($issues) ? implode('; ', $checks) : implode('; ', $checks),
        'vk_publication_vk_user_id' => (string) ($settings['vk_user_id'] ?? ''),
        'vk_publication_vk_user_name' => (string) ($settings['vk_user_name'] ?? ''),
        'vk_publication_group_name' => $groupName,
        'vk_publication_token_type' => 'user',
    ]);

    vkPublicationLog('publication_token_check', [
        'ok' => empty($issues),
        'issues' => $issues,
        'checks' => $checks,
        'group_id' => $settings['group_id'],
        'token_masked' => maskVkPublicationToken($settings['publication_token']),
    ]);

    $settings = getVkPublicationSettings();
    $settings['group_name'] = $groupName !== '' ? $groupName : trim((string) (getSystemSettings()['vk_publication_group_name'] ?? ''));

    return [
        'ok' => empty($issues),
        'issues' => $issues,
        'checks' => $checks,
        'settings' => $settings,
        'diagnostics' => $diagnostics,
    ];
}

function defaultVkPostTemplate(): string
{
    return "🎨 {participant_full_name}\n"
        . "🏫 {organization_name}\n"
        . "📍 {region_name}\n"
        . "🖼 {work_title}\n"
        . "🏆 {contest_title}\n"
        . "Номинация: {nomination}\n"
        . "Возрастная категория: {age_category}";
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
        'work_status' => trim((string) ($input['work_status'] ?? '')),
        'region' => trim((string) ($input['region'] ?? '')),
        'organization_name' => trim((string) ($input['organization_name'] ?? '')),
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

    if (($filters['work_status'] ?? '') !== '') {
        $where[] = 'w.status = ?';
        $params[] = (string) $filters['work_status'];
    }

    if (($filters['region'] ?? '') !== '') {
        $where[] = 'p.region LIKE ?';
        $params[] = '%' . $filters['region'] . '%';
    }

    if (($filters['organization_name'] ?? '') !== '') {
        $where[] = 'p.organization_name LIKE ?';
        $params[] = '%' . $filters['organization_name'] . '%';
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
        $row['work_image_fs_path'] = getParticipantDrawingFsPath((string) ($row['applicant_email'] ?? ''), $row['work_image_file']);
    }

    return $rows;
}

function buildVkPostText(array $workRow, string $template): string
{
    $participantFullName = trim((string) ($workRow['participant_fio'] ?? ''));
    $parts = preg_split('/\s+/u', $participantFullName) ?: [];

    $vars = [
        '{participant_name}' => trim((string) ($parts[1] ?? $parts[0] ?? '')),
        '{participant_full_name}' => $participantFullName,
        '{organization_name}' => trim((string) ($workRow['organization_name'] ?? '')),
        '{region_name}' => trim((string) ($workRow['region'] ?? '')),
        '{work_title}' => trim((string) ($workRow['work_title'] ?? '')),
        '{contest_title}' => trim((string) ($workRow['contest_title'] ?? '')),
        '{nomination}' => '',
        '{age_category}' => resolveAgeCategory((int) ($workRow['participant_age'] ?? 0)),
    ];

    $text = strtr($template, $vars);
    $lines = preg_split('/\R/u', $text) ?: [];
    $cleanLines = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^([\p{L}\p{N}\s\-]+:)?\s*[{}]*$/u', $line)) {
            continue;
        }
        $cleanLines[] = $line;
    }

    return trim(implode("\n", $cleanLines));
}

function resolveAgeCategory(int $age): string
{
    if ($age <= 0) {
        return '';
    }
    if ($age <= 6) {
        return 'до 6 лет';
    }
    if ($age <= 10) {
        return '7-10 лет';
    }
    if ($age <= 14) {
        return '11-14 лет';
    }
    return '15+ лет';
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
        } elseif (!empty($filters['required_data_only']) && trim((string) ($row['participant_fio'] ?? '')) === '') {
            $itemStatus = 'skipped';
            $skipReason = 'Не заполнено ФИО участника';
        }

        $postText = buildVkPostText($row, $template);
        if ($postText === '') {
            $itemStatus = 'skipped';
            $skipReason = 'Не удалось сформировать текст поста';
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

function createVkTaskFromPreview(string $title, int $createdBy, array $preview, string $publicationMode = 'manual'): int
{
    global $pdo;
    ensureVkPublicationSchema();

    $filters = $preview['filters'] ?? [];
    $items = $preview['items'] ?? [];
    $contestId = (int) ($filters['contest_id'] ?? 0);
    $status = $publicationMode === 'immediate' ? 'ready' : 'draft';
    $settings = getVkPublicationSettings();

    $stmt = $pdo->prepare("INSERT INTO vk_publication_tasks
        (title, contest_id, created_by, task_status, publication_mode, filters_json, summary_json, total_items, ready_items, skipped_items, vk_group_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
    $stmt->execute([
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
    ]);
    $taskId = (int) $pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO vk_publication_task_items
        (task_id, work_id, application_id, participant_id, contest_id, work_image_path, post_text, item_status, skip_reason, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

    foreach ($items as $item) {
        $ins->execute([
            $taskId,
            (int) ($item['work_id'] ?? 0),
            (int) ($item['application_id'] ?? 0),
            (int) ($item['participant_id'] ?? 0),
            (int) ($item['contest_id'] ?? 0),
            (string) ($item['work_image_path'] ?? ''),
            (string) ($item['post_text'] ?? ''),
            (string) ($item['item_status'] ?? 'pending'),
            $item['skip_reason'] ?? null,
        ]);
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

function publishVkTaskItem(int $itemId): array
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

    vkPublicationLog('publish_item_attempt', [
        'item_id' => (int) $item['id'],
        'task_id' => (int) $item['task_id'],
        'work_id' => (int) $item['work_id'],
    ]);

    $readiness = verifyVkPublicationReadiness(true);
    if (empty($readiness['ok'])) {
        $error = (string) ($readiness['issues'][0] ?? 'VK не готов к публикации');
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $error, implode('; ', $readiness['issues'] ?? []));
        return ['success' => false, 'error' => $error];
    }

    $settings = $readiness['settings'];
    try {
        $client = new VkApiClient($settings['publication_token'], (int) $settings['group_id'], (string) $settings['api_version']);
    } catch (Throwable $e) {
        $normalized = normalizeVkPublicationError($e);
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $normalized['message'], $normalized['technical']);
        return ['success' => false, 'error' => $normalized['message']];
    }

    $imageFsPath = getParticipantDrawingFsPath((string) ($item['applicant_email'] ?? ''), (string) ($item['work_image_path'] ?? ''));
    if (!$imageFsPath || !is_file($imageFsPath)) {
        $error = 'Изображение для публикации не найдено';
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $error, $error);
        return ['success' => false, 'error' => $error];
    }

    try {
        $published = $client->publishPhotoPost($imageFsPath, (string) ($item['post_text'] ?? ''), (bool) $settings['from_group']);

        $pdo->prepare("UPDATE vk_publication_task_items
            SET item_status = 'published', vk_post_id = ?, vk_post_url = ?, error_message = NULL, technical_error = NULL, published_at = NOW(), updated_at = NOW()
            WHERE id = ?")
            ->execute([(string) $published['post_id'], (string) $published['post_url'], (int) $item['id']]);

        $pdo->prepare("UPDATE works
            SET vk_published_at = NOW(), vk_post_id = ?, vk_post_url = ?, vk_publish_error = NULL, updated_at = NOW()
            WHERE id = ?")
            ->execute([(string) $published['post_id'], (string) $published['post_url'], (int) $item['work_id']]);

        refreshVkTaskCounters((int) $item['task_id']);
        vkPublicationLog('publish_item_success', [
            'item_id' => (int) $item['id'],
            'task_id' => (int) $item['task_id'],
            'work_id' => (int) $item['work_id'],
            'post_id' => (string) $published['post_id'],
        ]);

        return [
            'success' => true,
            'post_id' => (string) $published['post_id'],
            'post_url' => (string) $published['post_url'],
        ];
    } catch (Throwable $e) {
        $normalized = normalizeVkPublicationError($e);
        vkPublicationLog('publish_item_failed', [
            'item_id' => (int) $item['id'],
            'task_id' => (int) $item['task_id'],
            'work_id' => (int) $item['work_id'],
            'error' => $normalized['message'],
            'technical' => $normalized['technical'],
        ]);
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $normalized['message'], $normalized['technical']);
        return ['success' => false, 'error' => $normalized['message']];
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

    return [
        'message' => $message,
        'technical' => $technical,
    ];
}

function publishVkTask(int $taskId): array
{
    global $pdo;

    $readiness = verifyVkPublicationReadiness(true);
    if (empty($readiness['ok'])) {
        return [
            'total' => 0,
            'published' => 0,
            'failed' => 0,
            'error' => implode('; ', $readiness['issues'] ?? ['VK не готов к публикации']),
        ];
    }

    $pdo->prepare("UPDATE vk_publication_tasks SET task_status = 'publishing', updated_at = NOW() WHERE id = ?")
        ->execute([$taskId]);

    $stmt = $pdo->prepare("SELECT id FROM vk_publication_task_items
        WHERE task_id = ? AND item_status IN ('ready','failed','pending')
        ORDER BY id ASC");
    $stmt->execute([$taskId]);
    $itemIds = array_map('intval', array_column($stmt->fetchAll() ?: [], 'id'));

    $result = ['total' => count($itemIds), 'published' => 0, 'failed' => 0];

    foreach ($itemIds as $itemId) {
        $itemResult = publishVkTaskItem($itemId);
        if (!empty($itemResult['success'])) {
            $result['published']++;
        } else {
            $result['failed']++;
        }
    }

    refreshVkTaskCounters($taskId);

    return $result;
}

function retryFailedVkTaskItems(int $taskId): array
{
    global $pdo;
    $pdo->prepare("UPDATE vk_publication_task_items SET item_status = 'ready', updated_at = NOW() WHERE task_id = ? AND item_status = 'failed'")
        ->execute([$taskId]);

    return publishVkTask($taskId);
}
