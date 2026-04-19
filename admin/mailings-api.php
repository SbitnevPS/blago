<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (!isAdmin()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$admin = getCurrentUser();
$adminId = (int) ($admin['id'] ?? 0);

$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput ?: '', true);
$action = (string) (($jsonInput['action'] ?? $_REQUEST['action'] ?? $_POST['action'] ?? '') ?: '');

if ($action === 'recipients') {
    $filters = mailingNormalizeFilters((array) ($jsonInput['filters'] ?? []));
    $selectionMode = in_array((string) ($jsonInput['selection_mode'] ?? 'all'), ['all', 'none'], true) ? (string) $jsonInput['selection_mode'] : 'all';
    $exclusions = array_values(array_unique(array_map('intval', (array) ($jsonInput['exclusions'] ?? []))));
    $inclusions = array_values(array_unique(array_map('intval', (array) ($jsonInput['inclusions'] ?? []))));
    $page = max(1, (int) ($jsonInput['page'] ?? 1));
    $perPage = max(1, min(200, (int) ($jsonInput['per_page'] ?? 20)));
    $query = trim((string) ($jsonInput['query'] ?? ''));

    $total = mailingCountRecipients($filters, $query);
    $items = mailingFetchRecipientsPage($filters, $query, $page, $perPage, $selectionMode, $exclusions, $inclusions);

    jsonResponse(['success' => true, 'total' => $total, 'items' => $items]);
}

