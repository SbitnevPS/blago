<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';
if (!isAdmin()) {
redirect('/admin/login');
}

check_csrf();
$adminId = (int) (getCurrentAdminId() ?? 0);
$admin = null;
if ($adminId > 0) {
    $adminStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $adminStmt->execute([$adminId]);
    $admin = $adminStmt->fetch();
}
if (empty($admin)) {
    redirect('/admin/login');
}
$currentPage = 'messages';
$pageTitle = 'Сообщения';
$breadcrumb = 'Все отправленные сообщения';
$messagesReturnUrl = (string) ($_SERVER['REQUEST_URI'] ?? '/admin/messages');

$disputeThreadSubjectPrefix = 'Оспаривание решения по заявке #';
$selectedDisputeApplicationId = intval($_GET['dispute_application_id'] ?? 0);
$disputeThreads = [];
$selectedDisputeMessages = [];
$disputeRecipientName = 'Пользователь';
$isDisputeChatClosed = false;
$selectedApplicationStatus = '';
$viewParam = (string) ($_GET['view'] ?? '');
$messagesView = in_array($viewParam, ['main', 'disputes', 'disputes_archive'], true) ? $viewParam : 'main';
$disputeThreadsCount = 0;
$disputeUnreadTotal = 0;
$messageWelcomeTemplate = (string) getSystemSetting('message_welcome_template', "Здравствуйте, {name}!\n\n");

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

if (!function_exists('adminMessagesHasDisputeChatClosedColumn')) {
    function adminMessagesHasDisputeChatClosedColumn(PDO $pdo): bool {
        static $hasColumn = null;
        if ($hasColumn !== null) {
            return $hasColumn;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM applications LIKE 'dispute_chat_closed'");
            $hasColumn = (bool) ($stmt && $stmt->fetch());
        } catch (Exception $e) {
            $hasColumn = false;
        }

        return $hasColumn;
    }
}

if (!function_exists('adminMessagesHasDisputeChatArchivedColumn')) {
    function adminMessagesHasDisputeChatArchivedColumn(PDO $pdo): bool {
        static $hasColumn = null;
        if ($hasColumn !== null) {
            return $hasColumn;
        }
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM applications LIKE 'dispute_chat_archived'");
            $hasColumn = (bool) ($stmt && $stmt->fetch());
        } catch (Exception $e) {
            $hasColumn = false;
        }
        return $hasColumn;
    }
}

