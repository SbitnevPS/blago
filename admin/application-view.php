<?php
// admin/application-view.php - Просмотр заявки
require_once __DIR__ . '/../config.php';

// Проверка авторизации админа
if (!isAdmin()) {
    redirect('/admin/login');
}

$admin = getCurrentAdmin();
$application_id = $_GET['id'] ?? 0;

// Получаем заявку
$stmt = $pdo->prepare("
 SELECT a.*, c.title as contest_title, 
 u.name, u.surname, u.avatar_url, u.email, u.vk_id,
 u.organization_region, u.organization_name, u.organization_address
 FROM applications a
 JOIN contests c ON a.contest_id = c.id
 JOIN users u ON a.user_id = u.id
 WHERE a.id = ?
");
$stmt->execute([$application_id]);
$application = $stmt->fetch();

if (!$application) {
    redirect('applications.php');
}

// Получаем участников
$stmt = $pdo->prepare("SELECT * FROM participants WHERE application_id = ?");
$stmt->execute([$application_id]);
$participants = $stmt->fetchAll();

// Обработка изменения статуса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности';
    } elseif ($_POST['action'] === 'update_status') {
        $newStatus = $_POST['status'] ?? $application['status'];
        $stmt = $pdo->prepare("UPDATE applications SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $application_id]);
        $application['status'] = $newStatus;
        $_SESSION['success_message'] = 'Статус обновлён';
} elseif ($_POST['action'] === 'delete') {
 // Удаляем участников и заявку
 $pdo->prepare("DELETE FROM participants WHERE application_id = ?")->execute([$application_id]);
 $pdo->prepare("DELETE FROM applications WHERE id = ?")->execute([$application_id]);
 $_SESSION['success_message'] = 'Заявка удалена';
 redirect('applications.php');
 } elseif ($_POST['action'] === 'send_message') {
 $subject = trim($_POST['subject'] ?? '');
 $message = trim($_POST['message'] ?? '');
 $priority = $_POST['priority'] ?? 'normal';
 
 if (empty($subject) || empty($message)) {
 $error = 'Заполните тему и текст сообщения';
 } else {
 $stmt = $pdo->prepare("INSERT INTO admin_messages (user_id, admin_id, subject, message, priority, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
 $stmt->execute([$application['user_id'], $admin['id'], $subject, $message, $priority]);
 $_SESSION['success_message'] = 'Сообщение отправлено';
 }
 }
}

generateCSRFToken();

$currentPage = 'applications';
$pageTitle = 'Заявка #' . $application_id;
$breadcrumb = 'Заявки / Просмотр';

require_once __DIR__ . '/includes/header.php';
?>

<div class="flex items-center gap-md mb-lg">
    <a href="applications.php" class="btn btn--ghost">
        <i class="fas fa-arrow-left"></i> Назад
    </a>
</div>

<!-- Сообщения -->
<?php if (isset($_SESSION['success_message'])): ?>
<div class="alert alert--success alert--permanent mb-lg">
    <i class="fas fa-check-circle alert__icon"></i>
    <div class="alert__content">
        <div class="alert__message"><?= htmlspecialchars($_SESSION['success_message']) ?></div>
    </div>
    <button type="button" class="btn-close"></button>
</div>
<?php unset($_SESSION['success_message']); endif; ?>

<!-- Информация о заявке -->
<div class="card mb-lg">
<div class="card__header" style="padding:20px24px;">
<div class="flex justify-between items-center" style="flex-wrap: wrap; gap:16px;">
<div>
<h2 style="font-size:20px; margin-bottom:4px;">Заявка #<?= $application_id ?></h2>
<p class="text-secondary"><?= htmlspecialchars($application['contest_title']) ?></p>
</div>
<div class="flex gap-md items-center" style="flex-shrink:0;">
<button type="button" class="btn" style="background:#EEF2FF; color:#6366F1; padding:10px16px; border-radius:8px; border:none; cursor:pointer;" onclick="openMessageModal()">
<i class="fas fa-envelope"></i> Сообщение
</button>
<form method="POST" class="flex gap-md items-center">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="action" value="update_status">
<select name="status" class="form-select" style="width:140px; padding:8px12px;">
<option value="draft" <?= $application['status'] === 'draft' ? 'selected' : '' ?>>Черновик</option>
<option value="submitted" <?= $application['status'] === 'submitted' ? 'selected' : '' ?>>Отправлена</option>
<option value="approved" <?= $application['status'] === 'approved' ? 'selected' : '' ?>>Принята</option>
<option value="rejected" <?= $application['status'] === 'rejected' ? 'selected' : '' ?>>Отклонена</option>
</select>
<button type="submit" class="btn btn--primary" style="padding:10px20px;">
<i class="fas fa-save"></i> Сохранить
</button>
</form>
<form method="POST" onsubmit="return confirm('Удалить заявку?');">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="action" value="delete">
<button type="submit" class="btn" style="background:#FEE2E2; color:#DC2626; padding:10px16px; border-radius:8px; border:none; cursor:pointer;">
<i class="fas fa-trash"></i> Удалить
</button>
</form>
</div>
</div>
</div>
    <div class="card__body">
<div class="form-row">
<div class="form-group">
<label class="form-label">Заявитель</label>
<div class="flex items-center gap-md">
 <?php if (!empty($application['avatar_url'])): ?>
<img src="<?= htmlspecialchars($application['avatar_url']) ?>" 
 style="width:40px; height:40px; border-radius:50%;">
 <?php else: ?>
<div style="width:40px; height:40px; border-radius:50%; background: #EEF2FF; display: flex; align-items: center; justify-content: center; color: #6366F1;">
<i class="fas fa-user"></i>
</div>
 <?php endif; ?>
<div>
<p class="font-semibold"><?= htmlspecialchars(($application['name'] ?? '') . ' ' . ($application['surname'] ?? '')) ?></p>
<p class="text-secondary" style="font-size:13px;"><?= htmlspecialchars($application['email'] ?: 'Email не указан') ?></p>
</div>
</div>
</div>
<div class="form-group">
<label class="form-label">ФИО родителя/куратора</label>
<p><?= htmlspecialchars($application['parent_fio'] ?: '—') ?></p>
</div>
<div class="form-group">
<label class="form-label">Дата подачи</label>
<p><?= date('d.m.Y H:i', strtotime($application['created_at'])) ?></p>
</div>
</div>
        
 <!-- Данные организации заявителя -->
 <?php if ($application['organization_region'] || $application['organization_name'] || $application['organization_address']): ?>
<div class="form-group mt-md">
<label class="form-label">Место обучения</label>
 <?php if ($application['organization_region']): ?>
<p><strong>Регион:</strong> <?= htmlspecialchars($application['organization_region']) ?></p>
 <?php endif; ?>
 <?php if ($application['organization_name']): ?>
<p><strong>Организация:</strong> <?= htmlspecialchars($application['organization_name']) ?></p>
 <?php endif; ?>
 <?php if ($application['organization_address']): ?>
<p><strong>Адрес:</strong> <?= htmlspecialchars($application['organization_address']) ?></p>
 <?php endif; ?>
</div>
 <?php endif; ?>
        
 <?php if ($application['source_info']): ?>
<div class="form-group mt-md">
<label class="form-label">Откуда узнал о конкурсе</label>
<p><?= htmlspecialchars($application['source_info']) ?></p>
</div>
 <?php endif; ?>

 <?php if ($application['colleagues_info']): ?>
<div class="form-group mt-md">
<label class="form-label">Информация о коллегах</label>
<p><?= htmlspecialchars($application['colleagues_info']) ?></p>
</div>
 <?php endif; ?>
        
 <?php if ($application['payment_receipt']): ?>
<div class="form-group mt-md">
<label class="form-label">Квитанция об оплате</label>
<a href="/uploads/documents/<?= htmlspecialchars($application['payment_receipt']) ?>" 
 target="_blank" class="btn btn--secondary btn--sm">
<i class="fas fa-file-image"></i> Просмотреть
</a>
</div>
 <?php endif; ?>
</div>
</div>

<!-- Участники -->
<h2 class="mb-lg">Участники (<?= count($participants) ?>)</h2>

<?php foreach ($participants as $i => $p): ?>
<div class="card mb-lg">
    <div class="card__header">
        <div class="flex justify-between items-center">
            <h3>Участник <?= $i + 1 ?></h3>
            <?php if ($p['drawing_file']): ?>
            <span class="badge badge--success">
                <i class="fas fa-image"></i> Рисунок загружен
            </span>
            <?php endif; ?>
        </div>
    </div>
<div class="card__body">
<div class="form-row">
<div class="form-group">
<label class="form-label">ФИО участника</label>
<p class="font-semibold"><?= htmlspecialchars($p['fio']) ?></p>
</div>
<div class="form-group">
<label class="form-label">Возраст</label>
<p><?= $p['age'] ?> лет</p>
</div>
</div>
        
 <?php if ($p['drawing_file']): ?>
        <div class="form-group mt-lg">
            <label class="form-label">Рисунок</label>
            <div style="max-width: 400px;">
                <img src="/uploads/drawings/<?= htmlspecialchars($p['drawing_file']) ?>" 
                     alt="Рисунок участника" 
                     style="width: 100%; border-radius: var(--radius-lg); border: 2px solid var(--color-border);">
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($participants)): ?>
<div class="card">
<div class="card__body text-center" style="padding:40px;">
<p class="text-secondary">Нет участников</p>
</div>
</div>
<?php endif; ?>

