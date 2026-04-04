<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';
if (!isAdmin()) {
redirect('/admin/login');
}

check_csrf();
$admin = getCurrentUser();
$currentPage = 'messages';
$pageTitle = 'Сообщения';
$breadcrumb = 'Все отправленные сообщения';
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
<div class="alert alert--error mb-lg">
<i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<?php if (isset($success)): ?>
<div class="alert alert--success mb-lg">
<i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>

<!-- Статистика -->
<div class="stats-grid mb-lg">
<div class="stat-card" style="cursor:pointer;" onclick="filterByPriority('')">
<div class="stat-card__icon" style="background: #EEF2FF; color: #6366F1;">
<i class="fas fa-envelope"></i>
</div>
<div class="stat-card__content">
<div class="stat-card__value"><?= e($totalMessages) ?></div>
<div class="stat-card__label">Всего сообщений</div>
</div>
</div>

<div class="stat-card" style="cursor:pointer;" onclick="filterByPriority('normal')">
<div class="stat-card__icon" style="background: #F3F4F6; color: #6B7280;">
<i class="fas fa-circle"></i>
</div>
<div class="stat-card__content">
<div class="stat-card__value"><?= $priorityStats['normal'] ??0 ?></div>
<div class="stat-card__label">Обычных</div>
</div>
</div>

<div class="stat-card" style="cursor:pointer;" onclick="filterByPriority('important')">
<div class="stat-card__icon" style="background: #FEF3C7; color: #F59E0B;">
<i class="fas fa-exclamation-circle"></i>
</div>
<div class="stat-card__content">
<div class="stat-card__value"><?= $priorityStats['important'] ??0 ?></div>
<div class="stat-card__label">Важных</div>
</div>
</div>

<div class="stat-card" style="cursor:pointer;" onclick="filterByPriority('critical')">
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
<div class="flex justify-between items-center w-100">
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
<tr>
<td>#<?= $msg['id'] ?></td>
<td>
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
<td style="font-size: var(--font-size-sm);"><?= htmlspecialchars($msg['subject']) ?></td>
<td>
<?php if ($msg['priority'] === 'critical'): ?>
<span class="badge" style="background:#EF4444; color:white;">Критическое</span>
<?php elseif ($msg['priority'] === 'important'): ?>
<span class="badge" style="background:#F59E0B; color:white;">Важное</span>
<?php else: ?>
<span class="badge" style="background:#6B7280; color:white;">Обычное</span>
<?php endif; ?>
</td>
<td><?= htmlspecialchars(($msg['admin_name'] ?? 'Админ') . ' ' . ($msg['admin_surname'] ?? '')) ?></td>
<td><?= date('d.m.Y H:i', strtotime($msg['created_at'])) ?></td>
<td>
<div class="flex gap-sm">
<button type="button" class="btn btn--ghost btn--sm" onclick="viewMessage(<?= $msg['id'] ?>, <?= json_encode($msg['subject']) ?>, <?= json_encode($msg['message']) ?>, <?= json_encode($msg['priority']) ?>)" title="Просмотреть">
<i class="fas fa-eye"></i>
</button>
<button type="button" class="btn btn--ghost btn--sm" onclick="deleteMessage(<?= $msg['id'] ?>, <?= !empty($msg['is_broadcast']) ? 'true' : 'false' ?>, '<?= htmlspecialchars(addslashes($msg['subject'])) ?>')" title="Удалить" style="color:#EF4444;">
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
<div class="flex justify-between items-center" style="padding:16px20px; border-top:1px solid var(--color-border);">
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
<div class="modal__content" style="max-width:600px;">
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
<div style="position:relative;">
<input type="text" class="form-input" id="userSearch" placeholder="Начните вводить имя, фамилию или email..." autocomplete="off">
<input type="hidden" name="user_id" id="userId">
<div id="userResults" style="position:absolute; top:100%; left:0; right:0; background:white; border:1px solid #E5E7EB; border-radius:8px; margin-top:4px; max-height:250px; overflow-y:auto; display:none; z-index:100; box-shadow:04px12px rgba(0,0,0,0.1);">
</div>
</div>
</div>

<div class="form-group">
<div class="flex items-center justify-between" style="padding:12px; background:#F9FAFB; border-radius:8px; margin-bottom:16px;">
<div>
<div style="font-weight:500;">Отправить всем участникам</div>
<div style="font-size:12px; color:#6B7280;">Сообщение будет отправлено всем зарегистрированным пользователям</div>
</div>
<label style="position:relative; display:inline-block; width:50px; height:26px;">
<input type="checkbox" name="send_to_all" value="1" id="sendToAll" style="opacity:0; width:0; height:0;" onchange="toggleUserSelect()">
<span style="position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#D1D5DB; border-radius:26px; transition:0.3s;" id="toggleBg"></span>
<span style="position:absolute; content:''; height:20px; width:20px; left:3px; bottom:3px; background:white; border-radius:50%; transition:0.3s;" id="toggleKnob"></span>
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
<label class="form-label">Текст сообщения</label>
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

function viewMessage(id, subject, message, priority) {
 document.getElementById('viewMessageSubject').textContent = subject;
 document.getElementById('viewMessageContent').textContent = message;
 
 let priorityBadge = '';
 if (priority === 'critical') {
 priorityBadge = '<span class="badge" style="background:#EF4444; color:white; padding:4px12px;">Критическое</span>';
 } else if (priority === 'important') {
 priorityBadge = '<span class="badge" style="background:#F59E0B; color:white; padding:4px12px;">Важное</span>';
 } else {
 priorityBadge = '<span class="badge" style="background:#6B7280; color:white; padding:4px12px;">Обычное</span>';
 }
 document.getElementById('viewMessagePriority').innerHTML = priorityBadge;
 
 document.getElementById('viewMessageModal').classList.add('active');
 document.body.style.overflow = 'hidden';
}