if ($action === 'save' && isPostRequest()) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Неверный CSRF токен'], 403);
    }

    $id = max(0, (int) ($_POST['id'] ?? 0));
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body = (string) ($_POST['body'] ?? '');
    $filters = mailingNormalizeFilters((array) json_decode((string) ($_POST['filters_json'] ?? '{}'), true));
    $selectionMode = in_array((string) ($_POST['selection_mode'] ?? 'all'), ['all', 'none'], true) ? (string) $_POST['selection_mode'] : 'all';
    $exclusionsJson = json_encode(array_values(array_unique(array_map('intval', (array) json_decode((string) ($_POST['exclusions_json'] ?? '[]'), true)))), JSON_UNESCAPED_UNICODE);
    $inclusionsJson = json_encode(array_values(array_unique(array_map('intval', (array) json_decode((string) ($_POST['inclusions_json'] ?? '[]'), true)))), JSON_UNESCAPED_UNICODE);
    $status = in_array((string) ($_POST['status'] ?? 'saved'), ['draft', 'saved', 'running', 'stopped', 'completed'], true) ? (string) $_POST['status'] : 'saved';

    if ($subject === '') {
        jsonResponse(['success' => false, 'message' => 'Укажите тему письма.'], 422);
    }

    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE mailing_campaigns SET subject = ?, body = ?, filters_json = ?, include_blacklist = ?, contest_id = ?, min_participants = ?, selection_mode = ?, exclusions_json = ?, inclusions_json = ?, total_recipients = ?, status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([
            $subject,
            $body,
            json_encode($filters, JSON_UNESCAPED_UNICODE),
            (int) $filters['include_blacklist'],
            (int) $filters['contest_id'] > 0 ? (int) $filters['contest_id'] : null,
            (int) $filters['min_participants'],
            $selectionMode,
            $exclusionsJson,
            $inclusionsJson,
            max(0, (int) ($_POST['total_recipients'] ?? 0)),
            $status,
            $id,
        ]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO mailing_campaigns (subject, body, filters_json, include_blacklist, contest_id, min_participants, selection_mode, exclusions_json, inclusions_json, total_recipients, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $subject,
            $body,
            json_encode($filters, JSON_UNESCAPED_UNICODE),
            (int) $filters['include_blacklist'],
            (int) $filters['contest_id'] > 0 ? (int) $filters['contest_id'] : null,
            (int) $filters['min_participants'],
            $selectionMode,
            $exclusionsJson,
            $inclusionsJson,
            max(0, (int) ($_POST['total_recipients'] ?? 0)),
            $status,
            $adminId,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    $deleteIds = array_values(array_filter(array_map('intval', (array) ($_POST['delete_attachment_ids'] ?? [])), static fn($v) => $v > 0));
    if (!empty($deleteIds)) {
        $in = implode(',', array_fill(0, count($deleteIds), '?'));
        $stmt = $pdo->prepare("SELECT id, file_path FROM mailing_attachments WHERE mailing_id = ? AND id IN ($in)");
        $stmt->execute(array_merge([$id], $deleteIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $path = __DIR__ . '/../' . ltrim((string) ($row['file_path'] ?? ''), '/');
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $delStmt = $pdo->prepare("DELETE FROM mailing_attachments WHERE mailing_id = ? AND id IN ($in)");
        $delStmt->execute(array_merge([$id], $deleteIds));
    }

    if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'] ?? null)) {
        $allowedExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'zip', 'txt'];
        $dir = __DIR__ . '/../uploads/mailings';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        foreach ($_FILES['attachments']['name'] as $i => $originalName) {
            $tmp = (string) ($_FILES['attachments']['tmp_name'][$i] ?? '');
            $size = (int) ($_FILES['attachments']['size'][$i] ?? 0);
            $error = (int) ($_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK || $tmp === '' || $size <= 0 || $size > 10 * 1024 * 1024) {
                continue;
            }

            $ext = mb_strtolower((string) pathinfo((string) $originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                continue;
            }

            $stored = 'mailing_' . $id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $target = $dir . '/' . $stored;
            if (!move_uploaded_file($tmp, $target)) {
                continue;
            }

            $relativePath = 'uploads/mailings/' . $stored;
            $mime = detectMailFileMimeType($target);
            $stmt = $pdo->prepare('INSERT INTO mailing_attachments (mailing_id, file_path, original_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$id, $relativePath, (string) $originalName, $mime, filesize($target) ?: $size]);
        }
    }

    jsonResponse(['success' => true, 'id' => $id]);
}

if ($action === 'start') {
    $id = max(0, (int) ($jsonInput['id'] ?? 0));
    if ($id <= 0) {
        jsonResponse(['success' => false, 'message' => 'Не найдена рассылка'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM mailing_campaigns WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $mailing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mailing) {
        jsonResponse(['success' => false, 'message' => 'Рассылка не найдена'], 404);
    }

    $recipients = mailingResolveRecipientIds($mailing);
    $total = count($recipients);

    $pdo->prepare('DELETE FROM mailing_send_logs WHERE mailing_id = ?')->execute([$id]);
    $pdo->prepare('UPDATE mailing_campaigns SET status = ?, sent_count = 0, failed_count = 0, total_recipients = ?, started_at = NOW(), completed_at = NULL WHERE id = ?')
        ->execute(['running', $total, $id]);

    jsonResponse(['success' => true, 'total' => $total]);
}

if ($action === 'process_batch') {
    $id = max(0, (int) ($jsonInput['id'] ?? 0));
    $batchSize = 25;

    $stmt = $pdo->prepare('SELECT * FROM mailing_campaigns WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $mailing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mailing) {
        jsonResponse(['success' => false, 'message' => 'Рассылка не найдена'], 404);
    }

    if ((string) $mailing['status'] === 'stopped') {
        jsonResponse(['success' => true, 'status' => 'stopped', 'total' => (int) $mailing['total_recipients'], 'sent' => (int) $mailing['sent_count'], 'failed' => (int) $mailing['failed_count']]);
    }

    $allRecipients = mailingResolveRecipientIds($mailing);
    $sentIds = $pdo->prepare('SELECT user_id FROM mailing_send_logs WHERE mailing_id = ?');
    $sentIds->execute([$id]);
    $already = array_fill_keys(array_map('intval', $sentIds->fetchAll(PDO::FETCH_COLUMN) ?: []), true);

    $remaining = [];
    foreach ($allRecipients as $recipient) {
        if (!isset($already[(int) $recipient['id']])) {
            $remaining[] = $recipient;
        }
    }

    $currentBatch = array_slice($remaining, 0, $batchSize);

    $attStmt = $pdo->prepare('SELECT * FROM mailing_attachments WHERE mailing_id = ? ORDER BY id ASC');
    $attStmt->execute([$id]);
    $attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $sendAttachments = [];
    foreach ($attachments as $a) {
        $path = __DIR__ . '/../' . ltrim((string) ($a['file_path'] ?? ''), '/');
        if (is_file($path)) {
            $sendAttachments[] = ['path' => $path, 'name' => (string) ($a['original_name'] ?? basename($path))];
        }
    }

    $success = 0;
    $failed = 0;
    foreach ($currentBatch as $recipient) {
        $email = trim((string) ($recipient['email'] ?? ''));
        $userId = (int) ($recipient['id'] ?? 0);
        if ($email === '' || $userId <= 0) {
            continue;
        }

        $ok = sendEmail($email, (string) ($mailing['subject'] ?? ''), (string) ($mailing['body'] ?? ''), ['attachments' => $sendAttachments]);

        $logStmt = $pdo->prepare('INSERT INTO mailing_send_logs (mailing_id, user_id, email, send_status, error_message, sent_at) VALUES (?, ?, ?, ?, ?, ?)');
        if ($ok) {
            $success++;
            $logStmt->execute([$id, $userId, $email, 'sent', null, date('Y-m-d H:i:s')]);
        } else {
            $failed++;
            $logStmt->execute([$id, $userId, $email, 'failed', 'sendEmail вернул false', null]);
        }
    }

    $sentCount = (int) $mailing['sent_count'] + $success;
    $failedCount = (int) $mailing['failed_count'] + $failed;
    $total = (int) $mailing['total_recipients'];

    $isDone = count($remaining) <= $batchSize;
    $newStatus = $isDone ? 'completed' : 'running';
    $pdo->prepare('UPDATE mailing_campaigns SET sent_count = ?, failed_count = ?, status = ?, completed_at = ? WHERE id = ?')
        ->execute([$sentCount, $failedCount, $newStatus, $isDone ? date('Y-m-d H:i:s') : null, $id]);

    jsonResponse([
        'success' => true,
        'status' => $newStatus,
        'total' => $total,
        'sent' => $sentCount,
        'failed' => $failedCount,
    ]);
}

if ($action === 'stop') {
    $id = max(0, (int) ($jsonInput['id'] ?? 0));
    if ($id > 0) {
        $pdo->prepare('UPDATE mailing_campaigns SET status = ? WHERE id = ?')->execute(['stopped', $id]);
    }
    jsonResponse(['success' => true]);
}

if ($action === 'download') {
    $id = max(0, (int) ($_GET['id'] ?? 0));
    if ($id <= 0) {
        http_response_code(404);
        exit('Not found');
    }

    $stmt = $pdo->prepare('SELECT * FROM mailing_campaigns WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $mailing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mailing) {
        http_response_code(404);
        exit('Not found');
    }

    $recipients = mailingResolveRecipientIds($mailing);
    $emails = [];
    foreach ($recipients as $r) {
        $email = trim((string) ($r['email'] ?? ''));
        if ($email !== '') {
            $emails[$email] = true;
        }
    }

    $filename = 'mailing_' . $id . '_emails.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo implode("\n", array_keys($emails));
    exit;
}

jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
