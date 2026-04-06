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

function diplomaTemplateDefaults(): array {
    return [
        'title' => 'Диплом',
        'subtitle' => 'Награждается',
        'body_text' => 'за яркое творчество, доброе воображение и вдохновляющую работу в конкурсе детского рисунка.',
        'award_text' => 'Награждается {participant_full_name}',
        'contest_name_text' => '{contest_title}',
        'easter_text' => 'Пусть пасхальное настроение дарит радость, а каждая новая идея превращается в маленькое чудо!',
        'signature_1' => 'Председатель оргкомитета',
        'signature_2' => 'Куратор проекта',
        'position_1' => 'Оргкомитет конкурса',
        'position_2' => 'ДетскиеКонкурсы.рф',
        'footer_text' => 'Спасибо за участие! Продолжайте творить и вдохновлять.',
        'city' => 'Москва',
        'issue_date' => date('Y-m-d'),
        'diploma_prefix' => 'EASTER',
        'show_date' => 1,
        'show_number' => 1,
        'show_signatures' => 1,
        'show_background' => 1,
        'show_frame' => 1,
    ];
}

function getContestDiplomaTemplate(int $contestId): array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM contest_diploma_templates WHERE contest_id = ? LIMIT 1');
    $stmt->execute([$contestId]);
    $row = $stmt->fetch();
    return $row ? array_merge(diplomaTemplateDefaults(), $row) : diplomaTemplateDefaults();
}

function saveContestDiplomaTemplate(int $contestId, array $data): void {
    global $pdo;
    $defaults = diplomaTemplateDefaults();
    $payload = [
        'title' => trim((string)($data['title'] ?? $defaults['title'])),
        'subtitle' => trim((string)($data['subtitle'] ?? $defaults['subtitle'])),
        'body_text' => trim((string)($data['body_text'] ?? $defaults['body_text'])),
        'award_text' => trim((string)($data['award_text'] ?? $defaults['award_text'])),
        'contest_name_text' => trim((string)($data['contest_name_text'] ?? $defaults['contest_name_text'])),
        'easter_text' => trim((string)($data['easter_text'] ?? $defaults['easter_text'])),
        'signature_1' => trim((string)($data['signature_1'] ?? $defaults['signature_1'])),
        'signature_2' => trim((string)($data['signature_2'] ?? $defaults['signature_2'])),
        'position_1' => trim((string)($data['position_1'] ?? $defaults['position_1'])),
        'position_2' => trim((string)($data['position_2'] ?? $defaults['position_2'])),
        'footer_text' => trim((string)($data['footer_text'] ?? $defaults['footer_text'])),
        'city' => trim((string)($data['city'] ?? $defaults['city'])),
        'issue_date' => trim((string)($data['issue_date'] ?? $defaults['issue_date'])),
        'diploma_prefix' => trim((string)($data['diploma_prefix'] ?? $defaults['diploma_prefix'])),
        'show_date' => isset($data['show_date']) ? 1 : 0,
        'show_number' => isset($data['show_number']) ? 1 : 0,
        'show_signatures' => isset($data['show_signatures']) ? 1 : 0,
        'show_background' => isset($data['show_background']) ? 1 : 0,
        'show_frame' => isset($data['show_frame']) ? 1 : 0,
    ];

    $exists = $pdo->prepare('SELECT id FROM contest_diploma_templates WHERE contest_id = ? LIMIT 1');
    $exists->execute([$contestId]);
    $id = (int)$exists->fetchColumn();

    if ($id > 0) {
        $sql = "UPDATE contest_diploma_templates SET title=?, subtitle=?, body_text=?, award_text=?, contest_name_text=?, easter_text=?, signature_1=?, signature_2=?, position_1=?, position_2=?, footer_text=?, city=?, issue_date=?, diploma_prefix=?, show_date=?, show_number=?, show_signatures=?, show_background=?, show_frame=?, updated_at=NOW() WHERE contest_id=?";
        $pdo->prepare($sql)->execute([
            $payload['title'], $payload['subtitle'], $payload['body_text'], $payload['award_text'], $payload['contest_name_text'],
            $payload['easter_text'], $payload['signature_1'], $payload['signature_2'], $payload['position_1'], $payload['position_2'],
            $payload['footer_text'], $payload['city'], $payload['issue_date'], $payload['diploma_prefix'],
            $payload['show_date'], $payload['show_number'], $payload['show_signatures'], $payload['show_background'], $payload['show_frame'],
            $contestId,
        ]);
    } else {
        $sql = "INSERT INTO contest_diploma_templates (contest_id, title, subtitle, body_text, award_text, contest_name_text, easter_text, signature_1, signature_2, position_1, position_2, footer_text, city, issue_date, diploma_prefix, show_date, show_number, show_signatures, show_background, show_frame, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        $pdo->prepare($sql)->execute([
            $contestId, $payload['title'], $payload['subtitle'], $payload['body_text'], $payload['award_text'], $payload['contest_name_text'],
            $payload['easter_text'], $payload['signature_1'], $payload['signature_2'], $payload['position_1'], $payload['position_2'],
            $payload['footer_text'], $payload['city'], $payload['issue_date'], $payload['diploma_prefix'],
            $payload['show_date'], $payload['show_number'], $payload['show_signatures'], $payload['show_background'], $payload['show_frame'],
        ]);
    }
}