if (!function_exists('deleteDeclineNotificationsForApplication')) {
    function deleteDeclineNotificationsForApplication(PDO $pdo, int $applicationId): void {
        if ($applicationId <= 0) {
            return;
        }

        $declinedSubject = getSystemSetting('application_declined_subject', 'Ваша заявка отклонена');
        $declineLike = '%' . '#' . $applicationId . '%';
        $disputeTitle = buildDisputeChatTitle($applicationId);

        $deleteAdminStmt = $pdo->prepare("
            DELETE FROM admin_messages
            WHERE subject = ?
              AND message LIKE ?
        ");
        $deleteAdminStmt->execute([$declinedSubject, $declineLike]);

        $deleteDisputeStmt = $pdo->prepare("
            DELETE FROM messages
            WHERE application_id = ?
              AND title = ?
        ");
        $deleteDisputeStmt->execute([$applicationId, $disputeTitle]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply_dispute') {
    $isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (string) ($_POST['ajax'] ?? '') === '1';
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности';
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => $error], 403);
        }
    } else {
        $disputeApplicationId = intval($_POST['dispute_application_id'] ?? 0);
        $replyText = trim($_POST['reply_text'] ?? '');
        $attachmentUpload = uploadMessageAttachment($_FILES['attachment'] ?? []);
        if (empty($attachmentUpload['success'])) {
            $error = (string) ($attachmentUpload['message'] ?? 'Не удалось загрузить вложение.');
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $error], 422);
            }
        } elseif ($disputeApplicationId <= 0 || ($replyText === '' && empty($attachmentUpload['uploaded']))) {
            $error = 'Введите текст ответа или прикрепите файл';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $error], 422);
            }
        } else {
            $clientRequestId = trim((string) ($_POST['client_request_id'] ?? ''));
            if ($isAjaxRequest && $clientRequestId !== '') {
                if (!isset($_SESSION['processed_dispute_reply_ids']) || !is_array($_SESSION['processed_dispute_reply_ids'])) {
                    $_SESSION['processed_dispute_reply_ids'] = [];
                }
                if (isset($_SESSION['processed_dispute_reply_ids'][$clientRequestId])) {
                    jsonResponse(['success' => true, 'duplicate' => true]);
                }
            }

            $isClosedForReply = false;
            if (adminMessagesHasDisputeChatClosedColumn($pdo)) {
                $closedCheckStmt = $pdo->prepare("SELECT dispute_chat_closed FROM applications WHERE id = ? LIMIT 1");
                $closedCheckStmt->execute([$disputeApplicationId]);
                $isClosedForReply = (int) $closedCheckStmt->fetchColumn() === 1;
            }

            if ($isClosedForReply) {
                $error = 'Чат завершён. Отправка сообщений отключена.';
                if ($isAjaxRequest) {
                    jsonResponse(['success' => false, 'error' => $error], 423);
                }
            } else {
                $threadSubject = buildDisputeChatTitle($disputeApplicationId);
                $userStmt = $pdo->prepare("
                SELECT m.user_id
                FROM messages m
                WHERE m.application_id = ? AND m.title = ?
                ORDER BY m.created_at DESC
                LIMIT 1
                ");
                $userStmt->execute([$disputeApplicationId, $threadSubject]);
                $targetUserId = (int) $userStmt->fetchColumn();

                if ($targetUserId > 0) {
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
                        $targetUserId,
                        $disputeApplicationId,
                        $threadSubject,
                        $replyText,
                        $admin['id'],
                        $attachmentFile,
                        $attachmentOriginalName,
                        $attachmentMimeType,
                        $attachmentSize,
                    ]);
                    if ($isAjaxRequest) {
                        if ($clientRequestId !== '') {
                            $_SESSION['processed_dispute_reply_ids'][$clientRequestId] = time();
                            if (count($_SESSION['processed_dispute_reply_ids']) > 200) {
                                asort($_SESSION['processed_dispute_reply_ids']);
                                $_SESSION['processed_dispute_reply_ids'] = array_slice($_SESSION['processed_dispute_reply_ids'], -150, null, true);
                            }
                        }
                        $adminName = trim(($admin['surname'] ?? '') . ' ' . ($admin['name'] ?? '') . ' ' . ($admin['patronymic'] ?? ''));
                        if ($adminName === '') {
                            $adminName = 'Администратор';
                        }
                        jsonResponse([
                            'success' => true,
                            'message' => [
                                'id' => (int) $pdo->lastInsertId(),
                                'content' => $replyText,
                                'created_at' => date('d.m.Y H:i'),
                                'author_label' => 'Руководитель проекта — ' . $adminName,
                                'from_admin' => true,
                                'author_name' => $adminName,
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
                    $_SESSION['success_message'] = 'Ответ отправлен в чат';
                    redirect('/admin/messages?view=disputes&dispute_application_id=' . $disputeApplicationId);
                } else {
                    $error = 'Чат не найден';
                    if ($isAjaxRequest) {
                        jsonResponse(['success' => false, 'error' => $error], 404);
                    }
                }
            }
        }
    }
}

if (($_GET['action'] ?? '') === 'poll_dispute_messages') {
    $disputeApplicationId = intval($_GET['dispute_application_id'] ?? 0);
    $lastMessageId = max(0, intval($_GET['last_message_id'] ?? 0));
    if ($disputeApplicationId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Некорректный ID заявки'], 422);
    }

    $newMessagesRaw = [];
    foreach (getDisputeChatTitleVariants($disputeApplicationId) as $threadSubject) {
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
                author.is_admin AS author_is_admin,
                author.email AS author_email
            FROM messages m
            JOIN users author ON author.id = m.created_by
            WHERE m.application_id = ?
              AND m.title = ?
              AND m.id > ?
            ORDER BY m.id ASC
        ");
        $pollStmt->execute([$disputeApplicationId, $threadSubject, $lastMessageId]);
        $newMessagesRaw = $pollStmt->fetchAll();
        if (!empty($newMessagesRaw)) {
            break;
        }
    }

    $newMessages = [];
    foreach ($newMessagesRaw as $messageRow) {
        $authorName = trim(
            ($messageRow['author_surname'] ?? '')
            . ' '
            . ($messageRow['author_name'] ?? '')
            . ' '
            . ($messageRow['author_patronymic'] ?? '')
        );
        $fromAdmin = (int) ($messageRow['author_is_admin'] ?? 0) === 1;
        $newMessages[] = [
            'id' => (int) $messageRow['id'],
            'content' => (string) ($messageRow['content'] ?? ''),
            'created_at' => date('d.m.Y H:i', strtotime((string) $messageRow['created_at'])),
            'author_label' => $fromAdmin
                ? 'Руководитель проекта — ' . ($authorName !== '' ? $authorName : 'Администратор')
                : ($authorName !== '' ? $authorName : 'Пользователь'),
            'from_admin' => $fromAdmin,
            'author_name' => $authorName,
            'author_email' => (string) ($messageRow['author_email'] ?? ''),
            'attachment' => !empty($messageRow['attachment_file']) ? [
                'url' => buildMessageAttachmentPublicUrl((string) $messageRow['attachment_file']),
                'name' => (string) ($messageRow['attachment_original_name'] ?? basename((string) $messageRow['attachment_file'])),
                'mime_type' => (string) ($messageRow['attachment_mime_type'] ?? ''),
                'is_image' => isImageMessageAttachment((string) ($messageRow['attachment_mime_type'] ?? ''), (string) ($messageRow['attachment_original_name'] ?? '')),
            ] : null,
        ];
    }

    jsonResponse([
        'success' => true,
        'messages' => $newMessages,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close_dispute_chat') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности';
    } else {
        $disputeApplicationId = intval($_POST['dispute_application_id'] ?? 0);
        if ($disputeApplicationId <= 0) {
            $error = 'Чат не найден';
        } else {
            try {
                if (!adminMessagesHasDisputeChatClosedColumn($pdo)) {
                    $pdo->exec("ALTER TABLE applications ADD COLUMN dispute_chat_closed TINYINT(1) NOT NULL DEFAULT 0");
                }
                if (!adminMessagesHasDisputeChatArchivedColumn($pdo)) {
                    $pdo->exec("ALTER TABLE applications ADD COLUMN dispute_chat_archived TINYINT(1) NOT NULL DEFAULT 0");
                }
                $closeStmt = $pdo->prepare("UPDATE applications SET dispute_chat_closed = 1, dispute_chat_archived = 1 WHERE id = ?");
                $closeStmt->execute([$disputeApplicationId]);
                $_SESSION['success_message'] = 'Чат завершён и перемещён в архив. Пользователь больше не сможет отправлять сообщения.';
                redirect('/admin/messages?view=disputes_archive&dispute_application_id=' . $disputeApplicationId);
            } catch (Exception $e) {
                $error = 'Не удалось завершить чат';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reopen_dispute_chat') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности';
    } else {
        $disputeApplicationId = intval($_POST['dispute_application_id'] ?? 0);
        if ($disputeApplicationId > 0) {
            try {
                if (!adminMessagesHasDisputeChatClosedColumn($pdo)) {
                    $pdo->exec("ALTER TABLE applications ADD COLUMN dispute_chat_closed TINYINT(1) NOT NULL DEFAULT 0");
                }
                if (!adminMessagesHasDisputeChatArchivedColumn($pdo)) {
                    $pdo->exec("ALTER TABLE applications ADD COLUMN dispute_chat_archived TINYINT(1) NOT NULL DEFAULT 0");
                }
                $pdo->prepare("UPDATE applications SET dispute_chat_closed = 0, dispute_chat_archived = 0 WHERE id = ?")->execute([$disputeApplicationId]);
                $_SESSION['success_message'] = 'Чат возобновлён';
                redirect('/admin/messages?view=disputes&dispute_application_id=' . $disputeApplicationId);
            } catch (Exception $e) {
                $error = 'Не удалось возобновить чат';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_dispute_application') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности';
    } else {
        $disputeApplicationId = intval($_POST['dispute_application_id'] ?? 0);
        if ($disputeApplicationId <= 0) {
            $error = 'Заявка не найдена';
        } else {
            try {
                $approveStmt = $pdo->prepare("UPDATE applications SET status = 'approved', allow_edit = 0 WHERE id = ?");
                $approveStmt->execute([$disputeApplicationId]);
                deleteDeclineNotificationsForApplication($pdo, $disputeApplicationId);
                $_SESSION['success_message'] = 'Заявка принята';
                redirect('/admin/messages?view=disputes&dispute_application_id=' . $disputeApplicationId);
            } catch (Exception $e) {
                $error = 'Не удалось одобрить заявку';
            }
        }
    }
}

try {
    $archivedFilterSql = '';
    if (adminMessagesHasDisputeChatArchivedColumn($pdo)) {
        $pdo->exec("UPDATE applications SET dispute_chat_archived = 1 WHERE IFNULL(dispute_chat_closed, 0) = 1 AND IFNULL(dispute_chat_archived, 0) = 0");
        $archivedFilterSql = $messagesView === 'disputes_archive'
            ? ' AND a.dispute_chat_archived = 1 '
            : ' AND IFNULL(a.dispute_chat_archived, 0) = 0 ';
    }

    $threadsStmt = $pdo->query("
    SELECT
        m.application_id,
        m.title,
        MAX(IFNULL(a.dispute_chat_closed, 0)) AS dispute_chat_closed,
        MAX(IFNULL(a.dispute_chat_archived, 0)) AS dispute_chat_archived,
        MAX(m.created_at) AS last_message_at,
        SUM(CASE WHEN m.is_read = 0 AND u.is_admin = 0 THEN 1 ELSE 0 END) AS unread_count,
        SUBSTRING_INDEX(
            GROUP_CONCAT(m.content ORDER BY m.created_at DESC SEPARATOR '||__||'),
            '||__||',
            1
        ) AS last_message
    FROM messages m
    JOIN users u ON u.id = m.created_by
    LEFT JOIN applications a ON a.id = m.application_id
    WHERE (m.title LIKE 'Оспаривание решения по заявке%' OR m.title LIKE 'Оспаривание заявки%')
    $archivedFilterSql
    GROUP BY m.application_id, m.title
    ORDER BY last_message_at DESC
    ");
    $disputeThreads = $threadsStmt->fetchAll();
    $disputeThreadsCount = count($disputeThreads);
    foreach ($disputeThreads as $threadRow) {
        $disputeUnreadTotal += (int) ($threadRow['unread_count'] ?? 0);
    }
} catch (Exception $e) {
    $disputeThreads = [];
    $disputeThreadsCount = 0;
    $disputeUnreadTotal = 0;
}

if ($selectedDisputeApplicationId > 0) {
    try {
        $applicationStatusStmt = $pdo->prepare("SELECT status FROM applications WHERE id = ? LIMIT 1");
        $applicationStatusStmt->execute([$selectedDisputeApplicationId]);
        $selectedApplicationStatus = (string) ($applicationStatusStmt->fetchColumn() ?: '');

        if (adminMessagesHasDisputeChatClosedColumn($pdo)) {
            $closedStmt = $pdo->prepare("SELECT dispute_chat_closed FROM applications WHERE id = ? LIMIT 1");
            $closedStmt->execute([$selectedDisputeApplicationId]);
            $isDisputeChatClosed = (int) $closedStmt->fetchColumn() === 1;
        }

        foreach (getDisputeChatTitleVariants($selectedDisputeApplicationId) as $threadSubject) {
            $markReadStmt = $pdo->prepare("
            UPDATE messages m
            JOIN users u ON u.id = m.created_by
            SET m.is_read = 1
            WHERE m.application_id = ?
              AND m.title = ?
              AND m.is_read = 0
              AND u.is_admin = 0
            ");
            $markReadStmt->execute([$selectedDisputeApplicationId, $threadSubject]);

            $selectedStmt = $pdo->prepare("
            SELECT
                m.id,
                m.user_id,
                m.created_by,
                m.application_id,
                m.title,
                m.content,
                m.is_read,
                m.created_at,
                m.attachment_file,
                m.attachment_original_name,
                m.attachment_mime_type,
                author.id AS author_id,
                author.name AS author_name,
                author.surname AS author_surname,
                author.patronymic AS author_patronymic,
                author.is_admin AS author_is_admin,
                recipient.id AS recipient_id,
                recipient.name AS recipient_name,
                recipient.surname AS recipient_surname,
                recipient.patronymic AS recipient_patronymic
            FROM messages m
            JOIN users author ON author.id = m.created_by
            LEFT JOIN users recipient ON recipient.id = m.user_id
            WHERE m.application_id = ?
              AND m.title = ?
            ORDER BY m.created_at ASC
        ");
            $selectedStmt->execute([$selectedDisputeApplicationId, $threadSubject]);
            $selectedDisputeMessages = $selectedStmt->fetchAll();
            if (!empty($selectedDisputeMessages)) {
                break;
            }
        }

        if (!empty($selectedDisputeMessages)) {
            $firstMessage = $selectedDisputeMessages[0];
            $disputeRecipientName = trim(
                ($firstMessage['recipient_surname'] ?? '')
                . ' '
                . ($firstMessage['recipient_name'] ?? '')
                . ' '
                . ($firstMessage['recipient_patronymic'] ?? '')
            );
            if ($disputeRecipientName === '') {
                $disputeRecipientName = 'Пользователь';
            }
        }
    } catch (Exception $e) {
        $selectedDisputeMessages = [];
    }
}
// --- Фильтры ---
$search = $_GET['search'] ?? '';
$priority = $_GET['priority'] ?? '';
$sort = $_GET['sort'] ?? 'id_desc';
$filterUserId = max(0, (int) ($_GET['user_id'] ?? 0));
$filterUserQuery = trim((string) ($_GET['user_query'] ?? ''));
$allowedPriorities = ['normal', 'important', 'critical'];
if ($priority && !in_array($priority, $allowedPriorities)) {
$priority = '';
}
// --- Сортировка ---
$sortMap = [
'id_asc' => ['am.id', 'ASC'],
'id_desc' => ['am.id', 'DESC'],
'date_asc' => ['am.created_at', 'ASC'],
'date_desc' => ['am.created_at', 'DESC'],
];
[$sortField, $sortDir] = $sortMap[$sort] ?? $sortMap['id_desc'];
// --- Пагинация ---
$page = max(1, intval($_GET['page'] ??1));
$perPage =20;
$offset = ($page -1) * $perPage;
// --- WHERE ---
$where = "1=1";
$params = [];
if ($search) {
$where .= " AND (am.subject LIKE ? OR am.message LIKE ? OR u.name LIKE ? OR u.surname LIKE ?)";
$searchTerm = "%$search%";
$params = array_fill(0,4, $searchTerm);
}
if ($filterUserId > 0) {
    $where .= " AND am.user_id = ?";
    $params[] = $filterUserId;
} elseif ($filterUserQuery !== '') {
    $userSearchConditions = build_user_search_conditions('u');
    $where .= " AND (
        " . implode("
        OR ", $userSearchConditions) . "
    )";
    $userSearchTerm = '%' . $filterUserQuery . '%';
    $params = array_merge($params, array_fill(0, count($userSearchConditions), $userSearchTerm));
}
if ($priority) {
$where .= " AND am.priority = ?";
$params[] = $priority;
}
// --- COUNT ---
$countStmt = $pdo->prepare("
SELECT COUNT(DISTINCT am.user_id)
FROM admin_messages am
LEFT JOIN users u ON am.user_id = u.id
WHERE $where
");
$countStmt->execute($params);
$totalMessages = $countStmt->fetchColumn();
$totalPages = $perPage ? ceil($totalMessages / $perPage) :1;
// --- ДАННЫЕ ---
$stmt = $pdo->prepare("
SELECT
    am.user_id,
    u.name as user_name,
    u.surname as user_surname,
    u.patronymic as user_patronymic,
    u.email as user_email,
    COUNT(am.id) as messages_count,
    MAX(am.created_at) as last_message_at,
    SUBSTRING_INDEX(
        GROUP_CONCAT(am.subject ORDER BY am.created_at DESC, am.id DESC SEPARATOR '||__||'),
        '||__||',
        1
    ) as last_subject,
    SUBSTRING_INDEX(
        GROUP_CONCAT(am.priority ORDER BY am.created_at DESC, am.id DESC SEPARATOR '||__||'),
        '||__||',
        1
    ) as last_priority
FROM admin_messages am
LEFT JOIN users u ON am.user_id = u.id
LEFT JOIN users ad ON am.admin_id = ad.id
WHERE $where
GROUP BY am.user_id, u.name, u.surname, u.patronymic, u.email
ORDER BY MAX(am.created_at) DESC, am.user_id DESC
LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$messages = $stmt->fetchAll();

$chatUnreadByUser = [];
try {
    $chatUnreadStmt = $pdo->query("
        SELECT
            m.user_id,
            COUNT(*) AS unread_count,
            MAX(m.created_at) AS last_unread_at
        FROM messages m
        JOIN users author ON author.id = m.created_by
        WHERE m.is_read = 0
          AND author.is_admin = 0
        GROUP BY m.user_id
    ");
    foreach ($chatUnreadStmt->fetchAll() as $chatUnreadRow) {
        $chatUnreadByUser[(int) ($chatUnreadRow['user_id'] ?? 0)] = [
            'unread_count' => (int) ($chatUnreadRow['unread_count'] ?? 0),
            'last_unread_at' => (string) ($chatUnreadRow['last_unread_at'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $chatUnreadByUser = [];
}

if (!empty($chatUnreadByUser)) {
    $knownUserIds = [];
    foreach ($messages as $messageRow) {
        $knownUserIds[(int) ($messageRow['user_id'] ?? 0)] = true;
    }

    $missingUserIds = array_values(array_filter(array_keys($chatUnreadByUser), static function ($userId) use ($knownUserIds) {
        return $userId > 0 && !isset($knownUserIds[(int) $userId]);
    }));

    if (!empty($missingUserIds)) {
        $placeholders = implode(',', array_fill(0, count($missingUserIds), '?'));
        $missingUsersStmt = $pdo->prepare("
            SELECT id, name, surname, patronymic, email
            FROM users
            WHERE id IN ($placeholders)
            ORDER BY surname ASC, name ASC, id ASC
        ");
        $missingUsersStmt->execute($missingUserIds);
        foreach ($missingUsersStmt->fetchAll() as $userRow) {
            $userId = (int) ($userRow['id'] ?? 0);
            if ($userId <= 0 || !isset($chatUnreadByUser[$userId])) {
                continue;
            }
            $messages[] = [
                'user_id' => $userId,
                'user_name' => (string) ($userRow['name'] ?? ''),
                'user_surname' => (string) ($userRow['surname'] ?? ''),
                'user_patronymic' => (string) ($userRow['patronymic'] ?? ''),
                'user_email' => (string) ($userRow['email'] ?? ''),
                'messages_count' => 0,
                'last_message_at' => (string) ($chatUnreadByUser[$userId]['last_unread_at'] ?? date('Y-m-d H:i:s')),
                'last_subject' => 'Новый ответ пользователя в чате',
                'last_priority' => 'important',
            ];
        }

        usort($messages, static function (array $left, array $right): int {
            $leftTime = strtotime((string) ($left['last_message_at'] ?? '')) ?: 0;
            $rightTime = strtotime((string) ($right['last_message_at'] ?? '')) ?: 0;
            if ($leftTime === $rightTime) {
                return ((int) ($right['user_id'] ?? 0)) <=> ((int) ($left['user_id'] ?? 0));
            }
            return $rightTime <=> $leftTime;
        });
    }
}
// --- СТАТИСТИКА ---
$priorityStats = $pdo->query("
SELECT priority, COUNT(*) as count 
FROM admin_messages 
GROUP BY priority
")->fetchAll(PDO::FETCH_KEY_PAIR);
// --- ОТПРАВКА ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
$error = 'Ошибка безопасности';
} else {
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');
$priority = $_POST['priority'] ?? 'normal';
$sendToAll = isset($_POST['send_to_all']);
$userId = $_POST['user_id'] ?? null;
$attachmentUpload = uploadMessageAttachment($_FILES['attachment'] ?? []);
if (!in_array($priority, $allowedPriorities)) {
$priority = 'normal';
}
if (empty($attachmentUpload['success'])) {
$error = (string) ($attachmentUpload['message'] ?? 'Не удалось загрузить вложение.');
} elseif (!$subject || ($message === '' && empty($attachmentUpload['uploaded']))) {
$error = 'Заполните тему и сообщение или прикрепите файл';
} else {
[$attachmentFile, $attachmentOriginalName, $attachmentMimeType, $attachmentSize] = messageAttachmentInsertPayload($attachmentUpload);
if ($sendToAll) {
$users = $pdo->query("SELECT id FROM users WHERE is_admin =0")
->fetchAll(PDO::FETCH_COLUMN);
$pdo->beginTransaction();
$stmt = $pdo->prepare("
INSERT INTO admin_messages 
(user_id, admin_id, subject, message, priority, is_broadcast, created_at, attachment_file, attachment_original_name, attachment_mime_type, attachment_size)
VALUES (?, ?, ?, ?, ?,1, NOW(), ?, ?, ?, ?)
");
foreach ($users as $uid) {
$stmt->execute([$uid, $admin['id'], $subject, $message, $priority, $attachmentFile, $attachmentOriginalName, $attachmentMimeType, $attachmentSize]);
}
$pdo->commit();
$success = 'Отправлено: ' . count($users);
} elseif ($userId) {
$stmt = $pdo->prepare("
INSERT INTO admin_messages 
(user_id, admin_id, subject, message, priority, is_broadcast, created_at, attachment_file, attachment_original_name, attachment_mime_type, attachment_size)
VALUES (?, ?, ?, ?, ?,0, NOW(), ?, ?, ?, ?)
");
$stmt->execute([$userId, $admin['id'], $subject, $message, $priority, $attachmentFile, $attachmentOriginalName, $attachmentMimeType, $attachmentSize]);
$success = 'Сообщение отправлено';
} else {
$error = 'Выберите пользователя';
}
}
}
}
// --- УДАЛЕНИЕ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_selected_messages') {
    header('Content-Type: application/json');
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Неверный CSRF токен']);
        exit;
    }

    $selectedRaw = $_POST['selected'] ?? [];
    if (!is_array($selectedRaw) || empty($selectedRaw)) {
        echo json_encode(['success' => false, 'error' => 'Сообщения не выбраны']);
        exit;
    }

    $idsToDelete = [];
    $broadcastPairs = [];
    foreach ($selectedRaw as $item) {
        $item = trim((string) $item);
        if ($item === '') {
            continue;
        }
        if (strpos($item, 'b:') === 0) {
            $parts = explode(':', substr($item, 2), 2);
            if (count($parts) === 2) {
                $broadcastPairs[] = [(int) $parts[0], $parts[1]];
            }
            continue;
        }
        $id = (int) $item;
        if ($id > 0) {
            $idsToDelete[] = $id;
        }
    }

    $deletedCount = 0;
    try {
        $pdo->beginTransaction();
        if (!empty($idsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $deleteByIds = $pdo->prepare("DELETE FROM admin_messages WHERE id IN ($placeholders)");
            $deleteByIds->execute($idsToDelete);
            $deletedCount += $deleteByIds->rowCount();
        }

        if (!empty($broadcastPairs)) {
            $deleteBroadcastStmt = $pdo->prepare("
                DELETE FROM admin_messages
                WHERE is_broadcast = 1 AND admin_id = ? AND subject = ?
            ");
            foreach ($broadcastPairs as [$adminIdValue, $subjectValue]) {
                $deleteBroadcastStmt->execute([$adminIdValue, $subjectValue]);
                $deletedCount += $deleteBroadcastStmt->rowCount();
            }
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'deleted' => $deletedCount]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'error' => 'Ошибка удаления']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_message') {
header('Content-Type: application/json');
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
echo json_encode(['success' => false]);
exit;
}
$messageId = intval($_POST['message_id'] ??0);
$isBroadcast = !empty($_POST['is_broadcast']);
try {
$stmt = $pdo->prepare("SELECT subject, admin_id FROM admin_messages WHERE id = ?");
$stmt->execute([$messageId]);
$msg = $stmt->fetch();
if (!$msg) {
echo json_encode(['success' => false]);
exit;
}
if ($isBroadcast) {
$stmt = $pdo->prepare("
DELETE FROM admin_messages 
WHERE admin_id = ? AND subject = ? AND is_broadcast =1
");
$stmt->execute([$msg['admin_id'], $msg['subject']]);
} else {
$stmt = $pdo->prepare("DELETE FROM admin_messages WHERE id = ?");
$stmt->execute([$messageId]);
}
echo json_encode(['success' => true]);
} catch (Exception $e) {
echo json_encode(['success' => false]);
}
exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user_messages') {
header('Content-Type: application/json');
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
echo json_encode(['success' => false, 'error' => 'Неверный CSRF токен']);
exit;
}
$userIdToDelete = (int) ($_POST['user_id'] ?? 0);
if ($userIdToDelete <= 0) {
echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
exit;
}
try {
    $stmt = $pdo->prepare("DELETE FROM admin_messages WHERE user_id = ?");
    $stmt->execute([$userIdToDelete]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Не удалось удалить сообщения пользователя']);
}
exit;
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .card__body {
        overflow: visible;
    }

    .message-attachment-preview {
        display: block;
    }

    .message-attachment-preview__image-button {
        display: inline-flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
        width: min(100%, 280px);
        padding: 12px;
        border: 1px solid #dbe3f0;
        border-radius: 14px;
        background: #f8fbff;
        cursor: pointer;
        text-align: left;
    }

    .message-attachment-preview__thumb {
        display: block;
        width: 100%;
        max-height: 180px;
        object-fit: contain;
        border-radius: 12px;
        background: #fff;
    }

    .message-attachment-preview__caption {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #2563eb;
        font-size: 13px;
        font-weight: 600;
    }

    .message-attachment-preview__file {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 12px 14px;
        border-radius: 14px;
        border: 1px solid #dbe3f0;
        background: #fff;
        color: #0f172a;
        text-decoration: none;
        font-weight: 600;
    }

    .message-view-attachment {
        margin-top: 16px;
    }

    .message-user-row--unread {
        border-color: #f59e0b;
        background: #fffbeb;
        box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.2);
    }
</style>

<?php if (isset($error)): ?>
<div class="alert alert--error mb-lg js-toast-alert">
<i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<?php if (isset($success)): ?>
<div class="alert alert--success mb-lg js-toast-alert">
<i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>

<div class="messages-tabs" style="margin-bottom:16px;">
    <a href="/admin/messages?view=main" class="messages-tabs__tab <?= $messagesView === 'main' ? 'messages-tabs__tab--active' : '' ?>">
        <i class="fas fa-envelope"></i> Сообщения
    </a>
    <a href="/admin/messages?view=disputes" class="messages-tabs__tab <?= $messagesView === 'disputes' ? 'messages-tabs__tab--active' : '' ?>" style="position:relative;">
        <i class="fas fa-comments"></i> Чаты оспаривания заявок · <?= (int) $disputeThreadsCount ?>
        <?php if ($disputeUnreadTotal > 0): ?><span class="badge badge--warning" style="margin-left:8px;"><?= (int) $disputeUnreadTotal ?></span><?php endif; ?>
    </a>
    <a href="/admin/messages?view=disputes_archive" class="messages-tabs__tab <?= $messagesView === 'disputes_archive' ? 'messages-tabs__tab--active' : '' ?>">
        <i class="fas fa-box-archive"></i> Архив чатов
    </a>
</div>

<?php if ($messagesView === 'disputes'): ?>
<div class="card mb-lg">
    <div class="card__header">
        <h3>Чаты: оспаривание решения по заявке</h3>
    </div>
    <div class="card__body">
        <?php if (empty($disputeThreads)): ?>
            <div class="empty-state" style="padding:20px;">
                <div class="empty-state__icon"><i class="fas fa-comments"></i></div>
                <h3 class="empty-state__title" style="font-size:18px;">Чатов оспаривания пока нет</h3>
                <p class="empty-state__text">Когда пользователи откроют оспаривание по заявке, диалоги появятся здесь.</p>
            </div>
        <?php else: ?>
        <div class="admin-list-cards">
            <?php foreach ($disputeThreads as $thread): ?>
                <article class="admin-list-card <?= (int) ($thread['unread_count'] ?? 0) > 0 ? 'message-user-row--unread' : '' ?>">
                    <div class="admin-list-card__header">
                        <div class="admin-list-card__title-wrap">
                            <h4 class="admin-list-card__title"><?= htmlspecialchars($thread['title']) ?></h4>
                            <div class="admin-list-card__subtitle"><?= htmlspecialchars(mb_substr((string) ($thread['last_message'] ?? ''), 0, 150)) ?></div>
                        </div>
                        <div class="admin-list-card__statuses">
                            <?php if ((int) ($thread['unread_count'] ?? 0) > 0): ?>
                                <span class="badge badge--warning">Новых: <?= (int) $thread['unread_count'] ?></span>
                            <?php endif; ?>
                            <span class="badge <?= (int) ($thread['dispute_chat_closed'] ?? 0) === 1 ? 'badge--secondary' : 'badge--success' ?>">
                                <?= (int) ($thread['dispute_chat_closed'] ?? 0) === 1 ? 'Завершён' : 'Активен' ?>
                            </span>
                        </div>
                    </div>
                    <div class="admin-list-card__meta">
                        <span><strong>Заявка:</strong> <a href="/admin/application/<?= (int) $thread['application_id'] ?>">#<?= (int) $thread['application_id'] ?></a></span>
                        <span><strong>Обновлён:</strong> <?= date('d.m.Y H:i', strtotime($thread['last_message_at'])) ?></span>
                    </div>
                    <div class="admin-list-card__actions">
                        <a class="btn btn--ghost btn--sm" href="/admin/messages?view=disputes&dispute_application_id=<?= (int) $thread['application_id'] ?>">
                            <i class="fas fa-comments"></i> Открыть чат
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($messagesView === 'disputes_archive'): ?>
<div class="card mb-lg">
    <div class="card__header">
        <h3>Архив чатов оспаривания</h3>
    </div>
    <div class="card__body">
        <?php if (empty($disputeThreads)): ?>
            <div class="empty-state" style="padding:20px;">
                <div class="empty-state__icon"><i class="fas fa-box-archive"></i></div>
                <h3 class="empty-state__title" style="font-size:18px;">В архиве пока нет чатов</h3>
                <p class="empty-state__text">Здесь появятся завершённые чаты после переноса в архив.</p>
            </div>
        <?php else: ?>
        <div class="admin-list-cards">
            <?php foreach ($disputeThreads as $thread): ?>
                <article class="admin-list-card">
                    <div class="admin-list-card__header">
                        <div class="admin-list-card__title-wrap">
                            <h4 class="admin-list-card__title"><?= htmlspecialchars($thread['title']) ?></h4>
                            <div class="admin-list-card__subtitle"><?= htmlspecialchars(mb_substr((string) ($thread['last_message'] ?? ''), 0, 150)) ?></div>
                        </div>
                        <span class="badge badge--secondary">Архив</span>
                    </div>
                    <div class="admin-list-card__meta">
                        <span><strong>Заявка:</strong> <a href="/admin/application/<?= (int) $thread['application_id'] ?>">#<?= (int) $thread['application_id'] ?></a></span>
                        <span><strong>Дата:</strong> <?= date('d.m.Y H:i', strtotime($thread['last_message_at'])) ?></span>
                    </div>
                    <div class="admin-list-card__actions">
                        <a class="btn btn--ghost btn--sm" href="/admin/messages?view=disputes_archive&dispute_application_id=<?= (int) $thread['application_id'] ?>">Открыть</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (in_array($messagesView, ['disputes', 'disputes_archive'], true) && $selectedDisputeApplicationId > 0): ?>
<?php
ob_start();
?>
<div class="flex items-center justify-between gap-sm" style="margin-top:16px;">
    <div class="flex items-center gap-sm">
        <?php if ($isDisputeChatClosed): ?>
            <span class="badge" style="background:#6B7280; color:white;">Чат завершён</span>
            <form method="POST" onsubmit="return confirm('Возобновить чат? Пользователь снова сможет писать.');">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="reopen_dispute_chat">
                <input type="hidden" name="dispute_application_id" value="<?= (int) $selectedDisputeApplicationId ?>">
                <button type="submit" class="btn btn--ghost btn--sm" style="color:#2563EB;">
                    <i class="fas fa-lock-open"></i> Возобновить чат
                </button>
            </form>
        <?php else: ?>
            <span class="text-secondary" style="font-size:13px;">Чат активен</span>
            <form method="POST" onsubmit="return confirm('Завершить чат? Пользователь больше не сможет писать.');">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="close_dispute_chat">
                <input type="hidden" name="dispute_application_id" value="<?= (int) $selectedDisputeApplicationId ?>">
                <button type="submit" class="btn btn--ghost btn--sm" style="color:#EF4444;">
                    <i class="fas fa-lock"></i> Завершить чат
                </button>
            </form>
        <?php endif; ?>
    </div>

    <form method="POST" onsubmit="return confirm('Одобрить заявку и изменить статус на \"Заявка принята\"?');">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
        <input type="hidden" name="action" value="approve_dispute_application">
        <input type="hidden" name="dispute_application_id" value="<?= (int) $selectedDisputeApplicationId ?>">
        <button type="submit" class="btn btn--primary btn--sm" <?= $selectedApplicationStatus === 'approved' ? 'disabled' : '' ?>>
            <i class="fas fa-check-circle"></i> Одобрить заявку!
        </button>
    </form>
</div>
<?php
$adminChatExtraMiddleHtml = ob_get_clean();
$adminChatModalId = 'disputeChatModal';
$adminChatModalActive = true;
$adminChatModalTitle = 'Чат по заявке #' . (int) $selectedDisputeApplicationId;
$adminChatCloseHandler = 'closeDisputeChatModal()';
$adminChatApplicationUrl = '/admin/application/' . (int) $selectedDisputeApplicationId;
$adminChatMessagesContainerId = 'disputeChatMessages';
$adminChatMessages = $selectedDisputeMessages;
$adminChatCurrentUserLabel = $disputeRecipientName !== '' ? $disputeRecipientName : 'Пользователь';
$adminChatClosed = $isDisputeChatClosed;
$adminChatClosedText = 'Чат завершён. Пользователь больше не сможет писать.';
$adminChatFormAction = 'reply_dispute';
$adminChatComposerLabel = 'Ответ в чате';
$adminChatComposerTextareaName = 'reply_text';
$adminChatComposerPlaceholder = 'Введите сообщение пользователю...';
$adminChatComposerSubmitText = 'Ответить';
$adminChatComposerHiddenFields = [
    'action' => 'reply_dispute',
    'dispute_application_id' => (string) ((int) $selectedDisputeApplicationId),
    'client_request_id' => '',
];
$adminChatSupportsAttachments = true;
$adminChatAttachmentHelp = 'Можно прикрепить изображение или файл до 10 МБ.';
$adminChatExtraTopHtml = '';
$adminChatExtraBottomHtml = '';
$adminChatImageButtonClass = 'js-open-message-image';
require __DIR__ . '/includes/chat-thread-modal.php';
?>
<?php endif; ?>

<?php if ($messagesView === 'main'): ?>

<!-- Статистика -->
<div class="stats-grid stats-grid--messages mb-lg">
<div class="stat-card stat-card--compact" style="cursor:pointer;" onclick="filterByPriority('')">
<div class="stat-card__icon" style="background: #EEF2FF; color: #6366F1;">
<i class="fas fa-envelope"></i>
</div>
<div class="stat-card__content">
<div class="stat-card__value"><?= e($totalMessages) ?></div>
<div class="stat-card__label">Пользователей с сообщениями</div>
</div>
</div>

<div class="stat-card stat-card--compact" style="cursor:pointer;" onclick="filterByPriority('normal')">
<div class="stat-card__icon" style="background: #F3F4F6; color: #6B7280;">
<i class="fas fa-circle"></i>
</div>
<div class="stat-card__content">
<div class="stat-card__value"><?= $priorityStats['normal'] ??0 ?></div>
<div class="stat-card__label">Обычных уведомлений</div>
</div>
</div>

<div class="stat-card stat-card--compact" style="cursor:pointer;" onclick="filterByPriority('important')">
<div class="stat-card__icon" style="background: #FEF3C7; color: #F59E0B;">
<i class="fas fa-exclamation-circle"></i>
</div>
<div class="stat-card__content">
<div class="stat-card__value"><?= $priorityStats['important'] ??0 ?></div>
<div class="stat-card__label">Важных уведомлений</div>
</div>
</div>

<div class="stat-card stat-card--compact" style="cursor:pointer;" onclick="filterByPriority('critical')">
<div class="stat-card__icon" style="background: #FEE2E2; color: #EF4444;">
<i class="fas fa-exclamation-triangle"></i>
</div>
<div class="stat-card__content">
<div class="stat-card__value"><?= $priorityStats['critical'] ??0 ?></div>
<div class="stat-card__label">Критических уведомлений</div>
</div>
</div>
</div>

<!-- Поиск и фильтры -->
<div class="card card--allow-overflow mb-lg">
<div class="card__body">
<form method="GET" class="flex gap-md" style="align-items:flex-end; flex-wrap:wrap;">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<div style="flex:1; min-width:250px;">
<label class="form-label">Поиск по теме сообщения</label>
<input type="text" name="search" class="form-input" 
 placeholder="Поиск по теме, сообщению или пользователю..." 
 value="<?= htmlspecialchars($search) ?>">
</div>
<div
 style="flex:1; min-width:250px; position:relative;"
 <?= admin_live_search_attrs([
  'endpoint' => '/admin/search-message-users',
  'primary_template' => '{{name + surname||Без имени}}',
  'secondary_template' => '{{email||Email не указан}}',
  'value_template' => '{{name + surname||Без имени}} ({{email||Email не указан}})',
  'limit' => 7,
  'min_length' => 2,
  'min_length_numeric' => 1,
  'debounce' => 250,
 ]) ?>>
<label class="form-label">Поиск по родителю/куратору</label>
<input
 type="text"
 name="user_query"
 id="filterUserSearch"
 data-live-search-input
 class="form-input"
 placeholder="Фильтр по пользователю"
 value="<?= htmlspecialchars($filterUserQuery) ?>"
 autocomplete="off">
<input type="hidden" name="user_id" id="filterUserId" data-live-search-hidden value="<?= (int) $filterUserId ?>">
<div id="filterUserResults" class="user-results" data-live-search-results></div>
</div>
<div style="width:180px;">
<label class="form-label">Приоритет</label>
<select name="priority" class="form-input">
<option value="">Все</option>
<option value="normal" <?= $priority === 'normal' ? 'selected' : '' ?>>Обычное</option>
<option value="important" <?= $priority === 'important' ? 'selected' : '' ?>>Важное</option>
<option value="critical" <?= $priority === 'critical' ? 'selected' : '' ?>>Критическое</option>
</select>
</div>
<button type="submit" class="btn btn--primary">
<i class="fas fa-search"></i> Найти
</button>
<?php if ($search || $priority || $filterUserId > 0 || $filterUserQuery !== ''): ?>
<a href="messages.php" class="btn btn--ghost">Сбросить</a>
<?php endif; ?>
</form>
</div>
</div>

<!-- Список сообщений -->
<div class="messages-page-actions">
<button type="button" class="btn btn--primary" onclick="openSendModal()">
<i class="fas fa-pen"></i> Написать сообщение
</button>
</div>

<div class="card">
<div class="card__header">
<div class="flex justify-between items-center w-100 messages-toolbar">
<h3>Пользователи с сообщениями (<?= e($totalMessages) ?>)</h3>
</div>
</div>
<div class="card__body">
<?php if (empty($messages)): ?>
<div class="text-center text-secondary" style="padding:40px;">Сообщений не найдено</div>
<?php else: ?>
<div class="admin-list-cards">
<?php foreach ($messages as $msg): ?>
<?php
    $userId = (int) ($msg['user_id'] ?? 0);
    $chatUnreadCount = (int) ($chatUnreadByUser[$userId]['unread_count'] ?? 0);
    $userLabel = trim((string) (($msg['user_name'] ?? '') . ' ' . ($msg['user_patronymic'] ?? '') . ' ' . ($msg['user_surname'] ?? '')));
    if ($userLabel === '') {
        $userLabel = trim((string) (($msg['user_surname'] ?? '') . ' ' . ($msg['user_name'] ?? '')));
    }
    if ($userLabel === '') {
        $userLabel = 'Пользователь';
    }
    $userMessagesUrl = '/admin/messages/user/' . $userId;
?>
<article class="admin-list-card message-user-row <?= $chatUnreadCount > 0 ? 'message-user-row--unread' : '' ?>" data-user-id="<?= $userId ?>" data-user-url="<?= e($userMessagesUrl) ?>">
    <div class="admin-list-card__header">
        <div class="admin-list-card__title-wrap">
            <h4 class="admin-list-card__title"><?= htmlspecialchars($userLabel) ?></h4>
            <div class="admin-list-card__subtitle">
                <?= htmlspecialchars((string) ($msg['user_email'] ?? 'Email не указан')) ?>
            </div>
        </div>
        <?php if ($chatUnreadCount > 0): ?>
            <span class="badge badge--warning">Новый ответ: <?= $chatUnreadCount ?></span>
        <?php else: ?>
            <span class="badge <?= ($msg['last_priority'] ?? 'normal') === 'critical' ? 'badge--error' : (($msg['last_priority'] ?? 'normal') === 'important' ? 'badge--warning' : 'badge--secondary') ?>">
                <?= ($msg['last_priority'] ?? 'normal') === 'critical' ? 'Критическое' : (($msg['last_priority'] ?? 'normal') === 'important' ? 'Важное' : 'Обычное') ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="admin-list-card__meta">
        <span><strong>Последнее сообщение:</strong> <?= date('d.m.Y H:i', strtotime((string) $msg['last_message_at'])) ?></span>
        <span><strong>Тема:</strong> <?= htmlspecialchars((string) ($msg['last_subject'] ?? 'Без темы')) ?></span>
        <span><strong>Всего сообщений:</strong> <?= (int) ($msg['messages_count'] ?? 0) ?></span>
    </div>
    <div class="admin-list-card__actions">
        <a href="<?= e($userMessagesUrl) ?>" class="btn btn--primary btn--sm"><i class="fas fa-eye"></i> Открыть</a>
        <button type="button" class="btn btn--ghost btn--sm js-delete-user-messages" data-user-id="<?= (int) ($msg['user_id'] ?? 0) ?>" style="color:#EF4444;"><i class="fas fa-trash"></i> Удалить</button>
    </div>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Пагинация -->
<?php if ($totalPages >1): ?>
<div class="flex justify-between items-center" style="padding:16px 20px; border-top:1px solid var(--color-border);">
<div class="text-secondary" style="font-size:14px;">
 Страница <?= e($page) ?> из <?= e($totalPages) ?>
</div>
<div class="flex gap-sm">
<?php if ($page >1): ?>
<a href="?page=<?= $page -1 ?>&search=<?= urlencode($search) ?>&priority=<?= e($priority) ?>&sort=<?= e($sort) ?>&user_id=<?= (int) $filterUserId ?>&user_query=<?= urlencode($filterUserQuery) ?>" class="btn btn--ghost btn--sm">
<i class="fas fa-chevron-left"></i>
</a>
<?php endif; ?>
<?php if ($page< $totalPages): ?>
<a href="?page=<?= $page +1 ?>&search=<?= urlencode($search) ?>&priority=<?= e($priority) ?>&sort=<?= e($sort) ?>&user_id=<?= (int) $filterUserId ?>&user_query=<?= urlencode($filterUserQuery) ?>" class="btn btn--ghost btn--sm">
<i class="fas fa-chevron-right"></i>
</a>
<?php endif; ?>
</div>
</div>
<?php endif; ?>
</div>
</div>

<!-- Модальное окно отправки сообщения -->
<div class="modal" id="sendMessageModal">
<div class="modal__content message-modal message-compose-modal">
<div class="modal__header">
<h3>Написать сообщение</h3>
<button type="button" class="modal__close" onclick="closeSendModal()">&times;</button>
</div>
<form method="POST" id="sendMessageForm" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="action" value="send_message">
<div class="modal__body">
<div class="message-compose">
<div class="message-compose__intro">
<div class="message-compose__intro-icon"><i class="fas fa-envelope-open-text"></i></div>
<div>
<div class="message-compose__intro-title">Сообщение пользователю</div>
<div class="message-compose__intro-text">Выберите получателя через живой поиск или включите массовую отправку всем зарегистрированным участникам.</div>
</div>
</div>

<div class="message-compose__section">
<label class="form-label">Получатель</label>
<div
 class="message-recipient-search message-compose__search"
 <?= admin_live_search_attrs([
  'endpoint' => '/admin/search-users',
  'primary_template' => '{{name + surname||Без имени}}',
  'secondary_template' => '{{email||Email не указан}}',
  'value_template' => '{{name + surname||Без имени}} ({{email||Email не указан}})',
  'limit' => 7,
  'min_length' => 2,
  'min_length_numeric' => 1,
  'debounce' => 300,
 ]) ?>>
<input type="text" class="form-input" id="userSearch" data-live-search-input placeholder="Начните вводить имя, фамилию или email..." autocomplete="off">
<input type="hidden" name="user_id" id="userId" data-live-search-hidden>
<div id="userResults" class="user-results" data-live-search-results></div>
</div>
<div class="message-compose__section-note">Подсказки появляются прямо во время ввода.</div>
</div>

<div class="message-compose__section">
<div class="broadcast-toggle">
<div>
<div class="broadcast-toggle__title">Отправить всем участникам</div>
<div class="broadcast-toggle__subtitle">Если включить этот режим, поле получателя не потребуется.</div>
</div>
<label class="switch">
<input type="checkbox" name="send_to_all" value="1" id="sendToAll" onchange="toggleUserSelect()">
<span class="switch__track"></span>
<span class="switch__thumb"></span>
</label>
</div>
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
<div class="flex justify-between items-center gap-sm">
<label class="form-label">Текст сообщения</label>
<span class="message-form__counter message-compose__counter" id="messageCounter">0 символов</span>
</div>
<textarea name="message" class="form-textarea" rows="5" placeholder="Введите текст сообщения"></textarea>
</div>

<div class="message-compose__section">
<label class="form-label">Вложение</label>
<input type="file" id="adminMessagesAttachment" name="attachment" class="chat-composer__attachment-input js-message-attachment-input" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.doc,.docx,.rtf,.xls,.xlsx,.csv,.zip,image/*,application/pdf,text/plain,text/csv">
<div class="message-attachment-preview chat-composer__attachment-preview js-message-attachment-preview" hidden></div>
<div class="chat-composer__actions">
<label class="chat-composer__attachment-trigger" for="adminMessagesAttachment">
<i class="fas fa-paperclip"></i>
<span>Прикрепить файл</span>
</label>
<div class="chat-composer__attachment-help">Изображение покажем миниатюрой, для остальных файлов сохраним название. До 10 МБ.</div>
</div>
</div>
</div>
</div>
<div class="modal__footer">
<div class="message-compose__footer">
<div class="message-compose__footer-note">Все пояснения оставлены компактными, чтобы акцент был на отправке сообщения.</div>
<div class="flex gap-sm">
<button type="button" class="btn btn--ghost" onclick="closeSendModal()">Отмена</button>
<button type="submit" class="btn btn--primary"><i class="fas fa-paper-plane"></i> Отправить</button>
</div>
</div>
</div>
</form>
</div>
</div>

<?php endif; ?>

<!-- Модальное окно просмотра сообщения -->
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
<button type="button" class="btn btn--secondary" id="replyFromViewMessageBtn" onclick="openSendModalFromViewedMessage()">Написать сообщение пользователю</button>
<button type="button" class="btn btn--primary" onclick="closeViewModal()">Закрыть</button>
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
</div>

<script>
const csrfTokenValue = document.querySelector('input[name="csrf_token"]')?.value || '';
const selectedDisputeApplicationId = Number(<?= (int) $selectedDisputeApplicationId ?>);
const messageWelcomeTemplate = <?= json_encode($messageWelcomeTemplate, JSON_UNESCAPED_UNICODE) ?>;
let currentViewedMessage = null;
let isDisputeChatOpen = Boolean(document.getElementById('disputeChatModal')?.classList.contains('active'));
let pollTimerId = null;
let latestDisputeMessageId = Math.max(
 0,
 ...Array.from(document.querySelectorAll('#disputeChatMessages .dispute-chat-message'))
  .map((node) => Number(node.dataset.messageId || 0))
  .filter((value) => Number.isFinite(value))
);

function filterByPriority(priority) {
 const url = new URL(window.location.href);
 if (priority) {
  url.searchParams.set('priority', priority);
 } else {
  url.searchParams.delete('priority');
 }
 url.searchParams.delete('page');
 window.location.href = url.toString();
}

function viewMessage(subject, message, priority, options = {}) {
 currentViewedMessage = options;
 document.getElementById('viewMessageSubject').textContent = subject;
 const cleanedMessage = Number(options.applicationId || 0) > 0
  ? String(message || '').replace(/\n?Номер заявки:\s*#\d+\s*/u, '').trim()
  : message;
 document.getElementById('viewMessageContent').textContent = cleanedMessage;

 let priorityBadge = '';
 if (priority === 'critical') {
  priorityBadge = '<span class="badge" style="background:#EF4444; color:white; padding:4px 12px;">Критическое</span>';
 } else if (priority === 'important') {
  priorityBadge = '<span class="badge" style="background:#F59E0B; color:white; padding:4px 12px;">Важное</span>';
 } else {
  priorityBadge = '<span class="badge" style="background:#6B7280; color:white; padding:4px 12px;">Обычное</span>';
 }
 document.getElementById('viewMessagePriority').innerHTML = priorityBadge;
 const replyButton = document.getElementById('replyFromViewMessageBtn');
 if (replyButton) {
  const hasUser = Number(options.userId || 0) > 0 && !options.isBroadcast;
  replyButton.style.display = hasUser ? '' : 'none';
 }
 const applicationButton = document.getElementById('viewMessageApplicationBtn');
 if (applicationButton) {
  const applicationId = Number(options.applicationId || 0);
  if (applicationId > 0) {
   applicationButton.href = `/admin/application/${applicationId}`;
   applicationButton.style.display = '';
  } else {
   applicationButton.href = '#';
   applicationButton.style.display = 'none';
  }
 }

 const attachmentWrap = document.getElementById('viewMessageAttachment');
 if (attachmentWrap) {
  const attachmentUrl = String(options.attachmentUrl || '').trim();
  const attachmentName = String(options.attachmentName || '').trim();
  const attachmentIsImage = Boolean(Number(options.attachmentIsImage || 0));
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

 document.getElementById('viewMessageModal').classList.add('active');
 document.body.style.overflow = 'hidden';
}

function closeViewModal() {
 currentViewedMessage = null;
 document.getElementById('viewMessageModal').classList.remove('active');
 restoreBodyScrollIfNoModals();
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

function deleteMessage(id, isBroadcast) {
 if (!confirm('Вы уверены, что хотите удалить это сообщение' + (isBroadcast ? ' (для всех пользователей)' : '') + '?')) {
  return;
 }

 const formData = new FormData();
 formData.append('action', 'delete_message');
 formData.append('message_id', id);
 formData.append('is_broadcast', isBroadcast ? '1' : '0');
 formData.append('csrf_token', csrfTokenValue);

 fetch(window.location.href, {
  method: 'POST',
  body: formData
 })
  .then(response => response.json())
  .then(data => {
   if (data.success) {
    const row = document.querySelector(`.message-row[data-message-id="${id}"]`);
    if (row) {
     row.remove();
    }
    showToast('Сообщение удалено', 'success');
   } else {
    showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
   }
  })
  .catch(error => {
   showToast('Ошибка при удалении сообщения: ' + error.message, 'error');
   console.error(error);
  });
}

let selectionModeEnabled = false;

function getSelectionValue(row) {
 const messageId = Number(row.dataset.messageId || 0);
 const isBroadcast = row.dataset.messageBroadcast === '1';
 if (isBroadcast) {
  const adminId = Number(row.dataset.adminId || 0);
  const subject = row.dataset.messageSubject || '';
  return `b:${adminId}:${subject}`;
 }
 return String(messageId);
}

function updateBulkSelectionState() {
 const selected = Array.from(document.querySelectorAll('.message-select-checkbox:checked'));
 const selectedCount = selected.length;
 const selectedCounter = document.getElementById('selectedCountValue');
 const deleteButton = document.getElementById('bulkDeleteBtn');
 if (selectedCounter) {
  selectedCounter.textContent = String(selectedCount);
 }
 if (deleteButton) {
  deleteButton.disabled = selectedCount === 0;
 }
}

function clearSelectedMessages() {
 document.querySelectorAll('.message-select-checkbox').forEach((checkbox) => {
  checkbox.checked = false;
 });
 updateBulkSelectionState();
}

function toggleSelectionMode(forceState = null) {
 selectionModeEnabled = forceState === null ? !selectionModeEnabled : Boolean(forceState);
 const selectCols = document.querySelectorAll('.select-col');
 const bulkBar = document.getElementById('bulkActionsBar');
 const toggleBtn = document.getElementById('toggleSelectModeBtn');

 selectCols.forEach((col) => {
  col.style.display = selectionModeEnabled ? '' : 'none';
 });
 if (bulkBar) {
  bulkBar.style.display = selectionModeEnabled ? 'flex' : 'none';
 }
 if (toggleBtn) {
  toggleBtn.innerHTML = selectionModeEnabled
   ? '<i class="fas fa-times"></i> Отменить выбор'
   : '<i class="fas fa-check-square"></i> Выбрать';
 }

 if (!selectionModeEnabled) {
  clearSelectedMessages();
 }
 updateBulkSelectionState();
}

async function deleteSelectedMessages() {
 const checkedRows = Array.from(document.querySelectorAll('.message-row'))
  .filter((row) => row.querySelector('.message-select-checkbox')?.checked);
 if (!checkedRows.length) {
  showToast('Выберите хотя бы одно сообщение', 'error');
  return;
 }

 if (!confirm('Удалить выбранные сообщения?')) {
  return;
 }

 const selectedValues = checkedRows.map((row) => getSelectionValue(row));
 const formData = new FormData();
 formData.append('action', 'delete_selected_messages');
 formData.append('csrf_token', csrfTokenValue);
 selectedValues.forEach((value) => formData.append('selected[]', value));

 try {
  const response = await fetch(window.location.href, {
   method: 'POST',
   body: formData
  });
  const data = await response.json();
  if (!data.success) {
   showToast(data.error || 'Не удалось удалить сообщения', 'error');
   return;
  }

  checkedRows.forEach((row) => row.remove());
  showToast('Выбранные сообщения удалены', 'success');
  updateBulkSelectionState();
 } catch (error) {
  showToast('Ошибка удаления: ' + error.message, 'error');
 }
}

function openSendModal(prefill = null) {
 document.getElementById('sendMessageModal').classList.add('active');
 document.body.style.overflow = 'hidden';

 const userSearch = document.getElementById('userSearch');
 const userId = document.getElementById('userId');
 const userResults = document.getElementById('userResults');
 const sendToAll = document.getElementById('sendToAll');
 const subjectInput = document.querySelector('#sendMessageForm input[name="subject"]');
 const messageInput = document.querySelector('#sendMessageForm textarea[name="message"]');
 const attachmentInput = document.querySelector('#sendMessageForm input[name="attachment"]');
 const attachmentPreview = document.querySelector('#sendMessageForm .js-message-attachment-preview');

 userSearch.value = '';
 userSearch.disabled = false;
 userSearch.style.opacity = '1';
 userSearch.style.pointerEvents = 'auto';
 userId.value = '';
 if (userResults) {
  userResults.style.display = 'none';
  userResults.innerHTML = '';
 }
 sendToAll.checked = false;
 if (subjectInput) {
  subjectInput.value = '';
 }
 if (messageInput) {
  messageInput.value = '';
   messageInput.dispatchEvent(new Event('input', { bubbles: true }));
  }
  if (attachmentInput) {
   attachmentInput.value = '';
  }
  if (attachmentPreview) {
   attachmentPreview.innerHTML = '';
   attachmentPreview.hidden = true;
  }
 toggleUserSelect();

 if (prefill && Number(prefill.userId || 0) > 0) {
  const userLabel = (prefill.userName || '').trim();
  const emailLabel = (prefill.userEmail || '').trim();
  userId.value = String(prefill.userId);
  userSearch.value = emailLabel ? `${userLabel} (${emailLabel})` : userLabel;
  if (subjectInput) {
   const baseSubject = String(prefill.subject || '').trim();
   subjectInput.value = baseSubject === '' ? '' : (baseSubject.startsWith('Re: ') ? baseSubject : `Re: ${baseSubject}`);
  }
  if (messageInput) {
   const firstName = userLabel.split(/\s+/).filter(Boolean).slice(0, 1).join(' ');
   const fullName = userLabel || 'пользователь';
   messageInput.value = String(messageWelcomeTemplate || '')
    .replaceAll('{name}', firstName || fullName)
    .replaceAll('{full_name}', fullName)
    .replaceAll('{email}', emailLabel);
   messageInput.dispatchEvent(new Event('input', { bubbles: true }));
  }
 }

 setTimeout(() => {
  if (!sendToAll.checked) {
   userSearch.focus();
  }
 }, 10);
}

function closeSendModal() {
document.getElementById('sendMessageModal').classList.remove('active');
restoreBodyScrollIfNoModals();
}

function openSendModalFromViewedMessage() {
 if (!currentViewedMessage || Number(currentViewedMessage.userId || 0) <= 0 || currentViewedMessage.isBroadcast) {
  return;
 }
 closeViewModal();
 openSendModal(currentViewedMessage);
}

function closeDisputeChatModal() {
 const chatModal = document.getElementById('disputeChatModal');
 if (chatModal) {
  chatModal.classList.remove('active');
  isDisputeChatOpen = false;
  restoreBodyScrollIfNoModals();
  scheduleDisputePolling();
 }
}

function openDisputeChatModal() {
 const chatModal = document.getElementById('disputeChatModal');
 if (chatModal) {
  chatModal.classList.add('active');
  isDisputeChatOpen = true;
  document.body.style.overflow = 'hidden';
  scrollDisputeChatToBottom();
  scheduleDisputePolling();
 }
}

function toggleUserSelect() {
 const checkbox = document.getElementById('sendToAll');
 const userSearch = document.getElementById('userSearch');
 const userId = document.getElementById('userId');
 if (checkbox.checked) {
  userSearch.value = '';
  userSearch.disabled = true;
  userId.value = '';
  userSearch.style.opacity = '0.5';
  userSearch.style.pointerEvents = 'none';
 } else {
  userSearch.disabled = false;
  userSearch.style.opacity = '1';
  userSearch.style.pointerEvents = 'auto';
 }
}

const messageField = document.querySelector('textarea[name="message"]');
const messageCounter = document.getElementById('messageCounter');

function updateMessageCounter() {
 if (!messageField || !messageCounter) return;
 const count = messageField.value.trim().length;
 messageCounter.textContent = count + ' символов';
}

if (messageField) {
 messageField.addEventListener('input', updateMessageCounter);
 updateMessageCounter();
}

function escapeHtml(text) {
 const div = document.createElement('div');
 div.textContent = text;
 return div.innerHTML;
}

function scrollDisputeChatToBottom() {
 const messagesContainer = document.getElementById('disputeChatMessages');
 if (!messagesContainer) return;
 messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

function restoreBodyScrollIfNoModals() {
 const activeModal = document.querySelector('.modal.active');
 document.body.style.overflow = activeModal ? 'hidden' : '';
}

function showToast(message, type = 'success') {
 const toast = document.createElement('div');
 toast.className = 'alert ' + (type === 'success' ? 'alert--success' : 'alert--error');
 toast.style.cssText = 'position:fixed; top:20px; right:20px; z-index:3000; min-width:260px; max-width:420px; box-shadow:0 12px 30px rgba(0,0,0,.12); opacity:0; transform:translateY(-8px); transition:opacity .25s ease, transform .25s ease;';
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

function appendDisputeMessage(container, messageData) {
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
 meta.textContent = (messageData.author_label || 'Пользователь') + ' • ' + (messageData.created_at || '');

 const text = document.createElement('div');
 text.className = 'dispute-chat-message__text';
 text.textContent = messageData.content || '';

 bubble.appendChild(meta);
 bubble.appendChild(text);
 if (messageData.attachment && messageData.attachment.url) {
  const attachmentWrap = document.createElement('div');
  attachmentWrap.className = 'message-attachment';
  attachmentWrap.style.marginTop = '10px';

  if (messageData.attachment.is_image) {
   const button = document.createElement('button');
   button.type = 'button';
   button.className = 'message-attachment__image-button';
   button.addEventListener('click', () => openMessageImagePreview(encodeURIComponent(messageData.attachment.url || ''), encodeURIComponent(messageData.attachment.name || 'Изображение')));

   const image = document.createElement('img');
   image.className = 'message-attachment__thumb';
   image.src = messageData.attachment.url || '';
   image.alt = messageData.attachment.name || 'Изображение';

   const caption = document.createElement('span');
   caption.className = 'message-attachment__caption';
   caption.innerHTML = '<i class="fas fa-search-plus"></i> Посмотреть изображение';

   button.appendChild(image);
   button.appendChild(caption);
   attachmentWrap.appendChild(button);
  } else {
   const link = document.createElement('a');
   link.className = 'message-attachment__file';
   link.href = messageData.attachment.url || '#';
   link.target = '_blank';
   link.rel = 'noopener';
   link.download = messageData.attachment.name || 'attachment';
   link.innerHTML = '<i class="fas fa-download"></i><span>' + escapeHtml(messageData.attachment.name || 'Файл') + '</span>';
   attachmentWrap.appendChild(link);
  }

  bubble.appendChild(attachmentWrap);
 }
 messageWrap.appendChild(bubble);
 container.appendChild(messageWrap);
 container.scrollTop = container.scrollHeight;
 if (numericId > latestDisputeMessageId) {
  latestDisputeMessageId = numericId;
 }
}

function showNewDisputeAlert(messageData) {
 if (!messageData || !messageData.content) return;
 const toast = document.createElement('div');
 toast.className = 'alert alert--success';
 toast.style.cssText = 'position:fixed; top:20px; right:20px; z-index:3200; min-width:280px; max-width:420px; box-shadow:0 12px 30px rgba(0,0,0,.12); cursor:pointer;';
 const authorName = (messageData.author_name || 'Пользователь').trim();
 const authorEmail = (messageData.author_email || '').trim();
 const preview = (messageData.content || '').slice(0, 50);
 toast.innerHTML =
  '<div style="font-size:11px; opacity:.8; margin-bottom:4px;">новое сообщение</div>' +
  '<div style="font-weight:600;">' + escapeHtml(authorName) + (authorEmail ? ' (' + escapeHtml(authorEmail) + ')' : '') + '</div>' +
  '<div style="margin-top:4px; opacity:.9;">' + escapeHtml(preview) + (messageData.content.length > 50 ? '...' : '') + '</div>';
 toast.addEventListener('click', () => {
  openDisputeChatModal();
  toast.remove();
 });
 document.body.appendChild(toast);
 setTimeout(() => toast.remove(), 6000);
}

async function pollDisputeMessages() {
 if (!selectedDisputeApplicationId) return;

 try {
  const url = new URL(window.location.href);
  url.searchParams.set('view', 'disputes');
  url.searchParams.set('action', 'poll_dispute_messages');
  url.searchParams.set('dispute_application_id', String(selectedDisputeApplicationId));
  url.searchParams.set('last_message_id', String(latestDisputeMessageId));

  const response = await fetch(url.toString(), {
   headers: { 'X-Requested-With': 'XMLHttpRequest' }
  });
  const data = await response.json();
  if (!response.ok || !data.success || !Array.isArray(data.messages)) return;

  data.messages.forEach((messageData) => {
   appendDisputeMessage(document.getElementById('disputeChatMessages'), messageData);
   if (!isDisputeChatOpen) {
    showNewDisputeAlert(messageData);
   }
  });
 } catch (error) {
  console.error('Ошибка polling чата:', error);
 }
}

function scheduleDisputePolling() {
 if (pollTimerId) {
  clearTimeout(pollTimerId);
  pollTimerId = null;
 }
 if (!selectedDisputeApplicationId) return;

 const delay = isDisputeChatOpen ? 5000 : 30000;
 pollTimerId = setTimeout(async () => {
  await pollDisputeMessages();
  scheduleDisputePolling();
 }, delay);
}

function updatePriorityStyle(radio) {
 if (!radio) return;
 document.querySelectorAll('#sendMessageForm .priority-btn').forEach((btn) => btn.classList.remove('selected'));
 const selected = radio.closest('.priority-btn');
 if (selected) {
  selected.classList.add('selected');
 }
}

function buildAttachmentPreviewMarkup(file) {
 if (!file) return '';
 const fileName = escapeHtml(file.name || 'Файл');
 const isImage = String(file.type || '').startsWith('image/') || /\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(String(file.name || ''));
 if (isImage) {
  const objectUrl = URL.createObjectURL(file);
  return (
   `<div class="chat-composer__attachment-preview-item chat-composer__attachment-preview-item--image" title="${fileName}">` +
   `<button type="button" class="chat-composer__attachment-preview-main js-local-image-preview" data-image-src="${escapeHtml(objectUrl)}" data-image-title="${fileName}" title="${fileName}">` +
   `<img src="${escapeHtml(objectUrl)}" alt="${fileName}" class="chat-composer__attachment-preview-thumb">` +
   `<span class="chat-composer__attachment-preview-name">${fileName}</span>` +
   '</button>' +
   '<button type="button" class="chat-composer__attachment-remove js-message-attachment-remove" title="Удалить вложение" aria-label="Удалить вложение"><i class="fas fa-times"></i></button>' +
   '</div>'
  );
 }
 return `<div class="chat-composer__attachment-preview-item" title="${fileName}"><span class="chat-composer__attachment-preview-icon"><i class="fas fa-paperclip"></i></span><span class="chat-composer__attachment-preview-name">${fileName}</span><button type="button" class="chat-composer__attachment-remove js-message-attachment-remove" title="Удалить вложение" aria-label="Удалить вложение"><i class="fas fa-times"></i></button></div>`;
}

function initMessageAttachmentField(input) {
 if (!input) return;
 const preview = input.closest('form')?.querySelector('.js-message-attachment-preview');
 if (!preview) return;

 input.addEventListener('change', () => {
  const file = input.files && input.files[0] ? input.files[0] : null;
  preview.innerHTML = '';
  preview.hidden = !file;
  if (!file) {
   return;
  }

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
    input.value = '';
    preview.innerHTML = '';
    preview.hidden = true;
   });
  });
 });
}

document.addEventListener('DOMContentLoaded', function() {
 updatePriorityStyle(document.querySelector('input[name="priority"]:checked'));
 toggleSelectionMode(false);

 document.getElementById('toggleSelectModeBtn')?.addEventListener('click', function() {
  toggleSelectionMode();
 });
 document.getElementById('clearSelectedBtn')?.addEventListener('click', function() {
  clearSelectedMessages();
 });
 document.getElementById('bulkDeleteBtn')?.addEventListener('click', function() {
  deleteSelectedMessages();
 });
 document.querySelectorAll('.message-select-checkbox').forEach((checkbox) => {
  checkbox.addEventListener('change', updateBulkSelectionState);
 });

 document.getElementById('sendMessageModal')?.addEventListener('click', function(e) {
  if (e.target === this) {
   closeSendModal();
  }
 });

 document.getElementById('viewMessageModal')?.addEventListener('click', function(e) {
  if (e.target === this) {
   closeViewModal();
  }
 });

 const disputeChatModal = document.getElementById('disputeChatModal');
 if (disputeChatModal) {
  disputeChatModal.addEventListener('click', function(e) {
   if (e.target === disputeChatModal) {
    closeDisputeChatModal();
   }
  });
  if (isDisputeChatOpen) {
   document.body.style.overflow = 'hidden';
   scrollDisputeChatToBottom();
  }
  scheduleDisputePolling();
 }

 document.querySelectorAll('.js-toast-alert').forEach((alertEl) => {
  const type = alertEl.classList.contains('alert--error') ? 'error' : 'success';
  showToast(alertEl.textContent.trim(), type);
  alertEl.remove();
 });

 document.querySelectorAll('.js-chat-hotkey, textarea[name="message"]').forEach((textarea) => {
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

 document.querySelectorAll('.message-row').forEach((row) => {
  row.addEventListener('click', (event) => {
   if (selectionModeEnabled) {
    const checkbox = row.querySelector('.message-select-checkbox');
    if (checkbox) {
     checkbox.checked = !checkbox.checked;
     updateBulkSelectionState();
    }
    return;
   }

   if (event.target.closest('.message-select-checkbox, .js-view-message, .js-delete-message, a, button, input, label')) {
    return;
   }

   viewMessage(
    row.dataset.messageSubject || '',
    row.dataset.messageContent || '',
    row.dataset.messagePriority || 'normal',
    {
     userId: Number(row.dataset.userId || 0),
     userName: row.dataset.userName || '',
     userEmail: row.dataset.userEmail || '',
     subject: row.dataset.messageSubject || '',
     applicationId: Number(row.dataset.applicationId || 0),
     attachmentUrl: row.dataset.attachmentUrl || '',
     attachmentName: row.dataset.attachmentName || '',
     attachmentMime: row.dataset.attachmentMime || '',
     attachmentIsImage: Number(row.dataset.attachmentIsImage || 0),
     isBroadcast: row.dataset.messageBroadcast === '1'
    }
   );
  });
 });

 const navigateToAdminPath = (targetUrl) => {
  const normalizedTarget = String(targetUrl || '').trim();
  if (!normalizedTarget) {
   return;
  }

  let parsedUrl;
  try {
   parsedUrl = new URL(normalizedTarget, window.location.origin);
  } catch (error) {
   return;
  }

  if (!/^https?:$/.test(parsedUrl.protocol)) {
   return;
  }

  window.location.href = parsedUrl.pathname + parsedUrl.search + parsedUrl.hash;
 };

 document.querySelectorAll('.message-user-row').forEach((row) => {
  row.addEventListener('click', (event) => {
   if (event.target.closest('a, button, input, label')) {
    return;
   }
   navigateToAdminPath(row.dataset.userUrl || '');
  });
 });

 document.querySelectorAll('.js-view-message').forEach((button) => {
  button.addEventListener('click', (event) => {
   event.stopPropagation();
   const row = button.closest('.message-row');
   if (!row) return;
   viewMessage(
    row.dataset.messageSubject || '',
    row.dataset.messageContent || '',
    row.dataset.messagePriority || 'normal',
    {
     userId: Number(row.dataset.userId || 0),
     userName: row.dataset.userName || '',
     userEmail: row.dataset.userEmail || '',
     subject: row.dataset.messageSubject || '',
     applicationId: Number(row.dataset.applicationId || 0),
     attachmentUrl: row.dataset.attachmentUrl || '',
     attachmentName: row.dataset.attachmentName || '',
     attachmentMime: row.dataset.attachmentMime || '',
     attachmentIsImage: Number(row.dataset.attachmentIsImage || 0),
     isBroadcast: row.dataset.messageBroadcast === '1'
    }
   );
  });
 });

 document.querySelectorAll('.js-delete-user-messages').forEach((button) => {
  button.addEventListener('click', async (event) => {
   event.stopPropagation();
   const userId = Number(button.dataset.userId || 0);
   if (!userId) return;
   if (!confirm('Удалить все простые сообщения для этого пользователя?')) {
    return;
   }

   const formData = new FormData();
   formData.append('action', 'delete_user_messages');
   formData.append('user_id', String(userId));
   formData.append('csrf_token', csrfTokenValue);

   try {
    const response = await fetch(window.location.href, {
     method: 'POST',
     body: formData
    });
    const data = await response.json();
    if (!data.success) {
     throw new Error(data.error || 'Не удалось удалить сообщения');
    }
    const row = button.closest('.message-user-row');
    if (row) {
     row.remove();
    }
    showToast('Сообщения пользователя удалены', 'success');
   } catch (error) {
    showToast(error.message || 'Ошибка удаления', 'error');
   }
  });
 });

 document.querySelectorAll('.js-delete-message').forEach((button) => {
  button.addEventListener('click', (event) => {
   event.stopPropagation();
   const row = button.closest('.message-row');
   if (!row) return;
   const messageId = Number(row.dataset.messageId || 0);
   const isBroadcast = row.dataset.messageBroadcast === '1';
   if (!messageId) return;
   deleteMessage(messageId, isBroadcast);
  });
 });

 const disputeReplyForm = document.querySelector('#disputeChatModal form.dispute-chat-modal__composer');
 if (disputeReplyForm) {
  let isSendingReply = false;
  disputeReplyForm.addEventListener('submit', async (event) => {
   event.preventDefault();
   if (isSendingReply) return;
   const textarea = disputeReplyForm.querySelector('textarea[name="reply_text"]');
   if (!textarea || !disputeReplyForm.reportValidity()) return;
   isSendingReply = true;

   const submitButton = disputeReplyForm.querySelector('button[type="submit"]');
   const originalButtonHtml = submitButton ? submitButton.innerHTML : '';
   if (submitButton) {
    submitButton.disabled = true;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';
   }

   const formData = new FormData(disputeReplyForm);
   formData.append('ajax', '1');
   const requestId = 'reply_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
   formData.set('client_request_id', requestId);

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

  if (!data.duplicate) {
     appendDisputeMessage(document.getElementById('disputeChatMessages'), data.message);
    }
    textarea.value = '';
    const attachmentInput = disputeReplyForm.querySelector('input[name="attachment"]');
    const attachmentPreview = disputeReplyForm.querySelector('.js-message-attachment-preview');
    if (attachmentInput) {
     attachmentInput.value = '';
    }
    if (attachmentPreview) {
     attachmentPreview.innerHTML = '';
     attachmentPreview.hidden = true;
    }
    showToast('Сообщение отправлено', 'success');
   } catch (error) {
    showToast(error.message || 'Ошибка отправки сообщения', 'error');
   } finally {
    isSendingReply = false;
    if (submitButton) {
     submitButton.disabled = false;
     submitButton.innerHTML = originalButtonHtml;
    }
   }
  });
 }

 document.querySelectorAll('.js-message-attachment-input').forEach(initMessageAttachmentField);
 document.querySelectorAll('.js-open-message-image').forEach((button) => {
  button.addEventListener('click', () => {
   openMessageImagePreview(
    encodeURIComponent(button.dataset.imageUrl || ''),
    encodeURIComponent(button.dataset.imageTitle || 'Предпросмотр изображения')
   );
  });
 });

 document.getElementById('messageImagePreviewModal')?.addEventListener('click', function(e) {
  if (e.target === this) {
   closeMessageImagePreview();
  }
 });
});

document.addEventListener('keydown', function(e) {
 if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
  const activeTextarea = document.activeElement;
  if (activeTextarea && activeTextarea.classList.contains('js-chat-hotkey')) {
   e.preventDefault();
   const form = activeTextarea.closest('form');
   if (form && form.reportValidity()) form.requestSubmit();
  }
 }
 if (e.key === 'Escape') {
  closeViewModal();
  closeSendModal();
  closeDisputeChatModal();
 }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
