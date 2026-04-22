<?php
// messages.php - Сообщения пользователя
require_once dirname(__DIR__, 3) . '/config.php';

// Проверка авторизации
if (!isAuthenticated()) {
 redirect('/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/contests'));
}

$userId = getCurrentUserId();
$user = getCurrentUser();

if (!function_exists('messageAttachmentInsertPayload')) {
    function messageAttachmentInsertPayload(?array $uploadResult): array
    {
        if (empty($uploadResult['uploaded'])) {
            return [null, null, null, 0];
        }

        return [
            (string) ($uploadResult['file_name'] ?? ''),
            (string) ($uploadResult['original_name'] ?? ''),
            (string) ($uploadResult['mime_type'] ?? ''),
            (int) ($uploadResult['file_size'] ?? 0),
        ];
    }
}

if (!function_exists('markUserThreadMessagesAsRead')) {
    function markUserThreadMessagesAsRead(PDO $pdo, int $userId, int $applicationId, string $threadTitle): void
    {
        if ($userId <= 0 || $applicationId <= 0 || $threadTitle === '') {
            return;
        }

        $markReadStmt = $pdo->prepare("
            UPDATE messages m
            JOIN users u ON u.id = m.created_by
            SET m.is_read = 1
            WHERE m.user_id = ?
              AND m.application_id = ?
              AND m.title = ?
              AND m.is_read = 0
              AND u.is_admin = 1
        ");
        $markReadStmt->execute([$userId, $applicationId, $threadTitle]);
    }
}

// Получаем сообщения из admin_messages (сообщения от администрации)
$stmt = $pdo->prepare("
 SELECT am.id, am.user_id, am.subject, am.message, am.priority, am.is_read, am.created_at, am.admin_id, am.is_broadcast, am.attachment_file, am.attachment_original_name, am.attachment_mime_type
 FROM admin_messages am
 WHERE am.user_id = ?
 ORDER BY am.created_at DESC
 LIMIT 50
");
$stmt->execute([$userId]);
$messages = $stmt->fetchAll();

$threadChats = [];
$legacyDisputeApplicationId = max(0, intval($_REQUEST['dispute_application_id'] ?? 0));
$selectedChatApplicationId = max(0, intval($_REQUEST['chat_application_id'] ?? $legacyDisputeApplicationId));
$selectedChatTitle = trim((string) ($_REQUEST['chat_title'] ?? ''));
$selectedChatMessages = [];
$selectedChatClosed = false;
$selectedChatType = detectMessageThreadType($selectedChatTitle);
$selectedChatReplyAllowed = false;
try {
    $chatStmt = $pdo->prepare("
    SELECT
        m.application_id,
        m.title,
        c.title AS contest_title,
        MAX(IFNULL(a.dispute_chat_closed, 0)) AS thread_chat_closed,
        MAX(m.created_at) AS last_message_at,
        SUBSTRING_INDEX(
            GROUP_CONCAT(m.content ORDER BY m.created_at DESC SEPARATOR '||__||'),
            '||__||',
            1
        ) AS last_message,
        SUM(CASE WHEN m.is_read = 0 AND u.is_admin = 1 THEN 1 ELSE 0 END) AS unread_count
    FROM messages m
    JOIN users u ON u.id = m.created_by
    LEFT JOIN applications a ON a.id = m.application_id
    LEFT JOIN contests c ON c.id = a.contest_id
    WHERE m.user_id = ?
    GROUP BY m.application_id, m.title, c.title
    ORDER BY last_message_at DESC
    ");
    $chatStmt->execute([$userId]);
    $threadChats = $chatStmt->fetchAll();
} catch (Exception $e) {
    $threadChats = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) ($_POST['action'] ?? ''), ['reply_chat', 'dispute_reply'], true)) {
    $isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (string) ($_POST['ajax'] ?? '') === '1';
    $selectedChatApplicationId = max(0, intval($_POST['chat_application_id'] ?? ($_POST['dispute_application_id'] ?? 0)));
    $selectedChatTitle = trim((string) ($_POST['chat_title'] ?? ''));
    if ($selectedChatTitle === '' && (string) ($_POST['action'] ?? '') === 'dispute_reply') {
        $selectedChatTitle = buildDisputeChatTitle($selectedChatApplicationId);
    }
    $selectedChatType = detectMessageThreadType($selectedChatTitle);
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Ошибка безопасности. Обновите страницу.';
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 403);
        }
    } elseif ($selectedChatApplicationId <= 0 || $selectedChatTitle === '') {
        $_SESSION['error_message'] = 'Чат по заявке не найден.';
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 422);
        }
    } else {
        $chatMessage = trim((string) ($_POST['chat_message'] ?? ($_POST['dispute_reason'] ?? '')));
        $attachmentUpload = uploadMessageAttachment($_FILES['attachment'] ?? []);
        try {
            $applicationStmt = $pdo->prepare("
                SELECT id, status, dispute_chat_closed
                FROM applications
                WHERE id = ? AND user_id = ?
                LIMIT 1
            ");
            $applicationStmt->execute([$selectedChatApplicationId, $userId]);
            $applicationRow = $applicationStmt->fetch();

            if (!$applicationRow) {
                $_SESSION['error_message'] = 'Заявка не найдена.';
                if ($isAjaxRequest) {
                    jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 404);
                }
            } elseif ($selectedChatType === 'dispute' && !in_array((string) $applicationRow['status'], ['declined', 'rejected'], true)) {
                $_SESSION['error_message'] = 'Отправка сообщений доступна только для отклонённых заявок.';
                if ($isAjaxRequest) {
                    jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 422);
                }
            } elseif ((int) ($applicationRow['dispute_chat_closed'] ?? 0) === 1) {
                $_SESSION['error_message'] = 'Чат завершён администратором.';
                if ($isAjaxRequest) {
                    jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 423);
                }
            } elseif (empty($attachmentUpload['success'])) {
                $_SESSION['error_message'] = (string) ($attachmentUpload['message'] ?? 'Не удалось загрузить вложение.');
                if ($isAjaxRequest) {
                    jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 422);
                }
            } elseif ($chatMessage === '' && empty($attachmentUpload['uploaded'])) {
                $_SESSION['error_message'] = 'Введите сообщение для администратора или прикрепите файл.';
                if ($isAjaxRequest) {
                    jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 422);
                }
            } else {
                [$attachmentFile, $attachmentOriginalName, $attachmentMimeType, $attachmentSize] = messageAttachmentInsertPayload($attachmentUpload);
                $insertStmt = $pdo->prepare("
                    INSERT INTO messages (
                        user_id,
                        application_id,
                        title,
                        content,
                        created_by,
                        created_at,
                        attachment_file,
                        attachment_original_name,
                        attachment_mime_type,
                        attachment_size
                    )
                    VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)
                ");
                $insertStmt->execute([
                    $userId,
                    $selectedChatApplicationId,
                    $selectedChatTitle,
                    $chatMessage,
                    $userId,
                    $attachmentFile,
                    $attachmentOriginalName,
                    $attachmentMimeType,
                    $attachmentSize,
                ]);
                try {
                    if ($selectedChatType === 'dispute') {
                        $unarchiveStmt = $pdo->prepare("UPDATE applications SET dispute_chat_archived = 0 WHERE id = ?");
                        $unarchiveStmt->execute([$selectedChatApplicationId]);
                    }
                } catch (Exception $ignored) {
                }
                $_SESSION['success_message'] = 'Сообщение отправлено.';
                if ($isAjaxRequest) {
                    $userLabel = trim((string) (($user['surname'] ?? '') . ' ' . ($user['name'] ?? '') . ' ' . ($user['patronymic'] ?? '')));
                    if ($userLabel === '') {
                        $userLabel = 'Пользователь';
                    }
                    jsonResponse([
                        'success' => true,
                        'message' => [
                            'id' => (int) $pdo->lastInsertId(),
                            'content' => $chatMessage,
                            'created_at' => date('d.m.Y H:i'),
                            'author_label' => $userLabel,
                            'from_admin' => false,
                            'author_name' => $userLabel,
                            'author_email' => (string) ($user['email'] ?? ''),
                            'can_delete_attachment' => !empty($attachmentUpload['uploaded']),
                            'attachment' => !empty($attachmentUpload['uploaded']) ? [
                                'url' => (string) ($attachmentUpload['url'] ?? ''),
                                'name' => (string) ($attachmentUpload['original_name'] ?? ''),
                                'mime_type' => (string) ($attachmentUpload['mime_type'] ?? ''),
                                'is_image' => !empty($attachmentUpload['is_image']),
                            ] : null,
                        ],
                    ]);
                }
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = 'Не удалось отправить сообщение.';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 500);
            }
        }
    }

    redirect('/messages?chat_application_id=' . $selectedChatApplicationId . '&chat_title=' . urlencode($selectedChatTitle));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'delete_chat_attachment') {
    $isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (string) ($_POST['ajax'] ?? '') === '1';
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => 'Ошибка безопасности. Обновите страницу.'], 403);
        }
    } else {
        $messageId = max(0, intval($_POST['message_id'] ?? 0));
        if ($messageId <= 0) {
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => 'Сообщение не найдено.'], 422);
            }
        } else {
            $messageStmt = $pdo->prepare("
                SELECT m.id, m.attachment_file
                FROM messages m
                JOIN users u ON u.id = m.created_by
                WHERE m.id = ?
                  AND m.user_id = ?
                  AND u.is_admin = 0
                LIMIT 1
            ");
            $messageStmt->execute([$messageId, $userId]);
            $messageRow = $messageStmt->fetch();

            if (!$messageRow || empty($messageRow['attachment_file'])) {
                if ($isAjaxRequest) {
                    jsonResponse(['success' => false, 'error' => 'Вложение не найдено или уже удалено.'], 404);
                }
            } else {
                $attachmentPath = MESSAGE_ATTACHMENTS_PATH . '/' . basename((string) $messageRow['attachment_file']);
                $deleteStmt = $pdo->prepare("
                    UPDATE messages
                    SET attachment_file = NULL,
                        attachment_original_name = NULL,
                        attachment_mime_type = NULL,
                        attachment_size = 0
                    WHERE id = ?
                    LIMIT 1
                ");
                $deleteStmt->execute([$messageId]);
                if (is_file($attachmentPath)) {
                    @unlink($attachmentPath);
                }
                if ($isAjaxRequest) {
                    jsonResponse(['success' => true, 'message_id' => $messageId]);
                }
            }
        }
    }
}

