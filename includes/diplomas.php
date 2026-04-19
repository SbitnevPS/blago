<?php

define('DIPLOMAS_PATH', UPLOAD_PATH . '/diplomas');
define('DIPLOMAS_WEB_PATH', '/uploads/diplomas');

function ensureDiplomaStorage(): void {
    if (!is_dir(DIPLOMAS_PATH)) {
        mkdir(DIPLOMAS_PATH, 0775, true);
    }
}

function ensureDiplomaDirectory(string $path): void {
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function getDiplomaOwnerDirectoryName(?string $userEmail): string {
    $safeEmail = normalizeDrawingOwner((string)$userEmail);
    return $safeEmail !== '' ? $safeEmail : '_unknown';
}

function getDiplomaOwnerFsDirectory(?string $userEmail): string {
    return DIPLOMAS_PATH . '/' . getDiplomaOwnerDirectoryName($userEmail);
}

function getDiplomaOwnerRelativeDirectory(?string $userEmail): string {
    return trim(DIPLOMAS_WEB_PATH . '/' . getDiplomaOwnerDirectoryName($userEmail), '/');
}

function splitDiplomaFio(string $fio): array {
    $parts = preg_split('/\s+/u', trim($fio)) ?: [];
    return [
        'surname' => $parts[0] ?? '',
        'name' => $parts[1] ?? '',
        'patronymic' => $parts[2] ?? '',
    ];
}

function normalizeDiplomaNamePart($value): string {
    return trim(preg_replace('/\s+/u', ' ', (string)$value));
}

function buildParticipantCertificateName(array $row): string {
    $firstName = normalizeDiplomaNamePart($row['first_name'] ?? $row['name'] ?? '');
    $middleName = normalizeDiplomaNamePart($row['middle_name'] ?? $row['patronymic'] ?? '');
    $lastName = normalizeDiplomaNamePart($row['last_name'] ?? $row['surname'] ?? '');

    if ($firstName !== '' || $middleName !== '' || $lastName !== '') {
        return implode(' ', array_values(array_filter([$firstName, $middleName, $lastName], static fn($value) => $value !== '')));
    }

    $legacy = splitDiplomaFio((string)($row['fio'] ?? ''));
    return implode(' ', array_values(array_filter([
        normalizeDiplomaNamePart($legacy['name'] ?? ''),
        normalizeDiplomaNamePart($legacy['patronymic'] ?? ''),
        normalizeDiplomaNamePart($legacy['surname'] ?? ''),
    ], static fn($value) => $value !== '')));
}

function diplomaTemplateTypes(): array {
    return [
        'contest_participant' => 'Диплом участника конкурса',
        'participant_certificate' => 'Сертификат участника',
        'encouragement' => 'Благодарственный диплом',
        'winner' => 'Диплом победителя',
        'laureate' => 'Диплом лауреата',
        'nomination' => 'Диплом номинации',
    ];
}

function getParticipantAgeCategoryLabel(?int $age): string
{
    $age = (int)($age ?? 0);
    if ($age <= 0) {
        return '';
    }
    if ($age <= 4) {
        return 'до 4 лет';
    }
    if ($age <= 7) {
        return '5-7 лет';
    }
    if ($age <= 10) {
        return '8-10 лет';
    }
    if ($age <= 13) {
        return '11-13 лет';
    }
    if ($age <= 17) {
        return '14-17 лет';
    }

    return '18+ лет';
}

function matchesParticipantAgeCategory(?int $age, string $category): bool
{
    $age = (int)($age ?? 0);
    return match ($category) {
        '5-7' => $age >= 5 && $age <= 7,
        '8-10' => $age >= 8 && $age <= 10,
        '11-13' => $age >= 11 && $age <= 13,
        '14-17' => $age >= 14 && $age <= 17,
        default => false,
    };
}

function defaultDiplomaLayout(string $type = 'contest_participant'): array {
    if ($type === 'participant_certificate') {
        return [
            'title' => ['x' => 10, 'y' => 13, 'w' => 80, 'h' => 10, 'font_family' => 'dejavusans', 'font_size' => 24, 'color' => '#7A4B16', 'align' => 'center', 'line_height' => 1.15, 'visible' => 1],
            'subtitle' => ['x' => 20, 'y' => 31, 'w' => 60, 'h' => 5, 'font_family' => 'dejavusans', 'font_size' => 12, 'color' => '#5B4630', 'align' => 'center', 'line_height' => 1.2, 'visible' => 1],
            'name_line' => ['x' => 19, 'y' => 43.2, 'w' => 62, 'h' => 0.4, 'type' => 'line', 'color' => '#8B6B45', 'visible' => 1],
            'participant_name' => ['x' => 18, 'y' => 36.2, 'w' => 64, 'h' => 7, 'font_family' => 'dejavuserif', 'font_size' => 22, 'color' => '#6D4317', 'align' => 'center', 'line_height' => 1.1, 'visible' => 1, 'min_font_size' => 11, 'single_line' => 1],
            'body_text' => ['x' => 14, 'y' => 49, 'w' => 72, 'h' => 10, 'font_family' => 'dejavusans', 'font_size' => 10, 'color' => '#5E5E5E', 'align' => 'center', 'line_height' => 1.45, 'visible' => 1],
            'contest_title' => ['x' => 15, 'y' => 61, 'w' => 70, 'h' => 8, 'font_family' => 'dejavusans', 'font_size' => 14, 'color' => '#7A4B16', 'align' => 'center', 'line_height' => 1.2, 'visible' => 1],
            'award_text' => ['x' => 16, 'y' => 70, 'w' => 68, 'h' => 6, 'font_family' => 'dejavusans', 'font_size' => 10, 'color' => '#5E5E5E', 'align' => 'center', 'line_height' => 1.35, 'visible' => 1],
            'footer' => ['x' => 18, 'y' => 82, 'w' => 64, 'h' => 5, 'font_family' => 'dejavusans', 'font_size' => 10, 'color' => '#8C6239', 'align' => 'center', 'line_height' => 1.2, 'visible' => 1],
            'date' => ['x' => 10, 'y' => 92, 'w' => 26, 'h' => 4, 'font_family' => 'dejavusans', 'font_size' => 9, 'color' => '#6B7280', 'align' => 'left', 'line_height' => 1.1, 'visible' => 1],
            'city' => ['x' => 37, 'y' => 92, 'w' => 26, 'h' => 4, 'font_family' => 'dejavusans', 'font_size' => 9, 'color' => '#6B7280', 'align' => 'center', 'line_height' => 1.1, 'visible' => 1],
            'diploma_number' => ['x' => 64, 'y' => 92, 'w' => 26, 'h' => 4, 'font_family' => 'dejavusans', 'font_size' => 9, 'color' => '#6B7280', 'align' => 'right', 'line_height' => 1.1, 'visible' => 1],
        ];
    }

    return [
        'title' => ['x' => 7, 'y' => 7, 'w' => 86, 'h' => 10, 'font_family' => 'dejavusans', 'font_size' => 22, 'color' => '#B45309', 'align' => 'center', 'line_height' => 1.2, 'visible' => 1],
        'subtitle' => ['x' => 12, 'y' => 18, 'w' => 76, 'h' => 6, 'font_family' => 'dejavusans', 'font_size' => 12, 'color' => '#92400E', 'align' => 'center', 'line_height' => 1.2, 'visible' => 1],
        'award_text' => ['x' => 10, 'y' => 28, 'w' => 80, 'h' => 8, 'font_family' => 'dejavusans', 'font_size' => 11, 'color' => '#334155', 'align' => 'center', 'line_height' => 1.3, 'visible' => 1],
        'participant_name' => ['x' => 10, 'y' => 38, 'w' => 80, 'h' => 10, 'font_family' => 'dejavuserif', 'font_size' => 18, 'color' => '#1D4ED8', 'align' => 'center', 'line_height' => 1.25, 'visible' => 1],
        'body_text' => ['x' => 9, 'y' => 50, 'w' => 82, 'h' => 13, 'font_family' => 'dejavusans', 'font_size' => 10, 'color' => '#334155', 'align' => 'center', 'line_height' => 1.45, 'visible' => 1],
        'contest_title' => ['x' => 10, 'y' => 64, 'w' => 80, 'h' => 8, 'font_family' => 'dejavusans', 'font_size' => 14, 'color' => '#7C3AED', 'align' => 'center', 'line_height' => 1.2, 'visible' => 1],
        'diploma_number' => ['x' => 64, 'y' => 92, 'w' => 30, 'h' => 4, 'font_family' => 'dejavusans', 'font_size' => 9, 'color' => '#64748B', 'align' => 'right', 'line_height' => 1.1, 'visible' => 1],
        'date' => ['x' => 6, 'y' => 92, 'w' => 30, 'h' => 4, 'font_family' => 'dejavusans', 'font_size' => 9, 'color' => '#64748B', 'align' => 'left', 'line_height' => 1.1, 'visible' => 1],
        'city' => ['x' => 36, 'y' => 92, 'w' => 28, 'h' => 4, 'font_family' => 'dejavusans', 'font_size' => 9, 'color' => '#64748B', 'align' => 'center', 'line_height' => 1.1, 'visible' => 1],
        'signatures' => ['x' => 8, 'y' => 82, 'w' => 84, 'h' => 8, 'font_family' => 'dejavusans', 'font_size' => 9, 'color' => '#334155', 'align' => 'left', 'line_height' => 1.2, 'visible' => 1],
        'footer' => ['x' => 10, 'y' => 75, 'w' => 80, 'h' => 6, 'font_family' => 'dejavusans', 'font_size' => 10, 'color' => '#BE185D', 'align' => 'center', 'line_height' => 1.25, 'visible' => 1],
    ];
}

function diplomaTemplateDefaults(string $type = 'contest_participant'): array {
    $isEncouragement = $type === 'encouragement';
    $isParticipantCertificate = $type === 'participant_certificate';

    return [
        'title' => $isParticipantCertificate
            ? 'СЕРТИФИКАТ УЧАСТНИКА'
            : ($isEncouragement ? 'Благодарственный диплом' : 'Диплом'),
        'subtitle' => $isParticipantCertificate ? 'награждается' : 'Награждается',
        'body_text' => $isParticipantCertificate
            ? 'за участие в конкурсе детского творчества и проявленный интерес к искусству.'
            : ($isEncouragement
            ? 'за творческое старание, фантазию и яркое участие. Спасибо за ваш талант и вдохновение!'
            : 'за яркое творчество, доброе воображение и вдохновляющую работу в конкурсе детского рисунка.'),
        'award_text' => $isParticipantCertificate
            ? '{participant_name}'
            : ($isEncouragement
            ? 'Памятный диплом юного художника вручается {participant_full_name}'
            : 'Награждается {participant_full_name}'),
        'contest_name_text' => '{contest_title}',
        'easter_text' => $isParticipantCertificate
            ? 'Благодарим за участие и желаем новых творческих успехов!'
            : ($isEncouragement
                ? 'Работа рассмотрена. Спасибо за участие! Для вас доступен благодарственный диплом.'
                : 'Пусть каждая новая идея превращается в маленькое чудо!'),
        'signature_1' => 'Председатель оргкомитета',
        'signature_2' => 'Куратор проекта',
        'position_1' => 'Оргкомитет конкурса',
        'position_2' => siteBrandName(),
        'footer_text' => $isEncouragement
            ? 'Спасибо за участие! Продолжайте рисовать и радовать мир своими работами.'
            : 'Спасибо за участие! Продолжайте творить и вдохновлять.',
        'city' => 'Москва',
        'issue_date' => date('Y-m-d'),
        'diploma_prefix' => $isParticipantCertificate ? 'CERT' : ($isEncouragement ? 'THANK' : 'PART'),
        'show_date' => 1,
        'show_number' => 1,
        'show_signatures' => $isParticipantCertificate ? 0 : 1,
        'show_background' => 1,
        'show_frame' => $isParticipantCertificate ? 0 : 1,
        'layout_json' => json_encode(defaultDiplomaLayout($type), JSON_UNESCAPED_UNICODE),
        'styles_json' => json_encode(['background' => 'soft', 'frame' => 'gold', 'accent_color' => $isParticipantCertificate ? '#7A4B16' : '#7C3AED'], JSON_UNESCAPED_UNICODE),
        'assets_json' => json_encode(['background_image' => null, 'frame_image' => null, 'decor' => []], JSON_UNESCAPED_UNICODE),
        'template_type' => $type,
    ];
}

function ensureDiplomaSchema(): void {
    global $pdo;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS works (
            id INT AUTO_INCREMENT PRIMARY KEY,
            contest_id INT NOT NULL,
            application_id INT NOT NULL,
            participant_id INT NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            image_path VARCHAR(255) DEFAULT NULL,
            status ENUM('pending','accepted','reviewed','reviewed_non_competitive') NOT NULL DEFAULT 'pending',
            reviewed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_participant_work (participant_id),
            KEY idx_application (application_id),
            KEY idx_status (status),
            CONSTRAINT fk_works_contest FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE,
            CONSTRAINT fk_works_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
            CONSTRAINT fk_works_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }

    try {
        $statusColumn = $pdo->query("SHOW COLUMNS FROM works LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $statusType = strtolower((string) ($statusColumn['Type'] ?? ''));
        if ($statusType !== '' && strpos($statusType, 'reviewed_non_competitive') === false) {
            $pdo->exec("
                ALTER TABLE works
                MODIFY COLUMN status ENUM('pending','accepted','reviewed','reviewed_non_competitive')
                NOT NULL DEFAULT 'pending'
            ");
        }
    } catch (Throwable $e) {
    }

    try {
        $workColumns = $pdo->query("SHOW COLUMNS FROM works")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('attach_diploma', $workColumns, true)) {
            $pdo->exec("ALTER TABLE works ADD COLUMN attach_diploma TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
        }
        if (!in_array('attached_diploma_type', $workColumns, true)) {
            $pdo->exec("ALTER TABLE works ADD COLUMN attached_diploma_type VARCHAR(64) NULL AFTER attach_diploma");
        }
    } catch (Throwable $e) {
    }

    try {
        $contestColumns = $pdo->query("SHOW COLUMNS FROM contests")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('diploma_email_template_json', $contestColumns, true)) {
            $pdo->exec("ALTER TABLE contests ADD COLUMN diploma_email_template_json LONGTEXT NULL");
        }
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS diploma_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            contest_id INT NULL,
            template_type VARCHAR(64) NOT NULL,
            title VARCHAR(255) NOT NULL,
            subtitle VARCHAR(255) DEFAULT NULL,
            body_text TEXT,
            award_text TEXT,
            contest_name_text VARCHAR(255) DEFAULT NULL,
            easter_text TEXT,
            signature_1 VARCHAR(255) DEFAULT NULL,
            signature_2 VARCHAR(255) DEFAULT NULL,
            position_1 VARCHAR(255) DEFAULT NULL,
            position_2 VARCHAR(255) DEFAULT NULL,
            footer_text TEXT,
            city VARCHAR(120) DEFAULT NULL,
            issue_date DATE DEFAULT NULL,
            diploma_prefix VARCHAR(32) DEFAULT 'DIPL',
            show_date TINYINT(1) NOT NULL DEFAULT 1,
            show_number TINYINT(1) NOT NULL DEFAULT 1,
            show_signatures TINYINT(1) NOT NULL DEFAULT 1,
            show_background TINYINT(1) NOT NULL DEFAULT 1,
            show_frame TINYINT(1) NOT NULL DEFAULT 1,
            layout_json LONGTEXT,
            styles_json LONGTEXT,
            assets_json LONGTEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_template_type (template_type),
            KEY idx_contest (contest_id),
            UNIQUE KEY uniq_contest_type (contest_id, template_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }

    try {
        $hasLegacyUnique = false;
        $indexes = $pdo->query("SHOW INDEX FROM diploma_templates")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $indexRow) {
            if (($indexRow['Key_name'] ?? '') === 'uniq_contest_type') {
                $hasLegacyUnique = true;
                break;
            }
        }
        if ($hasLegacyUnique) {
            $pdo->exec("ALTER TABLE diploma_templates DROP INDEX uniq_contest_type");
        }
    } catch (Throwable $e) {
    }

    $participantDiplomaColumns = [];
    try {
        $participantDiplomaColumns = $pdo->query("SHOW COLUMNS FROM participant_diplomas")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }

    if (!in_array('work_id', $participantDiplomaColumns, true)) {
        try {
            $pdo->exec("ALTER TABLE participant_diplomas ADD COLUMN work_id INT NULL AFTER participant_id");
            $pdo->exec("ALTER TABLE participant_diplomas ADD KEY idx_work (work_id)");
        } catch (Throwable $e) {
        }
    }

    if (!in_array('diploma_type', $participantDiplomaColumns, true)) {
        try {
            $pdo->exec("ALTER TABLE participant_diplomas ADD COLUMN diploma_type VARCHAR(64) NOT NULL DEFAULT 'contest_participant' AFTER user_id");
            $pdo->exec("ALTER TABLE participant_diplomas ADD KEY idx_diploma_type (diploma_type)");
        } catch (Throwable $e) {
        }
    }

    if (!in_array('template_id', $participantDiplomaColumns, true)) {
        try {
            $pdo->exec("ALTER TABLE participant_diplomas ADD COLUMN template_id INT NULL AFTER diploma_type");
        } catch (Throwable $e) {
        }
    }

    try {
        $pdo->exec("UPDATE participant_diplomas SET diploma_type = 'contest_participant' WHERE diploma_type IS NULL OR diploma_type = ''");
    } catch (Throwable $e) {
    }
}

function getContestDiplomaEmailTemplateSettings(int $contestId): array
{
    global $pdo;
    ensureDiplomaSchema();

    if ($contestId <= 0) {
        return diplomaEmailTemplateDefaults();
    }

    try {
        $stmt = $pdo->prepare('SELECT diploma_email_template_json FROM contests WHERE id = ? LIMIT 1');
        $stmt->execute([$contestId]);
        $json = (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return diplomaEmailTemplateDefaults();
    }

    if ($json === '') {
        return diplomaEmailTemplateDefaults();
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return diplomaEmailTemplateDefaults();
    }

    return normalizeDiplomaEmailTemplateSettings($decoded);
}

function saveContestDiplomaEmailTemplateSettings(int $contestId, array $settings): void
{
    global $pdo;
    ensureDiplomaSchema();

    if ($contestId <= 0) {
        throw new RuntimeException('Конкурс не найден');
    }

    $normalized = normalizeDiplomaEmailTemplateSettings($settings);
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Не удалось сериализовать шаблон письма');
    }

    $stmt = $pdo->prepare('UPDATE contests SET diploma_email_template_json = ? WHERE id = ? LIMIT 1');
    $stmt->execute([$json, $contestId]);
}

function ensureApplicationWorks(int $applicationId): void {
    global $pdo;
    ensureDiplomaSchema();

    $stmt = $pdo->prepare("SELECT a.id, a.contest_id, p.id AS participant_id, p.fio, p.drawing_file
        FROM applications a
        INNER JOIN participants p ON p.application_id = a.id
        WHERE a.id = ?");
    $stmt->execute([$applicationId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $title = trim((string) ($row['fio'] ?? ''));
        if ($title === '') {
            $title = 'Работа участника #' . (int)$row['participant_id'];
        }
        $ins = $pdo->prepare("INSERT INTO works (contest_id, application_id, participant_id, title, image_path, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                contest_id = VALUES(contest_id),
                application_id = VALUES(application_id),
                title = CASE
                    WHEN works.title IS NULL OR TRIM(works.title) = '' THEN VALUES(title)
                    ELSE works.title
                END,
                image_path = VALUES(image_path),
                updated_at = NOW()");
        $ins->execute([
            (int)$row['contest_id'],
            (int)$applicationId,
            (int)$row['participant_id'],
            $title,
            (string)($row['drawing_file'] ?? ''),
        ]);
    }

    // Проставляем work_id старым дипломам через participant_id
    try {
        $pdo->prepare("UPDATE participant_diplomas pd
            INNER JOIN works w ON w.participant_id = pd.participant_id
            SET pd.work_id = w.id
            WHERE pd.application_id = ? AND (pd.work_id IS NULL OR pd.work_id = 0)")
            ->execute([$applicationId]);
    } catch (Throwable $e) {
    }
}

function getApplicationWorks(int $applicationId): array {
    global $pdo;
    ensureApplicationWorks($applicationId);

    $stmt = $pdo->prepare("SELECT
            w.*,
            p.fio,
            p.public_number AS participant_public_number,
            p.age,
            p.region,
            p.organization_name,
            p.organization_address,
            p.organization_email,
            p.drawing_file,
            p.drawing_compliant,
            p.drawing_comment,
            a.user_id,
            a.contest_id
        FROM works w
        INNER JOIN participants p ON p.id = w.participant_id
        INNER JOIN applications a ON a.id = w.application_id
        WHERE w.application_id = ?
        ORDER BY w.id ASC");
    $stmt->execute([$applicationId]);
    return $stmt->fetchAll() ?: [];
}

function getWorkStatusLabel(string $status): string {
    $map = [
        'pending' => 'На рассмотрении',
        'accepted' => 'Рисунок принят',
        'reviewed' => 'Требует корректировки',
        'reviewed_non_competitive' => 'Рисунок отклонён',
    ];
    return $map[$status] ?? ucfirst($status);
}

function getWorkStatusHint(string $status): string {
    $map = [
        'pending' => 'Работа ожидает проверки жюри. Диплом станет доступен после рассмотрения.',
        'accepted' => 'По рисунку принято положительное решение. Его можно публиковать и выдавать по нему диплом.',
        'reviewed' => 'По работе нужны исправления. Укажите, что нужно изменить, и отправьте заявку на корректировку.',
        'reviewed_non_competitive' => 'По рисунку принято решение не допускать его к участию.',
    ];
    return $map[$status] ?? 'Статус работы обновляется после проверки.';
}

function getWorkStatusBadgeClass(string $status): string {
    $map = [
        'pending' => 'badge--warning',
        'accepted' => 'badge--success',
        'reviewed' => 'badge--reviewed',
        'reviewed_non_competitive' => 'badge--error',
    ];
    return $map[$status] ?? 'badge--secondary';
}

function getWorkUiStatusMeta(string $status): array {
    return [
        'status_code' => $status,
        'label' => getWorkStatusLabel($status),
        'hint' => getWorkStatusHint($status),
        'badge_class' => getWorkStatusBadgeClass($status),
    ];
}

function buildApplicationWorkSummary(array $works): array {
    $summary = [
        'total' => count($works),
        'accepted' => 0,
        'reviewed' => 0,
        'pending' => 0,
        'diplomas' => 0,
        'vk_published' => 0,
    ];

    foreach ($works as $work) {
        $status = (string)($work['status'] ?? 'pending');
        if ($status === 'accepted') {
            $summary['accepted']++;
        } elseif ($status === 'reviewed') {
            $summary['reviewed']++;
        } elseif ($status === 'reviewed_non_competitive') {
            $summary['reviewed']++;
        } else {
            $summary['pending']++;
        }

        if (mapWorkStatusToDiplomaType($status) !== null) {
            $summary['diplomas']++;
        }
        if (!empty($work['vk_post_url']) || !empty($work['vk_post_id']) || !empty($work['vk_published_at'])) {
            $summary['vk_published']++;
        }
    }

    return $summary;
}

function getApplicationUiStatusMeta(array $summary): array {
    $total = (int)($summary['total'] ?? 0);
    $pending = (int)($summary['pending'] ?? 0);
    $accepted = (int)($summary['accepted'] ?? 0);
    $reviewed = (int)($summary['reviewed'] ?? 0);
    $diplomas = (int)($summary['diplomas'] ?? 0);
    $vkPublished = (int)($summary['vk_published'] ?? 0);

    if ($total === 0) {
        return ['label' => 'Нет работ', 'badge_class' => 'badge--secondary'];
    }

    if ($diplomas === 0) {
        if ($pending > 0 && $pending < $total) {
            return ['label' => 'Работы частично рассмотрены', 'badge_class' => 'badge--warning'];
        }
        return ['label' => 'Дипломы недоступны', 'badge_class' => 'badge--warning'];
    }

    if ($diplomas < $total) {
        return ['label' => 'Дипломы доступны: ' . $diplomas . ' из ' . $total, 'badge_class' => 'badge--info'];
    }

    if ($accepted === $total) {
        return ['label' => 'Дипломы участника доступны для всех работ', 'badge_class' => 'badge--success'];
    }

    if ($reviewed === $total) {
        return ['label' => 'Благодарственные дипломы доступны для всех работ', 'badge_class' => 'badge--info'];
    }

    if ($vkPublished > 0) {
        return ['label' => 'Дипломы доступны, часть работ опубликована', 'badge_class' => 'badge--primary'];
    }

    return ['label' => 'Дипломы доступны для всех работ', 'badge_class' => 'badge--success'];
}

function mapWorkStatusToDiplomaType(string $status): ?string {
    if ($status === 'accepted') {
        return 'contest_participant';
    }
    if ($status === 'reviewed') {
        return 'encouragement';
    }
    if ($status === 'reviewed_non_competitive') {
        return 'encouragement';
    }
    return null;
}

function getAllowedDiplomaTypesForWorkStatus(string $status): array {
    if ($status === 'accepted') {
        return ['contest_participant', 'participant_certificate'];
    }
    if ($status === 'reviewed') {
        return ['encouragement'];
    }
    if ($status === 'reviewed_non_competitive') {
        return ['encouragement'];
    }
    return [];
}

function getAvailableContestDiplomaTemplatesForWorkStatus(int $contestId, string $status): array
{
    $allowedTypes = getAllowedDiplomaTypesForWorkStatus($status);
    if (empty($allowedTypes)) {
        return [];
    }

    $templates = listContestDiplomaTemplates($contestId);
    return array_values(array_filter($templates, static function (array $template) use ($allowedTypes): bool {
        return in_array((string) ($template['template_type'] ?? ''), $allowedTypes, true);
    }));
}

function isDiplomaTypeAllowedForWorkStatus(string $status, string $type): bool {
    return in_array($type, getAllowedDiplomaTypesForWorkStatus($status), true);
}

function normalizeRequestedDiplomaType(string $status, ?string $requestedType): ?string {
    $allowed = getAllowedDiplomaTypesForWorkStatus($status);
    if ($requestedType !== null && in_array($requestedType, $allowed, true)) {
        return $requestedType;
    }

    return mapWorkStatusToDiplomaType($status);
}

function getAttachedDiplomaTypeForWork(array $work): ?string {
    if ((int)($work['attach_diploma'] ?? 0) !== 1) {
        return null;
    }

    $status = (string)($work['status'] ?? 'pending');
    $type = trim((string)($work['attached_diploma_type'] ?? ''));
    if ($type === '' || !isDiplomaTypeAllowedForWorkStatus($status, $type)) {
        return null;
    }

    return $type;
}

function getFrontendDiplomaTypeForWork(array $work): ?string {
    $attachedType = getAttachedDiplomaTypeForWork($work);
    if ($attachedType !== null) {
        return $attachedType;
    }

    return normalizeRequestedDiplomaType((string)($work['status'] ?? 'pending'), null);
}

function isFrontendDiplomaAvailableForWork(array $work): bool {
    return getFrontendDiplomaTypeForWork($work) !== null;
}

function updateWorkDiplomaAttachment(int $workId, bool $attachDiploma, ?string $diplomaType): array {
    global $pdo;

    $stmt = $pdo->prepare('SELECT * FROM works WHERE id = ? LIMIT 1');
    $stmt->execute([$workId]);
    $work = $stmt->fetch();
    if (!$work) {
        throw new RuntimeException('Работа не найдена');
    }

    $status = (string)($work['status'] ?? 'pending');
    $normalizedType = trim((string)$diplomaType);
    if ($attachDiploma) {
        if ($normalizedType === '') {
            throw new RuntimeException('Выберите шаблон диплома.');
        }
        if (!isDiplomaTypeAllowedForWorkStatus($status, $normalizedType)) {
            throw new RuntimeException('Выбранный шаблон недоступен для текущего статуса работы.');
        }
    } else {
        $normalizedType = $normalizedType !== '' && isDiplomaTypeAllowedForWorkStatus($status, $normalizedType)
            ? $normalizedType
            : null;
    }

    $pdo->prepare('UPDATE works SET attach_diploma = ?, attached_diploma_type = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$attachDiploma ? 1 : 0, $normalizedType, $workId]);

    $stmt = $pdo->prepare('SELECT * FROM works WHERE id = ? LIMIT 1');
    $stmt->execute([$workId]);
    return (array)$stmt->fetch();
}

function canShowIndividualDiplomaActions(array $work): bool {
    return mapWorkStatusToDiplomaType((string)($work['status'] ?? 'pending')) !== null;
}

function canShowBulkDiplomaActions(array $application, array $works = []): bool {
    return getApplicationCanonicalStatus($application) === 'approved';
}

function getApplicationDisplayPermissions(array $application, array $works): array {
    $summary = buildApplicationWorkSummary($works);
    $individual = false;
    foreach ($works as $work) {
        if (canShowIndividualDiplomaActions($work)) {
            $individual = true;
            break;
        }
    }

    return [
        'can_show_bulk_diplomas' => canShowBulkDiplomaActions($application, $works),
        'has_individual_diplomas' => $individual,
        'can_edit_application' => getApplicationUiStatus($application, $summary) === 'revision' || getApplicationCanonicalStatus($application) === 'draft',
    ];
}

function updateWorkStatus(int $workId, string $status): bool {
    global $pdo;
    if (!in_array($status, ['pending', 'accepted', 'reviewed', 'reviewed_non_competitive'], true)) {
        return false;
    }
    $stmt = $pdo->prepare("UPDATE works SET status = ?, reviewed_at = CASE WHEN ? = 'pending' THEN NULL ELSE NOW() END, updated_at = NOW() WHERE id = ?");
    $updated = $stmt->execute([$status, $status, $workId]);
    if (!$updated) {
        return false;
    }

    if ($status === 'accepted') {
        $workStmt = $pdo->prepare('SELECT attach_diploma, attached_diploma_type FROM works WHERE id = ? LIMIT 1');
        $workStmt->execute([$workId]);
        $work = (array)$workStmt->fetch();
        $requestedType = ((int)($work['attach_diploma'] ?? 0) === 1)
            ? trim((string)($work['attached_diploma_type'] ?? ''))
            : '';
        generateWorkDiploma($workId, true, $requestedType !== '' ? $requestedType : null);
    }

    return true;
}

function aggregateApplicationStatusByWorks(int $applicationId): string {
    $works = getApplicationWorks($applicationId);
    if (!$works) {
        return 'draft';
    }

    $counts = ['pending' => 0, 'accepted' => 0, 'reviewed' => 0];
    foreach ($works as $w) {
        $st = (string)($w['status'] ?? 'pending');
        if ($st === 'reviewed_non_competitive') {
            $counts['reviewed']++;
        } elseif (isset($counts[$st])) {
            $counts[$st]++;
        }
    }

    $total = count($works);
    if ($counts['pending'] === $total) {
        return 'pending';
    }
    if ($counts['accepted'] === $total) {
        return 'approved';
    }
    if ($counts['pending'] === 0) {
        return 'reviewed';
    }
    if ($counts['accepted'] > 0) {
        return 'partial_reviewed';
    }
    return 'pending';
}

function defaultTemplateExists(?int $contestId, string $type): bool {
    global $pdo;
    $stmt = $pdo->prepare('SELECT id FROM diploma_templates WHERE contest_id <=> ? AND template_type = ? LIMIT 1');
    $stmt->execute([$contestId, $type]);
    return (bool)$stmt->fetchColumn();
}

function getOrCreateDiplomaTemplate(?int $contestId, string $type): array {
    global $pdo;
    ensureDiplomaSchema();

    $stmt = $pdo->prepare('SELECT * FROM diploma_templates WHERE contest_id <=> ? AND template_type = ? LIMIT 1');
    $stmt->execute([$contestId, $type]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    $defaults = diplomaTemplateDefaults($type);
    $title = $defaults['title'];
    $pdo->prepare("INSERT INTO diploma_templates (
        contest_id, template_type, title, subtitle, body_text, award_text, contest_name_text, easter_text,
        signature_1, signature_2, position_1, position_2, footer_text, city, issue_date, diploma_prefix,
        show_date, show_number, show_signatures, show_background, show_frame,
        layout_json, styles_json, assets_json, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
    ->execute([
        $contestId,
        $type,
        $title,
        $defaults['subtitle'],
        $defaults['body_text'],
        $defaults['award_text'],
        $defaults['contest_name_text'],
        $defaults['easter_text'],
        $defaults['signature_1'],
        $defaults['signature_2'],
        $defaults['position_1'],
        $defaults['position_2'],
        $defaults['footer_text'],
        $defaults['city'],
        $defaults['issue_date'],
        $defaults['diploma_prefix'],
        $defaults['show_date'],
        $defaults['show_number'],
        $defaults['show_signatures'],
        $defaults['show_background'],
        $defaults['show_frame'],
        $defaults['layout_json'],
        $defaults['styles_json'],
        $defaults['assets_json'],
    ]);

    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM diploma_templates WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return (array)$stmt->fetch();
}

function getDiplomaTemplateForContest(int $contestId, string $type): array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM diploma_templates WHERE contest_id = ? AND template_type = ? ORDER BY updated_at DESC, id DESC LIMIT 1');
    $stmt->execute([$contestId, $type]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    $stmt = $pdo->prepare('SELECT * FROM diploma_templates WHERE contest_id IS NULL AND template_type = ? LIMIT 1');
    $stmt->execute([$type]);
    $global = $stmt->fetch();
    if ($global) {
        return $global;
    }

    return getOrCreateDiplomaTemplate($contestId, $type);
}

function getDiplomaTemplateById(int $templateId): ?array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM diploma_templates WHERE id = ? LIMIT 1');
    $stmt->execute([$templateId]);
    $row = $stmt->fetch();
    return $row ? (array)$row : null;
}

function resolveRequestedDiplomaTemplate(array $ctx, $requestedTemplate = null): ?array
{
    $status = (string)($ctx['work_status'] ?? 'pending');
    $contestId = (int)($ctx['contest_id'] ?? 0);
    $allowedTypes = getAllowedDiplomaTypesForWorkStatus($status);
    if (empty($allowedTypes)) {
        return null;
    }

    if (is_int($requestedTemplate) || (is_string($requestedTemplate) && ctype_digit(trim($requestedTemplate)))) {
        $templateId = (int) $requestedTemplate;
        if ($templateId > 0) {
            $template = getDiplomaTemplateById($templateId);
            if ($template
                && (int)($template['contest_id'] ?? 0) === $contestId
                && in_array((string)($template['template_type'] ?? ''), $allowedTypes, true)
            ) {
                return $template;
            }
            return null;
        }
    }

    $requestedType = is_string($requestedTemplate) ? trim($requestedTemplate) : '';
    if ($requestedType !== '' && in_array($requestedType, $allowedTypes, true)) {
        return getDiplomaTemplateForContest($contestId, $requestedType);
    }
    if ($requestedType !== '') {
        return null;
    }

    $fallbackType = mapWorkStatusToDiplomaType($status);
    if ($fallbackType === null) {
        return null;
    }

    return getDiplomaTemplateForContest($contestId, $fallbackType);
}

function listContestDiplomaTemplates(int $contestId): array {
    global $pdo;
    ensureDiplomaSchema();

    $stmt = $pdo->prepare("SELECT * FROM diploma_templates WHERE contest_id = ? ORDER BY updated_at DESC");
    $stmt->execute([$contestId]);
    return $stmt->fetchAll() ?: [];
}

function saveDiplomaTemplate(int $templateId, array $data): void {
    global $pdo;
    $existing = $pdo->prepare('SELECT * FROM diploma_templates WHERE id = ? LIMIT 1');
    $existing->execute([$templateId]);
    $row = $existing->fetch();
    if (!$row) {
        throw new RuntimeException('Шаблон не найден');
    }

    $type = (string)($row['template_type'] ?? 'contest_participant');
    $defaults = diplomaTemplateDefaults($type);

    $layoutJson = trim((string)($data['layout_json'] ?? ''));
    if ($layoutJson === '') {
        $layoutJson = (string)($row['layout_json'] ?? $defaults['layout_json']);
    }

    $stylesJson = trim((string)($data['styles_json'] ?? ''));
    if ($stylesJson === '') {
        $stylesJson = (string)($row['styles_json'] ?? $defaults['styles_json']);
    }

    $assetsJson = trim((string)($data['assets_json'] ?? ''));
    if ($assetsJson === '') {
        $assetsJson = (string)($row['assets_json'] ?? $defaults['assets_json']);
    }

    $pdo->prepare("UPDATE diploma_templates SET
        title = ?,
        subtitle = ?,
        body_text = ?,
        award_text = ?,
        contest_name_text = ?,
        easter_text = ?,
        signature_1 = ?,
        signature_2 = ?,
        position_1 = ?,
        position_2 = ?,
        footer_text = ?,
        city = ?,
        issue_date = ?,
        diploma_prefix = ?,
        show_date = ?,
        show_number = ?,
        show_signatures = ?,
        show_background = ?,
        show_frame = ?,
        layout_json = ?,
        styles_json = ?,
        assets_json = ?,
        updated_at = NOW()
    WHERE id = ?")
    ->execute([
        trim((string)($data['title'] ?? $row['title'] ?? $defaults['title'])),
        trim((string)($data['subtitle'] ?? $row['subtitle'] ?? $defaults['subtitle'])),
        trim((string)($data['body_text'] ?? $row['body_text'] ?? $defaults['body_text'])),
        trim((string)($data['award_text'] ?? $row['award_text'] ?? $defaults['award_text'])),
        trim((string)($data['contest_name_text'] ?? $row['contest_name_text'] ?? $defaults['contest_name_text'])),
        trim((string)($data['easter_text'] ?? $row['easter_text'] ?? $defaults['easter_text'])),
        trim((string)($data['signature_1'] ?? $row['signature_1'] ?? $defaults['signature_1'])),
        trim((string)($data['signature_2'] ?? $row['signature_2'] ?? $defaults['signature_2'])),
        trim((string)($data['position_1'] ?? $row['position_1'] ?? $defaults['position_1'])),
        trim((string)($data['position_2'] ?? $row['position_2'] ?? $defaults['position_2'])),
        trim((string)($data['footer_text'] ?? $row['footer_text'] ?? $defaults['footer_text'])),
        trim((string)($data['city'] ?? $row['city'] ?? $defaults['city'])),
        trim((string)($data['issue_date'] ?? $row['issue_date'] ?? $defaults['issue_date'])),
        trim((string)($data['diploma_prefix'] ?? $row['diploma_prefix'] ?? $defaults['diploma_prefix'])),
        isset($data['show_date']) ? 1 : 0,
        isset($data['show_number']) ? 1 : 0,
        isset($data['show_signatures']) ? 1 : 0,
        isset($data['show_background']) ? 1 : 0,
        isset($data['show_frame']) ? 1 : 0,
        $layoutJson,
        $stylesJson,
        $assetsJson,
        $templateId,
    ]);

    $pdo->prepare('UPDATE participant_diplomas SET file_path = NULL, generated_at = NULL, updated_at = NOW() WHERE template_id = ?')
        ->execute([$templateId]);
}

function duplicateDiplomaTemplate(int $templateId): int {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM diploma_templates WHERE id = ? LIMIT 1');
    $stmt->execute([$templateId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Шаблон не найден для дублирования');
    }

    $newTitle = trim((string)$row['title']) . ' (копия)';
    $pdo->prepare("INSERT INTO diploma_templates (
        contest_id, template_type, title, subtitle, body_text, award_text, contest_name_text, easter_text,
        signature_1, signature_2, position_1, position_2, footer_text, city, issue_date, diploma_prefix,
        show_date, show_number, show_signatures, show_background, show_frame, layout_json, styles_json, assets_json,
        created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
    ->execute([
        $row['contest_id'],
        $row['template_type'],
        $newTitle,
        $row['subtitle'],
        $row['body_text'],
        $row['award_text'],
        $row['contest_name_text'],
        $row['easter_text'],
        $row['signature_1'],
        $row['signature_2'],
        $row['position_1'],
        $row['position_2'],
        $row['footer_text'],
        $row['city'],
        $row['issue_date'],
        $row['diploma_prefix'],
        $row['show_date'],
        $row['show_number'],
        $row['show_signatures'],
        $row['show_background'],
        $row['show_frame'],
        $row['layout_json'],
        $row['styles_json'],
        $row['assets_json'],
    ]);

    return (int)$pdo->lastInsertId();
}

function getWorkDiplomaContext(int $workId): ?array {
    global $pdo;

    $stmt = $pdo->prepare("SELECT
        w.id AS work_id,
        w.status AS work_status,
        w.title AS work_title,
        w.image_path,
            p.id AS participant_id,
            p.public_number AS participant_public_number,
            p.fio,
            p.age,
            p.region,
            p.organization_name,
        a.id AS application_id,
        a.user_id,
        a.contest_id,
        c.title AS contest_title,
        u.email AS user_email,
        u.name AS user_name,
        u.surname AS user_surname
    FROM works w
    INNER JOIN participants p ON p.id = w.participant_id
    INNER JOIN applications a ON a.id = w.application_id
    INNER JOIN contests c ON c.id = a.contest_id
    INNER JOIN users u ON u.id = a.user_id
    WHERE w.id = ? LIMIT 1");
    $stmt->execute([$workId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $parts = splitDiplomaFio((string)($row['fio'] ?? ''));
    $row['participant_name'] = trim($parts['name']);
    $row['participant_full_name'] = trim((string)($row['fio'] ?? ''));
    $row['participant_certificate_name'] = buildParticipantCertificateName($row);
    return $row;
}

function nextDiplomaNumber(int $contestId, string $prefix): string {
    global $pdo;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM participant_diplomas WHERE contest_id = ?');
    $stmt->execute([$contestId]);
    $num = (int)$stmt->fetchColumn() + 1;
    return trim($prefix) . '-' . str_pad((string)$num, 5, '0', STR_PAD_LEFT);
}

function getOrCreateWorkDiploma(array $ctx, array $template, string $diplomaType): array {
    global $pdo;

    $stmt = $pdo->prepare('SELECT * FROM participant_diplomas WHERE work_id = ? LIMIT 1');
    $stmt->execute([(int)$ctx['work_id']]);
    $row = $stmt->fetch();
    if ($row) {
        if (($row['diploma_type'] ?? '') !== $diplomaType || (int)($row['template_id'] ?? 0) !== (int)($template['id'] ?? 0)) {
            $pdo->prepare('UPDATE participant_diplomas SET diploma_type = ?, template_id = ?, file_path = NULL, generated_at = NULL, updated_at = NOW() WHERE id = ?')
                ->execute([$diplomaType, (int)$template['id'], (int)$row['id']]);
            $stmt = $pdo->prepare('SELECT * FROM participant_diplomas WHERE id = ?');
            $stmt->execute([(int)$row['id']]);
            return (array)$stmt->fetch();
        }
        return $row;
    }

    $stmt = $pdo->prepare('SELECT * FROM participant_diplomas WHERE participant_id = ? AND work_id IS NULL LIMIT 1');
    $stmt->execute([(int)$ctx['participant_id']]);
    $legacy = $stmt->fetch();
    if ($legacy) {
        $pdo->prepare('UPDATE participant_diplomas SET work_id = ?, diploma_type = ?, template_id = ?, updated_at = NOW() WHERE id = ?')
            ->execute([(int)$ctx['work_id'], $diplomaType, (int)$template['id'], (int)$legacy['id']]);
        $stmt = $pdo->prepare('SELECT * FROM participant_diplomas WHERE id = ?');
        $stmt->execute([(int)$legacy['id']]);
        return (array)$stmt->fetch();
    }

    $number = nextDiplomaNumber((int)$ctx['contest_id'], (string)$template['diploma_prefix']);
    $token = bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO participant_diplomas (
        contest_id, application_id, participant_id, work_id, user_id, diploma_type, template_id,
        diploma_number, public_token, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
        ->execute([
            (int)$ctx['contest_id'],
            (int)$ctx['application_id'],
            (int)$ctx['participant_id'],
            (int)$ctx['work_id'],
            (int)$ctx['user_id'],
            $diplomaType,
            (int)$template['id'],
            $number,
            $token,
        ]);
    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM participant_diplomas WHERE id = ?');
    $stmt->execute([$id]);
    return (array)$stmt->fetch();
}

function renderDiplomaVars(string $text, array $vars): string {
    return strtr($text, [
        '{participant_name}' => (string)($vars['participant_name'] ?? ''),
        '{participant_full_name}' => (string)($vars['participant_full_name'] ?? ''),
        '{contest_title}' => (string)($vars['contest_title'] ?? ''),
        '{award_title}' => (string)($vars['award_title'] ?? 'Лауреат'),
        '{place}' => (string)($vars['place'] ?? 'Участник'),
        '{date}' => (string)($vars['date'] ?? ''),
        '{diploma_number}' => (string)($vars['diploma_number'] ?? ''),
        '{participant_diploma_number}' => (string)($vars['participant_diploma_number'] ?? ''),
        '{nomination}' => (string)($vars['nomination'] ?? 'Творческая номинация'),
        '{age_category}' => (string)($vars['age_category'] ?? ''),
        '{city}' => (string)($vars['city'] ?? ''),
        '{issue_date}' => (string)($vars['date'] ?? ''),
        '{certificate_number}' => (string)($vars['participant_diploma_number'] ?? $vars['diploma_number'] ?? ''),
    ]);
}

function getDiplomaPageSizeMm(array $template): array {
    if (getDiplomaSheetRotation($template) === 90) {
        return ['width' => 297.0, 'height' => 210.0];
    }

    return ['width' => 210.0, 'height' => 297.0];
}

function diplomaItemCss(array $cfg): string {
    return diplomaItemCssForTemplate($cfg, null);
}

function diplomaItemCssForTemplate(array $cfg, ?array $template = null): string {
    $x = max(0, (float)($cfg['x'] ?? 0));
    $y = max(0, (float)($cfg['y'] ?? 0));
    $w = max(1, (float)($cfg['w'] ?? 30));
    $h = max(1, (float)($cfg['h'] ?? 6));
    $font = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($cfg['font_family'] ?? 'dejavusans'));
    if ($font === '') {
        $font = 'dejavusans';
    }
    $fontSize = max(6, (int)($cfg['font_size'] ?? 12));
    $lineHeight = max(1, (float)($cfg['line_height'] ?? 1.25));
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($cfg['color'] ?? '')) ? $cfg['color'] : '#334155';
    $align = in_array(($cfg['align'] ?? 'left'), ['left', 'center', 'right'], true) ? $cfg['align'] : 'left';

    if ($template !== null) {
        $pageSize = getDiplomaPageSizeMm($template);
        $leftMm = ($x / 100) * $pageSize['width'];
        $topMm = ($y / 100) * $pageSize['height'];
        $widthMm = ($w / 100) * $pageSize['width'];
        $heightMm = ($h / 100) * $pageSize['height'];

        return sprintf(
            'position:absolute;left:%smm;top:%smm;width:%smm;height:%smm;font-family:%s;font-size:%spx;line-height:%s;color:%s;text-align:%s;overflow:hidden;',
            round($leftMm, 3),
            round($topMm, 3),
            round($widthMm, 3),
            round($heightMm, 3),
            $font,
            $fontSize,
            $lineHeight,
            $color,
            $align
        );
    }

    return sprintf(
        'position:absolute;left:%s%%;top:%s%%;width:%s%%;height:%s%%;font-family:%s;font-size:%spx;line-height:%s;color:%s;text-align:%s;overflow:hidden;',
        $x,
        $y,
        $w,
        $h,
        $font,
        $fontSize,
        $lineHeight,
        $color,
        $align
    );
}

function diplomaLineCss(array $cfg): string {
    return diplomaLineCssForTemplate($cfg, null);
}

function diplomaLineCssForTemplate(array $cfg, ?array $template = null): string {
    $x = max(0, (float)($cfg['x'] ?? 0));
    $y = max(0, (float)($cfg['y'] ?? 0));
    $w = max(1, (float)($cfg['w'] ?? 30));
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($cfg['color'] ?? '')) ? $cfg['color'] : '#8B6B45';
    $height = max(0.1, (float)($cfg['h'] ?? 0.3));

    if ($template !== null) {
        $pageSize = getDiplomaPageSizeMm($template);
        $leftMm = ($x / 100) * $pageSize['width'];
        $topMm = ($y / 100) * $pageSize['height'];
        $widthMm = ($w / 100) * $pageSize['width'];

        return sprintf(
            'position:absolute;left:%smm;top:%smm;width:%smm;height:0;border-top:%smm solid %s;',
            round($leftMm, 3),
            round($topMm, 3),
            round($widthMm, 3),
            $height,
            $color
        );
    }

    return sprintf(
        'position:absolute;left:%s%%;top:%s%%;width:%s%%;height:0;border-top:%smm solid %s;',
        $x,
        $y,
        $w,
        $height,
        $color
    );
}

function fitDiplomaTextConfig(string $key, array $cfg, string $text): array {
    if ($key !== 'participant_name') {
        return $cfg;
    }

    $width = max(1.0, (float)($cfg['w'] ?? 30));
    $fontSize = max(6, (int)($cfg['font_size'] ?? 12));
    $minFontSize = max(6, (int)($cfg['min_font_size'] ?? 9));
    $normalizedText = trim(preg_replace('/\s+/u', ' ', $text));
    if ($normalizedText === '') {
        return $cfg;
    }

    $estimatedChars = max(1, mb_strlen($normalizedText));
    $estimatedCapacity = max(8.0, $width * 1.45);

    while ($fontSize > $minFontSize && $estimatedChars > $estimatedCapacity) {
        $fontSize--;
        $estimatedCapacity += 1.35;
    }

    $cfg['font_size'] = $fontSize;
    return $cfg;
}

function getDiplomaAssetWebPath(string $filename): string {
    return '/uploads/diplomas/' . rawurlencode(basename($filename));
}

function getDiplomaAssetFilePath(string $filename): string {
    return DIPLOMAS_PATH . '/' . basename($filename);
}

function diplomaCssUrl(string $path): string {
    return "url('" . str_replace(["\\", "'"], ["\\\\", "\\'"], $path) . "')";
}

function resolveDiplomaBackgroundStyle(array $template): string {
    $assets = json_decode((string)($template['assets_json'] ?? ''), true);
    $backgroundImage = trim((string)($assets['background_image'] ?? ''));
    if ($backgroundImage !== '') {
        $backgroundPath = getDiplomaAssetFilePath($backgroundImage);
        if (is_file($backgroundPath)) {
            return 'background-image:' . diplomaCssUrl($backgroundPath) . ';background-size:100% 100%;background-position:center center;background-repeat:no-repeat;';
        }

        return 'background-image:' . diplomaCssUrl(getDiplomaAssetWebPath($backgroundImage)) . ';background-size:100% 100%;background-position:center center;background-repeat:no-repeat;';
    }

    return (string)($template['template_type'] ?? '') === 'participant_certificate'
        ? 'background:linear-gradient(180deg,#FCF5E8 0%, #FFFDF8 50%, #F7EEDC 100%);'
        : 'background: linear-gradient(180deg,#FFF9E6 0%, #F7FBFF 50%, #FFF1F2 100%);';
}

function getDiplomaTemplateStyles(array $template): array {
    $styles = json_decode((string)($template['styles_json'] ?? ''), true);
    return is_array($styles) ? $styles : [];
}

function getDiplomaNumberLabel(array $template): string {
    $styles = getDiplomaTemplateStyles($template);
    $label = trim((string)($styles['diploma_number_label'] ?? ''));
    return $label !== '' ? $label : 'Номер диплома';
}

function getDiplomaSheetRotation(array $template): int {
    $styles = getDiplomaTemplateStyles($template);
    $rotation = (int)($styles['sheet_rotation'] ?? 0);
    return $rotation === 90 ? 90 : 0;
}

function getDiplomaPageFormat(array $template): string {
    return getDiplomaSheetRotation($template) === 90 ? 'A4-L' : 'A4';
}

function prepareDiplomaRenderState(array $ctx, array $template, array $diplomaRow): array {
    $participantDisplayNumber = '';
    if (function_exists('getParticipantDisplayNumber')) {
        $participantDisplayNumber = trim((string)getParticipantDisplayNumber($ctx));
    }
    if ($participantDisplayNumber === '') {
        $participantId = (int)($ctx['participant_id'] ?? 0);
        $participantDisplayNumber = $participantId > 0 ? (string)$participantId : '';
    }
    $participantDiplomaNumber = $participantDisplayNumber !== '' ? '#' . ltrim($participantDisplayNumber, '#') : '';

    $vars = [
        'participant_name' => $ctx['participant_certificate_name'] ?? $ctx['participant_name'],
        'participant_full_name' => $ctx['participant_full_name'],
        'contest_title' => $ctx['contest_title'],
        'award_title' => $ctx['award_title'] ?? 'Лауреат',
        'place' => $ctx['place'] ?? 'Участник',
        'date' => !empty($template['issue_date']) ? date('d.m.Y', strtotime((string)$template['issue_date'])) : date('d.m.Y'),
        'diploma_number' => $diplomaRow['diploma_number'],
        'participant_diploma_number' => $participantDiplomaNumber,
        'nomination' => $ctx['nomination'] ?? 'Творческая номинация',
        'age_category' => (($ageCategory = getParticipantAgeCategoryLabel((int)($ctx['age'] ?? 0))) !== '' ? ('Возрастная категория: ' . $ageCategory) : ''),
        'city' => (string)($template['city'] ?? 'Москва'),
    ];

    $layout = json_decode((string)($template['layout_json'] ?? ''), true);
    if (!is_array($layout) || !$layout) {
        $layout = defaultDiplomaLayout((string)($template['template_type'] ?? 'contest_participant'));
    }
    if ((string)($template['template_type'] ?? '') === 'participant_certificate') {
        foreach ($layout as $key => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            $layout[$key]['visible'] = in_array($key, ['participant_name', 'diploma_number'], true) ? 1 : 0;
        }
        unset($layout['name_line']);
    }

    $bg = (int)$template['show_background'] === 1
        ? resolveDiplomaBackgroundStyle($template)
        : 'background:#FFFFFF;';
    $frame = (int)$template['show_frame'] === 1
        ? 'border:10px solid #EAB308; box-shadow: inset 0 0 0 4px #FDE68A;'
        : 'border:0;';
    $pageSize = getDiplomaPageSizeMm($template);
    $diplomaNumberLabel = getDiplomaNumberLabel($template);

    $mapText = [
        'title' => (string)($template['title'] ?? ''),
        'subtitle' => (string)($template['subtitle'] ?? ''),
        'participant_name' => (string)($vars['participant_name'] ?? ''),
        'body_text' => (string)($template['body_text'] ?? ''),
        'award_text' => (string)($template['award_text'] ?? ''),
        'contest_title' => (string)($template['contest_name_text'] ?? '{contest_title}'),
        'diploma_number' => $participantDiplomaNumber !== '' ? ($diplomaNumberLabel . ': ' . $participantDiplomaNumber) : '',
        'date' => $vars['date'],
        'city' => (string)($template['city'] ?? ''),
        'signatures' => (string)($template['signature_1'] ?? '') . ' / ' . (string)($template['signature_2'] ?? ''),
        'footer' => (string)($template['footer_text'] ?? ''),
    ];

    return [
        'vars' => $vars,
        'layout' => $layout,
        'bg' => $bg,
        'frame' => $frame,
        'page_size' => $pageSize,
        'map_text' => $mapText,
    ];
}

function buildDiplomaBackgroundHtml(array $template, array $state): string {
    $pageSize = $state['page_size'];
    $bg = $state['bg'];
    $frame = $state['frame'];

    return '<div style="width:' . $pageSize['width'] . 'mm;height:' . $pageSize['height'] . 'mm;' . $bg . $frame . 'padding:0;margin:0;box-sizing:border-box;font-family:dejavusans;position:relative;overflow:hidden;"></div>';
}

function buildDiplomaHtml(array $ctx, array $template, array $diplomaRow): string {
    $state = prepareDiplomaRenderState($ctx, $template, $diplomaRow);
    $vars = $state['vars'];
    $layout = $state['layout'];
    $mapText = $state['map_text'];
    $pageSize = $state['page_size'];
    $bg = $state['bg'];
    $frame = $state['frame'];
    $html = '<div style="width:' . $pageSize['width'] . 'mm;height:' . $pageSize['height'] . 'mm;' . $bg . $frame . 'padding:0;margin:0;box-sizing:border-box;font-family:dejavusans;position:relative;overflow:hidden;">';
    foreach ($layout as $key => $cfg) {
        if (!is_array($cfg) || (int)($cfg['visible'] ?? 1) !== 1) {
            continue;
        }
        if ($key === 'signatures' && (int)$template['show_signatures'] !== 1) {
            continue;
        }
        if ($key === 'date' && (int)$template['show_date'] !== 1) {
            continue;
        }
        if ($key === 'diploma_number' && (int)$template['show_number'] !== 1) {
            continue;
        }

        if (($cfg['type'] ?? '') === 'line') {
            $html .= '<div style="' . diplomaLineCssForTemplate($cfg, $template) . '"></div>';
            continue;
        }

        $raw = $mapText[$key] ?? '';
        $cfg = fitDiplomaTextConfig((string)$key, $cfg, renderDiplomaVars($raw, $vars));
        $text = nl2br(e(renderDiplomaVars($raw, $vars)));
        $html .= '<div style="' . diplomaItemCssForTemplate($cfg, $template) . '">' . $text . '</div>';
    }

    $html .= '</div>';
    return $html;
}

function getDiplomaBoxMm(array $cfg, array $template): array {
    $pageSize = getDiplomaPageSizeMm($template);
    $x = max(0, (float)($cfg['x'] ?? 0));
    $y = max(0, (float)($cfg['y'] ?? 0));
    $w = max(1, (float)($cfg['w'] ?? 30));
    $h = max(1, (float)($cfg['h'] ?? 6));

    return [
        'x' => round(($x / 100) * $pageSize['width'], 3),
        'y' => round(($y / 100) * $pageSize['height'], 3),
        'w' => round(($w / 100) * $pageSize['width'], 3),
        'h' => round(($h / 100) * $pageSize['height'], 3),
    ];
}

function buildDiplomaItemInnerHtml(array $cfg, string $text): string {
    $font = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($cfg['font_family'] ?? 'dejavusans'));
    if ($font === '') {
        $font = 'dejavusans';
    }
    $fontSize = max(6, (int)($cfg['font_size'] ?? 12));
    $lineHeight = max(1, (float)($cfg['line_height'] ?? 1.25));
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($cfg['color'] ?? '')) ? $cfg['color'] : '#334155';
    $align = in_array(($cfg['align'] ?? 'left'), ['left', 'center', 'right'], true) ? $cfg['align'] : 'left';

    return '<div style="margin:0;padding:0;font-family:' . $font . ';font-size:' . $fontSize . 'px;line-height:' . $lineHeight . ';color:' . $color . ';text-align:' . $align . ';overflow:hidden;">'
        . nl2br(e($text))
        . '</div>';
}

function buildDiplomaLineInnerHtml(array $cfg): string {
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($cfg['color'] ?? '')) ? $cfg['color'] : '#8B6B45';
    $height = max(0.1, (float)($cfg['h'] ?? 0.3));

    return '<div style="width:100%;height:0;border-top:' . $height . 'mm solid ' . $color . ';"></div>';
}

function renderDiplomaPdf(\Mpdf\Mpdf $mpdf, array $ctx, array $template, array $diplomaRow): void {
    $state = prepareDiplomaRenderState($ctx, $template, $diplomaRow);
    $vars = $state['vars'];
    $layout = $state['layout'];
    $mapText = $state['map_text'];

    $mpdf->WriteHTML(buildDiplomaBackgroundHtml($template, $state));

    foreach ($layout as $key => $cfg) {
        if (!is_array($cfg) || (int)($cfg['visible'] ?? 1) !== 1) {
            continue;
        }
        if ($key === 'signatures' && (int)$template['show_signatures'] !== 1) {
            continue;
        }
        if ($key === 'date' && (int)$template['show_date'] !== 1) {
            continue;
        }
        if ($key === 'diploma_number' && (int)$template['show_number'] !== 1) {
            continue;
        }

        $box = getDiplomaBoxMm($cfg, $template);
        if (($cfg['type'] ?? '') === 'line') {
            $mpdf->WriteFixedPosHTML(buildDiplomaLineInnerHtml($cfg), $box['x'], $box['y'], $box['w'], max(0.1, $box['h']), 'visible');
            continue;
        }

        $raw = $mapText[$key] ?? '';
        $text = renderDiplomaVars($raw, $vars);
        $cfg = fitDiplomaTextConfig((string)$key, $cfg, $text);
        $mpdf->WriteFixedPosHTML(buildDiplomaItemInnerHtml($cfg, $text), $box['x'], $box['y'], $box['w'], $box['h'], 'hidden');
    }
}

function generateWorkDiploma(int $workId, bool $forceRegenerate = false, $requestedTemplate = null): array {
    ensureDiplomaStorage();
    ensureDiplomaSchema();

    $ctx = getWorkDiplomaContext($workId);
    if (!$ctx) {
        throw new RuntimeException('Работа не найдена');
    }

    $requestedTemplateValue = is_string($requestedTemplate) ? trim($requestedTemplate) : $requestedTemplate;
    $hasExplicitRequestedTemplate = false;
    if (is_int($requestedTemplateValue)) {
        $hasExplicitRequestedTemplate = $requestedTemplateValue > 0;
    } elseif (is_string($requestedTemplateValue)) {
        $hasExplicitRequestedTemplate = $requestedTemplateValue !== '';
    }

    $template = resolveRequestedDiplomaTemplate($ctx, $requestedTemplate);
    if (!$template) {
        $status = (string)($ctx['work_status'] ?? 'pending');
        if (!canShowIndividualDiplomaActions(['status' => $status])) {
            throw new RuntimeException('Для работы со статусом "' . getWorkStatusLabel($status) . '" диплом недоступен.');
        }

        if ($hasExplicitRequestedTemplate) {
            throw new RuntimeException('Выбранный вариант диплома недоступен для этого участника. Можно использовать только варианты текущего конкурса и текущего статуса работы.');
        }

        throw new RuntimeException('Для текущего конкурса не создан подходящий вариант диплома для этого участника.');
    }

    $diplomaType = (string)($template['template_type'] ?? '');
    if ($diplomaType === '') {
        throw new RuntimeException('Шаблон диплома выбран некорректно');
    }

    $diploma = getOrCreateWorkDiploma($ctx, $template, $diplomaType);
    if (($diploma['diploma_type'] ?? '') !== $diplomaType || (int)($diploma['template_id'] ?? 0) !== (int)($template['id'] ?? 0)) {
        $forceRegenerate = true;
    }

    if (!class_exists('Mpdf\\Mpdf')) {
        throw new RuntimeException('mPDF не установлен. Выполните: composer require mpdf/mpdf');
    }

    $ownerFsDirectory = getDiplomaOwnerFsDirectory((string)($ctx['user_email'] ?? ''));
    $ownerRelativeDirectory = getDiplomaOwnerRelativeDirectory((string)($ctx['user_email'] ?? ''));
    ensureDiplomaDirectory($ownerFsDirectory);

    $fileName = 'diploma_' . $diplomaType . '_' . (int)$ctx['contest_id'] . '_' . (int)$ctx['application_id'] . '_' . (int)$ctx['participant_id'] . '_' . (int)$ctx['work_id'] . '.pdf';
    $relativePath = trim($ownerRelativeDirectory . '/' . $fileName, '/');
    $absolutePath = ROOT_PATH . '/' . $relativePath;
    $previousRelativePath = trim((string)($diploma['file_path'] ?? ''), '/');

    if (!$forceRegenerate && $previousRelativePath !== '' && $previousRelativePath !== $relativePath) {
        $forceRegenerate = true;
    }

    if (!$forceRegenerate && $previousRelativePath !== '' && is_file(ROOT_PATH . '/' . $previousRelativePath)) {
        return $diploma;
    }

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => getDiplomaPageFormat($template),
        'margin_left' => 0,
        'margin_right' => 0,
        'margin_top' => 0,
        'margin_bottom' => 0,
        'tempDir' => ROOT_PATH . '/storage/mpdf',
    ]);
    renderDiplomaPdf($mpdf, $ctx, $template, $diploma);
    $mpdf->Output($absolutePath, \Mpdf\Output\Destination::FILE);

    if ($previousRelativePath !== '' && $previousRelativePath !== $relativePath) {
        $previousAbsolutePath = ROOT_PATH . '/' . $previousRelativePath;
        if (is_file($previousAbsolutePath)) {
            @unlink($previousAbsolutePath);
        }
    }

    global $pdo;
    $pdo->prepare('UPDATE participant_diplomas SET file_path = ?, generated_at = NOW(), updated_at = NOW(), template_id = ?, diploma_type = ? WHERE id = ?')
        ->execute([$relativePath, (int)$template['id'], $diplomaType, (int)$diploma['id']]);

    $stmt = $pdo->prepare('SELECT * FROM participant_diplomas WHERE id = ?');
    $stmt->execute([(int)$diploma['id']]);
    return (array)$stmt->fetch();
}

function generateWorkDiplomaPreviewPdf(int $workId, array $template): string {
    ensureDiplomaStorage();
    ensureDiplomaSchema();

    $ctx = getWorkDiplomaContext($workId);
    if (!$ctx) {
        throw new RuntimeException('Работа не найдена');
    }

    if (!class_exists('Mpdf\\Mpdf')) {
        throw new RuntimeException('mPDF не установлен. Выполните: composer require mpdf/mpdf');
    }

    $diplomaRow = [
        'diploma_number' => 'PREVIEW-' . (int)($template['id'] ?? 0),
    ];

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => getDiplomaPageFormat($template),
        'margin_left' => 0,
        'margin_right' => 0,
        'margin_top' => 0,
        'margin_bottom' => 0,
        'tempDir' => ROOT_PATH . '/storage/mpdf',
    ]);
    renderDiplomaPdf($mpdf, $ctx, $template, $diplomaRow);
    return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}

function getPublicDiplomaUrl(string $token): string {
    return SITE_URL . '/diploma/' . urlencode($token);
}

function getDiplomaEmailEmbeddedImages(string $diplomaType): array
{
    $heroImage = match ($diplomaType) {
        'encouragement' => [
            'path' => ROOT_PATH . '/assets/email/diploma-hero-thanks.jpg',
            'cid' => 'diploma_hero_thanks',
            'name' => 'diploma-hero-thanks.jpg',
        ],
        default => [
            'path' => ROOT_PATH . '/assets/email/diploma-hero-main.jpg',
            'cid' => 'diploma_hero_main',
            'name' => 'diploma-hero-main.jpg',
        ],
    };

    return [
        [
            'path' => ROOT_PATH . '/assets/email/email-logo.png',
            'cid' => 'email_logo',
            'name' => 'email-logo.png',
        ],
        $heroImage,
        [
            'path' => ROOT_PATH . '/assets/email/diploma-footer-stars.png',
            'cid' => 'diploma_footer_stars',
            'name' => 'diploma-footer-stars.png',
        ],
    ];
}

function getAvailableDiplomaEmailImageCidMap(array $embeddedImages): array
{
    $available = [];

    foreach ($embeddedImages as $embeddedImage) {
        if (!is_array($embeddedImage)) {
            continue;
        }

        $path = trim((string)($embeddedImage['path'] ?? ''));
        $cid = trim((string)($embeddedImage['cid'] ?? ''));
        if ($path === '' || $cid === '' || !is_file($path) || !is_readable($path)) {
            continue;
        }

        if (strpos(detectMailFileMimeType($path), 'image/') !== 0) {
            continue;
        }

        $available[$cid] = true;
    }

    return $available;
}

function sendDiplomaByEmail(array $ctx, array $diploma): bool {
    global $pdo;
    $to = trim((string)($ctx['user_email'] ?? ''));
    $file = ROOT_PATH . '/' . ltrim((string)($diploma['file_path'] ?? ''), '/');
    if ($to === '' || !is_file($file)) {
        return false;
    }

    $diplomaType = (string)($diploma['diploma_type'] ?? 'contest_participant');
    $subject = match ($diplomaType) {
        'encouragement' => 'Ваш благодарственный диплом готов',
        'winner' => 'Ваш диплом победителя готов',
        'laureate' => 'Ваш диплом лауреата готов',
        'nomination' => 'Ваш диплом в номинации готов',
        default => 'Ваш диплом участника готов',
    };

    $publicUrl = getPublicDiplomaUrl((string)($diploma['public_token'] ?? ''));
    $attachmentName = 'diploma.pdf';
    $emailTemplate = getContestDiplomaEmailTemplateSettings((int) ($ctx['contest_id'] ?? 0));
    $emailData = [
        'diploma_type' => $diplomaType,
        'user_name' => trim((string)($ctx['user_name'] ?? '')),
        'participant_name' => trim((string)($ctx['participant_full_name'] ?? ($ctx['fio'] ?? ''))),
        'contest_title' => trim((string)($ctx['contest_title'] ?? '')),
        'diploma_number' => trim((string)($diploma['diploma_number'] ?? '')),
        'diploma_url' => $publicUrl,
        'site_url' => SITE_URL,
        'brand_name' => siteBrandName(),
        'brand_subtitle' => siteBrandSubtitle(),
        'attachment_name' => $attachmentName,
        'email_template' => $emailTemplate,
    ];
    $customSubject = buildDiplomaEmailSubject($emailData);
    if ($customSubject !== '') {
        $subject = $customSubject;
    }

    $ok = sendEmail($to, $subject, buildDiplomaEmailTemplate($emailData), [
        'text' => buildDiplomaEmailText($emailData),
        'attachments' => [
            [
                'path' => $file,
                'name' => $attachmentName,
            ],
        ],
    ]);
    if ($ok) {
        $pdo->prepare('UPDATE participant_diplomas SET email_sent_at = NOW(), updated_at = NOW() WHERE id = ?')->execute([(int)$diploma['id']]);
    }

    return $ok;
}

function findExistingWorkDiploma(int $workId, $requestedTemplate = null): ?array {
    global $pdo;

    $stmt = $pdo->prepare('SELECT * FROM participant_diplomas WHERE work_id = ? LIMIT 1');
    $stmt->execute([$workId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    if (is_int($requestedTemplate) || (is_string($requestedTemplate) && ctype_digit(trim($requestedTemplate)))) {
        $templateId = (int) $requestedTemplate;
        if ($templateId > 0 && (int)($row['template_id'] ?? 0) !== $templateId) {
            return null;
        }
    } elseif (is_string($requestedTemplate) && trim($requestedTemplate) !== '') {
        if ((string)($row['diploma_type'] ?? '') !== trim($requestedTemplate)) {
            return null;
        }
    }

    $filePath = trim((string)($row['file_path'] ?? ''));
    if ($filePath === '') {
        return null;
    }

    $absolutePath = ROOT_PATH . '/' . ltrim($filePath, '/');
    if (!is_file($absolutePath)) {
        return null;
    }

    return (array)$row;
}

function collectApplicationDiplomaLinks(int $applicationId, ?array $workIds = null): array {
    global $pdo;
    ensureApplicationWorks($applicationId);

    $sql = "SELECT pd.*, p.fio, w.id AS work_id
        FROM participant_diplomas pd
        INNER JOIN participants p ON p.id = pd.participant_id
        LEFT JOIN works w ON w.id = pd.work_id
        WHERE pd.application_id = ?";
    $params = [$applicationId];
    if (is_array($workIds) && $workIds) {
        $placeholders = implode(',', array_fill(0, count($workIds), '?'));
        $sql .= " AND pd.work_id IN ($placeholders)";
        foreach ($workIds as $workId) {
            $params[] = (int)$workId;
        }
    }
    $sql .= " ORDER BY pd.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'participant' => (string)$row['fio'],
            'work_id' => (int)($row['work_id'] ?? 0),
            'url' => getPublicDiplomaUrl((string)$row['public_token']),
        ];
    }
    return $result;
}

function buildApplicationDiplomaZip(int $applicationId, ?array $workIds = null): string {
    ensureDiplomaStorage();
    global $pdo;
    $sql = "SELECT pd.file_path, pd.participant_id, pd.diploma_number, pd.diploma_type, p.fio
        FROM participant_diplomas pd
        INNER JOIN participants p ON p.id = pd.participant_id
        WHERE pd.application_id = ?";
    $params = [$applicationId];
    if (is_array($workIds) && $workIds) {
        $placeholders = implode(',', array_fill(0, count($workIds), '?'));
        $sql .= " AND pd.work_id IN ($placeholders)";
        foreach ($workIds as $workId) {
            $params[] = (int)$workId;
        }
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $zipName = 'application_' . $applicationId . '_diplomas_' . date('Ymd_His') . '.zip';
    $zipRelative = trim(DIPLOMAS_WEB_PATH . '/' . $zipName, '/');
    $zipAbsolute = ROOT_PATH . '/' . $zipRelative;

    $zip = new ZipArchive();
    if ($zip->open($zipAbsolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Не удалось создать ZIP');
    }

    foreach ($rows as $row) {
        $file = ROOT_PATH . '/' . ltrim((string)$row['file_path'], '/');
        if (!is_file($file)) {
            continue;
        }
        $safeName = preg_replace('/[^\p{L}\p{N}_\-]+/u', '_', (string)$row['fio']);
        $suffix = ($row['diploma_type'] ?? 'contest_participant') === 'encouragement' ? 'thank' : 'participant';
        $zip->addFile($file, $safeName . '_' . $row['diploma_number'] . '_' . $suffix . '.pdf');
    }

    $zip->close();
    return $zipRelative;
}

// Backward compatibility
function getContestDiplomaTemplate(int $contestId): array {
    return getDiplomaTemplateForContest($contestId, 'contest_participant');
}

function saveContestDiplomaTemplate(int $contestId, array $data): void {
    $template = getDiplomaTemplateForContest($contestId, 'contest_participant');
    saveDiplomaTemplate((int)$template['id'], $data);
}

function getParticipantDiplomaContext(int $participantId): ?array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT application_id FROM participants WHERE id = ? LIMIT 1');
    $stmt->execute([$participantId]);
    $applicationId = (int)$stmt->fetchColumn();
    if ($applicationId <= 0) {
        return null;
    }
    ensureApplicationWorks($applicationId);
    $stmt = $pdo->prepare('SELECT id FROM works WHERE participant_id = ? LIMIT 1');
    $stmt->execute([$participantId]);
    $workId = (int)$stmt->fetchColumn();
    if ($workId <= 0) {
        return null;
    }
    return getWorkDiplomaContext($workId);
}

function generateParticipantDiploma(int $participantId, bool $forceRegenerate = false): array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT application_id FROM participants WHERE id = ? LIMIT 1');
    $stmt->execute([$participantId]);
    $applicationId = (int)$stmt->fetchColumn();
    if ($applicationId <= 0) {
        throw new RuntimeException('Участник не найден');
    }
    ensureApplicationWorks($applicationId);

    $stmt = $pdo->prepare('SELECT id FROM works WHERE participant_id = ? LIMIT 1');
    $stmt->execute([$participantId]);
    $workId = (int)$stmt->fetchColumn();
    if ($workId <= 0) {
        throw new RuntimeException('Работа участника не найдена');
    }

    // Исторически участник мог получать диплом без статуса работы; принимаем как accepted
    $pdo->prepare("UPDATE works SET status = CASE WHEN status = 'pending' THEN 'accepted' ELSE status END WHERE id = ?")->execute([$workId]);

    return generateWorkDiploma($workId, $forceRegenerate);
}

ensureDiplomaSchema();
