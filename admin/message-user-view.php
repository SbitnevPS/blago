<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (!isAdmin()) {
    redirect('/admin/login');
}

check_csrf();

$admin = getCurrentUser();
$userId = max(0, (int) ($_GET['id'] ?? 0));
$messagesReturnUrl = '/admin/messages';
$rawReturnUrl = trim((string) ($_GET['return_url'] ?? ''));
if ($rawReturnUrl !== '' && str_starts_with($rawReturnUrl, '/admin/')) {
    $messagesReturnUrl = $rawReturnUrl;
}

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

if (!function_exists('adminMessageUserViewHasDisputeChatClosedColumn')) {
    function adminMessageUserViewHasDisputeChatClosedColumn(PDO $pdo): bool
    {
        static $hasColumn = null;
        if ($hasColumn !== null) {
            return $hasColumn;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM applications LIKE 'dispute_chat_closed'");
            $hasColumn = (bool) ($stmt && $stmt->fetch());
        } catch (Throwable $e) {
            $hasColumn = false;
        }

        return $hasColumn;
    }
}

$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$userStmt->execute([$userId]);
$user = $userStmt->fetch();

if (!$user) {
    redirect($messagesReturnUrl);
}

$currentPageUrl = '/admin/messages/user/' . $userId;
$selectedChatApplicationId = max(0, (int) ($_REQUEST['chat_application_id'] ?? 0));
$selectedChatTitle = trim((string) ($_REQUEST['chat_title'] ?? ''));
$selectedChatMessages = [];
$selectedChatIsClosed = false;
$selectedChatType = detectMessageThreadType($selectedChatTitle);

$applicationsStmt = $pdo->prepare("
    SELECT
        a.id,
        a.status,
        a.created_at,
        c.title AS contest_title
    FROM applications a
    LEFT JOIN contests c ON c.id = a.contest_id
    WHERE a.user_id = ?
    ORDER BY a.created_at DESC, a.id DESC
");
$applicationsStmt->execute([$userId]);
$userApplications = $applicationsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    $isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (string) ($_POST['ajax'] ?? '') === '1';
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности';
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => $error], 403);
        }
    } else {
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        $priority = (string) ($_POST['priority'] ?? 'normal');
        $allowedPriorities = ['normal', 'important', 'critical'];
        $attachmentUpload = uploadMessageAttachment($_FILES['attachment'] ?? []);

        if (!in_array($priority, $allowedPriorities, true)) {
            $priority = 'normal';
        }

        if (empty($attachmentUpload['success'])) {
            $error = (string) ($attachmentUpload['message'] ?? 'Не удалось загрузить вложение.');
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $error], 422);
            }
        } elseif ($subject === '' || ($message === '' && empty($attachmentUpload['uploaded']))) {
            $error = 'Заполните тему и сообщение или прикрепите файл';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $error], 422);
            }
        } else {
            [$attachmentFile, $attachmentOriginalName, $attachmentMimeType, $attachmentSize] = messageAttachmentInsertPayload($attachmentUpload);
            $insertStmt = $pdo->prepare("
                INSERT INTO admin_messages (
                    user_id,
                    admin_id,
                    subject,
                    message,
                    priority,
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
                (int) ($admin['id'] ?? 0),
                $subject,
                $message,
                $priority,
                $attachmentFile,
                $attachmentOriginalName,
                $attachmentMimeType,
                $attachmentSize,
            ]);

            if ($isAjaxRequest) {
                $adminLabel = trim((string) (($admin['name'] ?? '') . ' ' . ($admin['patronymic'] ?? '') . ' ' . ($admin['surname'] ?? '')));
                if ($adminLabel === '') {
                    $adminLabel = 'Администратор';
                }
                jsonResponse([
                    'success' => true,
                    'message' => [
                        'id' => (int) $pdo->lastInsertId(),
                        'subject' => $subject,
                        'content' => $message,
                        'priority' => $priority,
                        'created_at' => date('d.m.Y H:i'),
                        'author_label' => $adminLabel,
                        'attachment' => !empty($attachmentUpload['uploaded']) ? [
                            'url' => (string) ($attachmentUpload['url'] ?? ''),
                            'name' => (string) ($attachmentUpload['original_name'] ?? ''),
                            'mime_type' => (string) ($attachmentUpload['mime_type'] ?? ''),
                            'is_image' => !empty($attachmentUpload['is_image']),
                        ] : null,
                    ],
                ]);
            }

            $_SESSION['success_message'] = 'Сообщение отправлено';
            redirect('/admin/messages/user/' . $userId);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_curator_chat') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности';
    } else {
        $targetApplicationId = max(0, (int) ($_POST['application_id'] ?? 0));
        $applicationCheckStmt = $pdo->prepare("
            SELECT a.id, c.title AS contest_title
            FROM applications a
            LEFT JOIN contests c ON c.id = a.contest_id
            WHERE a.id = ? AND a.user_id = ?
            LIMIT 1
        ");
        $applicationCheckStmt->execute([$targetApplicationId, $userId]);
        $targetApplication = $applicationCheckStmt->fetch();

        if (!$targetApplication) {
            $error = 'Не удалось найти выбранную заявку пользователя.';
        } else {
            $curatorChatTitle = buildCuratorChatTitle($targetApplicationId);
            $existingChatStmt = $pdo->prepare("
                SELECT id
                FROM messages
                WHERE user_id = ?
                  AND application_id = ?
                  AND title = ?
                LIMIT 1
            ");
            $existingChatStmt->execute([$userId, $targetApplicationId, $curatorChatTitle]);
            $existingChatId = (int) $existingChatStmt->fetchColumn();

            if ($existingChatId <= 0) {
                $introMessage = trim(
                    "Здравствуйте!\n\n"
                    . "Открыт чат с куратором по заявке #{$targetApplicationId}."
                    . (!empty($targetApplication['contest_title']) ? "\nКонкурс: " . (string) $targetApplication['contest_title'] : '')
                    . "\n\nЗдесь можно уточнить детали по заявке и получить помощь по ходу работы."
                );
                $insertCuratorChatStmt = $pdo->prepare("
                    INSERT INTO messages (user_id, application_id, title, content, created_by, created_at, is_read)
                    VALUES (?, ?, ?, ?, ?, NOW(), 0)
                ");
                $insertCuratorChatStmt->execute([
                    $userId,
                    $targetApplicationId,
                    $curatorChatTitle,
                    $introMessage,
                    (int) ($admin['id'] ?? 0),
                ]);
                $_SESSION['success_message'] = 'Чат с куратором создан';
            } else {
                $_SESSION['success_message'] = 'Чат с куратором уже существует';
            }

            redirect(
                $currentPageUrl
                . '?chat_application_id=' . $targetApplicationId
                . '&chat_title=' . urlencode($curatorChatTitle)
            );
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_thread_closed') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности';
    } else {
        $targetApplicationId = max(0, (int) ($_POST['chat_application_id'] ?? 0));
        $targetChatTitle = trim((string) ($_POST['chat_title'] ?? ''));
        $nextClosedState = (int) ($_POST['closed_state'] ?? 0) === 1 ? 1 : 0;

        if ($targetApplicationId <= 0 || $targetChatTitle === '') {
            $error = 'Чат не найден.';
        } elseif (!adminMessageUserViewHasDisputeChatClosedColumn($pdo)) {
            $error = 'Поле статуса чата не найдено в базе данных.';
        } else {
            $applicationCheckStmt = $pdo->prepare("
                SELECT id
                FROM applications
                WHERE id = ? AND user_id = ?
                LIMIT 1
            ");
            $applicationCheckStmt->execute([$targetApplicationId, $userId]);
            $applicationExists = (int) $applicationCheckStmt->fetchColumn() > 0;

            if (!$applicationExists) {
                $error = 'Заявка для этого чата не найдена.';
            } else {
                $toggleStmt = $pdo->prepare("UPDATE applications SET dispute_chat_closed = ? WHERE id = ? LIMIT 1");
                $toggleStmt->execute([$nextClosedState, $targetApplicationId]);
                $_SESSION['success_message'] = $nextClosedState === 1
                    ? 'Чат закрыт. Пользователь может только просматривать переписку.'
                    : 'Чат возобновлён. Пользователь снова может отправлять сообщения.';

                redirect(
                    $currentPageUrl
                    . '?chat_application_id=' . $targetApplicationId
                    . '&chat_title=' . urlencode($targetChatTitle)
                );
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply_thread') {
    $isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (string) ($_POST['ajax'] ?? '') === '1';
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности';
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => $error], 403);
        }
    } else {
        $replyText = trim((string) ($_POST['reply_text'] ?? ''));
        $attachmentUpload = uploadMessageAttachment($_FILES['attachment'] ?? []);

        if (empty($attachmentUpload['success'])) {
            $error = (string) ($attachmentUpload['message'] ?? 'Не удалось загрузить вложение.');
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $error], 422);
            }
        } elseif ($selectedChatApplicationId <= 0 || $selectedChatTitle === '') {
            $error = 'Чат не найден.';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $error], 404);
            }
        } elseif ($replyText === '' && empty($attachmentUpload['uploaded'])) {
            $error = 'Введите сообщение или прикрепите файл.';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $error], 422);
            }
        } else {
            $isClosedForReply = false;
            if (adminMessageUserViewHasDisputeChatClosedColumn($pdo)) {
                $closedStmt = $pdo->prepare("SELECT dispute_chat_closed FROM applications WHERE id = ? LIMIT 1");
                $closedStmt->execute([$selectedChatApplicationId]);
                $isClosedForReply = (int) $closedStmt->fetchColumn() === 1;
            }

            if ($isClosedForReply) {
                $error = 'Этот чат завершён. Отправка сообщений отключена.';
                if ($isAjaxRequest) {
                    jsonResponse(['success' => false, 'error' => $error], 423);
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
                        is_read,
                        attachment_file,
                        attachment_original_name,
                        attachment_mime_type,
                        attachment_size
                    )
                    VALUES (?, ?, ?, ?, ?, NOW(), 0, ?, ?, ?, ?)
                ");
                $insertStmt->execute([
                    $userId,
                    $selectedChatApplicationId,
                    $selectedChatTitle,
                    $replyText,
                    (int) ($admin['id'] ?? 0),
                    $attachmentFile,
                    $attachmentOriginalName,
                    $attachmentMimeType,
                    $attachmentSize,
                ]);

                if ($isAjaxRequest) {
                    $adminLabel = trim((string) (($admin['surname'] ?? '') . ' ' . ($admin['name'] ?? '') . ' ' . ($admin['patronymic'] ?? '')));
                    if ($adminLabel === '') {
                        $adminLabel = 'Администратор';
                    }
                    jsonResponse([
                        'success' => true,
                        'message' => [
                            'id' => (int) $pdo->lastInsertId(),
                            'content' => $replyText,
                            'created_at' => date('d.m.Y H:i'),
                            'author_label' => 'Руководитель проекта — ' . $adminLabel,
                            'from_admin' => true,
                            'author_name' => $adminLabel,
                            'author_email' => (string) ($admin['email'] ?? ''),
                            'attachment' => !empty($attachmentUpload['uploaded']) ? [
                                'url' => (string) ($attachmentUpload['url'] ?? ''),
                                'name' => (string) ($attachmentUpload['original_name'] ?? ''),
                                'mime_type' => (string) ($attachmentUpload['mime_type'] ?? ''),
                                'is_image' => !empty($attachmentUpload['is_image']),
                            ] : null,
                        ],
                    ]);
                }

                $_SESSION['success_message'] = 'Сообщение отправлено в чат';
                redirect(
                    $currentPageUrl
                    . '?chat_application_id=' . $selectedChatApplicationId
                    . '&chat_title=' . urlencode($selectedChatTitle)
                );
            }
        }
    }
}

