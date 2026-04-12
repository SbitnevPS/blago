<?php

function ensureMailingsSchema(): void
{
    global $pdo;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_blacklist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            reason VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mailing_campaigns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subject VARCHAR(255) NOT NULL,
            body LONGTEXT NULL,
            filters_json LONGTEXT NULL,
            include_blacklist TINYINT(1) NOT NULL DEFAULT 0,
            contest_id INT NULL,
            min_participants INT NOT NULL DEFAULT 0,
            selection_mode ENUM('all','none') NOT NULL DEFAULT 'all',
            exclusions_json LONGTEXT NULL,
            inclusions_json LONGTEXT NULL,
            total_recipients INT NOT NULL DEFAULT 0,
            sent_count INT NOT NULL DEFAULT 0,
            failed_count INT NOT NULL DEFAULT 0,
            status ENUM('draft','saved','running','stopped','completed') NOT NULL DEFAULT 'draft',
            created_by INT NOT NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_status (status),
            KEY idx_created_by (created_by),
            KEY idx_created_at (created_at),
            CONSTRAINT fk_mailing_campaigns_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mailing_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mailing_id INT NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NULL,
            file_size INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mailing_id (mailing_id),
            CONSTRAINT fk_mailing_attachments_campaign FOREIGN KEY (mailing_id) REFERENCES mailing_campaigns(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mailing_send_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mailing_id INT NOT NULL,
            user_id INT NOT NULL,
            email VARCHAR(255) NOT NULL,
            send_status ENUM('sent','failed','skipped') NOT NULL,
            error_message TEXT NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mailing_user (mailing_id, user_id),
            KEY idx_mailing_status (mailing_id, send_status),
            CONSTRAINT fk_mailing_send_logs_campaign FOREIGN KEY (mailing_id) REFERENCES mailing_campaigns(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }
}

function mailingNormalizeFilters(array $input): array
{
    return [
        'contest_id' => max(0, (int) ($input['contest_id'] ?? 0)),
        'min_participants' => max(0, (int) ($input['min_participants'] ?? 0)),
        'include_blacklist' => !empty($input['include_blacklist']) ? 1 : 0,
    ];
}

function mailingDecodeIdsJson($json): array
{
    $raw = json_decode((string) $json, true);
    if (!is_array($raw)) {
        return [];
    }

    $ids = [];
    foreach ($raw as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }

    return array_keys($ids);
}

function mailingBuildRecipientWhere(array $filters, string $search = ''): array
{
    global $pdo;

    $where = ["u.email IS NOT NULL", "TRIM(u.email) <> ''"];
    $params = [];

    $contestId = (int) ($filters['contest_id'] ?? 0);
    $minParticipants = (int) ($filters['min_participants'] ?? 0);
    $includeBlacklist = (int) ($filters['include_blacklist'] ?? 0) === 1;

    if (!$includeBlacklist) {
        $where[] = 'eb.email IS NULL';
    }

    if ($contestId > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM applications a WHERE a.user_id = u.id AND a.contest_id = ?)';
        $params[] = $contestId;
    }

    if ($minParticipants > 0) {
        $where[] = "EXISTS (
            SELECT 1
            FROM applications a2
            INNER JOIN (
                SELECT application_id, COUNT(*) AS participants_count
                FROM participants
                GROUP BY application_id
            ) pc ON pc.application_id = a2.id
            WHERE a2.user_id = u.id
              AND pc.participants_count >= ?
              " . ($contestId > 0 ? "AND a2.contest_id = ?" : "") . "
        )";
        $params[] = $minParticipants;
        if ($contestId > 0) {
            $params[] = $contestId;
        }
    }

    $search = trim($search);
    if ($search !== '') {
        $where[] = "(LOWER(CONCAT_WS(' ', u.surname, u.name)) LIKE ? OR LOWER(u.email) LIKE ?)";
        $needle = '%' . mb_strtolower($search) . '%';
        $params[] = $needle;
        $params[] = $needle;
    }

    return [
        'sql' => implode(' AND ', $where),
        'params' => $params,
    ];
}

