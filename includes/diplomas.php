<?php

define('DIPLOMAS_PATH', UPLOAD_PATH . '/diplomas');
define('DIPLOMAS_WEB_PATH', '/uploads/diplomas');

function ensureDiplomaStorage(): void {
    if (!is_dir(DIPLOMAS_PATH)) {
        mkdir(DIPLOMAS_PATH, 0775, true);
    }
}

function splitDiplomaFio(string $fio): array {
    $parts = preg_split('/\s+/u', trim($fio)) ?: [];
    return [
        'surname' => $parts[0] ?? '',
        'name' => $parts[1] ?? '',
        'patronymic' => $parts[2] ?? '',
    ];
}

function diplomaTemplateTypes(): array {
    return [
        'contest_participant' => 'Диплом участника конкурса',
        'encouragement' => 'Благодарственный диплом',
        'winner' => 'Диплом победителя',
        'laureate' => 'Диплом лауреата',
        'nomination' => 'Диплом номинации',
    ];
}

function defaultDiplomaLayout(): array {
    return [
        'title' => ['x' => 7, 'y' => 7, 'w' => 86, 'h' => 10, 'font_family' => 'dejavusans', 'font_size' => 22, 'color' => '#B45309', 'align' => 'center', 'line_height' => 1.2, 'visible' => 1],
        'subtitle' => ['x' => 12, 'y' => 18, 'w' => 76, 'h' => 6, 'font_family' => 'dejavusans', 'font_size' => 12, 'color' => '#92400E', 'align' => 'center', 'line_height' => 1.2, 'visible' => 1],
        'award_text' => ['x' => 10, 'y' => 28, 'w' => 80, 'h' => 8, 'font_family' => 'dejavusans', 'font_size' => 11, 'color' => '#334155', 'align' => 'center', 'line_height' => 1.3, 'visible' => 1],
        'participant_name' => ['x' => 10, 'y' => 38, 'w' => 80, 'h' => 10, 'font_family' => 'dejavusans', 'font_size' => 18, 'color' => '#1D4ED8', 'align' => 'center', 'line_height' => 1.25, 'visible' => 1],
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

    return [
        'title' => $isEncouragement ? 'Благодарственный диплом' : 'Диплом',
        'subtitle' => 'Награждается',
        'body_text' => $isEncouragement
            ? 'за творческое старание, фантазию и яркое участие. Спасибо за ваш талант и вдохновение!'
            : 'за яркое творчество, доброе воображение и вдохновляющую работу в конкурсе детского рисунка.',
        'award_text' => $isEncouragement
            ? 'Памятный диплом юного художника вручается {participant_full_name}'
            : 'Награждается {participant_full_name}',
        'contest_name_text' => '{contest_title}',
        'easter_text' => $isEncouragement
            ? 'Работа рассмотрена. Спасибо за участие! Для вас доступен благодарственный диплом.'
            : 'Пусть каждая новая идея превращается в маленькое чудо!',
        'signature_1' => 'Председатель оргкомитета',
        'signature_2' => 'Куратор проекта',
        'position_1' => 'Оргкомитет конкурса',
        'position_2' => 'ДетскиеКонкурсы.рф',
        'footer_text' => $isEncouragement
            ? 'Спасибо за участие! Продолжайте рисовать и радовать мир своими работами.'
            : 'Спасибо за участие! Продолжайте творить и вдохновлять.',
        'city' => 'Москва',
        'issue_date' => date('Y-m-d'),
        'diploma_prefix' => $isEncouragement ? 'THANK' : 'PART',
        'show_date' => 1,
        'show_number' => 1,
        'show_signatures' => 1,
        'show_background' => 1,
        'show_frame' => 1,
        'layout_json' => json_encode(defaultDiplomaLayout(), JSON_UNESCAPED_UNICODE),
        'styles_json' => json_encode(['background' => 'soft', 'frame' => 'gold', 'accent_color' => '#7C3AED'], JSON_UNESCAPED_UNICODE),
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
            status ENUM('pending','accepted','reviewed') NOT NULL DEFAULT 'pending',
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
                title = COALESCE(NULLIF(VALUES(title), ''), works.title),
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
        'accepted' => 'Принята к участию',
        'reviewed' => 'Работа рассмотрена',
    ];
    return $map[$status] ?? ucfirst($status);
}

function getWorkStatusBadgeClass(string $status): string {
    $map = [
        'pending' => 'badge--warning',
        'accepted' => 'badge--success',
        'reviewed' => 'badge--secondary',
    ];
    return $map[$status] ?? 'badge--secondary';
}

function mapWorkStatusToDiplomaType(string $status): ?string {
    if ($status === 'accepted') {
        return 'contest_participant';
    }
    if ($status === 'reviewed') {
        return 'encouragement';
    }
    return null;
}

function updateWorkStatus(int $workId, string $status): bool {
    global $pdo;
    if (!in_array($status, ['pending', 'accepted', 'reviewed'], true)) {
        return false;
    }
    $stmt = $pdo->prepare("UPDATE works SET status = ?, reviewed_at = CASE WHEN ? = 'pending' THEN NULL ELSE NOW() END, updated_at = NOW() WHERE id = ?");
    return $stmt->execute([$status, $status, $workId]);
}

function aggregateApplicationStatusByWorks(int $applicationId): string {
    $works = getApplicationWorks($applicationId);
    if (!$works) {
        return 'draft';
    }

    $counts = ['pending' => 0, 'accepted' => 0, 'reviewed' => 0];
    foreach ($works as $w) {
        $st = (string)($w['status'] ?? 'pending');
        if (isset($counts[$st])) {
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
    $stmt = $pdo->prepare('SELECT * FROM diploma_templates WHERE contest_id = ? AND template_type = ? LIMIT 1');
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

function listContestDiplomaTemplates(int $contestId): array {
    global $pdo;
    ensureDiplomaSchema();

    $stmt = $pdo->prepare("SELECT * FROM diploma_templates WHERE contest_id = ? ORDER BY updated_at DESC");
    $stmt->execute([$contestId]);
    $rows = $stmt->fetchAll() ?: [];

    // гарантируем наличие минимум двух типов
    foreach (['contest_participant', 'encouragement'] as $type) {
        $exists = false;
        foreach ($rows as $r) {
            if (($r['template_type'] ?? '') === $type) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $rows[] = getOrCreateDiplomaTemplate($contestId, $type);
        }
    }

    return $rows;
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
        p.fio,
        p.age,
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
        '{nomination}' => (string)($vars['nomination'] ?? 'Творческая номинация'),
        '{age_category}' => (string)($vars['age_category'] ?? ''),
        '{city}' => (string)($vars['city'] ?? ''),
    ]);
}

function diplomaItemCss(array $cfg): string {
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

function buildDiplomaHtml(array $ctx, array $template, array $diplomaRow): string {
    $vars = [
        'participant_name' => $ctx['participant_name'],
        'participant_full_name' => $ctx['participant_full_name'],
        'contest_title' => $ctx['contest_title'],
        'award_title' => $ctx['award_title'] ?? 'Лауреат',
        'place' => $ctx['place'] ?? 'Участник',
        'date' => !empty($template['issue_date']) ? date('d.m.Y', strtotime((string)$template['issue_date'])) : date('d.m.Y'),
        'diploma_number' => $diplomaRow['diploma_number'],
        'nomination' => $ctx['nomination'] ?? 'Творческая номинация',
        'age_category' => !empty($ctx['age']) ? ('Возрастная категория: ' . (int)$ctx['age'] . '+') : '',
        'city' => (string)($template['city'] ?? 'Москва'),
    ];

    $layout = json_decode((string)($template['layout_json'] ?? ''), true);
    if (!is_array($layout) || !$layout) {
        $layout = defaultDiplomaLayout();
    }

    $bg = (int)$template['show_background'] === 1
        ? 'background: linear-gradient(180deg,#FFF9E6 0%, #F7FBFF 50%, #FFF1F2 100%);'
        : 'background:#FFFFFF;';
    $frame = (int)$template['show_frame'] === 1
        ? 'border:10px solid #EAB308; box-shadow: inset 0 0 0 4px #FDE68A;'
        : 'border:1px solid #E5E7EB;';

    $mapText = [
        'title' => (string)($template['title'] ?? ''),
        'subtitle' => (string)($template['subtitle'] ?? ''),
        'participant_name' => (string)($ctx['participant_full_name'] ?? ''),
        'body_text' => (string)($template['body_text'] ?? ''),
        'award_text' => (string)($template['award_text'] ?? ''),
        'contest_title' => (string)($template['contest_name_text'] ?? '{contest_title}'),
        'diploma_number' => '№ ' . (string)($diplomaRow['diploma_number'] ?? ''),
        'date' => $vars['date'],
        'city' => (string)($template['city'] ?? ''),
        'signatures' => (string)($template['signature_1'] ?? '') . ' / ' . (string)($template['signature_2'] ?? ''),
        'footer' => (string)($template['footer_text'] ?? ''),
    ];

    $html = '<div style="width:100%;height:100%;' . $bg . $frame . 'padding:0;box-sizing:border-box;font-family:dejavusans;position:relative;">';
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

        $raw = $mapText[$key] ?? '';
        $text = nl2br(e(renderDiplomaVars($raw, $vars)));
        $html .= '<div style="' . diplomaItemCss($cfg) . '">' . $text . '</div>';
    }

    $html .= '</div>';
    return $html;
}

function generateWorkDiploma(int $workId, bool $forceRegenerate = false): array {
    ensureDiplomaStorage();
    ensureDiplomaSchema();

    $ctx = getWorkDiplomaContext($workId);
    if (!$ctx) {
        throw new RuntimeException('Работа не найдена');
    }

    $diplomaType = mapWorkStatusToDiplomaType((string)$ctx['work_status']);
    if ($diplomaType === null) {
        throw new RuntimeException('Для работы в статусе "На рассмотрении" диплом недоступен');
    }

    $template = getDiplomaTemplateForContest((int)$ctx['contest_id'], $diplomaType);
    $diploma = getOrCreateWorkDiploma($ctx, $template, $diplomaType);

    if (!$forceRegenerate && !empty($diploma['file_path']) && is_file(ROOT_PATH . '/' . $diploma['file_path'])) {
        return $diploma;
    }

    if (!class_exists('Mpdf\\Mpdf')) {
        throw new RuntimeException('mPDF не установлен. Выполните: composer require mpdf/mpdf');
    }

    $fileName = 'diploma_' . (int)$ctx['contest_id'] . '_' . (int)$ctx['application_id'] . '_' . (int)$ctx['participant_id'] . '_' . (int)$ctx['work_id'] . '.pdf';
    $relativePath = trim(DIPLOMAS_WEB_PATH . '/' . $fileName, '/');
    $absolutePath = ROOT_PATH . '/' . $relativePath;

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 8,
        'margin_right' => 8,
        'margin_top' => 8,
        'margin_bottom' => 8,
        'tempDir' => ROOT_PATH . '/storage/mpdf',
    ]);
    $mpdf->WriteHTML(buildDiplomaHtml($ctx, $template, $diploma));
    $mpdf->Output($absolutePath, \Mpdf\Output\Destination::FILE);

    global $pdo;
    $pdo->prepare('UPDATE participant_diplomas SET file_path = ?, generated_at = NOW(), updated_at = NOW(), template_id = ?, diploma_type = ? WHERE id = ?')
        ->execute([$relativePath, (int)$template['id'], $diplomaType, (int)$diploma['id']]);

    $stmt = $pdo->prepare('SELECT * FROM participant_diplomas WHERE id = ?');
    $stmt->execute([(int)$diploma['id']]);
    return (array)$stmt->fetch();
}