function getParticipantDiplomaContext(int $participantId): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT p.*, a.id AS application_id, a.user_id, a.contest_id, c.title AS contest_title, u.email AS user_email
        FROM participants p
        INNER JOIN applications a ON a.id = p.application_id
        INNER JOIN contests c ON c.id = a.contest_id
        INNER JOIN users u ON u.id = a.user_id
        WHERE p.id = ? LIMIT 1");
    $stmt->execute([$participantId]);
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

function getOrCreateParticipantDiploma(array $ctx, array $template): array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM participant_diplomas WHERE participant_id = ? LIMIT 1');
    $stmt->execute([(int)$ctx['id']]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    $number = nextDiplomaNumber((int)$ctx['contest_id'], (string)$template['diploma_prefix']);
    $token = bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO participant_diplomas (contest_id, application_id, participant_id, user_id, diploma_number, public_token, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())")
        ->execute([(int)$ctx['contest_id'], (int)$ctx['application_id'], (int)$ctx['id'], (int)$ctx['user_id'], $number, $token]);
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
    ]);
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
    ];

    $bg = (int)$template['show_background'] === 1
        ? 'background: linear-gradient(180deg,#FFF9E6 0%, #F7FBFF 50%, #FFF1F2 100%);'
        : 'background:#FFFFFF;';
    $frame = (int)$template['show_frame'] === 1
        ? 'border:10px solid #EAB308; box-shadow: inset 0 0 0 4px #FDE68A;'
        : 'border:1px solid #E5E7EB;';

    return '<div style="width:100%;height:100%;' . $bg . $frame . 'padding:34px 38px;box-sizing:border-box;font-family:dejavusans;">'
        . '<div style="text-align:center;font-size:42px;color:#B45309;font-weight:bold;">' . e(renderDiplomaVars((string)$template['title'], $vars)) . '</div>'
        . '<div style="text-align:center;font-size:20px;color:#92400E;margin-top:6px;">' . e(renderDiplomaVars((string)$template['subtitle'], $vars)) . '</div>'
        . '<div style="text-align:center;font-size:18px;margin-top:20px;color:#334155;">' . e(renderDiplomaVars((string)$template['award_text'], $vars)) . '</div>'
        . '<div style="text-align:center;font-size:34px;margin-top:14px;font-weight:bold;color:#1D4ED8;">' . e($ctx['participant_full_name']) . '</div>'
        . '<div style="text-align:center;font-size:17px;margin-top:18px;line-height:1.55;color:#334155;">' . nl2br(e(renderDiplomaVars((string)$template['body_text'], $vars))) . '</div>'
        . '<div style="text-align:center;font-size:25px;margin-top:20px;font-weight:700;color:#7C3AED;">' . e(renderDiplomaVars((string)$template['contest_name_text'], $vars)) . '</div>'
        . '<div style="text-align:center;font-size:16px;margin-top:20px;color:#BE185D;line-height:1.45;">' . nl2br(e(renderDiplomaVars((string)$template['easter_text'], $vars))) . '</div>'
        . '<div style="margin-top:28px;text-align:center;font-size:13px;color:#475569;">🥚 🐣 🌷</div>'
        . ((int)$template['show_signatures'] === 1 ? '<table style="width:100%;margin-top:34px;font-size:13px;color:#334155;"><tr><td style="text-align:left;width:50%;"><div style="font-weight:600;">' . e((string)$template['signature_1']) . '</div><div>' . e((string)$template['position_1']) . '</div></td><td style="text-align:right;width:50%;"><div style="font-weight:600;">' . e((string)$template['signature_2']) . '</div><div>' . e((string)$template['position_2']) . '</div></td></tr></table>' : '')
        . '<table style="width:100%;margin-top:20px;font-size:12px;color:#64748B;"><tr><td style="text-align:left;">' . e((string)$template['city']) . '</td><td style="text-align:center;">' . e((string)$template['footer_text']) . '</td><td style="text-align:right;">'
        . ((int)$template['show_date'] === 1 ? e($vars['date']) : '') . ((int)$template['show_number'] === 1 ? ' № ' . e((string)$diplomaRow['diploma_number']) : '')
        . '</td></tr></table>'
        . '</div>';
}

