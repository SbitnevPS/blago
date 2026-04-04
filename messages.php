<?php
// messages.php - Сообщения пользователя
require_once __DIR__ . '/config.php';

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

// Подсчет непрочитанных
$unreadCount = $pdo->prepare("SELECT COUNT(*) FROM admin_messages WHERE user_id = ? AND is_read =0");
$unreadCount->execute([$userId]);
$unreadCount = $unreadCount->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Сообщения - ДетскиеКонкурсы.рф</title>
<?php include __DIR__ . '/includes/site-head.php'; ?>
<link rel="stylesheet" href="css/messages.css">
</head>
<body>
<nav class="navbar">
<div class="container">
<div class="navbar__inner">
<a href="/" class="navbar__logo">
<i class="fas fa-paint-brush navbar__logo-icon"></i> ДетскиеКонкурсы.рф
</a>
<div class="navbar__menu">
<a href="contests.php" class="navbar__link">Конкурсы</a>
<a href="my-applications.php" class="navbar__link">Мои заявки</a>
<a href="profile.php" class="navbar__link">Мой профиль</a>
<a href="messages.php" class="navbar__link navbar__link--active messages-link">
<i class="fas fa-envelope"></i> Сообщения
 <?php if ($unreadCount >0): ?>
<span class="messages-badge messages-badge--pulse"><?= $unreadCount ?></span>
 <?php endif; ?>
</a>
<div class="navbar__user">
 <?php if (!empty($user['avatar_url'])): ?>
<img src="<?= htmlspecialchars($user['avatar_url']) ?>" alt="" class="navbar__avatar">
 <?php else: ?>
<div class="navbar__avatar navbar__avatar--placeholder">
<i class="fas fa-user navbar__avatar-icon"></i>
</div>
 <?php endif; ?>
<a href="logout.php" class="btn btn--ghost btn--sm"><i class="fas fa-sign-out-alt"></i></a>
</div>
</div>
</div>
</div>
</nav>

<main class="container messages-container">
<div class="messages-page">
<div class="messages-page__header">
<h1>Сообщения</h1>
<p class="text-secondary">Все уведомления и комментарии к вашим заявкам</p>
</div>
        
 <?php if (empty($messages)): ?>
<div class="empty-state">
<div class="empty-state__icon"><i class="fas fa-envelope-open"></i></div>
<h3 class="empty-state__title">Нет сообщений</h3>
<p class="empty-state__text">У вас пока нет уведомлений</p>
</div>
 <?php else: ?>
<div id="messagesList">
 <?php foreach ($messages as $msg): ?>
<div class="message-card <?= $msg['is_read'] ? '' : 'message-card--unread' ?>" 
 onclick="showMessage(<?= $msg['id'] ?>, '<?= htmlspecialchars(addslashes($msg['subject'])) ?>', '<?= htmlspecialchars(addslashes($msg['message'])) ?>', '<?= htmlspecialchars(date('d.m.Y H:i', strtotime($msg['created_at']))) ?>', '<?= $msg['priority'] ?>')">
<div class="message-card__header">
<div class="message-card__title">
 <?php if ($msg['priority'] === 'critical'): ?>
<span class="badge message-priority-badge message-priority-badge--critical">КРИТИЧЕСКОЕ</span>
 <?php elseif ($msg['priority'] === 'important'): ?>
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
<h2 id="detailTitle"></h2>
<div class="message-detail__date" id="detailDate"></div>
<div class="mb-lg" id="detailPriority"></div>
<div class="message-detail__text" id="detailContent"></div>
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
let currentUnreadCount = <?= $unreadCount ?>;

function showMessage(id, title, content, date, priority) {
    document.getElementById('messagesList').style.display = 'none';
    document.getElementById('messageDetail').classList.add('active');
    document.getElementById('detailTitle').textContent = title;
    document.getElementById('detailDate').textContent = date;
    
    // Priority badge
    let priorityHtml = '';
    if (priority === 'critical') {
        priorityHtml = '<span class="badge message-detail-priority message-detail-priority--critical"><i class="fas fa-exclamation-triangle"></i> КРИТИЧЕСКОЕ СООБЩЕНИЕ</span>';
    } else if (priority === 'important') {
        priorityHtml = '<span class="badge message-detail-priority message-detail-priority--important"><i class="fas fa-exclamation-circle"></i> ВАЖНОЕ СООБЩЕНИЕ</span>';
    }
    
    document.getElementById('detailPriority').innerHTML = priorityHtml;
    document.getElementById('detailContent').innerHTML = content.replace(/\n/g, '<br>');
    window.scrollTo(0,0);
    
    // Помечаем сообщение как прочитанное
    markAsRead(id);
}
        
function markAsRead(messageId) {
    const formData = new URLSearchParams();
    formData.append('id', messageId);
    formData.append('csrf_token', '<?= generateCSRFToken() ?>');
    
    fetch('/mark-message-read.php', {
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