function getPublicDiplomaUrl(string $token): string {
    return SITE_URL . '/diploma/' . urlencode($token);
}

function sendDiplomaByEmail(array $ctx, array $diploma): bool {
    global $pdo;
    $to = trim((string)($ctx['user_email'] ?? ''));
    $file = ROOT_PATH . '/' . ltrim((string)($diploma['file_path'] ?? ''), '/');
    if ($to === '' || !is_file($file)) {
        return false;
    }

    $diplomaType = (string)($diploma['diploma_type'] ?? 'contest_participant');
    $subject = $diplomaType === 'encouragement'
        ? 'Ваш благодарственный диплом'
        : 'Ваш диплом участника конкурса';

    $boundary = '==Multipart_Boundary_x' . md5((string)microtime()) . 'x';
    $headers = "From: no-reply@kids-contests.ru\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    if ($diplomaType === 'encouragement') {
        $body .= "Здравствуйте!\nРабота рассмотрена. Спасибо за участие! Для вас доступен благодарственный диплом: " . getPublicDiplomaUrl((string)$diploma['public_token']) . "\n\n";
    } else {
        $body .= "Здравствуйте!\nВаш диплом участника готов. Публичная ссылка: " . getPublicDiplomaUrl((string)$diploma['public_token']) . "\n\n";
    }

    $content = chunk_split(base64_encode((string)file_get_contents($file)));
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: application/pdf; name=\"diploma.pdf\"\r\n";
    $body .= "Content-Disposition: attachment; filename=\"diploma.pdf\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= $content . "\r\n";
    $body .= "--{$boundary}--";

    $ok = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    if ($ok) {
        $pdo->prepare('UPDATE participant_diplomas SET email_sent_at = NOW(), updated_at = NOW() WHERE id = ?')->execute([(int)$diploma['id']]);
    }

    return $ok;
}

function collectApplicationDiplomaLinks(int $applicationId): array {
    global $pdo;
    ensureApplicationWorks($applicationId);

    $stmt = $pdo->prepare("SELECT pd.*, p.fio, w.id AS work_id
        FROM participant_diplomas pd
        INNER JOIN participants p ON p.id = pd.participant_id
        LEFT JOIN works w ON w.id = pd.work_id
        WHERE pd.application_id = ?
        ORDER BY pd.id ASC");
    $stmt->execute([$applicationId]);
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

function buildApplicationDiplomaZip(int $applicationId): string {
    ensureDiplomaStorage();
    global $pdo;
    $stmt = $pdo->prepare("SELECT pd.file_path, pd.participant_id, pd.diploma_number, pd.diploma_type, p.fio
        FROM participant_diplomas pd
        INNER JOIN participants p ON p.id = pd.participant_id
        WHERE pd.application_id = ?");
    $stmt->execute([$applicationId]);
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
