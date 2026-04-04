<?php
// admin/user-view.php - Просмотр пользователя
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

// Проверка авторизации админа
if (!isAdmin()) {
 redirect('/admin/login');
}

check_csrf();

$admin = getCurrentUser();
$user_id = $_GET['id'] ??0;

// Получаем пользователя
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
 redirect('users.php');
}

// Получаем заявки пользователя
$stmt = $pdo->prepare("
 SELECT a.*, c.title as contest_title,
 (SELECT COUNT(*) FROM participants WHERE application_id = a.id) as participants_count
 FROM applications a
 LEFT JOIN contests c ON a.contest_id = c.id
 WHERE a.user_id = ?
 ORDER BY a.created_at DESC
");
$stmt->execute([$user_id]);
$applications = $stmt->fetchAll();

// Получаем сообщения для этого пользователя
$stmt = $pdo->prepare("
 SELECT am.*, u.name as admin_name, u.surname as admin_surname
 FROM admin_messages am
 LEFT JOIN users u ON am.admin_id = u.id
 WHERE am.user_id = ?
 ORDER BY am.created_at DESC
");
$stmt->execute([$user_id]);
$messages = $stmt->fetchAll();

$currentPage = 'users';
$pageTitle = htmlspecialchars($user['name'] . ' ' . $user['surname']);
$breadcrumb = 'Пользователи / Просмотр';

// Обработка отправки сообщения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
 if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
 $error = 'Ошибка безопасности';
 } else {
 $subject = trim($_POST['subject'] ?? '');
 $message = trim($_POST['message'] ?? '');
 $priority = $_POST['priority'] ?? 'normal';
        
 if (empty($subject) || empty($message)) {
 $error = 'Заполните тему и сообщение';
 } else {
 $stmt = $pdo->prepare("
 INSERT INTO admin_messages (user_id, admin_id, subject, message, priority)
 VALUES (?, ?, ?, ?, ?)
 ");
 $stmt->execute([$user_id, $admin['id'], $subject, $message, $priority]);
 $success = 'Сообщение отправлено';
            
 // Перенаправление для избежания повторной отправки
 header('Location: user-view.php?id=' . $user_id . '&success=1');
 exit;
 }
 }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="flex items-center gap-md mb-lg">
<a href="users.php" class="btn btn--ghost">
<i class="fas fa-arrow-left"></i> Назад
</a>
<button type="button" class="btn btn--primary" onclick="openMessageModal()">
<i class="fas fa-envelope"></i> Отправить сообщение
</button>
</div>

<!-- Информация о пользователе -->
<div class="card mb-lg">
<div class="card__body">
<div class="flex gap-xl" style="flex-wrap: wrap;">
 <?php if (!empty($user['avatar_url'])): ?>
<img src="<?= htmlspecialchars($user['avatar_url']) ?>" 
 style="width:120px; height:120px; border-radius:50%; object-fit: cover;">
 <?php else: ?>
<div style="width:120px; height:120px; border-radius:50%; background: #EEF2FF; display: flex; align-items: center; justify-content: center; color: #6366F1; font-size:48px;">
<i class="fas fa-user"></i>
</div>
 <?php endif; ?>
            
<div style="flex:1; min-width:250px;">
<h2><?= htmlspecialchars(($user['name'] ?? '') . ' ' . ($user['surname'] ?? '')) ?></h2>
 <?php if ($user['is_admin']): ?>
<span class="badge badge--primary mb-md">Администратор</span>
 <?php endif; ?>
                
<div class="form-row mt-lg">
<div class="form-group">
<label class="form-label">VK ID</label>
<p><?= htmlspecialchars($user['vk_id']) ?></p>
</div>
<div class="form-group">
<label class="form-label">Email</label>
<p><?= htmlspecialchars($user['email'] ?: 'Не указан') ?></p>
</div>
<div class="form-group">
<label class="form-label">Регион</label>
<p><?= htmlspecialchars($user['organization_region'] ?? 'Не указан') ?></p>
</div>
<div class="form-group">
<label class="form-label">Образовательное учреждение</label>
<p><?= htmlspecialchars($user['organization_name'] ?? 'Не указано') ?></p>
</div>
<div class="form-group">
<label class="form-label">Адрес организации</label>
<p><?= htmlspecialchars($user['organization_address'] ?? 'Не указан') ?></p>
</div>
<div class="form-group">
<label class="form-label">Дата регистрации</label>
<p><?= date('d.m.Y H:i', strtotime($user['created_at'])) ?></p>
</div>
</div>
                
 <?php if ($user['vk_id']): ?>
<div class="mt-lg">
<a href="https://vk.com/id<?= $user['vk_id'] ?>" target="_blank" class="btn btn--secondary">
<i class="fab fa-vk"></i> Открыть профиль VK
</a>
</div>
 <?php endif; ?>
</div>
</div>
</div>
</div>

<!-- Заявки пользователя -->
<div class="card mb-lg">
<div class="card__header">
<h3>Заявки пользователя (<?= count($applications) ?>)</h3>
</div>
<div class="card__body" style="padding:0;">
<table class="table">
<thead>
<tr>
<th>ID</th>
<th>Конкурс</th>
<th>Участников</th>
<th>Статус</th>
<th>Дата</th>
<th></th>
</tr>
</thead>
<tbody>
 <?php foreach ($applications as $app): ?>
<tr>
<td>#<?= $app['id'] ?></td>
<td><?= htmlspecialchars($app['contest_title']) ?></td>
<td><?= $app['participants_count'] ?></td>
<td>
<span class="badge <?= $app['status'] === 'submitted' ? 'badge--success' : 'badge--warning' ?>">
 <?= $app['status'] === 'submitted' ? 'Отправлена' : 'Черновик' ?>
</span>
</td>
<td><?= date('d.m.Y', strtotime($app['created_at'])) ?></td>
<td>
<a href="application-view.php?id=<?= $app['id'] ?>" class="btn btn--ghost btn--sm">
<i class="fas fa-eye"></i>
</a>
</td>
</tr>
 <?php endforeach; ?>
                
 <?php if (empty($applications)): ?>
<tr>
<td colspan="6" class="text-center text-secondary" style="padding:40px;">
 Заявок нет
</td>
</tr>
 <?php endif; ?>
</tbody>
</table>
</div>
</div>

<!-- Сообщения от администрации -->
<div class="card">
<div class="card__header">
<h3>Сообщения (<?= count($messages) ?>)</h3>
</div>
<div class="card__body" style="padding:0;">
 <?php if (!empty($messages)): ?>
<div class="messages-list">
 <?php foreach ($messages as $msg): ?>
<div class="message-item" style="padding:16px20px; border-bottom:1px solid #E5E7EB;">
<div class="flex justify-between items-center mb-sm">
<div class="flex items-center gap-sm">
 <?php if ($msg['priority'] === 'critical'): ?>
<span class="badge" style="background: #EF4444; color: white; font-size:11px;">Критическое</span>
 <?php elseif ($msg['priority'] === 'important'): ?>
<span class="badge" style="background: #F59E0B; color: white; font-size:11px;">Важное</span>
 <?php else: ?>
<span class="badge" style="background: #6B7280; color: white; font-size:11px;">Обычное</span>
 <?php endif; ?>
<strong><?= htmlspecialchars($msg['subject']) ?></strong>
</div>
<span class="text-secondary" style="font-size:13px;">
 <?= date('d.m.Y H:i', strtotime($msg['created_at'])) ?>
</span>
</div>
<p style="margin:0; color: #4B5563; font-size:14px;"><?= nl2br(htmlspecialchars($msg['message'])) ?></p>
<div class="text-secondary" style="font-size:12px; margin-top:8px;">
 От: <?= htmlspecialchars(($msg['admin_name'] ?? 'Админ') . ' ' . ($msg['admin_surname'] ?? '')) ?>
</div>
</div>
 <?php endforeach; ?>
</div>
 <?php else: ?>
<div class="text-center text-secondary" style="padding:40px;">
 Сообщений нет
</div>
 <?php endif; ?>
</div>
</div>

<!-- Модальное окно отправки сообщения -->
<div class="modal" id="messageModal">
<div class="modal__content" style="max-width:550px;">
<div class="modal__header">
<h3>Отправить сообщение</h3>
<button type="button" class="modal__close" onclick="closeMessageModal()">&times;</button>
</div>
<form method="POST" action="user-view.php?id=<?= e($user_id) ?>">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="action" value="send_message">
<div class="modal__body">
<div class="form-group">
<label class="form-label">Приоритет сообщения</label>
<div class="priority-buttons">
<label class="priority-btn priority-btn--normal" onclick="selectPriority('normal')">
<input type="radio" name="priority" value="normal" checked>
<span class="priority-icon"><i class="fas fa-circle"></i></span>
<span class="priority-text">Обычное</span>
</label>
<label class="priority-btn priority-btn--important" onclick="selectPriority('important')">
<input type="radio" name="priority" value="important">
<span class="priority-icon"><i class="fas fa-exclamation-circle"></i></span>
<span class="priority-text">Важное</span>
</label>
<label class="priority-btn priority-btn--critical" onclick="selectPriority('critical')">
<input type="radio" name="priority" value="critical">
<span class="priority-icon"><i class="fas fa-exclamation-triangle"></i></span>
<span class="priority-text">Критическое</span>
</label>
</div>
</div>
<div class="form-group">
<label class="form-label">Тема сообщения</label>
<input type="text" name="subject" class="form-input" required placeholder="Введите тему">
</div>
<div class="form-group">
<label class="form-label">Текст сообщения</label>
<textarea name="message" class="form-textarea" rows="5" required placeholder="Введите текст сообщения"></textarea>
</div>
</div>
<div class="modal__footer flex gap-md">
<button type="button" class="btn btn--ghost" onclick="closeMessageModal()">Отмена</button>
<button type="submit" class="btn btn--primary">
<i class="fas fa-paper-plane"></i> Отправить
</button>
</div>
</form>
</div>
</div>

<style>
.priority-buttons {
 display: flex;
 gap:12px;
}

.priority-btn {
 flex:1;
 display: flex;
 flex-direction: column;
 align-items: center;
 gap:6px;
 padding:14px10px;
 border-radius:10px;
 border:2px solid #E5E7EB;
 background: white;
 cursor: pointer;
 transition: all0.2s ease;
}

.priority-btn:hover {
 border-color: #9CA3AF;
}

.priority-btn input {
 display: none;
}

.priority-btn.selected,
.priority-btn:has(input:checked) {
 border-width:2px;
}

.priority-btn--normal.selected,
.priority-btn--normal:has(input:checked) {
 background: linear-gradient(135deg, #F3F4F6, #E5E7EB);
 border-color: #6B7280;
 color: #374151;
}

.priority-btn--normal .priority-icon {
 color: #6B7280;
}

.priority-btn--important.selected,
.priority-btn--important:has(input:checked) {
 background: linear-gradient(135deg, #FEF3C7, #FDE68A);
 border-color: #F59E0B;
 color: #92400E;
}

.priority-btn--important .priority-icon {
 color: #F59E0B;
}

.priority-btn--critical.selected,
.priority-btn--critical:has(input:checked) {
 background: linear-gradient(135deg, #FEE2E2, #FECACA);
 border-color: #EF4444;
 color: #991B1B;
}

.priority-btn--critical .priority-icon {
 color: #EF4444;
}

.priority-icon {
 font-size:20px;
}

.priority-text {
 font-size:12px;
 font-weight:600;
}

.message-item:last-child {
 border-bottom: none;
}
</style>

<script>
function openMessageModal() {
 document.getElementById('messageModal').classList.add('active');
 document.body.style.overflow = 'hidden';
}

function closeMessageModal() {
 document.getElementById('messageModal').classList.remove('active');
 document.body.style.overflow = '';
}

function selectPriority(value) {
 document.querySelectorAll('.priority-btn').forEach(btn => btn.classList.remove('selected'));
 document.querySelector(`.priority-btn--${value}`).classList.add('selected');
 document.querySelector(`input[value="${value}"]`).checked = true;
}

// Инициализация при загрузке
document.addEventListener('DOMContentLoaded', function() {
 selectPriority('normal');
});

// Закрытие по Escape
document.addEventListener('keydown', function(e) {
 if (e.key === 'Escape') {
 closeMessageModal();
 }
});

// Закрытие по клику вне модального окна
document.getElementById('messageModal').addEventListener('click', function(e) {
 if (e.target === this) {
 closeMessageModal();
 }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