function mailingCountRecipients(array $filters, string $search = ''): int
{
    global $pdo;

    $clause = mailingBuildRecipientWhere($filters, $search);
    $sql = "SELECT COUNT(*)
        FROM users u
        LEFT JOIN email_blacklist eb ON LOWER(eb.email) = LOWER(u.email)
        WHERE {$clause['sql']}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($clause['params']);
    return (int) $stmt->fetchColumn();
}

function mailingFetchRecipientsPage(array $filters, string $search, int $page, int $perPage, string $selectionMode, array $exclusions, array $inclusions): array
{
    global $pdo;

    $page = max(1, $page);
    $perPage = max(1, min(200, $perPage));
    $offset = ($page - 1) * $perPage;

    $clause = mailingBuildRecipientWhere($filters, $search);

    $sql = "SELECT
            u.id,
            u.name,
            u.surname,
            u.email,
            CASE WHEN eb.email IS NULL THEN 0 ELSE 1 END AS is_blacklisted,
            (
                SELECT MAX(pc.participants_count)
                FROM applications ax
                INNER JOIN (
                    SELECT application_id, COUNT(*) AS participants_count
                    FROM participants
                    GROUP BY application_id
                ) pc ON pc.application_id = ax.id
                WHERE ax.user_id = u.id
            ) AS max_participants
        FROM users u
        LEFT JOIN email_blacklist eb ON LOWER(eb.email) = LOWER(u.email)
        WHERE {$clause['sql']}
        ORDER BY u.id DESC
        LIMIT {$perPage} OFFSET {$offset}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($clause['params']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $excludedMap = array_fill_keys(array_map('intval', $exclusions), true);
    $includedMap = array_fill_keys(array_map('intval', $inclusions), true);

    foreach ($rows as &$row) {
        $uid = (int) ($row['id'] ?? 0);
        $isSelected = $selectionMode === 'all'
            ? !isset($excludedMap[$uid])
            : isset($includedMap[$uid]);
        $row['is_selected'] = $isSelected ? 1 : 0;
        $row['display_name'] = trim((string) (($row['surname'] ?? '') . ' ' . ($row['name'] ?? '')));
        $row['max_participants'] = (int) ($row['max_participants'] ?? 0);
    }
    unset($row);

    return $rows;
}

function mailingResolveRecipientIds(array $mailing): array
{
    global $pdo;

    $filters = json_decode((string) ($mailing['filters_json'] ?? '{}'), true);
    if (!is_array($filters)) {
        $filters = [];
    }

    $selectionMode = (string) ($mailing['selection_mode'] ?? 'all');
    $exclusions = mailingDecodeIdsJson($mailing['exclusions_json'] ?? '[]');
    $inclusions = mailingDecodeIdsJson($mailing['inclusions_json'] ?? '[]');

    $clause = mailingBuildRecipientWhere($filters, '');
    $sql = "SELECT u.id, u.email
        FROM users u
        LEFT JOIN email_blacklist eb ON LOWER(eb.email) = LOWER(u.email)
        WHERE {$clause['sql']}
        ORDER BY u.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($clause['params']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $excludedMap = array_fill_keys(array_map('intval', $exclusions), true);
    $includedMap = array_fill_keys(array_map('intval', $inclusions), true);
    $selected = [];

    foreach ($rows as $row) {
        $uid = (int) ($row['id'] ?? 0);
        if ($uid <= 0) {
            continue;
        }
        $isSelected = $selectionMode === 'all' ? !isset($excludedMap[$uid]) : isset($includedMap[$uid]);
        if ($isSelected) {
            $selected[] = ['id' => $uid, 'email' => (string) ($row['email'] ?? '')];
        }
    }

    return $selected;
}

function mailingGetStatusLabel(string $status): string
{
    return match ($status) {
        'saved' => 'Сохранена',
        'running' => 'Выполняется',
        'stopped' => 'Остановлена',
        'completed' => 'Завершена',
        default => 'Черновик',
    };
}
