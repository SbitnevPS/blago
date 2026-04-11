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
        if (!in_array('vk_donut_enabled', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN vk_donut_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_post_url");
        }
        if (!in_array('vk_donut_paid_duration', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN vk_donut_paid_duration INT NULL AFTER vk_donut_enabled");
        }
        if (!in_array('vk_donut_can_publish_free_copy', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN vk_donut_can_publish_free_copy TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_donut_paid_duration");
        }
        if (!in_array('vk_donut_settings_snapshot', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN vk_donut_settings_snapshot LONGTEXT NULL AFTER vk_donut_can_publish_free_copy");
        }
        if (!in_array('donation_enabled', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN donation_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_donut_settings_snapshot");
        }
        if (!in_array('donation_goal_id', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN donation_goal_id INT NULL AFTER donation_enabled");
        }
        if (!in_array('vk_donate_id', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN vk_donate_id VARCHAR(64) NULL AFTER donation_goal_id");
        }
        if (!in_array('publication_type', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN publication_type VARCHAR(32) NOT NULL DEFAULT 'standard' AFTER vk_donate_id");
        }
        if (!in_array('resolved_mode', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN resolved_mode VARCHAR(32) NULL AFTER publication_type");
        }
        if (!in_array('capability_status', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN capability_status VARCHAR(32) NULL AFTER resolved_mode");
        }
        if (!in_array('failure_stage', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN failure_stage VARCHAR(64) NULL AFTER capability_status");
        }
        if (!in_array('request_payload_json', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN request_payload_json LONGTEXT NULL AFTER failure_stage");
        }
        if (!in_array('response_payload_json', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN response_payload_json LONGTEXT NULL AFTER request_payload_json");
        }
        if (!in_array('verification_status', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN verification_status VARCHAR(64) NULL AFTER response_payload_json");
        }
        if (!in_array('verification_message', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN verification_message TEXT NULL AFTER verification_status");
        }
        if (!in_array('detected_mode', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN detected_mode VARCHAR(64) NULL AFTER verification_message");
        }
        if (!in_array('detected_features_json', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN detected_features_json LONGTEXT NULL AFTER detected_mode");
        }
        if (!in_array('vk_post_readback_json', $itemColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD COLUMN vk_post_readback_json LONGTEXT NULL AFTER detected_features_json");
        }
        try {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD INDEX idx_donation_goal_id (donation_goal_id)");
        } catch (Throwable $e) {
        }
        try {
            $pdo->exec("ALTER TABLE vk_publication_task_items ADD INDEX idx_vk_donate_id (vk_donate_id)");
        } catch (Throwable $e) {
        }
    } catch (Throwable $e) {
    }

    try {
        $taskColumns = $pdo->query("SHOW COLUMNS FROM vk_publication_tasks")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('vk_donut_enabled', $taskColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD COLUMN vk_donut_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_group_id");
        }
        if (!in_array('vk_donut_paid_duration', $taskColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD COLUMN vk_donut_paid_duration INT NULL AFTER vk_donut_enabled");
        }
        if (!in_array('vk_donut_can_publish_free_copy', $taskColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD COLUMN vk_donut_can_publish_free_copy TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_donut_paid_duration");
        }
        if (!in_array('vk_donut_settings_snapshot', $taskColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD COLUMN vk_donut_settings_snapshot LONGTEXT NULL AFTER vk_donut_can_publish_free_copy");
        }
        if (!in_array('vk_post_url', $taskColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD COLUMN vk_post_url VARCHAR(255) NULL AFTER vk_donut_settings_snapshot");
        }
        if (!in_array('donation_enabled', $taskColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD COLUMN donation_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_post_url");
        }
        if (!in_array('donation_goal_id', $taskColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD COLUMN donation_goal_id INT NULL AFTER donation_enabled");
        }
        if (!in_array('vk_donate_id', $taskColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD COLUMN vk_donate_id VARCHAR(64) NULL AFTER donation_goal_id");
        }
        if (!in_array('publication_type', $taskColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD COLUMN publication_type VARCHAR(32) NOT NULL DEFAULT 'standard' AFTER vk_donate_id");
        }
        if (!in_array('resolved_mode', $taskColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD COLUMN resolved_mode VARCHAR(32) NULL AFTER publication_type");
        }
        if (!in_array('capability_status', $taskColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD COLUMN capability_status VARCHAR(32) NULL AFTER resolved_mode");
        }
        if (!in_array('failure_stage', $taskColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD COLUMN failure_stage VARCHAR(64) NULL AFTER capability_status");
        }
        if (!in_array('request_payload_json', $taskColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD COLUMN request_payload_json LONGTEXT NULL AFTER failure_stage");
        }
        if (!in_array('response_payload_json', $taskColumns, true)) {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD COLUMN response_payload_json LONGTEXT NULL AFTER request_payload_json");
        }
        try {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD INDEX idx_donation_goal_id (donation_goal_id)");
        } catch (Throwable $e) {
        }
        try {
            $pdo->exec("ALTER TABLE vk_publication_tasks ADD INDEX idx_vk_donate_id (vk_donate_id)");
        } catch (Throwable $e) {
        }
    } catch (Throwable $e) {
    }
}

function ensureVkDonatesSchema(): void
{
    global $pdo;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS vk_donates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            vk_donate_id VARCHAR(64) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }
}

function getActiveVkDonates(): array
{
    global $pdo;
    ensureVkDonatesSchema();

    $stmt = $pdo->query("SELECT id, title, description, vk_donate_id, is_active FROM vk_donates WHERE is_active = 1 ORDER BY title ASC, id ASC");
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function getVkDonateById(int $id): ?array
{
    global $pdo;
    ensureVkDonatesSchema();
    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id, title, description, vk_donate_id, is_active FROM vk_donates WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getVkDonateByVkId(string $vkDonateId): ?array
{
    global $pdo;
    ensureVkDonatesSchema();

    $normalizedVkDonateId = trim($vkDonateId);
    if ($normalizedVkDonateId === '') {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id, title, description, vk_donate_id, is_active FROM vk_donates WHERE vk_donate_id = ? LIMIT 1");
    $stmt->execute([$normalizedVkDonateId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function upsertVkDonate(array $vkGoal): void
{
    global $pdo;
    ensureVkDonatesSchema();

    $vkDonateId = trim((string) ($vkGoal['vk_donate_id'] ?? ''));
    if ($vkDonateId === '') {
        return;
    }

    $title = trim((string) ($vkGoal['title'] ?? ''));
    $description = trim((string) ($vkGoal['description'] ?? ''));
    $isActive = !empty($vkGoal['is_active']) ? 1 : 0;

    $existingStmt = $pdo->prepare("SELECT id FROM vk_donates WHERE vk_donate_id = ? LIMIT 1");
    $existingStmt->execute([$vkDonateId]);
    $existingId = (int) ($existingStmt->fetchColumn() ?: 0);

    if ($existingId > 0) {
        $pdo->prepare("UPDATE vk_donates SET title = ?, description = ?, is_active = ? WHERE id = ?")
            ->execute([
                $title !== '' ? $title : ('Цель #' . $vkDonateId),
                $description !== '' ? $description : null,
                $isActive,
                $existingId,
            ]);
        return;
    }

    $pdo->prepare("INSERT INTO vk_donates (title, description, vk_donate_id, is_active) VALUES (?, ?, ?, ?)")
        ->execute([
            $title !== '' ? $title : ('Цель #' . $vkDonateId),
            $description !== '' ? $description : null,
            $vkDonateId,
            $isActive,
        ]);
}

function syncVkDonatesFromVk(): array
{
    global $pdo;
    ensureVkDonatesSchema();

    $readiness = verifyVkPublicationReadiness(true);
    if (empty($readiness['ok'])) {
        throw new RuntimeException((string) ($readiness['issues'][0] ?? 'VK не готов к синхронизации донатов.'));
    }

    $settings = $readiness['settings'];
    $client = new VkApiClient((string) $settings['publication_token'], (int) $settings['group_id'], (string) $settings['api_version']);
    $goals = $client->getDonutGoals();

    $seenVkIds = [];
    $inserted = 0;
    $updated = 0;

    foreach ($goals as $goal) {
        if (!is_array($goal)) {
            continue;
        }
        $vkDonateId = trim((string) ($goal['id'] ?? $goal['goal_id'] ?? $goal['vk_donate_id'] ?? ''));
        if ($vkDonateId === '') {
            continue;
        }

        $seenVkIds[] = $vkDonateId;

        $existingStmt = $pdo->prepare("SELECT id FROM vk_donates WHERE vk_donate_id = ? LIMIT 1");
        $existingStmt->execute([$vkDonateId]);
        $existingId = (int) ($existingStmt->fetchColumn() ?: 0);

        upsertVkDonate([
            'vk_donate_id' => $vkDonateId,
            'title' => (string) ($goal['title'] ?? $goal['name'] ?? ''),
            'description' => (string) ($goal['description'] ?? ''),
            'is_active' => !array_key_exists('is_active', $goal) ? 1 : ((int) $goal['is_active'] === 1),
        ]);

        if ($existingId > 0) {
            $updated++;
        } else {
            $inserted++;
        }
    }

    if (empty($seenVkIds)) {
        $pdo->exec("UPDATE vk_donates SET is_active = 0");
    } else {
        $placeholders = implode(',', array_fill(0, count($seenVkIds), '?'));
        $stmt = $pdo->prepare("UPDATE vk_donates SET is_active = 0 WHERE vk_donate_id NOT IN ($placeholders)");
        $stmt->execute($seenVkIds);
    }

    $activeCount = (int) ($pdo->query("SELECT COUNT(*) FROM vk_donates WHERE is_active = 1")->fetchColumn() ?: 0);

    return [
        'inserted' => $inserted,
        'updated' => $updated,
        'active_count' => $activeCount,
        'fetched' => count($seenVkIds),
    ];
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
        . "🖼 {drawing_title}\n"
        . "🏆 {contest_title}\n"
        . "Номинация: {nomination}\n"
        . "Возраст: {participant_age}\n"
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
        '{drawing_title}' => trim((string) ($workRow['work_title'] ?? '')),
        '{contest_title}' => trim((string) ($workRow['contest_title'] ?? '')),
        '{nomination}' => '',
        '{participant_age}' => (int) ($workRow['participant_age'] ?? 0) > 0 ? (string) ((int) $workRow['participant_age']) : '',
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

function createVkTaskFromPreview(string $title, int $createdBy, array $preview, string $publicationMode = 'manual', array $publicationMeta = []): int
{
    global $pdo;
    ensureVkPublicationSchema();

    $filters = $preview['filters'] ?? [];
    $items = $preview['items'] ?? [];
    $contestId = (int) ($filters['contest_id'] ?? 0);
    $status = $publicationMode === 'immediate' ? 'ready' : 'draft';
    $settings = getVkPublicationSettings();
    $vkDonutEnabled = !empty($publicationMeta['vk_donut_enabled']) ? 1 : 0;
    $vkDonutPaidDuration = isset($publicationMeta['vk_donut_paid_duration']) ? (int) $publicationMeta['vk_donut_paid_duration'] : null;
    $vkDonutCanPublishFreeCopy = !empty($publicationMeta['vk_donut_can_publish_free_copy']) ? 1 : 0;
    $vkDonutSnapshot = !empty($publicationMeta['vk_donut_settings_snapshot']) ? json_encode($publicationMeta['vk_donut_settings_snapshot'], JSON_UNESCAPED_UNICODE) : null;
    $donationEnabled = !empty($publicationMeta['donation_enabled']) ? 1 : 0;
    $donationGoalId = isset($publicationMeta['donation_goal_id']) ? (int) $publicationMeta['donation_goal_id'] : null;
    $vkDonateId = trim((string) ($publicationMeta['vk_donate_id'] ?? ''));
    $publicationType = trim((string) ($publicationMeta['publication_type'] ?? ''));
    if (!in_array($publicationType, ['standard', 'vk_donut', 'donation_goal'], true)) {
        $publicationType = $vkDonutEnabled ? 'vk_donut' : ($donationEnabled ? 'donation_goal' : 'standard');
    }

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

    if (in_array('vk_donut_enabled', $taskColumns, true)) {
        $taskInsertColumns[] = 'vk_donut_enabled';
        $taskInsertValues[] = $vkDonutEnabled;
    }
    if (in_array('vk_donut_paid_duration', $taskColumns, true)) {
        $taskInsertColumns[] = 'vk_donut_paid_duration';
        $taskInsertValues[] = $vkDonutEnabled ? $vkDonutPaidDuration : null;
    }
    if (in_array('vk_donut_can_publish_free_copy', $taskColumns, true)) {
        $taskInsertColumns[] = 'vk_donut_can_publish_free_copy';
        $taskInsertValues[] = $vkDonutEnabled ? $vkDonutCanPublishFreeCopy : 0;
    }
    if (in_array('vk_donut_settings_snapshot', $taskColumns, true)) {
        $taskInsertColumns[] = 'vk_donut_settings_snapshot';
        $taskInsertValues[] = $vkDonutEnabled ? $vkDonutSnapshot : null;
    }
    if (in_array('donation_enabled', $taskColumns, true)) {
        $taskInsertColumns[] = 'donation_enabled';
        $taskInsertValues[] = $donationEnabled;
    }
    if (in_array('donation_goal_id', $taskColumns, true)) {
        $taskInsertColumns[] = 'donation_goal_id';
        $taskInsertValues[] = $donationEnabled ? ($donationGoalId > 0 ? $donationGoalId : null) : null;
    }
    if (in_array('vk_donate_id', $taskColumns, true)) {
        $taskInsertColumns[] = 'vk_donate_id';
        $taskInsertValues[] = $donationEnabled && $vkDonateId !== '' ? $vkDonateId : null;
    }
    if (in_array('publication_type', $taskColumns, true)) {
        $taskInsertColumns[] = 'publication_type';
        $taskInsertValues[] = $publicationType;
    }
    if (in_array('resolved_mode', $taskColumns, true)) {
        $taskInsertColumns[] = 'resolved_mode';
        $taskInsertValues[] = $publicationType;
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
    $supportsItemDonutEnabled = in_array('vk_donut_enabled', $itemColumns, true);
    $supportsItemDonutDuration = in_array('vk_donut_paid_duration', $itemColumns, true);
    $supportsItemDonutFreeCopy = in_array('vk_donut_can_publish_free_copy', $itemColumns, true);
    $supportsItemDonutSnapshot = in_array('vk_donut_settings_snapshot', $itemColumns, true);
    $supportsItemDonationEnabled = in_array('donation_enabled', $itemColumns, true);
    $supportsItemDonationGoal = in_array('donation_goal_id', $itemColumns, true);
    $supportsItemVkDonateId = in_array('vk_donate_id', $itemColumns, true);
    $supportsItemPublicationType = in_array('publication_type', $itemColumns, true);
    $supportsItemResolvedMode = in_array('resolved_mode', $itemColumns, true);
    $supportsItemCapabilityStatus = in_array('capability_status', $itemColumns, true);

    if ($supportsItemDonutEnabled) {
        $itemInsertColumns[] = 'vk_donut_enabled';
    }
    if ($supportsItemDonutDuration) {
        $itemInsertColumns[] = 'vk_donut_paid_duration';
    }
    if ($supportsItemDonutFreeCopy) {
        $itemInsertColumns[] = 'vk_donut_can_publish_free_copy';
    }
    if ($supportsItemDonutSnapshot) {
        $itemInsertColumns[] = 'vk_donut_settings_snapshot';
    }
    if ($supportsItemDonationEnabled) {
        $itemInsertColumns[] = 'donation_enabled';
    }
    if ($supportsItemDonationGoal) {
        $itemInsertColumns[] = 'donation_goal_id';
    }
    if ($supportsItemVkDonateId) {
        $itemInsertColumns[] = 'vk_donate_id';
    }
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
        if ($supportsItemDonutEnabled) {
            $itemInsertValues[] = $vkDonutEnabled;
        }
        if ($supportsItemDonutDuration) {
            $itemInsertValues[] = $vkDonutEnabled ? $vkDonutPaidDuration : null;
        }
        if ($supportsItemDonutFreeCopy) {
            $itemInsertValues[] = $vkDonutEnabled ? $vkDonutCanPublishFreeCopy : 0;
        }
        if ($supportsItemDonutSnapshot) {
            $itemInsertValues[] = $vkDonutEnabled ? $vkDonutSnapshot : null;
        }
        if ($supportsItemDonationEnabled) {
            $itemInsertValues[] = $donationEnabled;
        }
        if ($supportsItemDonationGoal) {
            $itemInsertValues[] = $donationEnabled ? ($donationGoalId > 0 ? $donationGoalId : null) : null;
        }
        if ($supportsItemVkDonateId) {
            $itemInsertValues[] = $donationEnabled && $vkDonateId !== '' ? $vkDonateId : null;
        }
        if ($supportsItemPublicationType) {
            $itemInsertValues[] = $publicationType;
        }
        if ($supportsItemResolvedMode) {
            $itemInsertValues[] = $publicationType;
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
            u.email AS applicant_email,
            d.title AS donation_goal_title
        FROM vk_publication_task_items i
        LEFT JOIN works w ON w.id = i.work_id
        LEFT JOIN participants p ON p.id = i.participant_id
        LEFT JOIN contests c ON c.id = i.contest_id
        LEFT JOIN applications a ON a.id = i.application_id
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN vk_donates d ON d.id = i.donation_goal_id
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

function resolveVkPublicationType(array $source): string
{
    $explicitType = trim((string) ($source['publication_type'] ?? ''));
    if (in_array($explicitType, ['standard', 'vk_donut', 'donation_goal'], true)) {
        return $explicitType;
    }

    if (!empty($source['vk_donut_enabled'])) {
        return 'vk_donut';
    }

    if (!empty($source['donation_enabled'])) {
        return 'donation_goal';
    }

    return 'standard';
}

function buildVkPublicationCapabilities(array $readiness): array
{
    $base = [
        'standard' => ['status' => 'not_checked', 'message' => 'Проверка ещё не выполнялась.'],
        'vk_donut' => ['status' => 'not_checked', 'message' => 'Проверка ещё не выполнялась.'],
        'donation_goal' => ['status' => 'not_checked', 'message' => 'Проверка ещё не выполнялась.'],
    ];

    if (empty($readiness['ok'])) {
        $message = implode('; ', $readiness['issues'] ?? ['VK не готов к публикации.']);
        foreach ($base as $mode => $meta) {
            $base[$mode] = ['status' => 'unsupported', 'message' => $message];
        }
        return $base;
    }

    $base['standard'] = ['status' => 'supported', 'message' => 'Базовая публикация доступна.'];
    $base['vk_donut'] = ['status' => 'not_checked', 'message' => 'Требуется тест публикации с donut-параметрами.'];
    $base['donation_goal'] = ['status' => 'not_checked', 'message' => 'Требуется тест публикации с donation_goal_id.'];

    return $base;
}

function getVkPublicationCapabilities(): array
{
    $readiness = verifyVkPublicationReadiness(true);
    $capabilities = buildVkPublicationCapabilities($readiness);

    $settings = getSystemSettings();
    $cached = json_decode((string) ($settings['vk_publication_capabilities_json'] ?? ''), true);
    if (is_array($cached)) {
        foreach (['standard', 'vk_donut', 'donation_goal'] as $mode) {
            if (!empty($cached[$mode]) && is_array($cached[$mode])) {
                $capabilities[$mode] = array_merge($capabilities[$mode], $cached[$mode]);
            }
        }
    }

    return $capabilities;
}

function updateVkCapabilityFromError(string $mode, Throwable $error): void
{
    if (!in_array($mode, ['standard', 'vk_donut', 'donation_goal'], true)) {
        return;
    }

    $settings = getSystemSettings();
    $cached = json_decode((string) ($settings['vk_publication_capabilities_json'] ?? ''), true);
    if (!is_array($cached)) {
        $cached = [];
    }

    $normalized = normalizeVkPublicationError($error);
    $cached[$mode] = [
        'status' => 'unsupported',
        'message' => $normalized['message'],
        'technical' => $normalized['technical'],
        'checked_at' => date('c'),
    ];

    saveSystemSettings([
        'vk_publication_capabilities_json' => json_encode($cached, JSON_UNESCAPED_UNICODE),
    ]);
}

function markVkCapabilitySupported(string $mode, string $message): void
{
    if (!in_array($mode, ['standard', 'vk_donut', 'donation_goal'], true)) {
        return;
    }

    $settings = getSystemSettings();
    $cached = json_decode((string) ($settings['vk_publication_capabilities_json'] ?? ''), true);
    if (!is_array($cached)) {
        $cached = [];
    }

    $cached[$mode] = [
        'status' => 'supported',
        'message' => $message,
        'checked_at' => date('c'),
    ];

    saveSystemSettings([
        'vk_publication_capabilities_json' => json_encode($cached, JSON_UNESCAPED_UNICODE),
    ]);
}

function buildVkWallParamsByMode(array $item, array $options = []): array
{
    $type = resolveVkPublicationType($item);
    if (!empty($options['wall_params']) && is_array($options['wall_params'])) {
        return $options['wall_params'];
    }

    if ($type === 'vk_donut') {
        return buildVkDonutWallParamsFromTask($item);
    }

    if ($type === 'donation_goal') {
        return buildVkDonateWallParamsFromItem($item);
    }

    return [];
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

    $publicationType = resolveVkPublicationType($item);
    $failureStage = null;
    $requestPayload = [];
    $responsePayload = [];
    $verificationStatus = null;
    $verificationMessage = null;
    $detectedMode = null;
    $detectedFeatures = [];
    $vkPostReadbackJson = null;
    $capabilities = getVkPublicationCapabilities();
    $capabilityMeta = $capabilities[$publicationType] ?? ['status' => 'not_checked', 'message' => ''];

    vkPublicationLog('publish_item_attempt', [
        'item_id' => (int) $item['id'],
        'task_id' => (int) $item['task_id'],
        'work_id' => (int) $item['work_id'],
        'publication_type' => $publicationType,
        'capability_status' => $capabilityMeta['status'] ?? 'not_checked',
        'donation_enabled' => (int) ($item['donation_enabled'] ?? 0),
        'donation_goal_id' => (int) ($item['donation_goal_id'] ?? 0),
        'vk_donate_id' => (string) ($item['vk_donate_id'] ?? ''),
        'vk_donut_enabled' => (int) ($item['vk_donut_enabled'] ?? 0),
        'vk_donut_paid_duration' => isset($item['vk_donut_paid_duration']) ? (int) $item['vk_donut_paid_duration'] : null,
        'vk_donut_can_publish_free_copy' => (int) ($item['vk_donut_can_publish_free_copy'] ?? 0),
        'build_by_mode' => buildVkWallParamsByMode($item, $options),
        'build_donate_params' => buildVkDonateWallParamsFromItem($item),
        'build_donut_params' => buildVkDonutWallParamsFromTask($item),
    ]);

    $readiness = verifyVkPublicationReadiness(true);
    if (empty($readiness['ok'])) {
        $failureStage = 'validation';
        $error = (string) ($readiness['issues'][0] ?? 'VK не готов к публикации');
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $error, implode('; ', $readiness['issues'] ?? []));
        return ['success' => false, 'error' => $error];
    }

    $settings = $readiness['settings'];
    try {
        $client = new VkApiClient($settings['publication_token'], (int) $settings['group_id'], (string) $settings['api_version']);
    } catch (Throwable $e) {
        $failureStage = 'validation';
        $normalized = normalizeVkPublicationError($e);
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $normalized['message'], $normalized['technical']);
        return ['success' => false, 'error' => $normalized['message']];
    }

    $imageFsPath = getParticipantDrawingFsPath((string) ($item['applicant_email'] ?? ''), (string) ($item['work_image_path'] ?? ''));
    if (!$imageFsPath || !is_file($imageFsPath)) {
        $failureStage = 'validation';
        $error = 'Изображение для публикации не найдено';
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $error, $error);
        return ['success' => false, 'error' => $error];
    }

    $extraWallParams = buildVkWallParamsByMode($item, $options);
    $extraWallParams['_publication_mode'] = $publicationType;

    if ($publicationType === 'donation_goal' && empty($extraWallParams)) {
        $failureStage = 'validation';
        $support = getVkDonationAttachmentSupport();
        $vkDonateId = trim((string) ($item['vk_donate_id'] ?? ''));
        $error = empty($support['supported'])
            ? (string) ($support['message'] ?? 'Публикация с выбранной целью доната недоступна для текущего VK API.')
            : 'Нельзя публиковать с пустым vk_donate_id.';
        $technical = empty($support['supported'])
            ? 'donation attachment disabled: ' . (string) ($support['code'] ?? 'unknown')
            : $error;
        if ($vkDonateId === '' && !empty($support['supported'])) {
            $technical = 'donation attachment blocked: empty vk_donate_id';
        }
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $error, $technical);
        return ['success' => false, 'error' => $error];
    }

    $requestPayload = [
        'item_id' => (int) $item['id'],
        'task_id' => (int) $item['task_id'],
        'publication_type' => $publicationType,
        'message' => (string) ($item['post_text'] ?? ''),
        'image_path' => (string) ($item['work_image_path'] ?? ''),
        'extra_wall_params' => sanitizeVkExtraWallParamsForLog($extraWallParams),
    ];

    try {
        $failureStage = 'wall_post';
        $published = $client->publishPhotoPost(
            $imageFsPath,
            (string) ($item['post_text'] ?? ''),
            (bool) $settings['from_group'],
            $extraWallParams
        );
        $responsePayload = $published;
        $readbackOk = !empty($published['readback_ok']);
        $readbackError = trim((string) ($published['readback_error'] ?? ''));
        $readbackPost = is_array($published['readback_post'] ?? null) ? $published['readback_post'] : [];
        $verification = [
            'verification_status' => 'unknown_result',
            'verification_message' => 'VK создал пост, но не удалось верифицировать результат.',
            'detected_mode' => 'unknown',
            'detected_features' => [],
        ];

        if ($readbackOk) {
            $verification = verifyVkPublicationReadback($publicationType, $readbackPost);
        } elseif ($publicationType === 'standard') {
            $verification = [
                'verification_status' => 'standard_post_created_without_readback_confirmation',
                'verification_message' => $readbackError !== ''
                    ? 'Пост создан, но readback-диагностика не подтвердила результат: ' . $readbackError
                    : 'Пост создан, но readback-диагностика не вернула подтверждающий ответ.',
                'detected_mode' => 'unknown',
                'detected_features' => [],
            ];
        } elseif ($publicationType === 'vk_donut') {
            $verification = [
                'verification_status' => 'unknown_result',
                'verification_message' => $readbackError !== ''
                    ? 'Пост создан, но VK не подтвердил donut/paywall через readback: ' . $readbackError
                    : 'Пост создан, но readback-ответ VK не подтвердил donut/paywall.',
                'detected_mode' => 'unknown',
                'detected_features' => [],
            ];
        } elseif ($publicationType === 'donation_goal') {
            $verification = [
                'verification_status' => 'unknown_result',
                'verification_message' => $readbackError !== ''
                    ? 'Пост создан, но VK не подтвердил цель сбора через readback: ' . $readbackError
                    : 'Пост создан, но readback-ответ VK не подтвердил цель сбора.',
                'detected_mode' => 'unknown',
                'detected_features' => [],
            ];
        }

        $verificationStatus = (string) ($verification['verification_status'] ?? 'unknown_result');
        $verificationMessage = (string) ($verification['verification_message'] ?? 'VK создал пост, но не удалось верифицировать результат.');
        $detectedMode = (string) ($verification['detected_mode'] ?? 'unknown');
        $detectedFeatures = is_array($verification['detected_features'] ?? null) ? $verification['detected_features'] : [];
        $vkPostReadbackJson = json_encode($published['readback_raw'] ?? [], JSON_UNESCAPED_UNICODE);
        $verificationFailed = false;
        if ($publicationType === 'vk_donut') {
            $verificationFailed = in_array($verificationStatus, ['post_created_but_donut_not_confirmed', 'unknown_result'], true);
        } elseif ($publicationType === 'donation_goal') {
            $verificationFailed = in_array($verificationStatus, ['post_created_but_goal_not_confirmed', 'unknown_result'], true);
        }

        if ($verificationFailed) {
            throw new RuntimeException($verificationMessage);
        }

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
                $publicationType,
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
        markVkCapabilitySupported($publicationType, 'Публикация успешно прошла.');
        vkPublicationLog('publish_item_success', [
            'item_id' => (int) $item['id'],
            'task_id' => (int) $item['task_id'],
            'work_id' => (int) $item['work_id'],
            'publication_type' => $publicationType,
            'post_id' => (string) $published['post_id'],
            'donation_enabled' => (int) ($item['donation_enabled'] ?? 0),
            'donation_goal_id' => (int) ($item['donation_goal_id'] ?? 0),
            'vk_donate_id' => (string) ($item['vk_donate_id'] ?? ''),
            'extra_wall_params' => sanitizeVkExtraWallParamsForLog($extraWallParams),
            'verification_status' => $verificationStatus,
            'verification_message' => $verificationMessage,
            'readback_ok' => $readbackOk ? 1 : 0,
            'readback_error' => $readbackError,
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
        updateVkCapabilityFromError($publicationType, $e);
        $isDonationEnabled = (int) ($item['donation_enabled'] ?? 0) === 1;
        $errorMessage = $normalized['message'];
        if ($isDonationEnabled && isVkDonationAttachmentError($e)) {
            $errorMessage = 'Пост не опубликован: не удалось прикрепить выбранный донат.';
            vkPublicationLog('publish_item_donation_failed', [
                'item_id' => (int) $item['id'],
                'task_id' => (int) $item['task_id'],
                'work_id' => (int) $item['work_id'],
                'donation_goal_id' => (int) ($item['donation_goal_id'] ?? 0),
                'vk_donate_id' => (string) ($item['vk_donate_id'] ?? ''),
                'vk_error_text' => $normalized['message'],
                'technical' => $normalized['technical'],
            ]);
        }
        vkPublicationLog('publish_item_failed', [
            'item_id' => (int) $item['id'],
            'task_id' => (int) $item['task_id'],
            'work_id' => (int) $item['work_id'],
            'publication_type' => $publicationType,
            'failure_stage' => $failureStage ?? 'wall_post',
            'error' => $errorMessage,
            'technical' => $normalized['technical'],
            'post_id' => (string) ($responsePayload['post_id'] ?? ''),
            'post_url' => (string) ($responsePayload['post_url'] ?? ''),
            'donation_enabled' => (int) ($item['donation_enabled'] ?? 0),
            'donation_goal_id' => (int) ($item['donation_goal_id'] ?? 0),
            'vk_donate_id' => (string) ($item['vk_donate_id'] ?? ''),
            'extra_wall_params' => sanitizeVkExtraWallParamsForLog($extraWallParams),
        ]);
        markVkTaskItemFailed((int) $item['id'], (int) $item['task_id'], $errorMessage, $normalized['technical']);
        $pdo->prepare("UPDATE vk_publication_task_items
            SET resolved_mode = ?, capability_status = ?, failure_stage = ?, request_payload_json = ?, response_payload_json = ?,
                verification_status = COALESCE(verification_status, ?), verification_message = COALESCE(verification_message, ?),
                detected_mode = COALESCE(detected_mode, ?), detected_features_json = COALESCE(detected_features_json, ?),
                vk_post_readback_json = COALESCE(vk_post_readback_json, ?), updated_at = NOW()
            WHERE id = ?")
            ->execute([
                $publicationType,
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

    return [
        'message' => $message,
        'technical' => $technical,
    ];
}

function publishVkTask(int $taskId, array $options = []): array
{
    global $pdo;

    $task = getVkTaskById($taskId);
    $taskType = $task ? resolveVkPublicationType($task) : 'standard';

    if ($task && $taskType === 'vk_donut' && empty($options['wall_params']) && !empty($task['vk_donut_enabled'])) {
        $options['wall_params'] = buildVkDonutWallParamsFromTask($task);
        if (empty($options['wall_params'])) {
            return [
                'total' => 0,
                'published' => 0,
                'failed' => 0,
                'error' => 'Некорректные параметры VK Donut: укажите paid_duration.',
            ];
        }
    }
    if ($task && $taskType === 'donation_goal' && empty($task['vk_donate_id'])) {
        return [
            'total' => 0,
            'published' => 0,
            'failed' => 0,
            'error' => 'Некорректные параметры публикации donation goal: отсутствует vk_donate_id.',
        ];
    }

    $readiness = verifyVkPublicationReadiness(true);
    if (empty($readiness['ok'])) {
        return [
            'total' => 0,
            'published' => 0,
            'failed' => 0,
            'error' => implode('; ', $readiness['issues'] ?? ['VK не готов к публикации']),
        ];
    }

    $pdo->prepare("UPDATE vk_publication_tasks SET task_status = 'publishing', resolved_mode = ?, capability_status = 'not_checked', updated_at = NOW() WHERE id = ?")
        ->execute([$taskType, $taskId]);

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

function buildVkDonutWallParamsFromTask(array $task): array
{
    if (empty($task['vk_donut_enabled'])) {
        return [];
    }

    $paidDuration = (int) ($task['vk_donut_paid_duration'] ?? 0);
    if ($paidDuration <= 0) {
        return [];
    }

    $donut = [
        'is_donut' => 1,
        'paid_duration' => $paidDuration,
        'can_publish_free_copy' => !empty($task['vk_donut_can_publish_free_copy']) ? 1 : 0,
    ];

    return ['donut' => $donut];
}

function buildVkDonateWallParamsFromItem(array $item): array
{
    $enabled = (int) ($item['donation_enabled'] ?? 0) === 1;
    $vkDonateId = trim((string) ($item['vk_donate_id'] ?? ''));
    if (!$enabled || $vkDonateId === '') {
        return [];
    }

    return ['donation_goal_id' => $vkDonateId];
}

function getVkDonationAttachmentSupport(): array
{
    $capabilities = getVkPublicationCapabilities();
    $donation = $capabilities['donation_goal'] ?? [];
    $status = (string) ($donation['status'] ?? 'not_checked');

    if ($status === 'supported') {
        return [
            'supported' => true,
            'code' => 'supported',
            'status' => $status,
            'message' => (string) ($donation['message'] ?? ''),
        ];
    }

    if ($status === 'unsupported') {
        return [
            'supported' => false,
            'code' => 'unsupported',
            'status' => $status,
            'message' => (string) ($donation['message'] ?? 'Режим donation goal подтверждён как нерабочий по ответу VK API.'),
        ];
    }

    return [
        'supported' => true,
        'code' => 'not_checked',
        'status' => 'not_checked',
        'message' => (string) ($donation['message'] ?? 'Поддержка donation goal ещё не подтверждена: доступен диагностический запуск.'),
    ];
}

function sanitizeVkExtraWallParamsForLog(array $params): array
{
    $out = [];
    foreach ($params as $key => $value) {
        $normalizedKey = trim((string) $key);
        if ($normalizedKey === '' || in_array($normalizedKey, ['access_token', 'v'], true)) {
            continue;
        }
        $out[$normalizedKey] = $value;
    }
    return $out;
}

function detectVkPostFeatures(array $post): array
{
    $json = json_encode($post, JSON_UNESCAPED_UNICODE);
    $jsonLower = mb_strtolower((string) $json);
    $donut = is_array($post['donut'] ?? null) ? $post['donut'] : [];
    $donutDetected = !empty($donut)
        || (int) ($post['is_donut'] ?? 0) === 1
        || str_contains($jsonLower, '"donut"')
        || str_contains($jsonLower, 'is_donut');

    $goalHints = ['goal', 'donat', 'donation', 'fundrais', 'support', 'donut_link'];
    $goalDetected = false;
    foreach ($goalHints as $hint) {
        if (str_contains($jsonLower, $hint)) {
            $goalDetected = true;
            break;
        }
    }

    return [
        'has_donut' => $donutDetected,
        'has_goal' => $goalDetected,
        'donut' => $donut,
        'attachments_count' => is_array($post['attachments'] ?? null) ? count($post['attachments']) : 0,
    ];
}

function verifyVkPublicationReadback(string $mode, array $post): array
{
    $features = detectVkPostFeatures($post);
    $status = 'unknown_result';
    $message = 'VK создал пост, но итог верификации неоднозначен.';
    $detectedMode = 'unknown';

    if ($features['has_donut'] && !$features['has_goal']) {
        $detectedMode = 'vk_donut';
    } elseif ($features['has_goal'] && !$features['has_donut']) {
        $detectedMode = 'donation_goal';
    } elseif (!$features['has_goal'] && !$features['has_donut']) {
        $detectedMode = 'standard';
    }

    if ($mode === 'standard') {
        $status = (!$features['has_goal'] && !$features['has_donut']) ? 'confirmed_standard' : 'post_created_but_unexpected_features';
        $message = $status === 'confirmed_standard'
            ? 'Пост создан, признаки donut/paywall и цели сбора не обнаружены.'
            : 'Пост создан, но в readback обнаружены неожиданные признаки донатов/целей.';
    } elseif ($mode === 'vk_donut') {
        $status = $features['has_donut'] ? 'confirmed_vk_donut' : 'post_created_but_donut_not_confirmed';
        $message = $features['has_donut']
            ? 'Пост создан и donut/paywall подтверждён readback-ответом VK.'
            : 'Пост создан, но VK не подтвердил donut/paywall в readback.';
    } elseif ($mode === 'donation_goal') {
        $status = $features['has_goal'] ? 'confirmed_donation_goal' : 'post_created_but_goal_not_confirmed';
        $message = $features['has_goal']
            ? 'Пост создан и цель сбора подтверждена readback-ответом VK.'
            : 'Пост создан, но VK не подтвердил прикрепление цели сбора. Публикация считается неуспешной.';
    }

    return [
        'verification_status' => $status,
        'verification_message' => $message,
        'detected_mode' => $detectedMode,
        'detected_features' => $features,
    ];
}

function isVkDonationAttachmentError(Throwable $e): bool
{
    $message = mb_strtolower((string) $e->getMessage());
    $technical = $e instanceof VkApiException ? mb_strtolower($e->getTechnicalMessage()) : '';
    $haystack = $message . ' ' . $technical;

    return str_contains($haystack, 'donut')
        || str_contains($haystack, 'donate')
        || str_contains($haystack, 'donation')
        || str_contains($haystack, 'goal');
}

function retryFailedVkTaskItems(int $taskId): array
{
    global $pdo;
    $pdo->prepare("UPDATE vk_publication_task_items SET item_status = 'ready', updated_at = NOW() WHERE task_id = ? AND item_status = 'failed'")
        ->execute([$taskId]);

    return publishVkTask($taskId);
}
