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

$disputeThreadSubjectPrefix = 'Оспаривание решения по заявке #';
$selectedDisputeApplicationId = intval($_GET['dispute_application_id'] ?? 0);
$disputeThreads = [];
$selectedDisputeMessages = [];
$disputeRecipientName = 'Пользователь';
$isDisputeChatClosed = false;

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
        if ($disputeApplicationId <= 0 || $replyText === '') {
            $error = 'Заполните текст ответа';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $error], 422);
            }
        } else {
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
                $threadSubject = $disputeThreadSubjectPrefix . $disputeApplicationId;
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
                    $insertStmt = $pdo->prepare("
                    INSERT INTO messages (user_id, application_id, title, content, created_by, created_at, is_read)
                    VALUES (?, ?, ?, ?, ?, NOW(), 0)
                    ");
                    $insertStmt->execute([
                        $targetUserId,
                        $disputeApplicationId,
                        $threadSubject,
                        $replyText,
                        $admin['id'],
                    ]);
                    if ($isAjaxRequest) {
                        $adminName = trim(($admin['surname'] ?? '') . ' ' . ($admin['name'] ?? '') . ' ' . ($admin['patronymic'] ?? ''));
                        if ($adminName === '') {
                            $adminName = 'Администратор';
                        }
                        jsonResponse([
                            'success' => true,
                            'message' => [
                                'content' => $replyText,
                                'created_at' => date('d.m.Y H:i'),
                                'author_label' => 'Руководитель проекта — ' . $adminName,
                                'from_admin' => true,
                            ],
                        ]);
                    }
                    $_SESSION['success_message'] = 'Ответ отправлен в чат';
                    redirect('/admin/messages?dispute_application_id=' . $disputeApplicationId);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close_dispute_chat') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности';
    } else {
        $disputeApplicationId = intval($_POST['dispute_application_id'] ?? 0);
        if ($disputeApplicationId <= 0) {
            $error = 'Чат не найден';
        } else {
            try {
                $closeStmt = $pdo->prepare("UPDATE applications SET dispute_chat_closed = 1 WHERE id = ?");
                $closeStmt->execute([$disputeApplicationId]);
                $_SESSION['success_message'] = 'Чат завершён. Пользователь больше не сможет отправлять сообщения.';
                redirect('/admin/messages?dispute_application_id=' . $disputeApplicationId);
            } catch (Exception $e) {
                $error = 'Не удалось завершить чат';
            }
        }
    }
}