<!-- Модальное окно отправки сообщения -->
<div class="modal" id="messageModal">
<div class="modal__content" style="max-width:550px;">
<div class="modal__header">
<h3>Отправить сообщение пользователю</h3>
<button type="button" class="modal__close" onclick="closeMessageModal()">&times;</button>
</div>
<form method="POST" action="application-view.php?id=<?= $application_id ?>">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="action" value="send_message">
<div class="modal__body">
<div class="form-group">
<label class="form-label">Пользователь</label>
<p><strong><?= htmlspecialchars(($application['name'] ?? '') . ' ' . ($application['surname'] ?? '')) ?></strong> (<?= htmlspecialchars($application['email']) ?>)</p>
</div>
<div class="form-group">
<label class="form-label">Приоритет сообщения</label>
<div class="priority-buttons" style="display:flex; gap:12px;">
<label class="priority-btn priority-btn--normal" onclick="selectPriority('normal')" style="flex:1; display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px10px; border-radius:10px; border:2px solid #E5E7EB; background:white; cursor:pointer;">
<input type="radio" name="priority" value="normal" checked style="display:none;">
<span style="color:#6B7280; font-size:20px;"><i class="fas fa-circle"></i></span>
<span style="font-size:12px; font-weight:600;">Обычное</span>
</label>
<label class="priority-btn priority-btn--important" onclick="selectPriority('important')" style="flex:1; display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px10px; border-radius:10px; border:2px solid #E5E7EB; background:white; cursor:pointer;">
<input type="radio" name="priority" value="important" style="display:none;">
<span style="color:#F59E0B; font-size:20px;"><i class="fas fa-exclamation-circle"></i></span>
<span style="font-size:12px; font-weight:600;">Важное</span>
</label>
<label class="priority-btn priority-btn--critical" onclick="selectPriority('critical')" style="flex:1; display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px10px; border-radius:10px; border:2px solid #E5E7EB; background:white; cursor:pointer;">
<input type="radio" name="priority" value="critical" style="display:none;">
<span style="color:#EF4444; font-size:20px;"><i class="fas fa-exclamation-triangle"></i></span>
<span style="font-size:12px; font-weight:600;">Критическое</span>
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
<div class="modal__footer flex gap-md" style="padding:20px; border-top:1px solid #E5E7EB; display:flex; justify-content:flex-end; gap:12px;">
<button type="button" class="btn btn--ghost" onclick="closeMessageModal()">Отмена</button>
<button type="submit" class="btn btn--primary"><i class="fas fa-paper-plane"></i> Отправить</button>
</div>
</form>
</div>
</div>

<script>
function openMessageModal() { document.getElementById('messageModal').classList.add('active'); document.body.style.overflow = 'hidden'; }
function closeMessageModal() { document.getElementById('messageModal').classList.remove('active'); document.body.style.overflow = ''; }
function selectPriority(value) {
 document.querySelectorAll('.priority-btn').forEach(btn => btn.style.borderColor = '#E5E7EB');
 document.querySelector(`.priority-btn--${value}`).style.borderColor = value === 'normal' ? '#6B7280' : value === 'important' ? '#F59E0B' : '#EF4444';
 document.querySelector(`input[value="${value}"]`).checked = true;
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeMessageModal(); });
document.getElementById('messageModal').addEventListener('click', function(e) { if (e.target === this) closeMessageModal(); });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>