if (($_GET['action'] ?? '') === 'poll_chat_messages') {
    $pollApplicationId = max(0, intval($_GET['chat_application_id'] ?? 0));
    $pollChatTitle = trim((string) ($_GET['chat_title'] ?? ''));
    $lastMessageId = max(0, intval($_GET['last_message_id'] ?? 0));

    if ($pollApplicationId <= 0 || $pollChatTitle === '') {
        jsonResponse(['success' => false, 'error' => 'Чат не найден.'], 422);
    }

    $checkStmt = $pdo->prepare("
        SELECT id
        FROM applications
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $checkStmt->execute([$pollApplicationId, $userId]);
    if (!$checkStmt->fetch()) {
        jsonResponse(['success' => false, 'error' => 'Заявка не найдена.'], 404);
    }

    $pollStmt = $pdo->prepare("
        SELECT
            m.id,
            m.content,
            m.created_at,
            m.attachment_file,
            m.attachment_original_name,
            m.attachment_mime_type,
            u.name,
            u.surname,
            u.patronymic,
            u.email,
            u.is_admin
        FROM messages m
        JOIN users u ON u.id = m.created_by
        WHERE m.user_id = ?
          AND m.application_id = ?
          AND m.title = ?
          AND m.id > ?
        ORDER BY m.id ASC
    ");
    $pollStmt->execute([$userId, $pollApplicationId, $pollChatTitle, $lastMessageId]);
    $rows = $pollStmt->fetchAll();

    $messagesPayload = [];
    foreach ($rows as $row) {
        $authorName = trim((string) (($row['surname'] ?? '') . ' ' . ($row['name'] ?? '') . ' ' . ($row['patronymic'] ?? '')));
        $fromAdmin = (int) ($row['is_admin'] ?? 0) === 1;
        $attachmentFile = (string) ($row['attachment_file'] ?? '');
        $attachmentName = (string) ($row['attachment_original_name'] ?? basename($attachmentFile));
        $messagesPayload[] = [
            'id' => (int) $row['id'],
            'content' => (string) ($row['content'] ?? ''),
            'created_at' => date('d.m.Y H:i', strtotime((string) $row['created_at'])),
            'author_label' => $fromAdmin ? 'Руководитель проекта — ' . ($authorName !== '' ? $authorName : 'Администратор') : ($authorName !== '' ? $authorName : 'Пользователь'),
            'from_admin' => $fromAdmin,
            'author_name' => $authorName,
            'author_email' => (string) ($row['email'] ?? ''),
            'can_delete_attachment' => !$fromAdmin && $attachmentFile !== '',
            'attachment' => $attachmentFile !== '' ? [
                'url' => buildMessageAttachmentPublicUrl($attachmentFile),
                'name' => $attachmentName,
                'mime_type' => (string) ($row['attachment_mime_type'] ?? ''),
                'is_image' => isImageMessageAttachment((string) ($row['attachment_mime_type'] ?? ''), $attachmentName),
            ] : null,
        ];
    }

    try {
        markUserThreadMessagesAsRead($pdo, $userId, $pollApplicationId, $pollChatTitle);
    } catch (Throwable $ignored) {
    }

    jsonResponse(['success' => true, 'messages' => $messagesPayload]);
}

if ($selectedChatApplicationId > 0) {
    try {
        if ($selectedChatTitle === '') {
            if ($legacyDisputeApplicationId > 0) {
                foreach (getDisputeChatTitleVariants($selectedChatApplicationId) as $candidateTitle) {
                    $titleCheckStmt = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM messages
                        WHERE user_id = ?
                          AND application_id = ?
                          AND title = ?
                    ");
                    $titleCheckStmt->execute([$userId, $selectedChatApplicationId, $candidateTitle]);
                    if ((int) $titleCheckStmt->fetchColumn() > 0) {
                        $selectedChatTitle = $candidateTitle;
                        break;
                    }
                }
            }

            if ($selectedChatTitle === '') {
                $latestTitleStmt = $pdo->prepare("
                    SELECT title
                    FROM messages
                    WHERE user_id = ?
                      AND application_id = ?
                    ORDER BY created_at DESC, id DESC
                    LIMIT 1
                ");
                $latestTitleStmt->execute([$userId, $selectedChatApplicationId]);
                $selectedChatTitle = trim((string) ($latestTitleStmt->fetchColumn() ?: ''));
            }
        }

        $selectedChatType = detectMessageThreadType($selectedChatTitle);
        $selectedMetaStmt = $pdo->prepare("
            SELECT id, status, dispute_chat_closed
            FROM applications
            WHERE id = ? AND user_id = ?
            LIMIT 1
        ");
        $selectedMetaStmt->execute([$selectedChatApplicationId, $userId]);
        $selectedApplication = $selectedMetaStmt->fetch();

        if ($selectedApplication && $selectedChatTitle !== '') {
            $selectedMessagesStmt = $pdo->prepare("
                SELECT m.id, m.content, m.created_at, m.attachment_file, m.attachment_original_name, m.attachment_mime_type, u.name, u.surname, u.patronymic, u.is_admin
                FROM messages m
                JOIN users u ON u.id = m.created_by
                WHERE m.user_id = ?
                  AND m.application_id = ?
                  AND m.title = ?
                ORDER BY m.created_at ASC, m.id ASC
            ");
            $selectedMessagesStmt->execute([$userId, $selectedChatApplicationId, $selectedChatTitle]);
            $selectedChatMessages = $selectedMessagesStmt->fetchAll();
            if (!empty($selectedChatMessages)) {
                try {
                    markUserThreadMessagesAsRead($pdo, $userId, $selectedChatApplicationId, $selectedChatTitle);
                } catch (Throwable $ignored) {
                }
                $selectedChatClosed = (int) ($selectedApplication['dispute_chat_closed'] ?? 0) === 1;
                $selectedChatReplyAllowed = !$selectedChatClosed
                    && ($selectedChatType !== 'dispute' || in_array((string) $selectedApplication['status'], ['declined', 'rejected'], true));
            } else {
                $selectedChatApplicationId = 0;
            }
        } else {
            $selectedChatApplicationId = 0;
        }
    } catch (Exception $e) {
        $selectedChatApplicationId = 0;
        $selectedChatMessages = [];
    }
}

// Подсчет непрочитанных
$unreadCount = $pdo->prepare("SELECT COUNT(*) FROM admin_messages WHERE user_id = ? AND is_read =0");
$unreadCount->execute([$userId]);
$unreadCount = $unreadCount->fetchColumn();
$declinedSubject = getSystemSetting('application_declined_subject', 'Ваша заявка отклонена');
$revisionSubject = getSystemSetting('application_revision_subject', 'Заявка отправлена на корректировку');
$currentPage = 'messages';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(sitePageTitle('Сообщения'), ENT_QUOTES, 'UTF-8') ?></title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
<link rel="stylesheet" href="/css/messages.css">
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="container messages-container">
<div class="messages-page">
<div class="messages-page__header">
<div class="flex items-center justify-between gap-3 flex-wrap">
<div>
<h1>Сообщения</h1>
<p class="text-secondary">Уведомления от администрации и переписки по вашим заявкам.</p>
</div>
<?php if ((int) $unreadCount > 0): ?>
<button type="button" class="btn btn--ghost btn--sm" id="markAllMessagesReadBtn">Прочитать все</button>
<?php endif; ?>
</div>
</div>

<?php if (!empty($threadChats)): ?>
<div class="card mb-lg messages-chats-card">
    <div class="card__header">
        <h3>Чаты по заявкам</h3>
    </div>
    <div class="card__body messages-chats-card__body">
        <table class="table messages-chats-table">
            <thead>
                <tr>
                    <th>Тема</th>
                    <th>Последнее сообщение</th>
                    <th>Дата</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($threadChats as $chat): ?>
                <?php $chatTitle = (string) ($chat['title'] ?? ''); ?>
                <tr class="messages-chats-table__row">
                    <td data-label="Тема" class="messages-chats-table__topic-cell">
                        <a class="font-semibold messages-chats-table__topic-link" href="/application/<?= (int) $chat['application_id'] ?>">
                            <?= htmlspecialchars($chatTitle) ?>
                        </a>
                        <?php if (!empty($chat['contest_title'])): ?>
                            <div class="text-secondary messages-chats-table__meta">Конкурс: <?= htmlspecialchars($chat['contest_title']) ?></div>
                        <?php endif; ?>
                        <div class="text-secondary messages-chats-table__meta"><?= htmlspecialchars(getMessageThreadLabel($chatTitle)) ?></div>
                        <div class="messages-chats-table__badges">
                            <span class="badge <?= (int) ($chat['thread_chat_closed'] ?? 0) === 1 ? 'badge--secondary' : 'badge--success' ?>">
                                <?= (int) ($chat['thread_chat_closed'] ?? 0) === 1 ? 'Чат закрыт' : 'Чат открыт' ?>
                            </span>
                        
                        <?php if ((int) $chat['unread_count'] > 0): ?>
                            <span class="badge messages-chats-table__unread-badge">Ответ администратора: <?= (int) $chat['unread_count'] ?></span>
                        <?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Последнее сообщение" class="messages-chats-table__message-cell"><?= htmlspecialchars(mb_substr((string) ($chat['last_message'] ?? ''), 0, 120)) ?></td>
                    <td data-label="Дата" class="messages-chats-table__date-cell"><?= date('d.m.Y H:i', strtotime($chat['last_message_at'])) ?></td>
                    <td data-label="Действия" class="messages-chats-table__actions-cell">
                        <a class="btn btn--ghost btn--sm messages-chats-table__open-btn" href="/messages?chat_application_id=<?= (int) $chat['application_id'] ?>&chat_title=<?= urlencode($chatTitle) ?>">
                            <i class="fas fa-comments"></i> Открыть чат
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
        
 <?php if (empty($messages)): ?>
<div class="empty-state">
<div class="empty-state__icon"><i class="fas fa-envelope-open"></i></div>
<h3 class="empty-state__title">Нет сообщений</h3>
<p class="empty-state__text">Когда появятся ответы по заявкам, они будут отображаться здесь.</p>
</div>
 <?php else: ?>
<div id="messagesList">
 <?php foreach ($messages as $msg): ?>
<?php
    $messagePriority = $msg['priority'];
    if ((string) $msg['subject'] === $declinedSubject) {
        $messagePriority = 'critical';
    }
    $messagePreview = trim((string) ($msg['message'] ?? ''));
    if ($messagePreview === '' && !empty($msg['attachment_file'])) {
        $messagePreview = 'Сообщение содержит вложение';
    }
?>
<div class="message-card <?= $msg['is_read'] ? '' : 'message-card--unread' ?>" 
 data-message-id="<?= (int) $msg['id'] ?>"
 data-message-subject="<?= htmlspecialchars((string) ($msg['subject'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
 onclick='showMessage(
 <?= (int) $msg['id'] ?>,
 <?= json_encode($msg['subject'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
 <?= json_encode($msg['message'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
 <?= json_encode(date('d.m.Y H:i', strtotime($msg['created_at'])), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
 <?= json_encode($messagePriority, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
 <?= (int) preg_match('/#(\\d+)/u', (string) $msg['message'], $idMatches) ? (int) $idMatches[1] : 0 ?>,
 <?= json_encode(((string) $msg['subject'] === $declinedSubject), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
 <?= json_encode(((string) $msg['subject'] === $revisionSubject), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
 <?= json_encode(!empty($msg['attachment_file']) ? buildMessageAttachmentPublicUrl((string) $msg['attachment_file']) : '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
 <?= json_encode((string) ($msg['attachment_original_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
 <?= json_encode(!empty($msg['attachment_file']) && isImageMessageAttachment((string) ($msg['attachment_mime_type'] ?? ''), (string) ($msg['attachment_original_name'] ?? '')), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
 )'>
<div class="message-card__header">
<div class="message-card__title">
 <?php if ($messagePriority === 'critical'): ?>
<span class="badge message-priority-badge message-priority-badge--critical">КРИТИЧЕСКОЕ</span>
 <?php elseif ($messagePriority === 'important'): ?>
<span class="badge message-priority-badge message-priority-badge--important">ВАЖНОЕ</span>
 <?php endif; ?>
<?= htmlspecialchars($msg['subject']) ?>
</div>
<div class="message-card__date"><?= date('d.m.Y H:i', strtotime($msg['created_at'])) ?></div>
</div>
<div class="message-card__preview"><?= htmlspecialchars(mb_substr($messagePreview, 0, 150)) ?><?= mb_strlen($messagePreview) > 150 ? '...' : '' ?></div>
<?php if (!empty($msg['attachment_file'])): ?>
<div class="message-card__attachment"><i class="fas fa-paperclip"></i> Есть вложение</div>
<?php endif; ?>
<div class="message-card__actions" style="margin-top:10px;">
    <button type="button" class="btn btn--ghost btn--sm js-delete-user-message" data-message-id="<?= (int) $msg['id'] ?>" data-message-subject="<?= htmlspecialchars((string) ($msg['subject'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <i class="fas fa-trash"></i> Удалить
    </button>
</div>
</div>
 <?php endforeach; ?>
</div>
        
<!-- Детали сообщения -->
<div class="message-detail" id="messageDetail">
<button class="btn btn--ghost message-detail__back" onclick="hideMessage()">
<i class="fas fa-arrow-left"></i> Назад к списку
</button>
<div class="message-detail__content">
<div class="mb-lg" id="detailPriority"></div>
<h2 id="detailTitle"></h2>
<div class="message-detail__date" id="detailDate"></div>
<div class="message-detail__text" id="detailContent"></div>
<div class="message-detail__attachment" id="detailAttachment" style="display:none;"></div>
<div class="mt-lg" id="detailActionWrap" style="display:none;">
    <a class="btn btn--secondary" id="detailActionLink" href="#">
        <i class="fas fa-file-alt"></i> Перейти к заявке
    </a>
</div>
<div class="mt-lg">
    <button type="button" class="btn btn--ghost btn--sm" id="detailDeleteMessageBtn" style="color:#ef4444;">
        <i class="fas fa-trash"></i> Удалить это сообщение
    </button>
</div>
</div>
</div>
<?php endif; ?>

<?php if ($selectedChatApplicationId > 0 && $selectedChatTitle !== ''): ?>
<div class="modal active" id="disputeChatModal">
    <div class="modal__content message-modal dispute-chat-modal">
        <div class="modal__header">
            <h3><?= htmlspecialchars($selectedChatTitle) ?></h3>
            <div class="flex items-center gap-sm">
                <a href="/application/<?= (int) $selectedChatApplicationId ?>" class="btn btn--ghost btn--sm">
                    <i class="fas fa-external-link-alt"></i> Открыть заявку
                </a>
                <button type="button" class="modal__close" onclick="closeDisputeChatModal()">&times;</button>
            </div>
        </div>
        <div class="modal__body dispute-chat-modal__body">
            <div style="margin-bottom:12px;">
                <span class="badge <?= $selectedChatClosed ? 'badge--secondary' : 'badge--success' ?>">
                    <?= $selectedChatClosed ? 'Чат закрыт' : 'Чат открыт' ?>
                </span>
                <span class="text-secondary" style="margin-left:8px; font-size:13px;"><?= htmlspecialchars(getMessageThreadLabel($selectedChatTitle)) ?></span>
            </div>
            <div class="dispute-chat-modal__messages" id="disputeChatMessages">
                <?php if (!empty($selectedChatMessages)): ?>
                    <?php foreach ($selectedChatMessages as $chatMessage): ?>
                        <?php
                            $fromAdmin = (int) ($chatMessage['is_admin'] ?? 0) === 1;
                            $authorName = trim(($chatMessage['surname'] ?? '') . ' ' . ($chatMessage['name'] ?? '') . ' ' . ($chatMessage['patronymic'] ?? ''));
                            $attachmentFile = (string) ($chatMessage['attachment_file'] ?? '');
                            $attachmentUrl = $attachmentFile !== '' ? buildMessageAttachmentPublicUrl($attachmentFile) : '';
                            $attachmentName = (string) ($chatMessage['attachment_original_name'] ?? basename($attachmentFile));
                            $attachmentIsImage = $attachmentUrl !== '' && isImageMessageAttachment((string) ($chatMessage['attachment_mime_type'] ?? ''), $attachmentName);
                        ?>
                        <div class="dispute-chat-message <?= $fromAdmin ? 'dispute-chat-message--user' : 'dispute-chat-message--admin' ?>" data-message-id="<?= (int) ($chatMessage['id'] ?? 0) ?>">
                            <div class="dispute-chat-message__bubble">
                                <div class="dispute-chat-message__meta">
                                    <?= htmlspecialchars($fromAdmin ? 'Руководитель проекта — ' . ($authorName !== '' ? $authorName : 'Администратор') : ($authorName !== '' ? $authorName : 'Пользователь')) ?>
                                    <span>• <?= date('d.m.Y H:i', strtotime($chatMessage['created_at'])) ?></span>
                                </div>
                                <div class="dispute-chat-message__text"><?= htmlspecialchars($chatMessage['content']) ?></div>
                                <?php if ($attachmentUrl !== ''): ?>
                                    <div class="message-attachment" style="margin-top:10px;">
                                        <?php if ($attachmentIsImage): ?>
                                            <button type="button" class="message-attachment__image-button" onclick="openMessageImageModal('<?= rawurlencode($attachmentUrl) ?>','<?= rawurlencode($attachmentName) ?>')">
                                                <img src="<?= htmlspecialchars($attachmentUrl) ?>" alt="<?= htmlspecialchars($attachmentName) ?>" class="message-attachment__thumb">
                                                <span class="message-attachment__caption"><i class="fas fa-search-plus"></i> Посмотреть изображение</span>
                                            </button>
                                        <?php else: ?>
                                            <a href="<?= htmlspecialchars($attachmentUrl) ?>" class="message-attachment__file" target="_blank" rel="noopener" download="<?= htmlspecialchars($attachmentName) ?>">
                                                <i class="fas fa-download"></i>
                                                <span><?= htmlspecialchars($attachmentName) ?></span>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!$fromAdmin): ?>
                                            <button type="button" class="btn btn--ghost btn--sm js-delete-chat-attachment" data-message-id="<?= (int) ($chatMessage['id'] ?? 0) ?>" style="margin-top:8px; color:#ef4444;">
                                                <i class="fas fa-trash"></i> Удалить файл
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-secondary">Сообщений пока нет.</p>
                <?php endif; ?>
            </div>
            <?php if ($selectedChatClosed): ?>
                <div class="alert alert--warning" style="margin-top:12px;">
                    <i class="fas fa-lock"></i> Чат завершён администратором. Доступен только просмотр.
                </div>
            <?php elseif ($selectedChatReplyAllowed): ?>
                <form method="POST" class="dispute-chat-modal__composer" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="reply_chat">
                    <input type="hidden" name="chat_application_id" value="<?= (int) $selectedChatApplicationId ?>">
                    <input type="hidden" name="chat_title" value="<?= htmlspecialchars($selectedChatTitle, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="file" id="messagesFrontendChatAttachment" name="attachment" class="chat-composer__attachment-input js-message-attachment-input" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.doc,.docx,.rtf,.xls,.xlsx,.csv,.zip,image/*,application/pdf,text/plain,text/csv">
                    <div class="message-attachment-preview chat-composer__attachment-preview js-message-attachment-preview" hidden></div>
                    <div class="chat-composer__row">
                        <label class="chat-composer__attachment-trigger" for="messagesFrontendChatAttachment" title="Прикрепить файл">
                            <i class="fas fa-paperclip"></i>
                        </label>
                        <textarea name="chat_message" class="form-textarea js-chat-hotkey chat-composer__textarea" rows="1" placeholder="Напишите сообщение..." aria-label="<?= $selectedChatType === 'curator' ? 'Сообщение куратору' : 'Сообщение администратору' ?>"></textarea>
                        <button type="submit" class="btn btn--primary chat-composer__submit" title="Отправить" aria-label="Отправить">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                    <div class="chat-composer__attachment-help">Изображение покажем миниатюрой, для остальных файлов сохраним название. До 10 МБ.</div>
                </form>
            <?php else: ?>
                <div class="alert alert--secondary" style="margin-top:12px;">
                    <i class="fas fa-info-circle"></i> Отправка новых сообщений сейчас недоступна, но историю чата можно просматривать.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
</div>
</main>

<div class="modal" id="messageImageModal">
    <div class="modal__content" style="max-width:min(1100px,96vw); width:96vw;">
        <div class="modal__header">
            <h3 id="messageImageModalTitle">Просмотр изображения</h3>
            <button type="button" class="modal__close" onclick="closeMessageImageModal()">&times;</button>
        </div>
        <div class="modal__body" style="display:flex; justify-content:center; align-items:center; max-height:80vh;">
            <img id="messageImageModalImage" src="" alt="" style="display:block; max-width:100%; max-height:70vh; border-radius:16px; object-fit:contain;">
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>

<script>
let currentUnreadCount = <?= (int) $unreadCount ?>;
let currentMessageId = 0;

function showMessage(id, title, content, date, priority, applicationId, isDeclinedNotice, isRevisionNotice, attachmentUrl, attachmentName, attachmentIsImage) {
    currentMessageId = Number(id || 0);
    document.getElementById('messagesList').style.display = 'none';
    document.getElementById('messageDetail').classList.add('active');
    document.getElementById('detailTitle').textContent = title;
    document.getElementById('detailDate').textContent = date;
    
    // Priority badge
    let priorityHtml = '';
    if (priority === 'critical') {
        priorityHtml = '<span class="badge message-detail-priority message-detail-priority--critical"><i class="fas fa-exclamation-triangle"></i><span>КРИТИЧЕСКОЕ СООБЩЕНИЕ</span></span>';
    } else if (priority === 'important') {
        priorityHtml = '<span class="badge message-detail-priority message-detail-priority--important"><i class="fas fa-exclamation-circle"></i><span>ВАЖНОЕ СООБЩЕНИЕ</span></span>';
    }
    
    document.getElementById('detailPriority').innerHTML = priorityHtml;
    document.getElementById('detailContent').textContent = content;
    const attachmentWrap = document.getElementById('detailAttachment');
    if (attachmentWrap) {
        if (attachmentUrl && attachmentName) {
            if (attachmentIsImage) {
                attachmentWrap.innerHTML =
                    `<button type="button" class="message-attachment__image-button" onclick="openMessageImageModal('${encodeURIComponent(attachmentUrl)}','${encodeURIComponent(attachmentName)}')">` +
                    `<img src="${escapeHtml(attachmentUrl)}" alt="${escapeHtml(attachmentName)}" class="message-attachment__thumb">` +
                    '<span class="message-attachment__caption"><i class="fas fa-search-plus"></i> Посмотреть изображение</span>' +
                    '</button>';
            } else {
                attachmentWrap.innerHTML =
                    `<a href="${escapeHtml(attachmentUrl)}" class="message-attachment__file" target="_blank" rel="noopener" download="${escapeHtml(attachmentName)}">` +
                    '<i class="fas fa-download"></i>' +
                    `<span>${escapeHtml(attachmentName)}</span>` +
                    '</a>';
            }
            attachmentWrap.style.display = '';
        } else {
            attachmentWrap.innerHTML = '';
            attachmentWrap.style.display = 'none';
        }
    }
    const actionWrap = document.getElementById('detailActionWrap');
    const actionLink = document.getElementById('detailActionLink');
    if ((isDeclinedNotice || isRevisionNotice) && applicationId > 0) {
        actionLink.href = '/application/' + applicationId;
        actionWrap.style.display = 'block';
    } else {
        actionWrap.style.display = 'none';
    }
    window.scrollTo(0,0);
    
    // Помечаем сообщение как прочитанное
    markAsRead(id);
}
        
function markAsRead(messageId) {
    const formData = new URLSearchParams();
    formData.append('id', messageId);
    formData.append('csrf_token', '<?= generateCSRFToken() ?>');
    
    fetch('/mark-message-read', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData.toString(),
    })
        .then((response) => response.json())
        .then((data) => {
            if (data.success && currentUnreadCount > 0) {
                currentUnreadCount--;
                updateUnreadBadge();
            }
            if (data.success) {
                const card = document.querySelector('.message-card[data-message-id="' + messageId + '"]');
                if (card) {
                    card.classList.remove('message-card--unread');
                }
            }
        });
}

function updateUnreadBadge() {
    const badge = document.querySelector('.messages-badge');
    if (currentUnreadCount > 0) {
        if (badge) {
            badge.textContent = currentUnreadCount;
        } else {
            const link = document.querySelector('.messages-link');
            const newBadge = document.createElement('span');
            newBadge.className = 'messages-badge messages-badge--pulse';
            newBadge.textContent = currentUnreadCount;
            link.appendChild(newBadge);
        }
    } else if (badge) {
        badge.remove();
    }

    const markAllButton = document.getElementById('markAllMessagesReadBtn');
    if (markAllButton && currentUnreadCount <= 0) {
        markAllButton.remove();
    }
}

const markAllMessagesReadBtn = document.getElementById('markAllMessagesReadBtn');
if (markAllMessagesReadBtn) {
    markAllMessagesReadBtn.addEventListener('click', () => {
        const formData = new URLSearchParams();
        formData.append('mark_all', '1');
        formData.append('csrf_token', '<?= generateCSRFToken() ?>');

        fetch('/mark-message-read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: formData.toString(),
        })
            .then((response) => response.json())
            .then((data) => {
                if (!data.success) {
                    return;
                }

                currentUnreadCount = 0;
                updateUnreadBadge();
                document.querySelectorAll('.message-card--unread').forEach((card) => {
                    card.classList.remove('message-card--unread');
                });
            });
    });
}
        
function hideMessage() {
    currentMessageId = 0;
    document.getElementById('messageDetail').classList.remove('active');
    document.getElementById('messagesList').style.display = 'block';
}

function removeMessageCardById(messageId) {
    const card = document.querySelector('.message-card[data-message-id="' + messageId + '"]');
    if (!card) return false;
    const wasUnread = card.classList.contains('message-card--unread');
    card.remove();
    if (wasUnread && currentUnreadCount > 0) {
        currentUnreadCount--;
        updateUnreadBadge();
    }
    return true;
}

function ensureMessagesEmptyState() {
    const cardsLeft = document.querySelectorAll('.message-card').length;
    if (cardsLeft > 0 || document.querySelector('.empty-state')) {
        return;
    }
    const list = document.getElementById('messagesList');
    if (!list || !list.parentNode) return;
    const emptyState = document.createElement('div');
    emptyState.className = 'empty-state';
    emptyState.innerHTML =
        '<div class="empty-state__icon"><i class="fas fa-envelope-open"></i></div>' +
        '<h3 class="empty-state__title">Нет сообщений</h3>' +
        '<p class="empty-state__text">Когда появятся ответы по заявкам, они будут отображаться здесь.</p>';
    list.parentNode.insertBefore(emptyState, list);
    list.style.display = 'none';
}

function deleteUserMessage(messageId, messageSubject, options = {}) {
    const numericId = Number(messageId || 0);
    if (!numericId) return;

    const title = String(messageSubject || '').trim();
    const confirmationText = title !== '' ? `Удалить сообщение «${title}»?` : 'Удалить сообщение?';
    if (!window.confirm(confirmationText)) return;

    const formData = new URLSearchParams();
    formData.append('id', String(numericId));
    formData.append('csrf_token', '<?= generateCSRFToken() ?>');

    fetch('/delete-user-message', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData.toString(),
    })
        .then((response) => response.json())
        .then((data) => {
            if (!data.success) {
                alert(data.error || 'Не удалось удалить сообщение');
                return;
            }
            removeMessageCardById(numericId);
            ensureMessagesEmptyState();
            if (options.fromDetail === true) {
                hideMessage();
            }
        })
        .catch(() => {
            alert('Не удалось удалить сообщение');
        });
}

document.querySelectorAll('.js-delete-user-message').forEach((button) => {
    button.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        deleteUserMessage(button.dataset.messageId, button.dataset.messageSubject);
    });
});

document.getElementById('detailDeleteMessageBtn')?.addEventListener('click', () => {
    if (!currentMessageId) return;
    const card = document.querySelector('.message-card[data-message-id="' + currentMessageId + '"]');
    const subject = card?.dataset.messageSubject || document.getElementById('detailTitle')?.textContent || '';
    deleteUserMessage(currentMessageId, subject, { fromDetail: true });
});

function closeDisputeChatModal() {
    const url = new URL(window.location.href);
    url.searchParams.delete('chat_application_id');
    url.searchParams.delete('chat_title');
    url.searchParams.delete('dispute_application_id');
    window.location.href = url.toString();
}
</script>
<?php include dirname(__DIR__) . '/partials/frontend-chat-helpers.php'; ?>
<?php include dirname(__DIR__) . '/partials/frontend-chat-runtime.php'; ?>
<script>
window.__messagesPageChat = window.initFrontendLiveChat({
    modalId: 'disputeChatModal',
    messagesContainerId: 'disputeChatMessages',
    formSelector: '#disputeChatModal form.dispute-chat-modal__composer',
    textareaSelector: 'textarea[name="chat_message"]',
    submitUrl: window.location.href,
    openState: Boolean(document.getElementById('disputeChatModal')?.classList.contains('active')),
    pollUrlBuilder: (lastMessageId) => {
        const applicationId = Number(<?= (int) $selectedChatApplicationId ?>);
        const chatTitle = <?= json_encode((string) $selectedChatTitle) ?>;
        if (!applicationId || !chatTitle) return null;
        const url = new URL(window.location.href);
        url.searchParams.set('action', 'poll_chat_messages');
        url.searchParams.set('chat_application_id', String(applicationId));
        url.searchParams.set('chat_title', chatTitle);
        url.searchParams.set('last_message_id', String(lastMessageId || 0));
        return url.toString();
    },
    onSubmitSuccess: () => {
        if (window.__messagesPageChat?.scrollToBottom) {
            window.__messagesPageChat.scrollToBottom();
        }
    },
    onSubmitError: (error) => {
        console.error(error);
    },
    onDeleteAttachment: async (messageId, button) => {
        const formData = new FormData();
        formData.append('action', 'delete_chat_attachment');
        formData.append('message_id', String(messageId));
        formData.append('csrf_token', <?= json_encode(generateCSRFToken()) ?>);
        formData.append('ajax', '1');

        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData,
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Не удалось удалить файл');
        }

        const attachmentWrap = button.closest('.message-attachment');
        if (attachmentWrap) {
            attachmentWrap.remove();
        }
    },
    onCloseOverlay: () => {
        closeDisputeChatModal();
    },
});
</script>
</body>
</html>
