<?php
// messages.php - Сообщения пользователя
require_once dirname(__DIR__, 3) . '/config.php';

// Проверка авторизации
if (!isAuthenticated()) {
 redirect('/login');
}

$userId = getCurrentUserId();
$user = getCurrentUser();

// Получаем сообщения из admin_messages (сообщения от администрации)
$stmt = $pdo->prepare("
 SELECT am.id, am.user_id, am.subject, am.message, am.priority, am.is_read, am.created_at, am.admin_id, am.is_broadcast
 FROM admin_messages am
 WHERE am.user_id = ?
 ORDER BY am.created_at DESC
 LIMIT 50
");
$stmt->execute([$userId]);
$messages = $stmt->fetchAll();

$disputeChats = [];
try {
    $chatStmt = $pdo->prepare("
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
        SUM(CASE WHEN m.is_read = 0 AND u.is_admin = 1 THEN 1 ELSE 0 END) AS unread_count
    FROM messages m
    JOIN users u ON u.id = m.created_by
    LEFT JOIN applications a ON a.id = m.application_id
    LEFT JOIN contests c ON c.id = a.contest_id
    WHERE m.user_id = ?
      AND m.title LIKE 'Оспаривание решения по заявке%'
    GROUP BY m.application_id, m.title, c.title
    ORDER BY last_message_at DESC
    ");
    $chatStmt->execute([$userId]);
    $disputeChats = $chatStmt->fetchAll();
} catch (Exception $e) {
    $disputeChats = [];
}

// Подсчет непрочитанных
$unreadCount = $pdo->prepare("SELECT COUNT(*) FROM admin_messages WHERE user_id = ? AND is_read =0");
$unreadCount->execute([$userId]);
$unreadCount = $unreadCount->fetchColumn();
$declinedSubject = getSystemSetting('application_declined_subject', 'Ваша заявка отклонена');
$currentPage = 'messages';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Сообщения - ДетскиеКонкурсы.рф</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
<link rel="stylesheet" href="/css/messages.css">
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="container messages-container">
<div class="messages-page">
<div class="messages-page__header">
<h1>Сообщения</h1>
<p class="text-secondary">Уведомления от администрации и переписки по вашим заявкам.</p>
</div>

<?php if (!empty($disputeChats)): ?>
<div class="card mb-lg">
    <div class="card__header">
        <h3>Чаты по заявкам</h3>
    </div>
    <div class="card__body" style="padding:0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Тема</th>
                    <th>Последнее сообщение</th>
                    <th>Дата</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($disputeChats as $chat): ?>
                <tr>
                    <td data-label="Тема">
                        <div class="font-semibold"><?= htmlspecialchars($chat['title']) ?></div>
                        <?php if (!empty($chat['contest_title'])): ?>
                            <div class="text-secondary" style="margin-top:4px; font-size:13px;">Конкурс: <?= htmlspecialchars($chat['contest_title']) ?></div>
                        <?php endif; ?>
                        <?php if ((int) $chat['unread_count'] > 0): ?>
                            <span class="badge" style="background:#F59E0B; color:white; margin-top:4px;">Ответ администратора: <?= (int) $chat['unread_count'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Последнее сообщение"><?= htmlspecialchars(mb_substr((string) ($chat['last_message'] ?? ''), 0, 120)) ?></td>
                    <td data-label="Дата"><?= date('d.m.Y H:i', strtotime($chat['last_message_at'])) ?></td>
                    <td data-label="Действия">
                        <a class="btn btn--ghost btn--sm" href="/application/<?= (int) $chat['application_id'] ?>#dispute-chat">
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
?>
<div class="message-card <?= $msg['is_read'] ? '' : 'message-card--unread' ?>" 
 data-message-id="<?= (int) $msg['id'] ?>"
 onclick='showMessage(
 <?= (int) $msg['id'] ?>,
 <?= json_encode($msg['subject'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
 <?= json_encode($msg['message'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
 <?= json_encode(date('d.m.Y H:i', strtotime($msg['created_at'])), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
 <?= json_encode($messagePriority, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
 <?= (int) preg_match('/#(\\d+)/u', (string) $msg['message'], $idMatches) ? (int) $idMatches[1] : 0 ?>,
 <?= json_encode(((string) $msg['subject'] === $declinedSubject), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
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
<div class="message-card__preview"><?= htmlspecialchars(mb_substr($msg['message'],0,150)) ?>...</div>
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
<div class="mt-lg" id="detailActionWrap" style="display:none;">
    <a class="btn btn--secondary" id="detailActionLink" href="#">
        <i class="fas fa-file-alt"></i> Перейти к заявке
    </a>
</div>
</div>
</div>
 <?php endif; ?>
</div>
</main>

<footer class="footer">
<div class="container">
<div class="footer__inner">
<p class="footer__text">© <?= date('Y') ?> ДетскиеКонкурсы.рф</p>
</div>
</div>
</footer>

<script>
let currentUnreadCount = <?= (int) $unreadCount ?>;

function showMessage(id, title, content, date, priority, applicationId, isDeclinedNotice) {
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
    const actionWrap = document.getElementById('detailActionWrap');
    const actionLink = document.getElementById('detailActionLink');
    if (isDeclinedNotice && applicationId > 0) {
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
}
        
function hideMessage() {
    document.getElementById('messageDetail').classList.remove('active');
    document.getElementById('messagesList').style.display = 'block';
}
</script>
</body>
</html>