function generateParticipantDiploma(int $participantId, bool $forceRegenerate = false): array {
    ensureDiplomaStorage();
    $ctx = getParticipantDiplomaContext($participantId);
    if (!$ctx) {
        throw new RuntimeException('Участник не найден');
    }

    $template = getContestDiplomaTemplate((int)$ctx['contest_id']);
    $diploma = getOrCreateParticipantDiploma($ctx, $template);
    if (!$forceRegenerate && !empty($diploma['file_path']) && is_file(ROOT_PATH . '/' . $diploma['file_path'])) {
        return $diploma;
    }

    if (!class_exists('Mpdf\\Mpdf')) {
        throw new RuntimeException('mPDF не установлен. Выполните: composer require mpdf/mpdf');
    }

    $fileName = 'diploma_' . (int)$ctx['contest_id'] . '_' . (int)$ctx['application_id'] . '_' . (int)$ctx['id'] . '.pdf';
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
    $pdo->prepare('UPDATE participant_diplomas SET file_path = ?, generated_at = NOW(), updated_at = NOW() WHERE id = ?')
        ->execute([$relativePath, (int)$diploma['id']]);

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

    $subject = 'Ваш диплом участника конкурса';
    $boundary = '==Multipart_Boundary_x' . md5((string)microtime()) . 'x';
    $headers = "From: no-reply@kids-contests.ru\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= "Здравствуйте!\nВаш диплом готов. Публичная ссылка: " . getPublicDiplomaUrl((string)$diploma['public_token']) . "\n\n";

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
    $stmt = $pdo->prepare("SELECT pd.*, p.fio FROM participant_diplomas pd INNER JOIN participants p ON p.id = pd.participant_id WHERE pd.application_id = ? ORDER BY pd.id ASC");
    $stmt->execute([$applicationId]);
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'participant' => (string)$row['fio'],
            'url' => getPublicDiplomaUrl((string)$row['public_token']),
        ];
    }
    return $result;
}

function buildApplicationDiplomaZip(int $applicationId): string {
    ensureDiplomaStorage();
    global $pdo;
    $stmt = $pdo->prepare("SELECT pd.file_path, pd.participant_id, pd.diploma_number, p.fio FROM participant_diplomas pd INNER JOIN participants p ON p.id = pd.participant_id WHERE pd.application_id = ?");
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
        $zip->addFile($file, $safeName . '_' . $row['diploma_number'] . '.pdf');
    }

    $zip->close();
    return $zipRelative;
}