if (($_GET['action'] ?? '') === 'poll_thread_messages') {
    $pollApplicationId = max(0, (int) ($_GET['chat_application_id'] ?? 0));
    $pollChatTitle = trim((string) ($_GET['chat_title'] ?? ''));
    $lastMessageId = max(0, (int) ($_GET['last_message_id'] ?? 0));

    if ($pollApplicationId <= 0 || $pollChatTitle === '') {
        jsonResponse(['success' => false, 'error' => 'Чат не найден.'], 422);
    }

    $threadCheckStmt = $pdo->prepare("
        SELECT id
        FROM messages
        WHERE user_id = ?
          AND application_id = ?
          AND title = ?
        LIMIT 1
    ");
    $threadCheckStmt->execute([$userId, $pollApplicationId, $pollChatTitle]);
    if (!(int) $threadCheckStmt->fetchColumn()) {
        jsonResponse(['success' => false, 'error' => 'Чат не найден.'], 404);
    }

    $pollStmt = $pdo->prepare("
        SELECT
            m.id,
            m.content,
            m.created_at,
            m.attachment_file,
            m.attachment_original_name,
            m.attachment_mime_type,
            author.name AS author_name,
            author.surname AS author_surname,
            author.patronymic AS author_patronymic,
            author.email AS author_email,
            author.is_admin AS author_is_admin
        FROM messages m
        JOIN users author ON author.id = m.created_by
        WHERE m.user_id = ?
          AND m.application_id = ?
          AND m.title = ?
          AND m.id > ?
        ORDER BY m.id ASC
    ");
    $pollStmt->execute([$userId, $pollApplicationId, $pollChatTitle, $lastMessageId]);
    $rows = $pollStmt->fetchAll();

    $incomingUserMessageIds = [];
    foreach ($rows as $row) {
        if ((int) ($row['author_is_admin'] ?? 0) !== 1) {
            $incomingUserMessageIds[] = (int) ($row['id'] ?? 0);
        }
    }
    if (!empty($incomingUserMessageIds)) {
        $placeholders = implode(',', array_fill(0, count($incomingUserMessageIds), '?'));
        $markPolledReadStmt = $pdo->prepare("
            UPDATE messages
            SET is_read = 1
            WHERE id IN ($placeholders)
              AND is_read = 0
        ");
        $markPolledReadStmt->execute($incomingUserMessageIds);
    }

    $messagesPayload = [];
    foreach ($rows as $row) {
        $authorName = trim((string) (($row['author_surname'] ?? '') . ' ' . ($row['author_name'] ?? '') . ' ' . ($row['author_patronymic'] ?? '')));
        $fromAdmin = (int) ($row['author_is_admin'] ?? 0) === 1;
        $attachmentFile = (string) ($row['attachment_file'] ?? '');
        $attachmentName = (string) ($row['attachment_original_name'] ?? basename($attachmentFile));
        $userLabel = trim((string) (($user['name'] ?? '') . ' ' . ($user['patronymic'] ?? '') . ' ' . ($user['surname'] ?? '')));
        if ($userLabel === '') {
            $userLabel = trim((string) (($user['surname'] ?? '') . ' ' . ($user['name'] ?? '')));
        }
        if ($userLabel === '') {
            $userLabel = 'Пользователь';
        }
        $messagesPayload[] = [
            'id' => (int) $row['id'],
            'content' => (string) ($row['content'] ?? ''),
            'created_at' => date('d.m.Y H:i', strtotime((string) $row['created_at'])),
            'author_label' => $fromAdmin
                ? 'Руководитель проекта — ' . ($authorName !== '' ? $authorName : 'Администратор')
                : ($authorName !== '' ? $authorName : $userLabel),
            'from_admin' => $fromAdmin,
            'author_name' => $authorName,
            'author_email' => (string) ($row['author_email'] ?? ''),
            'attachment' => $attachmentFile !== '' ? [
                'url' => buildMessageAttachmentPublicUrl($attachmentFile),
                'name' => $attachmentName,
                'mime_type' => (string) ($row['attachment_mime_type'] ?? ''),
                'is_image' => isImageMessageAttachment((string) ($row['attachment_mime_type'] ?? ''), $attachmentName),
            ] : null,
        ];
    }

    jsonResponse(['success' => true, 'messages' => $messagesPayload]);
}

$messagesStmt = $pdo->prepare("
    SELECT
        am.*,
        author.name AS admin_name,
        author.surname AS admin_surname,
        author.patronymic AS admin_patronymic
    FROM admin_messages am
    LEFT JOIN users author ON author.id = am.admin_id
    WHERE am.user_id = ?
    ORDER BY am.created_at DESC, am.id DESC
");
$messagesStmt->execute([$userId]);
$notifications = $messagesStmt->fetchAll();

if ($selectedChatApplicationId > 0 && $selectedChatTitle !== '') {
    $markSelectedChatReadStmt = $pdo->prepare("
        UPDATE messages m
        JOIN users author ON author.id = m.created_by
        SET m.is_read = 1
        WHERE m.user_id = ?
          AND m.application_id = ?
          AND m.title = ?
          AND m.is_read = 0
          AND author.is_admin = 0
    ");
    $markSelectedChatReadStmt->execute([$userId, $selectedChatApplicationId, $selectedChatTitle]);
}

$chatThreadsStmt = $pdo->prepare("
    SELECT
        m.application_id,
        m.title,
        c.title AS contest_title,
        MAX(m.created_at) AS last_message_at,
        SUBSTRING_INDEX(
            GROUP_CONCAT(m.content ORDER BY m.created_at DESC SEPARATOR '||__||'),
            '||__||',
            1
        ) AS last_message,
        SUM(CASE WHEN m.is_read = 0 AND author.is_admin = 0 THEN 1 ELSE 0 END) AS unread_count
    FROM messages m
    JOIN users author ON author.id = m.created_by
    LEFT JOIN applications a ON a.id = m.application_id
    LEFT JOIN contests c ON c.id = a.contest_id
    WHERE m.user_id = ?
    GROUP BY m.application_id, m.title, c.title
    ORDER BY last_message_at DESC
");
$chatThreadsStmt->execute([$userId]);
$chatThreads = $chatThreadsStmt->fetchAll();

$latestChat = $chatThreads[0] ?? null;
if ($selectedChatApplicationId > 0 && $selectedChatTitle !== '') {
    $selectedChatStmt = $pdo->prepare("
        SELECT
            m.*,
            author.name AS author_name,
            author.surname AS author_surname,
            author.patronymic AS author_patronymic,
            author.email AS author_email,
            author.is_admin AS author_is_admin
        FROM messages m
        JOIN users author ON author.id = m.created_by
        WHERE m.user_id = ?
          AND m.application_id = ?
          AND m.title = ?
        ORDER BY m.created_at ASC, m.id ASC
    ");
    $selectedChatStmt->execute([$userId, $selectedChatApplicationId, $selectedChatTitle]);
    $selectedChatMessages = $selectedChatStmt->fetchAll();

    if (empty($selectedChatMessages)) {
        $selectedChatApplicationId = 0;
        $selectedChatTitle = '';
        $selectedChatType = 'thread';
    } elseif (adminMessageUserViewHasDisputeChatClosedColumn($pdo)) {
        $closedStmt = $pdo->prepare("SELECT dispute_chat_closed FROM applications WHERE id = ? LIMIT 1");
        $closedStmt->execute([$selectedChatApplicationId]);
        $selectedChatIsClosed = (int) $closedStmt->fetchColumn() === 1;
    }
}

$userDisplayName = trim((string) (($user['name'] ?? '') . ' ' . ($user['patronymic'] ?? '') . ' ' . ($user['surname'] ?? '')));
if ($userDisplayName === '') {
    $userDisplayName = trim((string) (($user['surname'] ?? '') . ' ' . ($user['name'] ?? '')));
}
if ($userDisplayName === '') {
    $userDisplayName = 'Пользователь';
}

$pageTitle = 'Сообщения пользователя';
$breadcrumb = 'Сообщения / ' . $userDisplayName;
$currentPage = 'messages';
$headerBackUrl = $messagesReturnUrl;
$headerBackLabel = 'К списку';

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .message-thread-card--unread {
        border-color: #f59e0b;
        background: #fffbeb;
        box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.25);
    }
</style>

<?php if (isset($error)): ?>
<div class="alert alert--error mb-lg">
    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['success_message'])): ?>
<div class="alert alert--success mb-lg">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars((string) $_SESSION['success_message']) ?>
</div>
<?php unset($_SESSION['success_message']); endif; ?>

<div class="card mb-lg">
    <div class="card__body">
        <div class="message-user-hero">
            <div class="message-user-hero__main">
                <div class="message-user-hero__title"><?= htmlspecialchars($userDisplayName) ?></div>
                <div class="message-user-hero__meta"><?= htmlspecialchars((string) ($user['email'] ?? 'Email не указан')) ?></div>
                <div class="message-user-hero__stats">
                    <span class="message-user-hero__stat">Сообщений отправлено: <?= count($notifications) ?></span>
                    <span class="message-user-hero__stat">Чатов: <?= count($chatThreads) ?></span>
                </div>
            </div>
            <div class="message-user-hero__actions">
                <button type="button" class="btn btn--primary" onclick="openSendModal()">
                    <i class="fas fa-paper-plane"></i> Написать сообщение пользователю
                </button>
                <?php if (!empty($userApplications)): ?>
                <button type="button" class="btn btn--ghost" onclick="openCreateChatModal()">
                    <i class="fas fa-plus-circle"></i> Создать чат с куратором
                </button>
                <?php endif; ?>
                <?php if ($latestChat): ?>
                <a class="btn btn--secondary" href="<?= htmlspecialchars($currentPageUrl) ?>?chat_application_id=<?= (int) ($latestChat['application_id'] ?? 0) ?>&chat_title=<?= urlencode((string) ($latestChat['title'] ?? '')) ?>">
                    <i class="fas fa-comments"></i> Открыть чат
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card__header">
        <h3>История сообщений</h3>
    </div>
    <div class="card__body">
        <?php if (empty($notifications) && empty($chatThreads)): ?>
            <div class="text-secondary" style="padding: 28px 0;">Для этого пользователя пока нет сообщений.</div>
        <?php else: ?>
            <div class="admin-list-cards">
                <?php foreach ($chatThreads as $thread): ?>
                    <?php
                        $threadTitle = (string) ($thread['title'] ?? '');
                        $threadLabel = getMessageThreadLabel($threadTitle);
                        $threadUrl = $currentPageUrl
                            . '?chat_application_id=' . (int) ($thread['application_id'] ?? 0)
                            . '&chat_title=' . urlencode($threadTitle);
                    ?>
                    <?php $threadUnreadCount = (int) ($thread['unread_count'] ?? 0); ?>
                    <article class="admin-list-card <?= $threadUnreadCount > 0 ? 'message-thread-card--unread' : '' ?>">
                        <div class="admin-list-card__header">
                            <div class="admin-list-card__title-wrap">
                                <h4 class="admin-list-card__title"><?= htmlspecialchars($threadTitle !== '' ? $threadTitle : 'Чат') ?></h4>
                                <div class="admin-list-card__subtitle"><?= htmlspecialchars($threadLabel) ?> • <?= htmlspecialchars(mb_substr((string) ($thread['last_message'] ?? ''), 0, 160)) ?></div>
                            </div>
                            <?php if ($threadUnreadCount > 0): ?>
                                <span class="badge badge--warning">Новых: <?= $threadUnreadCount ?></span>
                            <?php else: ?>
                                <span class="badge badge--secondary"><?= htmlspecialchars($threadLabel) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="admin-list-card__meta">
                            <span><strong>Заявка:</strong> #<?= (int) ($thread['application_id'] ?? 0) ?></span>
                            <?php if (!empty($thread['contest_title'])): ?>
                                <span><strong>Конкурс:</strong> <?= htmlspecialchars((string) $thread['contest_title']) ?></span>
                            <?php endif; ?>
                            <span><strong>Последнее сообщение:</strong> <?= date('d.m.Y H:i', strtotime((string) $thread['last_message_at'])) ?></span>
                        </div>
                        <div class="admin-list-card__actions">
                            <a class="btn btn--primary btn--sm" href="<?= htmlspecialchars($threadUrl) ?>">
                                <i class="fas fa-comments"></i> Открыть
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>

                <?php foreach ($notifications as $message): ?>
                    <?php
                        $priorityClass = (string) ($message['priority'] ?? 'normal');
                        $authorLabel = trim((string) (($message['admin_name'] ?? '') . ' ' . ($message['admin_patronymic'] ?? '') . ' ' . ($message['admin_surname'] ?? '')));
                        if ($authorLabel === '') {
                            $authorLabel = 'Администратор';
                        }
                        $applicationId = 0;
                        if (preg_match('/Номер заявки:\s*#(\d+)/u', (string) ($message['message'] ?? ''), $matches)) {
                            $applicationId = (int) ($matches[1] ?? 0);
                        }
                    ?>
                    <article
                        class="admin-list-card message-row"
                        data-message-id="<?= (int) $message['id'] ?>"
                        data-user-id="<?= $userId ?>"
                        data-user-name="<?= htmlspecialchars($userDisplayName, ENT_QUOTES, 'UTF-8') ?>"
                        data-user-email="<?= htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-application-id="<?= $applicationId ?>"
                        data-message-subject="<?= htmlspecialchars((string) ($message['subject'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-message-content="<?= htmlspecialchars((string) ($message['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-message-priority="<?= htmlspecialchars($priorityClass, ENT_QUOTES, 'UTF-8') ?>"
                        data-attachment-url="<?= !empty($message['attachment_file']) ? htmlspecialchars(buildMessageAttachmentPublicUrl((string) $message['attachment_file']), ENT_QUOTES, 'UTF-8') : '' ?>"
                        data-attachment-name="<?= htmlspecialchars((string) ($message['attachment_original_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-attachment-is-image="<?= !empty($message['attachment_file']) && isImageMessageAttachment((string) ($message['attachment_mime_type'] ?? ''), (string) ($message['attachment_original_name'] ?? '')) ? '1' : '0' ?>">
                        <div class="admin-list-card__header">
                            <div class="admin-list-card__title-wrap">
                                <h4 class="admin-list-card__title"><?= htmlspecialchars((string) ($message['subject'] ?? 'Без темы')) ?></h4>
                                <div class="admin-list-card__subtitle">Простое уведомление • Отправил: <?= htmlspecialchars($authorLabel) ?></div>
                            </div>
                            <span class="badge <?= $priorityClass === 'critical' ? 'badge--error' : ($priorityClass === 'important' ? 'badge--warning' : 'badge--secondary') ?>">
                                <?= $priorityClass === 'critical' ? 'Критическое' : ($priorityClass === 'important' ? 'Важное' : 'Обычное') ?>
                            </span>
                        </div>
                        <div class="admin-list-card__meta">
                            <span><strong>Дата:</strong> <?= date('d.m.Y H:i', strtotime((string) $message['created_at'])) ?></span>
                            <?php if (!empty($message['attachment_file'])): ?>
                                <span><strong>Вложение:</strong> <?= htmlspecialchars((string) ($message['attachment_original_name'] ?? 'Файл')) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="admin-list-card__actions">
                            <button type="button" class="btn btn--primary btn--sm js-view-message">
                                <i class="fas fa-eye"></i> Открыть
                            </button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal" id="sendMessageModal">
    <div class="modal__content message-modal message-compose-modal">
        <div class="modal__header">
            <h3>Написать сообщение пользователю</h3>
            <button type="button" class="modal__close" onclick="closeSendModal()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="action" value="send_message">
            <div class="modal__body">
                <div class="message-compose">
                    <div class="message-compose__intro">
                        <div class="message-compose__intro-icon"><i class="fas fa-envelope-open-text"></i></div>
                        <div>
                            <div class="message-compose__intro-title">Сообщение пользователю</div>
                            <div class="message-compose__intro-text"><?= htmlspecialchars($userDisplayName) ?> • <?= htmlspecialchars((string) ($user['email'] ?? 'Email не указан')) ?></div>
                        </div>
                    </div>

                    <div class="message-compose__section" style="display:none;">
                        <label class="form-label">Получатель</label>
                        <input type="text" class="form-input" value="<?= htmlspecialchars($userDisplayName . ' (' . ((string) ($user['email'] ?? 'Email не указан')) . ')') ?>" disabled>
                    </div>

                    <div class="message-compose__section">
                        <label class="form-label">Приоритет сообщения</label>
                        <div class="message-compose__priority-grid">
                            <label class="priority-btn priority-btn--normal selected">
                                <input type="radio" name="priority" value="normal" checked onchange="updatePriorityStyle(this)">
                                <span class="priority-icon"><i class="fas fa-circle"></i></span>
                                <span class="priority-text">Обычное</span>
                            </label>
                            <label class="priority-btn priority-btn--important">
                                <input type="radio" name="priority" value="important" onchange="updatePriorityStyle(this)">
                                <span class="priority-icon"><i class="fas fa-exclamation-circle"></i></span>
                                <span class="priority-text">Важное</span>
                            </label>
                            <label class="priority-btn priority-btn--critical">
                                <input type="radio" name="priority" value="critical" onchange="updatePriorityStyle(this)">
                                <span class="priority-icon"><i class="fas fa-exclamation-triangle"></i></span>
                                <span class="priority-text">Критическое</span>
                            </label>
                        </div>
                    </div>

                    <div class="message-compose__section">
                        <label class="form-label">Тема сообщения</label>
                        <input type="text" name="subject" class="form-input" required placeholder="Введите тему сообщения">
                    </div>

                    <div class="message-compose__section">
                        <label class="form-label">Текст сообщения</label>
                        <textarea name="message" class="form-textarea" rows="5" placeholder="Введите текст сообщения"></textarea>
                    </div>

                    <div class="message-compose__section">
                        <input type="file" id="messageUserViewAttachment" name="attachment" class="chat-composer__attachment-input js-message-attachment-input" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.doc,.docx,.rtf,.xls,.xlsx,.csv,.zip,image/*,application/pdf,text/plain,text/csv">
                        <div class="message-attachment-preview chat-composer__attachment-preview js-message-attachment-preview" hidden></div>
                        <div class="chat-composer__row">
                            <label class="chat-composer__attachment-trigger" for="messageUserViewAttachment" title="Прикрепить файл">
                                <i class="fas fa-paperclip"></i>
                            </label>
                            <div class="chat-composer__attachment-help">Изображение покажем миниатюрой, для остальных файлов сохраним название. До 10 МБ.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal__footer">
                <div class="flex gap-sm" style="margin-left:auto;">
                    <button type="button" class="btn btn--ghost" onclick="closeSendModal()">Отмена</button>
                    <button type="submit" class="btn btn--primary"><i class="fas fa-paper-plane"></i> Отправить</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($userApplications)): ?>
<div class="modal" id="createChatModal">
    <div class="modal__content message-modal message-compose-modal" style="max-width:640px;">
        <div class="modal__header">
            <h3>Создать чат с куратором</h3>
            <button type="button" class="modal__close" onclick="closeCreateChatModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="action" value="create_curator_chat">
            <div class="modal__body">
                <div class="message-compose">
                    <div class="message-compose__intro">
                        <div class="message-compose__intro-icon"><i class="fas fa-comments"></i></div>
                        <div>
                            <div class="message-compose__intro-title">Новый чат по заявке</div>
                            <div class="message-compose__intro-text">Выберите заявку пользователя. Чат с куратором откроется сразу после создания.</div>
                        </div>
                    </div>
                    <div class="message-compose__section">
                        <label class="form-label" for="createChatApplicationId">Заявка пользователя</label>
                        <select class="form-select" id="createChatApplicationId" name="application_id" required>
                            <option value="">Выберите заявку</option>
                            <?php foreach ($userApplications as $applicationRow): ?>
                                <option value="<?= (int) ($applicationRow['id'] ?? 0) ?>">
                                    #<?= (int) ($applicationRow['id'] ?? 0) ?><?= !empty($applicationRow['contest_title']) ? ' • ' . htmlspecialchars((string) $applicationRow['contest_title']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-help">Если чат по этой заявке уже создан, откроем существующую переписку.</div>
                    </div>
                </div>
            </div>
            <div class="modal__footer">
                <div class="flex gap-sm" style="margin-left:auto;">
                    <button type="button" class="btn btn--ghost" onclick="closeCreateChatModal()">Отмена</button>
                    <button type="submit" class="btn btn--primary"><i class="fas fa-plus-circle"></i> Создать чат</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="modal" id="viewMessageModal">
    <div class="modal__content" style="max-width:600px;">
        <div class="modal__header">
            <h3 id="viewMessageSubject"></h3>
            <button type="button" class="modal__close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="modal__body">
            <div id="viewMessagePriority" class="mb-md"></div>
            <div id="viewMessageContent" style="white-space:pre-wrap; line-height:1.6;"></div>
            <div id="viewMessageAttachment" class="message-view-attachment" style="display:none;"></div>
        </div>
        <div class="modal__footer">
            <a href="#" class="btn btn--ghost" id="viewMessageApplicationBtn" style="display:none;">
                <i class="fas fa-external-link-alt"></i> Открыть заявку
            </a>
            <button type="button" class="btn btn--secondary" onclick="openSendModalFromViewedMessage()">Написать сообщение пользователю</button>
            <button type="button" class="btn btn--primary" onclick="closeViewModal()">Закрыть</button>
        </div>
    </div>
</div>

<div class="modal" id="messageImagePreviewModal">
    <div class="modal__content" style="max-width:min(1100px,96vw); width:96vw;">
        <div class="modal__header">
            <h3 id="messageImagePreviewTitle">Предпросмотр изображения</h3>
            <button type="button" class="modal__close" onclick="closeMessageImagePreview()">&times;</button>
        </div>
        <div class="modal__body" style="display:flex; justify-content:center; align-items:center; max-height:80vh;">
            <img id="messageImagePreviewImage" src="" alt="" style="display:block; max-width:100%; max-height:70vh; border-radius:16px; object-fit:contain;">
        </div>
    </div>
</div>

<?php if ($selectedChatApplicationId > 0 && $selectedChatTitle !== ''): ?>
<?php
$adminChatModalId = 'threadChatModal';
$adminChatModalActive = true;
$adminChatModalTitle = $selectedChatTitle;
$adminChatCloseHandler = 'closeThreadChatModal()';
$adminChatApplicationUrl = '/admin/application/' . (int) $selectedChatApplicationId;
$adminChatMessagesContainerId = 'threadChatMessages';
$adminChatMessages = $selectedChatMessages;
$adminChatCurrentUserLabel = $userDisplayName;
$adminChatClosed = $selectedChatIsClosed;
$adminChatClosedText = 'Чат завершён. Доступен только просмотр переписки.';
$adminChatFormAction = 'reply_thread';
$adminChatComposerLabel = 'Сообщение в чат';
$adminChatComposerTextareaName = 'reply_text';
$adminChatComposerPlaceholder = 'Введите сообщение';
$adminChatComposerSubmitText = 'Отправить';
$adminChatComposerHiddenFields = [
    'action' => 'reply_thread',
    'chat_application_id' => (string) ((int) $selectedChatApplicationId),
    'chat_title' => $selectedChatTitle,
];
$adminChatSupportsAttachments = true;
$adminChatAttachmentHelp = '';
$adminChatExtraTopHtml = '';
$selectedChatToggleHtml = '';
if (adminMessageUserViewHasDisputeChatClosedColumn($pdo)) {
    ob_start();
    ?>
    <div class="flex items-center justify-between gap-sm flex-wrap" style="margin-bottom:12px;">
        <div class="text-secondary" style="font-size:14px;">
            <?= $selectedChatIsClosed ? 'Чат закрыт для новых сообщений со стороны пользователя.' : 'Чат открыт. Пользователь может продолжать переписку.' ?>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="action" value="toggle_thread_closed">
            <input type="hidden" name="chat_application_id" value="<?= (int) $selectedChatApplicationId ?>">
            <input type="hidden" name="chat_title" value="<?= htmlspecialchars($selectedChatTitle, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="closed_state" value="<?= $selectedChatIsClosed ? '0' : '1' ?>">
            <button type="submit" class="btn btn--<?= $selectedChatIsClosed ? 'secondary' : 'ghost' ?> btn--sm">
                <i class="fas fa-<?= $selectedChatIsClosed ? 'lock-open' : 'lock' ?>"></i>
                <?= $selectedChatIsClosed ? 'Возобновить чат' : 'Закрыть чат' ?>
            </button>
        </form>
    </div>
    <?php
    $selectedChatToggleHtml = (string) ob_get_clean();
}
$adminChatExtraTopHtml = $selectedChatToggleHtml;
$adminChatExtraBottomHtml = '';
$adminChatImageButtonClass = 'js-open-message-image';
require __DIR__ . '/includes/chat-thread-modal.php';
?>
<?php endif; ?>

<script>
let currentViewedMessage = null;

function openSendModal() {
    const modal = document.getElementById('sendMessageModal');
    if (!modal) return;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeSendModal() {
    document.getElementById('sendMessageModal').classList.remove('active');
    restoreBodyScrollIfNoModals();
}

function openCreateChatModal() {
    const modal = document.getElementById('createChatModal');
    if (!modal) return;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeCreateChatModal() {
    const modal = document.getElementById('createChatModal');
    if (!modal) return;
    modal.classList.remove('active');
    restoreBodyScrollIfNoModals();
}

function updatePriorityStyle(radio) {
    if (!radio) return;
    document.querySelectorAll('#sendMessageModal .priority-btn').forEach((btn) => btn.classList.remove('selected'));
    const selected = radio.closest('.priority-btn');
    if (selected) {
        selected.classList.add('selected');
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function viewMessage(row) {
    if (!row) return;
    currentViewedMessage = row.dataset;
    const subject = row.dataset.messageSubject || '';
    const message = row.dataset.messageContent || '';
    const priority = row.dataset.messagePriority || 'normal';
    const applicationId = Number(row.dataset.applicationId || 0);

    document.getElementById('viewMessageSubject').textContent = subject;
    document.getElementById('viewMessageContent').textContent = message;
    document.getElementById('viewMessagePriority').innerHTML =
        priority === 'critical'
            ? '<span class="badge" style="background:#EF4444; color:white; padding:4px 12px;">Критическое</span>'
            : priority === 'important'
                ? '<span class="badge" style="background:#F59E0B; color:white; padding:4px 12px;">Важное</span>'
                : '<span class="badge" style="background:#6B7280; color:white; padding:4px 12px;">Обычное</span>';

    const applicationButton = document.getElementById('viewMessageApplicationBtn');
    if (applicationButton) {
        if (applicationId > 0) {
            applicationButton.href = '/admin/application/' + applicationId;
            applicationButton.style.display = '';
        } else {
            applicationButton.style.display = 'none';
        }
    }

    const attachmentWrap = document.getElementById('viewMessageAttachment');
    if (attachmentWrap) {
        const attachmentUrl = row.dataset.attachmentUrl || '';
        const attachmentName = row.dataset.attachmentName || '';
        const attachmentIsImage = row.dataset.attachmentIsImage === '1';
        if (attachmentUrl && attachmentName) {
            if (attachmentIsImage) {
                attachmentWrap.innerHTML =
                    `<button type="button" class="message-attachment__image-button" onclick="openMessageImagePreview('${encodeURIComponent(attachmentUrl)}','${encodeURIComponent(attachmentName)}')">` +
                    `<img src="${escapeHtml(attachmentUrl)}" alt="${escapeHtml(attachmentName)}" class="message-attachment__thumb">` +
                    '<span class="message-attachment__caption"><i class="fas fa-search-plus"></i> Посмотреть изображение</span>' +
                    '</button>';
            } else {
                attachmentWrap.innerHTML =
                    `<a href="${escapeHtml(attachmentUrl)}" class="message-attachment__file" target="_blank" rel="noopener" download="${escapeHtml(attachmentName)}">` +
                    '<i class="fas fa-download"></i><span>' + escapeHtml(attachmentName) + '</span></a>';
            }
            attachmentWrap.style.display = '';
        } else {
            attachmentWrap.innerHTML = '';
            attachmentWrap.style.display = 'none';
        }
    }

    document.getElementById('viewMessageModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeViewModal() {
    currentViewedMessage = null;
    document.getElementById('viewMessageModal').classList.remove('active');
    restoreBodyScrollIfNoModals();
}

function openSendModalFromViewedMessage() {
    closeViewModal();
    openSendModal();
}

function openMessageImagePreview(encodedUrl, encodedTitle) {
    const imageUrl = decodeURIComponent(encodedUrl || '');
    const imageTitle = decodeURIComponent(encodedTitle || '');
    const modal = document.getElementById('messageImagePreviewModal');
    const image = document.getElementById('messageImagePreviewImage');
    const title = document.getElementById('messageImagePreviewTitle');
    if (!modal || !image || !title || !imageUrl) return;
    image.src = imageUrl;
    image.alt = imageTitle;
    title.textContent = imageTitle || 'Предпросмотр изображения';
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeMessageImagePreview() {
    const modal = document.getElementById('messageImagePreviewModal');
    const image = document.getElementById('messageImagePreviewImage');
    if (!modal || !image) return;
    modal.classList.remove('active');
    image.src = '';
    image.alt = '';
    restoreBodyScrollIfNoModals();
}

function closeThreadChatModal() {
    const url = new URL(window.location.href);
    url.searchParams.delete('chat_application_id');
    url.searchParams.delete('chat_title');
    window.location.href = url.toString();
}

function restoreBodyScrollIfNoModals() {
    const activeModal = document.querySelector('.modal.active');
    document.body.style.overflow = activeModal ? 'hidden' : '';
}

function autoResizeChatTextarea(textarea) {
    if (!textarea) return;
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
}

function bindAutoGrowingChatTextareas(scope = document) {
    if (!scope) return;
    scope.querySelectorAll('.chat-composer__textarea').forEach((textarea) => {
        if (textarea.dataset.boundAutoResize === '1') return;
        textarea.dataset.boundAutoResize = '1';
        autoResizeChatTextarea(textarea);
        textarea.addEventListener('input', () => autoResizeChatTextarea(textarea));
    });
}

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = 'alert ' + (type === 'success' ? 'alert--success' : 'alert--error');
    toast.style.cssText = 'position:fixed; top:20px; right:20px; z-index:3200; min-width:260px; max-width:420px; box-shadow:0 12px 30px rgba(0,0,0,.12); opacity:0; transform:translateY(-8px); transition:opacity .25s ease, transform .25s ease;';
    toast.textContent = message;
    document.body.appendChild(toast);
    requestAnimationFrame(() => {
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
    });
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-8px)';
        setTimeout(() => toast.remove(), 260);
    }, 2600);
}

function updateUserMessageStats() {
    const stats = Array.from(document.querySelectorAll('.message-user-hero__stat'));
    const messagesStat = stats.find((node) => node.textContent.includes('Сообщений отправлено'));
    if (!messagesStat) return;
    const match = messagesStat.textContent.match(/(\d+)/);
    const currentValue = match ? Number(match[1]) : 0;
    messagesStat.textContent = 'Сообщений отправлено: ' + String(currentValue + 1);
}

function buildNotificationCard(messageData) {
    const cardsWrap = document.querySelector('.admin-list-cards');
    if (!cardsWrap || !messageData) return;

    const priority = String(messageData.priority || 'normal');
    const badgeClass = priority === 'critical' ? 'badge--error' : (priority === 'important' ? 'badge--warning' : 'badge--secondary');
    const badgeLabel = priority === 'critical' ? 'Критическое' : (priority === 'important' ? 'Важное' : 'Обычное');
    const attachmentUrl = String(messageData.attachment?.url || '');
    const attachmentName = String(messageData.attachment?.name || '');
    const attachmentIsImage = messageData.attachment?.is_image ? '1' : '0';

    const article = document.createElement('article');
    article.className = 'admin-list-card message-row';
    article.dataset.messageId = String(messageData.id || 0);
    article.dataset.userId = String(<?= (int) $userId ?>);
    article.dataset.userName = <?= json_encode($userDisplayName) ?>;
    article.dataset.userEmail = <?= json_encode((string) ($user['email'] ?? '')) ?>;
    article.dataset.applicationId = '0';
    article.dataset.messageSubject = String(messageData.subject || '');
    article.dataset.messageContent = String(messageData.content || '');
    article.dataset.messagePriority = priority;
    article.dataset.attachmentUrl = attachmentUrl;
    article.dataset.attachmentName = attachmentName;
    article.dataset.attachmentIsImage = attachmentIsImage;

    article.innerHTML =
        '<div class="admin-list-card__header">' +
            '<div class="admin-list-card__title-wrap">' +
                '<h4 class="admin-list-card__title">' + escapeHtml(String(messageData.subject || 'Без темы')) + '</h4>' +
                '<div class="admin-list-card__subtitle">Простое уведомление • Отправил: ' + escapeHtml(String(messageData.author_label || 'Администратор')) + '</div>' +
            '</div>' +
            '<span class="badge ' + badgeClass + '">' + badgeLabel + '</span>' +
        '</div>' +
        '<div class="admin-list-card__meta">' +
            '<span><strong>Дата:</strong> ' + escapeHtml(String(messageData.created_at || '')) + '</span>' +
            (attachmentName ? '<span><strong>Вложение:</strong> ' + escapeHtml(attachmentName) + '</span>' : '') +
        '</div>' +
        '<div class="admin-list-card__actions">' +
            '<button type="button" class="btn btn--primary btn--sm js-view-message"><i class="fas fa-eye"></i> Открыть</button>' +
        '</div>';

    const firstNotification = Array.from(cardsWrap.querySelectorAll('.message-row'))[0] || null;
    if (firstNotification) {
        cardsWrap.insertBefore(article, firstNotification);
    } else {
        cardsWrap.appendChild(article);
    }

    article.addEventListener('click', (event) => {
        if (event.target.closest('button, a, input, label')) {
            return;
        }
        viewMessage(article);
    });
    article.querySelector('.js-view-message')?.addEventListener('click', (event) => {
        event.stopPropagation();
        viewMessage(article);
    });
}

function appendThreadMessage(messageData) {
    const container = document.getElementById('threadChatMessages');
    if (!container || !messageData) return;
    const numericId = Number(messageData.id || 0);
    if (numericId > 0 && container.querySelector(`.dispute-chat-message[data-message-id="${numericId}"]`)) {
        return;
    }

    const messageWrap = document.createElement('div');
    messageWrap.className = 'dispute-chat-message ' + (messageData.from_admin ? 'dispute-chat-message--admin' : 'dispute-chat-message--user');
    messageWrap.dataset.messageId = String(numericId || 0);

    const bubble = document.createElement('div');
    bubble.className = 'dispute-chat-message__bubble';

    const meta = document.createElement('div');
    meta.className = 'dispute-chat-message__meta';
    meta.textContent = String(messageData.author_label || 'Руководитель проекта') + ' • ' + String(messageData.created_at || '');

    const text = document.createElement('div');
    text.className = 'dispute-chat-message__text';
    text.textContent = String(messageData.content || '');

    bubble.appendChild(meta);
    bubble.appendChild(text);

    if (messageData.attachment && messageData.attachment.url) {
        const attachmentWrap = document.createElement('div');
        attachmentWrap.className = 'message-attachment';
        attachmentWrap.style.marginTop = '10px';
        if (messageData.attachment.is_image) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'message-attachment__image-button js-open-message-image';
            button.dataset.imageUrl = String(messageData.attachment.url || '');
            button.dataset.imageTitle = String(messageData.attachment.name || 'Изображение');
            button.addEventListener('click', () => {
                openMessageImagePreview(
                    encodeURIComponent(button.dataset.imageUrl || ''),
                    encodeURIComponent(button.dataset.imageTitle || 'Предпросмотр изображения')
                );
            });

            const image = document.createElement('img');
            image.className = 'message-attachment__thumb';
            image.src = button.dataset.imageUrl;
            image.alt = button.dataset.imageTitle;

            const caption = document.createElement('span');
            caption.className = 'message-attachment__caption';
            caption.innerHTML = '<i class="fas fa-search-plus"></i> Посмотреть изображение';

            button.appendChild(image);
            button.appendChild(caption);
            attachmentWrap.appendChild(button);
        } else {
            const link = document.createElement('a');
            link.className = 'message-attachment__file';
            link.href = String(messageData.attachment.url || '#');
            link.target = '_blank';
            link.rel = 'noopener';
            link.download = String(messageData.attachment.name || 'attachment');
            link.innerHTML = '<i class="fas fa-download"></i><span>' + escapeHtml(String(messageData.attachment.name || 'Файл')) + '</span>';
            attachmentWrap.appendChild(link);
        }
        bubble.appendChild(attachmentWrap);
    }

    messageWrap.appendChild(bubble);
    container.appendChild(messageWrap);
    container.scrollTop = container.scrollHeight;
}

function showThreadToast(messageData) {
    if (!messageData) return;
    const previewSource = String(messageData.content || '').trim();
    const preview = previewSource !== '' ? previewSource.slice(0, 60) : 'Новое сообщение в чате';
    const authorName = String(messageData.author_name || 'Пользователь').trim();
    showToast(`${authorName}: ${preview}${previewSource.length > 60 ? '...' : ''}`, 'success');
}

function buildAttachmentPreviewMarkup(file) {
    if (!file) return '';
    const fileName = escapeHtml(file.name || 'Файл');
    const fileSizeRaw = Number(file.size || 0);
    const fileSize = Number.isFinite(fileSizeRaw) && fileSizeRaw > 0
        ? (fileSizeRaw >= 1024 * 1024
            ? (fileSizeRaw / (1024 * 1024)).toFixed(1).replace('.0', '') + ' МБ'
            : (fileSizeRaw >= 1024 ? Math.round(fileSizeRaw / 1024) + ' КБ' : fileSizeRaw + ' Б'))
        : '';
    const fileMeta = fileSize !== '' ? `<span class="chat-composer__attachment-preview-size">${escapeHtml(fileSize)}</span>` : '';
    const isImage = String(file.type || '').startsWith('image/') || /\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(String(file.name || ''));
    if (isImage) {
        const objectUrl = URL.createObjectURL(file);
        return `<div class="chat-composer__attachment-preview-item chat-composer__attachment-preview-item--image" title="${fileName}"><button type="button" class="chat-composer__attachment-preview-main js-local-image-preview" data-image-src="${escapeHtml(objectUrl)}" data-image-title="${fileName}" title="${fileName}"><img src="${escapeHtml(objectUrl)}" alt="${fileName}" class="chat-composer__attachment-preview-thumb"><span class="chat-composer__attachment-preview-text"><span class="chat-composer__attachment-preview-name">${fileName}</span>${fileMeta}</span></button><button type="button" class="chat-composer__attachment-remove js-message-attachment-remove" title="Удалить вложение" aria-label="Удалить вложение"><i class="fas fa-times"></i></button></div>`;
    }
    return `<div class="chat-composer__attachment-preview-item" title="${fileName}"><span class="chat-composer__attachment-preview-icon"><i class="fas fa-paperclip"></i></span><span class="chat-composer__attachment-preview-text"><span class="chat-composer__attachment-preview-name">${fileName}</span>${fileMeta}</span><button type="button" class="chat-composer__attachment-remove js-message-attachment-remove" title="Удалить вложение" aria-label="Удалить вложение"><i class="fas fa-times"></i></button></div>`;
}

function initAttachmentPreview(input) {
    if (!input) return;
    const preview = input.closest('form')?.querySelector('.js-message-attachment-preview');
    if (!preview) return;
    input.addEventListener('change', () => {
        preview.querySelectorAll('.js-local-image-preview').forEach((button) => {
            const src = String(button.dataset.imageSrc || '');
            if (src.startsWith('blob:')) {
                URL.revokeObjectURL(src);
            }
        });
        const file = input.files && input.files[0] ? input.files[0] : null;
        preview.innerHTML = '';
        preview.hidden = !file;
        if (!file) return;
        preview.innerHTML = buildAttachmentPreviewMarkup(file);
        preview.querySelectorAll('.js-local-image-preview').forEach((button) => {
            button.addEventListener('click', () => {
                openMessageImagePreview(
                    encodeURIComponent(button.dataset.imageSrc || ''),
                    encodeURIComponent(button.dataset.imageTitle || 'Предпросмотр изображения')
                );
            });
        });
        preview.querySelectorAll('.js-message-attachment-remove').forEach((button) => {
            button.addEventListener('click', () => {
                preview.querySelectorAll('.js-local-image-preview').forEach((previewButton) => {
                    const src = String(previewButton.dataset.imageSrc || '');
                    if (src.startsWith('blob:')) {
                        URL.revokeObjectURL(src);
                    }
                });
                input.value = '';
                preview.innerHTML = '';
                preview.hidden = true;
            });
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    bindAutoGrowingChatTextareas();
    let threadPollTimerId = null;
    let isThreadChatOpen = Boolean(document.getElementById('threadChatModal'));
    let latestThreadMessageId = Math.max(
        0,
        ...Array.from(document.querySelectorAll('#threadChatMessages .dispute-chat-message'))
            .map((node) => Number(node.dataset.messageId || 0))
            .filter((value) => Number.isFinite(value))
    );

    async function pollThreadMessages() {
        if (!document.getElementById('threadChatModal')) return;

        try {
            const url = new URL(window.location.href);
            url.searchParams.set('action', 'poll_thread_messages');
            url.searchParams.set('chat_application_id', String(<?= (int) $selectedChatApplicationId ?>));
            url.searchParams.set('chat_title', <?= json_encode((string) $selectedChatTitle) ?>);
            url.searchParams.set('last_message_id', String(latestThreadMessageId));
            const response = await fetch(url.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            if (!response.ok || !data.success || !Array.isArray(data.messages)) return;

            data.messages.forEach((messageData) => {
                appendThreadMessage(messageData);
                const numericId = Number(messageData.id || 0);
                if (numericId > latestThreadMessageId) {
                    latestThreadMessageId = numericId;
                }
                if (!isThreadChatOpen) {
                    showThreadToast(messageData);
                }
            });
        } catch (error) {
            console.error('Ошибка polling чата пользователя:', error);
        }
    }

    function scheduleThreadPolling() {
        if (threadPollTimerId) {
            clearTimeout(threadPollTimerId);
            threadPollTimerId = null;
        }
        if (!document.getElementById('threadChatModal')) return;
        const delay = isThreadChatOpen ? 5000 : 30000;
        threadPollTimerId = setTimeout(async () => {
            await pollThreadMessages();
            scheduleThreadPolling();
        }, delay);
    }

    if (document.getElementById('threadChatModal')) {
        document.body.style.overflow = 'hidden';
        const messagesWrap = document.getElementById('threadChatMessages');
        if (messagesWrap) {
            messagesWrap.scrollTop = messagesWrap.scrollHeight;
        }
        isThreadChatOpen = true;
        scheduleThreadPolling();
    }

    document.querySelectorAll('.message-row').forEach((row) => {
        row.addEventListener('click', (event) => {
            if (event.target.closest('button, a, input, label')) {
                return;
            }
            viewMessage(row);
        });
    });

    document.querySelectorAll('.js-view-message').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            viewMessage(button.closest('.message-row'));
        });
    });

    document.querySelectorAll('.js-message-attachment-input').forEach(initAttachmentPreview);

    const sendMessageForm = document.querySelector('#sendMessageModal form');
    if (sendMessageForm) {
        let isSendingMessage = false;
        sendMessageForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (isSendingMessage || !sendMessageForm.reportValidity()) return;
            isSendingMessage = true;

            const submitButton = sendMessageForm.querySelector('button[type="submit"]');
            const originalButtonHtml = submitButton ? submitButton.innerHTML : '';
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';
            }

            const formData = new FormData(sendMessageForm);
            formData.append('ajax', '1');

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'Не удалось отправить сообщение');
                }

                buildNotificationCard(data.message);
                updateUserMessageStats();
                sendMessageForm.reset();
                sendMessageForm.querySelectorAll('.chat-composer__textarea').forEach(autoResizeChatTextarea);
                const preview = sendMessageForm.querySelector('.js-message-attachment-preview');
                if (preview) {
                    preview.querySelectorAll('.js-local-image-preview').forEach((button) => {
                        const src = String(button.dataset.imageSrc || '');
                        if (src.startsWith('blob:')) {
                            URL.revokeObjectURL(src);
                        }
                    });
                    preview.innerHTML = '';
                    preview.hidden = true;
                }
                document.querySelectorAll('#sendMessageModal .priority-btn').forEach((btn) => btn.classList.remove('selected'));
                document.querySelector('#sendMessageModal .priority-btn--normal')?.classList.add('selected');
                closeSendModal();
                showToast('Сообщение отправлено', 'success');
            } catch (error) {
                showToast(error.message || 'Ошибка отправки сообщения', 'error');
            } finally {
                isSendingMessage = false;
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonHtml;
                }
            }
        });
    }

    const threadChatForm = document.querySelector('#threadChatModal form.dispute-chat-modal__composer');
    if (threadChatForm) {
        let isSendingThreadReply = false;
        threadChatForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (isSendingThreadReply || !threadChatForm.reportValidity()) return;
            isSendingThreadReply = true;

            const submitButton = threadChatForm.querySelector('button[type="submit"]');
            const originalButtonHtml = submitButton ? submitButton.innerHTML : '';
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';
            }

            const formData = new FormData(threadChatForm);
            formData.append('ajax', '1');

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'Не удалось отправить сообщение');
                }

                appendThreadMessage(data.message);
                latestThreadMessageId = Math.max(latestThreadMessageId, Number(data.message?.id || 0));
                threadChatForm.reset();
                threadChatForm.querySelectorAll('.chat-composer__textarea').forEach(autoResizeChatTextarea);
                const preview = threadChatForm.querySelector('.js-message-attachment-preview');
                if (preview) {
                    preview.querySelectorAll('.js-local-image-preview').forEach((button) => {
                        const src = String(button.dataset.imageSrc || '');
                        if (src.startsWith('blob:')) {
                            URL.revokeObjectURL(src);
                        }
                    });
                    preview.innerHTML = '';
                    preview.hidden = true;
                }
                showToast('Сообщение отправлено в чат', 'success');
            } catch (error) {
                showToast(error.message || 'Ошибка отправки сообщения', 'error');
            } finally {
                isSendingThreadReply = false;
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonHtml;
                }
            }
        });
    }

    document.getElementById('sendMessageModal')?.addEventListener('click', function(event) {
        if (event.target === this) closeSendModal();
    });
    document.getElementById('createChatModal')?.addEventListener('click', function(event) {
        if (event.target === this) closeCreateChatModal();
    });
    document.getElementById('viewMessageModal')?.addEventListener('click', function(event) {
        if (event.target === this) closeViewModal();
    });
    document.getElementById('messageImagePreviewModal')?.addEventListener('click', function(event) {
        if (event.target === this) closeMessageImagePreview();
    });
    document.getElementById('threadChatModal')?.addEventListener('click', function(event) {
        if (event.target === this) closeThreadChatModal();
    });
    document.querySelectorAll('.js-open-message-image').forEach((button) => {
        button.addEventListener('click', () => {
            openMessageImagePreview(
                encodeURIComponent(button.dataset.imageUrl || ''),
                encodeURIComponent(button.dataset.imageTitle || 'Предпросмотр изображения')
            );
        });
    });
    document.querySelectorAll('.js-chat-hotkey').forEach((textarea) => {
        textarea.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                event.preventDefault();
                const form = textarea.closest('form');
                if (form && form.reportValidity()) {
                    form.requestSubmit();
                }
            }
        });
    });

    window.addEventListener('focus', () => {
        if (document.getElementById('threadChatModal')) {
            pollThreadMessages();
            scheduleThreadPolling();
        }
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