function closeViewModal() {
 document.getElementById('viewMessageModal').classList.remove('active');
 document.body.style.overflow = '';
}

function deleteMessage(id, isBroadcast, subject) {
 if (!confirm('Вы уверены, что хотите удалить это сообщение' + (isBroadcast ? ' (для всех пользователей)' : '') + '?')) {
 return;
 }
 
 const csrfToken = document.querySelector('input[name="csrf_token"]').value;
 
 const formData = new FormData();
 formData.append('action', 'delete_message');
 formData.append('message_id', id);
 formData.append('is_broadcast', isBroadcast ? '1' : '0');
 formData.append('csrf_token', csrfToken);
 
 fetch(window.location.href, {
 method: 'POST',
 body: formData
 })
 .then(response => response.json())
 .then(data => {
 if (data.success) {
 window.location.reload();
 } else {
 alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
 }
 })
 .catch(error => {
 alert('Ошибка при удалении сообщения: ' + error.message);
 console.error(error);
 });
}

// Модальное окно отправки
function openSendModal() {
 document.getElementById('sendMessageModal').classList.add('active');
 document.body.style.overflow = 'hidden';
 
 // Сброс формы
 const userSearch = document.getElementById('userSearch');
 const userId = document.getElementById('userId');
 const sendToAll = document.getElementById('sendToAll');
 const toggleBg = document.getElementById('toggleBg');
 const toggleKnob = document.getElementById('toggleKnob');
 
 userSearch.value = '';
 userSearch.disabled = false;
 userSearch.style.opacity = '1';
 userSearch.style.pointerEvents = 'auto';
 userId.value = '';
 sendToAll.checked = false;
 toggleBg.style.background = '#D1D5DB';
 toggleKnob.style.transform = 'translateX(0)';
}

function closeSendModal() {
 document.getElementById('sendMessageModal').classList.remove('active');
 document.body.style.overflow = '';
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

// Стили переключателя
document.getElementById('sendToAll').addEventListener('change', function() {
 const bg = document.getElementById('toggleBg');
 const knob = document.getElementById('toggleKnob');
 if (this.checked) {
 bg.style.background = '#6366F1';
 knob.style.transform = 'translateX(24px)';
 } else {
 bg.style.background = '#D1D5DB';
 knob.style.transform = 'translateX(0)';
 }
});

// Поиск пользователей
let searchTimeout;
const userSearchInput = document.getElementById('userSearch');
const userResults = document.getElementById('userResults');
const userIdInput = document.getElementById('userId');

userSearchInput.addEventListener('input', function() {
 clearTimeout(searchTimeout);
 const query = this.value.trim();
 
 if (query.length<2) {
 userResults.style.display = 'none';
 userIdInput.value = '';
 return;
 }
 
 searchTimeout = setTimeout(function() {
 fetch('/admin/search-users?q=' + encodeURIComponent(query))
 .then(response => response.json())
 .then(users => {
 if (users.length >0) {
 userResults.innerHTML = users.map(u => 
 '<div class="user-result" style="padding:12px16px; cursor:pointer; border-bottom:1px solid #F3F4F6;" onmouseover="this.style.background=\'#F9FAFB\'" onmouseout="this.style.background=\'white\'" onclick="selectUser(' + u.id + ', \'' + escapeHtml(u.name + ' ' + u.surname + ' (' + u.email + ')') + '\')">' +
 '<div style="font-weight:500;">' + escapeHtml(u.name + ' ' + u.surname) + '</div>' +
 '<div style="font-size:12px; color:#6B7280;">' + escapeHtml(u.email) + '</div>' +
 '</div>'
 ).join('');
 userResults.style.display = 'block';
 } else {
 userResults.innerHTML = '<div style="padding:12px16px; color:#6B7280;">Пользователи не найдены</div>';
 userResults.style.display = 'block';
 }
 });
 },300);
});

function selectUser(id, name) {
 userIdInput.value = id;
 userSearchInput.value = name.replace(/&quot;/g, '"');
 userResults.style.display = 'none';
}

function escapeHtml(text) {
 const div = document.createElement('div');
 div.textContent = text;
 return div.innerHTML;
}

// Закрытие результатов при клике вне
document.addEventListener('click', function(e) {
 if (!userSearchInput.contains(e.target) && !userResults.contains(e.target)) {
 userResults.style.display = 'none';
 }
});

// Стили приоритета
function updatePriorityStyle(radio) {
 document.querySelectorAll('.priority-option').forEach(el => {
 el.style.borderColor = '#E5E7EB';
 el.style.background = 'white';
 });
 const selected = document.getElementById('priority-' + radio.value);
 if (radio.value === 'normal') {
 selected.style.borderColor = '#6B7280';
 } else if (radio.value === 'important') {
 selected.style.borderColor = '#F59E0B';
 } else if (radio.value === 'critical') {
 selected.style.borderColor = '#EF4444';
 }
}

// Инициализация стилей приоритета при загрузке
document.addEventListener('DOMContentLoaded', function() {
 updatePriorityStyle(document.querySelector('input[name="priority"]:checked'));
});

document.getElementById('sendMessageModal').addEventListener('click', function(e) {
 if (e.target === this) {
 closeSendModal();
 }
});

document.addEventListener('keydown', function(e) {
 if (e.key === 'Escape') {
 closeViewModal();
 closeSendModal();
 }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>