try {
    $threadsStmt = $pdo->query("
    SELECT
        m.application_id,
        m.title,
        MAX(m.created_at) AS last_message_at,
        SUM(CASE WHEN m.is_read = 0 AND u.is_admin = 0 THEN 1 ELSE 0 END) AS unread_count,
        SUBSTRING_INDEX(
            GROUP_CONCAT(m.content ORDER BY m.created_at DESC SEPARATOR '||__||'),
            '||__||',
            1
        ) AS last_message
    FROM messages m
    JOIN users u ON u.id = m.created_by
    WHERE m.title LIKE 'Оспаривание решения по заявке%'
    GROUP BY m.application_id, m.title
    ORDER BY last_message_at DESC
    ");
    $disputeThreads = $threadsStmt->fetchAll();
} catch (Exception $e) {
    $disputeThreads = [];
}

if ($selectedDisputeApplicationId > 0) {
    try {
        if (adminMessagesHasDisputeChatClosedColumn($pdo)) {
            $closedStmt = $pdo->prepare("SELECT dispute_chat_closed FROM applications WHERE id = ? LIMIT 1");
            $closedStmt->execute([$selectedDisputeApplicationId]);
            $isDisputeChatClosed = (int) $closedStmt->fetchColumn() === 1;
        }

        $threadSubject = $disputeThreadSubjectPrefix . $selectedDisputeApplicationId;
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
if ($priority) {
$where .= " AND am.priority = ?";
$params[] = $priority;
}
// --- COUNT ---
$countStmt = $pdo->prepare("
SELECT COUNT(DISTINCT 
CASE 
WHEN am.is_broadcast =1 THEN CONCAT(am.admin_id, '-', am.subject)
ELSE am.id 
END
)
FROM admin_messages am
LEFT JOIN users u ON am.user_id = u.id
WHERE $where
");
$countStmt->execute($params);
$totalMessages = $countStmt->fetchColumn();
$totalPages = $perPage ? ceil($totalMessages / $perPage) :1;
// --- ДАННЫЕ ---
$stmt = $pdo->prepare("
SELECT am.*, 
u.name as user_name, u.surname as user_surname, u.email as user_email,
ad.name as admin_name, ad.surname as admin_surname
FROM admin_messages am
LEFT JOIN users u ON am.user_id = u.id
LEFT JOIN users ad ON am.admin_id = ad.id
WHERE $where
ORDER BY $sortField $sortDir, am.id DESC
LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$messages = $stmt->fetchAll();

foreach ($messages as &$messageItem) {
    if (mb_stripos((string) ($messageItem['subject'] ?? ''), 'Ваша заявка отклонена') !== false) {
        $messageItem['priority'] = 'critical';
    }
}
unset($messageItem);
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
if (!in_array($priority, $allowedPriorities)) {
$priority = 'normal';
}
if (!$subject || !$message) {
$error = 'Заполните тему и сообщение';
} else {
if ($sendToAll) {
$users = $pdo->query("SELECT id FROM users WHERE is_admin =0")
->fetchAll(PDO::FETCH_COLUMN);
$pdo->beginTransaction();
$stmt = $pdo->prepare("
INSERT INTO admin_messages 
(user_id, admin_id, subject, message, priority, is_broadcast, created_at)
VALUES (?, ?, ?, ?, ?,1, NOW())
");
foreach ($users as $uid) {
$stmt->execute([$uid, $admin['id'], $subject, $message, $priority]);
}
$pdo->commit();
$success = 'Отправлено: ' . count($users);
} elseif ($userId) {
$stmt = $pdo->prepare("
INSERT INTO admin_messages 
(user_id, admin_id, subject, message, priority, is_broadcast, created_at)
VALUES (?, ?, ?, ?, ?,0, NOW())
");
$stmt->execute([$userId, $admin['id'], $subject, $message, $priority]);
$success = 'Сообщение отправлено';
} else {
$error = 'Выберите пользователя';
}
}
}
}
// --- УДАЛЕНИЕ ---
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

require_once __DIR__ . '/includes/header.php';
?>

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

<?php if (!empty($disputeThreads)): ?>
<div class="card mb-lg">
    <div class="card__header">
        <h3>Чаты: оспаривание решения по заявке</h3>
    </div>
    <div class="card__body" style="padding:0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Тема</th>
                    <th>Последнее сообщение</th>
                    <th>Дата</th>
                    <th>Новое</th>
                    <th>Заявка</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($disputeThreads as $thread): ?>
                <tr>
                    <td data-label="Тема"><?= htmlspecialchars($thread['title']) ?></td>
                    <td data-label="Последнее сообщение"><?= htmlspecialchars(mb_substr((string) ($thread['last_message'] ?? ''), 0, 120)) ?></td>
                    <td data-label="Дата"><?= date('d.m.Y H:i', strtotime($thread['last_message_at'])) ?></td>
                    <td data-label="Новое">
                        <?php if ((int) ($thread['unread_count'] ?? 0) > 0): ?>
                            <span class="badge" style="background:#F59E0B; color:white;">Новое: <?= (int) $thread['unread_count'] ?></span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td data-label="Заявка">
                        <?php if (!empty($thread['application_id'])): ?>
                            <a href="/admin/application/<?= (int) $thread['application_id'] ?>">#<?= (int) $thread['application_id'] ?></a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td data-label="Действия">
                        <a class="btn btn--ghost btn--sm" href="/admin/messages?dispute_application_id=<?= (int) $thread['application_id'] ?>">
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

<?php if ($selectedDisputeApplicationId > 0): ?>
<div class="modal active" id="disputeChatModal">
    <div class="modal__content message-modal dispute-chat-modal">
        <div class="modal__header">
            <h3>Чат по заявке #<?= (int) $selectedDisputeApplicationId ?></h3>
            <div class="flex items-center gap-sm">
                <a href="/admin/application/<?= (int) $selectedDisputeApplicationId ?>" class="btn btn--ghost btn--sm">
                    <i class="fas fa-external-link-alt"></i> Открыть заявку
                </a>
                <button type="button" class="modal__close" onclick="closeDisputeChatModal()">&times;</button>
            </div>
        </div>
        <div class="modal__body dispute-chat-modal__body">
        <?php if (empty($selectedDisputeMessages)): ?>
            <p class="text-secondary">Сообщения не найдены.</p>
        <?php else: ?>
            <div class="dispute-chat-modal__messages" id="disputeChatMessages">
                <?php foreach ($selectedDisputeMessages as $chatMessage): ?>
                    <?php $fromAdmin = (int) ($chatMessage['author_is_admin'] ?? 0) === 1; ?>
                    <?php
                        $chatAuthorName = trim(
                            ($chatMessage['author_surname'] ?? '')
                            . ' '
                            . ($chatMessage['author_name'] ?? '')
                            . ' '
                            . ($chatMessage['author_patronymic'] ?? '')
                        );
                        if ($fromAdmin) {
                            $chatAuthorLabel = 'Руководитель проекта — ' . ($chatAuthorName !== '' ? $chatAuthorName : trim(($admin['surname'] ?? '') . ' ' . ($admin['name'] ?? '')));
                        } else {
                            $chatAuthorLabel = $chatAuthorName !== '' ? $chatAuthorName : ($disputeRecipientName !== '' ? $disputeRecipientName : 'Пользователь');
                        }
                    ?>
                    <div class="dispute-chat-message <?= $fromAdmin ? 'dispute-chat-message--admin' : 'dispute-chat-message--user' ?>">
                        <div class="dispute-chat-message__bubble">
                            <div class="dispute-chat-message__meta">
                                <?= htmlspecialchars($chatAuthorLabel) ?>
                                <span>• <?= date('d.m.Y H:i', strtotime($chatMessage['created_at'])) ?></span>
                            </div>
                            <div class="dispute-chat-message__text"><?= htmlspecialchars($chatMessage['content']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="flex items-center justify-between gap-sm" style="margin-top:16px;">
            <?php if ($isDisputeChatClosed): ?>
                <span class="badge" style="background:#6B7280; color:white;">Чат завершён</span>
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

        <?php if (!$isDisputeChatClosed): ?>
            <form method="POST" class="dispute-chat-modal__composer">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="reply_dispute">
                <input type="hidden" name="dispute_application_id" value="<?= (int) $selectedDisputeApplicationId ?>">
                <div class="form-group">
                    <label class="form-label">Ответ в чате</label>
                    <textarea name="reply_text" class="form-textarea js-chat-hotkey" rows="4" required placeholder="Введите сообщение пользователю..."></textarea>
                </div>
                <button type="submit" class="btn btn--primary">
                    <i class="fas fa-paper-plane"></i> Ответить
                </button>
            </form>
        <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Статистика -->
<div class="stats-grid stats-grid--messages mb-lg">
<div class="stat-card stat-card--compact" style="cursor:pointer;" onclick="filterByPriority('')">
<div class="stat-card__icon" style="background: #EEF2FF; color: #6366F1;">
<i class="fas fa-envelope"></i>
</div>
<div class="stat-card__content">
<div class="stat-card__value"><?= e($totalMessages) ?></div>
<div class="stat-card__label">Всего сообщений</div>
</div>
</div>

<div class="stat-card stat-card--compact" style="cursor:pointer;" onclick="filterByPriority('normal')">
<div class="stat-card__icon" style="background: #F3F4F6; color: #6B7280;">
<i class="fas fa-circle"></i>
</div>
<div class="stat-card__content">
<div class="stat-card__value"><?= $priorityStats['normal'] ??0 ?></div>
<div class="stat-card__label">Обычных</div>
</div>
</div>

<div class="stat-card stat-card--compact" style="cursor:pointer;" onclick="filterByPriority('important')">
<div class="stat-card__icon" style="background: #FEF3C7; color: #F59E0B;">
<i class="fas fa-exclamation-circle"></i>
</div>
<div class="stat-card__content">
<div class="stat-card__value"><?= $priorityStats['important'] ??0 ?></div>
<div class="stat-card__label">Важных</div>
</div>
</div>

<div class="stat-card stat-card--compact" style="cursor:pointer;" onclick="filterByPriority('critical')">
<div class="stat-card__icon" style="background: #FEE2E2; color: #EF4444;">
<i class="fas fa-exclamation-triangle"></i>
</div>
<div class="stat-card__content">
<div class="stat-card__value"><?= $priorityStats['critical'] ??0 ?></div>
<div class="stat-card__label">Критических</div>
</div>
</div>
</div>

<!-- Поиск и фильтры -->
<div class="card mb-lg">
<div class="card__body">
<form method="GET" class="flex gap-md" style="align-items:flex-end; flex-wrap:wrap;">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<div style="flex:1; min-width:250px;">
<label class="form-label">Поиск</label>
<input type="text" name="search" class="form-input" 
 placeholder="Поиск по теме, сообщению или пользователю..." 
 value="<?= htmlspecialchars($search) ?>">
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
<?php if ($search || $priority): ?>
<a href="messages.php" class="btn btn--ghost">Сбросить</a>
<?php endif; ?>
</form>
</div>
</div>

<!-- Список сообщений -->
<div class="card">
<div class="card__header">
<div class="flex justify-between items-center w-100 messages-toolbar">
<h3>Сообщения (<?= e($totalMessages) ?>)</h3>
<button type="button" class="btn btn--primary" onclick="openSendModal()">
<i class="fas fa-pen"></i> Написать сообщение
</button>
</div>
</div>
<div class="card__body" style="padding:0;">
<table class="table">
<thead>
<tr>
<th><a href="?sort=<?= $sort === 'id_desc' ? 'id_asc' : 'id_desc' ?>&search=<?= urlencode($search) ?>&priority=<?= e($priority) ?>" style="color:inherit; text-decoration:none;">ID <?= $sort === 'id_desc' ? '↓' : ($sort === 'id_asc' ? '↑' : '') ?></a></th>
<th>Получатель</th>
<th>Тема</th>
<th>Приоритет</th>
<th>Отправил</th>
<th><a href="?sort=<?= $sort === 'date_desc' ? 'date_asc' : 'date_desc' ?>&search=<?= urlencode($search) ?>&priority=<?= e($priority) ?>" style="color:inherit; text-decoration:none;">Дата <?= $sort === 'date_desc' ? '↓' : ($sort === 'date_asc' ? '↑' : '') ?></a></th>
<th style="width:140px;">Действия</th>
</tr>
</thead>
<tbody>
<?php 
// Фильтруем дубликаты broadcast-сообщений (оставляем только первое для каждого subject - которое уже последнее благодаря сортировке)
$shownBroadcast = [];
foreach ($messages as $msg) {
 $broadcastKey = $msg['admin_id'] . '-' . $msg['subject'];
 if (!empty($msg['is_broadcast'])) {
 if (isset($shownBroadcast[$broadcastKey])) continue;
 $shownBroadcast[$broadcastKey] = true;
 }
?>
<tr class="message-row"
    data-message-id="<?= (int) $msg['id'] ?>"
    data-message-subject="<?= e($msg['subject']) ?>"
    data-message-content="<?= e($msg['message']) ?>"
    data-message-priority="<?= e($msg['priority']) ?>"
    data-message-broadcast="<?= !empty($msg['is_broadcast']) ? '1' : '0' ?>">
<td data-label="ID">#<?= $msg['id'] ?></td>
<td data-label="Получатель">
<div class="flex items-center gap-sm">
<?php if (!empty($msg['is_broadcast'])): ?>
<div>
<div class="font-semibold" style="color:#6366F1;"><i class="fas fa-bullhorn" style="margin-right:6px;"></i>Отправлено для всех</div>
<div class="text-secondary" style="font-size:12px;">Все пользователи</div>
</div>
<?php elseif (!empty($msg['user_name'])): ?>
<div>
<div class="font-semibold"><?= htmlspecialchars(($msg['user_name'] ?? '') . ' ' . ($msg['user_surname'] ?? '')) ?></div>
<div class="text-secondary" style="font-size:12px;"><?= htmlspecialchars($msg['user_email'] ?: '') ?></div>
</div>
<?php else: ?>
<span class="text-secondary">Пользователь удален</span>
<?php endif; ?>
</div>
</td>
<td data-label="Тема" style="font-size: var(--font-size-sm);"><?= htmlspecialchars($msg['subject']) ?></td>
<td data-label="Приоритет">
<?php if ($msg['priority'] === 'critical'): ?>
<span class="badge" style="background:#EF4444; color:white;">Критическое</span>
<?php elseif ($msg['priority'] === 'important'): ?>
<span class="badge" style="background:#F59E0B; color:white;">Важное</span>
<?php else: ?>
<span class="badge" style="background:#6B7280; color:white;">Обычное</span>
<?php endif; ?>
</td>
<td data-label="Отправил"><?= htmlspecialchars(($msg['admin_name'] ?? 'Админ') . ' ' . ($msg['admin_surname'] ?? '')) ?></td>
<td data-label="Дата"><?= date('d.m.Y H:i', strtotime($msg['created_at'])) ?></td>
<td data-label="Действия">
<div class="flex gap-sm">
<button type="button" class="btn btn--ghost btn--sm js-view-message" title="Просмотр">
<i class="fas fa-eye"></i> Просмотр
</button>
<button type="button" class="btn btn--ghost btn--sm js-delete-message" title="Удалить" style="color:#EF4444;">
<i class="fas fa-trash"></i>
</button>
</div>
</td>
</tr>
<?php } ?>

<?php if (empty($messages)): ?>
<tr>
<td colspan="7" class="text-center text-secondary" style="padding:40px;">
 Сообщений не найдено
</td>
</tr>
<?php endif; ?>
</tbody>
</table>

<!-- Пагинация -->
<?php if ($totalPages >1): ?>
<div class="flex justify-between items-center" style="padding:16px 20px; border-top:1px solid var(--color-border);">
<div class="text-secondary" style="font-size:14px;">
 Страница <?= e($page) ?> из <?= e($totalPages) ?>
</div>
<div class="flex gap-sm">
<?php if ($page >1): ?>
<a href="?page=<?= $page -1 ?>&search=<?= urlencode($search) ?>&priority=<?= e($priority) ?>&sort=<?= e($sort) ?>" class="btn btn--ghost btn--sm">
<i class="fas fa-chevron-left"></i>
</a>
<?php endif; ?>
<?php if ($page< $totalPages): ?>
<a href="?page=<?= $page +1 ?>&search=<?= urlencode($search) ?>&priority=<?= e($priority) ?>&sort=<?= e($sort) ?>" class="btn btn--ghost btn--sm">
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
<div class="modal__content message-modal">
<div class="modal__header">
<h3>Написать сообщение</h3>
<button type="button" class="modal__close" onclick="closeSendModal()">&times;</button>
</div>
<form method="POST" id="sendMessageForm">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="action" value="send_message">
<div class="modal__body">
<div class="form-group">
<label class="form-label">Получатель</label>
<div class="message-recipient-search">
<input type="text" class="form-input" id="userSearch" placeholder="Начните вводить имя, фамилию или email..." autocomplete="off">
<input type="hidden" name="user_id" id="userId">
<div id="userResults" class="user-results">
</div>
</div>
</div>

<div class="form-group">
<div class="broadcast-toggle">
<div>
<div class="broadcast-toggle__title">Отправить всем участникам</div>
<div class="broadcast-toggle__subtitle">Сообщение будет отправлено всем зарегистрированным пользователям</div>
</div>
<label class="switch">
<input type="checkbox" name="send_to_all" value="1" id="sendToAll" onchange="toggleUserSelect()">
<span class="switch__track"></span>
<span class="switch__thumb"></span>
</label>
</div>
</div>

<div class="form-group">
<label class="form-label">Приоритет сообщения</label>
<div class="flex gap-md">
<label style="flex:1; cursor:pointer;">
<input type="radio" name="priority" value="normal" checked style="display:none;" onchange="updatePriorityStyle(this)">
<div class="priority-option" id="priority-normal" style="padding:12px; border:2px solid #E5E7EB; border-radius:8px; text-align:center; background:white;">
<i class="fas fa-circle" style="color:#6B7280;"></i><span style="margin-left:8px;">Обычное</span>
</div>
</label>
<label style="flex:1; cursor:pointer;">
<input type="radio" name="priority" value="important" style="display:none;" onchange="updatePriorityStyle(this)">
<div class="priority-option" id="priority-important" style="padding:12px; border:2px solid #E5E7EB; border-radius:8px; text-align:center; background:white;">
<i class="fas fa-exclamation-circle" style="color:#F59E0B;"></i><span style="margin-left:8px;">Важное</span>
</div>
</label>
<label style="flex:1; cursor:pointer;">
<input type="radio" name="priority" value="critical" style="display:none;" onchange="updatePriorityStyle(this)">
<div class="priority-option" id="priority-critical" style="padding:12px; border:2px solid #E5E7EB; border-radius:8px; text-align:center; background:white;">
<i class="fas fa-exclamation-triangle" style="color:#EF4444;"></i><span style="margin-left:8px;">Критическое</span>
</div>
</label>
</div>
</div>

<div class="form-group">
<label class="form-label">Тема сообщения</label>
<input type="text" name="subject" class="form-input" required placeholder="Введите тему сообщения">
</div>

<div class="form-group">
<div class="flex justify-between items-center gap-sm">
<label class="form-label">Текст сообщения</label>
<span class="message-form__counter" id="messageCounter">0 символов</span>
</div>
<textarea name="message" class="form-textarea" rows="5" required placeholder="Введите текст сообщения"></textarea>
</div>
</div>
<div class="modal__footer">
<button type="button" class="btn btn--ghost" onclick="closeSendModal()">Отмена</button>
<button type="submit" class="btn btn--primary"><i class="fas fa-paper-plane"></i> Отправить</button>
</div>
</form>
</div>
</div>

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
</div>
<div class="modal__footer">
<button type="button" class="btn btn--primary" onclick="closeViewModal()">Закрыть</button>
</div>
</div>
</div>

<script>
const csrfTokenValue = document.querySelector('input[name="csrf_token"]')?.value || '';

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

function viewMessage(subject, message, priority) {
 document.getElementById('viewMessageSubject').textContent = subject;
 document.getElementById('viewMessageContent').textContent = message;

 let priorityBadge = '';
 if (priority === 'critical') {
  priorityBadge = '<span class="badge" style="background:#EF4444; color:white; padding:4px 12px;">Критическое</span>';
 } else if (priority === 'important') {
  priorityBadge = '<span class="badge" style="background:#F59E0B; color:white; padding:4px 12px;">Важное</span>';
 } else {
  priorityBadge = '<span class="badge" style="background:#6B7280; color:white; padding:4px 12px;">Обычное</span>';
 }
 document.getElementById('viewMessagePriority').innerHTML = priorityBadge;

 document.getElementById('viewMessageModal').classList.add('active');
 document.body.style.overflow = 'hidden';
}

function closeViewModal() {
 document.getElementById('viewMessageModal').classList.remove('active');
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
    const row = document.querySelector(`tr[data-message-id="${id}"]`);
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

function openSendModal() {
 document.getElementById('sendMessageModal').classList.add('active');
 document.body.style.overflow = 'hidden';

 const userSearch = document.getElementById('userSearch');
 const userId = document.getElementById('userId');
 const sendToAll = document.getElementById('sendToAll');

 userSearch.value = '';
 userSearch.disabled = false;
 userSearch.style.opacity = '1';
 userSearch.style.pointerEvents = 'auto';
 userId.value = '';
 sendToAll.checked = false;
}

function closeSendModal() {
 document.getElementById('sendMessageModal').classList.remove('active');
 restoreBodyScrollIfNoModals();
}

function closeDisputeChatModal() {
 const chatModal = document.getElementById('disputeChatModal');
 if (chatModal) {
  chatModal.classList.remove('active');
  const url = new URL(window.location.href);
  url.searchParams.delete('dispute_application_id');
  window.location.href = url.toString();
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

let searchTimeout;
const userSearchInput = document.getElementById('userSearch');
const userResults = document.getElementById('userResults');
const userIdInput = document.getElementById('userId');
const messageField = document.querySelector('textarea[name="message"]');
const messageCounter = document.getElementById('messageCounter');

userSearchInput?.addEventListener('input', function() {
 clearTimeout(searchTimeout);
 const query = this.value.trim();

 if (query.length < 2) {
  userResults.style.display = 'none';
  userIdInput.value = '';
  return;
 }

 searchTimeout = setTimeout(function() {
  fetch('/admin/search-users?q=' + encodeURIComponent(query))
   .then(response => response.json())
   .then(users => {
   if (users.length > 0) {
     userResults.innerHTML = users.map(u =>
      '<button type="button" class="user-results__item" onclick="selectUser(' + u.id + ', \'' + escapeHtml((u.name + ' ' + u.surname + ' (' + u.email + ')').trim()).replace(/'/g, '&#39;') + '\')">' +
      '<div class="user-results__name">' + escapeHtml((u.name + ' ' + u.surname).trim()) + '</div>' +
      '<div class="user-results__email">' + escapeHtml(u.email) + '</div>' +
      '</button>'
     ).join('');
     userResults.style.display = 'block';
    } else {
     userResults.innerHTML = '<div class="user-results__empty">Пользователи не найдены</div>';
     userResults.style.display = 'block';
    }
   });
 }, 300);
});

function updateMessageCounter() {
 if (!messageField || !messageCounter) return;
 const count = messageField.value.trim().length;
 messageCounter.textContent = count + ' символов';
}

if (messageField) {
 messageField.addEventListener('input', updateMessageCounter);
 updateMessageCounter();
}

function selectUser(id, name) {
 userIdInput.value = id;
 userSearchInput.value = name.replace(/&quot;/g, '"').trim();
 userResults.style.display = 'none';
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
 const messageWrap = document.createElement('div');
 messageWrap.className = 'dispute-chat-message ' + (messageData.from_admin ? 'dispute-chat-message--admin' : 'dispute-chat-message--user');

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
 messageWrap.appendChild(bubble);
 container.appendChild(messageWrap);
 container.scrollTop = container.scrollHeight;
}

document.addEventListener('click', function(e) {
 if (userSearchInput && userResults && !userSearchInput.contains(e.target) && !userResults.contains(e.target)) {
  userResults.style.display = 'none';
 }
});

function updatePriorityStyle(radio) {
 if (!radio) return;
 document.querySelectorAll('.priority-option').forEach(el => {
  el.style.borderColor = '#E5E7EB';
  el.style.background = 'white';
 });
 const selected = document.getElementById('priority-' + radio.value);
 if (!selected) return;
 if (radio.value === 'normal') {
  selected.style.borderColor = '#6B7280';
 } else if (radio.value === 'important') {
  selected.style.borderColor = '#F59E0B';
 } else if (radio.value === 'critical') {
  selected.style.borderColor = '#EF4444';
 }
}

document.addEventListener('DOMContentLoaded', function() {
 updatePriorityStyle(document.querySelector('input[name="priority"]:checked'));

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
  document.body.style.overflow = 'hidden';
  scrollDisputeChatToBottom();
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

 document.querySelectorAll('tr.message-row').forEach((row) => {
  row.addEventListener('click', () => {
   viewMessage(
    row.dataset.messageSubject || '',
    row.dataset.messageContent || '',
    row.dataset.messagePriority || 'normal'
   );
  });
 });

 document.querySelectorAll('.js-view-message').forEach((button) => {
  button.addEventListener('click', (event) => {
   event.stopPropagation();
   const row = button.closest('tr.message-row');
   if (!row) return;
   viewMessage(
    row.dataset.messageSubject || '',
    row.dataset.messageContent || '',
    row.dataset.messagePriority || 'normal'
   );
  });
 });

 document.querySelectorAll('.js-delete-message').forEach((button) => {
  button.addEventListener('click', (event) => {
   event.stopPropagation();
   const row = button.closest('tr.message-row');
   if (!row) return;
   const messageId = Number(row.dataset.messageId || 0);
   const isBroadcast = row.dataset.messageBroadcast === '1';
   if (!messageId) return;
   deleteMessage(messageId, isBroadcast);
  });
 });

 const disputeReplyForm = document.querySelector('#disputeChatModal form.dispute-chat-modal__composer');
 if (disputeReplyForm) {
  disputeReplyForm.addEventListener('submit', async (event) => {
   event.preventDefault();
   const textarea = disputeReplyForm.querySelector('textarea[name="reply_text"]');
   if (!textarea || !disputeReplyForm.reportValidity()) return;

   const submitButton = disputeReplyForm.querySelector('button[type="submit"]');
   const originalButtonHtml = submitButton ? submitButton.innerHTML : '';
   if (submitButton) {
    submitButton.disabled = true;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';
   }

   const formData = new FormData(disputeReplyForm);
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

    appendDisputeMessage(document.getElementById('disputeChatMessages'), data.message);
    textarea.value = '';
    showToast('Сообщение отправлено', 'success');
   } catch (error) {
    showToast(error.message || 'Ошибка отправки сообщения', 'error');
   } finally {
    if (submitButton) {
     submitButton.disabled = false;
     submitButton.innerHTML = originalButtonHtml;
    }
   }
  });
 }
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
