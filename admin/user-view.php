<?php
// admin/user-view.php - Просмотр пользователя
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

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

// Проверка авторизации админа
if (!isAdmin()) {
 redirect('/admin/login');
}

check_csrf();

$admin = getCurrentUser();
$user_id = $_GET['id'] ??0;
$currentUserViewUri = (string) ($_SERVER['REQUEST_URI'] ?? ('/admin/user/' . $user_id));
$rawUserReturnUrl = trim((string) ($_GET['return_url'] ?? ''));
$userReturnUrl = '/admin/users';
if ($rawUserReturnUrl !== '' && str_starts_with($rawUserReturnUrl, '/admin/')) {
    $userReturnUrl = $rawUserReturnUrl;
} else {
    $refererPath = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
    $refererQuery = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_QUERY);
    if ($refererPath !== '' && !str_starts_with($refererPath, '/admin/user/') && str_starts_with($refererPath, '/admin/')) {
        $userReturnUrl = $refererPath . ($refererQuery !== '' ? ('?' . $refererQuery) : '');
    }
}

// Получаем пользователя
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
 redirect($userReturnUrl);
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
$headerBackUrl = $userReturnUrl;
$headerBackLabel = 'Назад';
$hasSuccessMessage = isset($_GET['success']) && $_GET['success'] == '1';
$userAvatar = getUserAvatarData($user ?? []);

// Обработка отправки сообщения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
 if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
 $error = 'Ошибка безопасности';
 } else {
 $subject = trim($_POST['subject'] ?? '');
 $message = trim($_POST['message'] ?? '');
 $priority = $_POST['priority'] ?? 'normal';
 $attachmentUpload = uploadMessageAttachment($_FILES['attachment'] ?? []);
        
 if (empty($attachmentUpload['success'])) {
 $error = (string) ($attachmentUpload['message'] ?? 'Не удалось загрузить вложение.');
 } elseif ($subject === '' || ($message === '' && empty($attachmentUpload['uploaded']))) {
 $error = 'Заполните тему и сообщение или прикрепите файл';
 } else {
 [$attachmentFile, $attachmentOriginalName, $attachmentMimeType, $attachmentSize] = messageAttachmentInsertPayload($attachmentUpload);
 $stmt = $pdo->prepare("
 INSERT INTO admin_messages (user_id, admin_id, subject, message, priority, attachment_file, attachment_original_name, attachment_mime_type, attachment_size)
 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
 ");
 $stmt->execute([$user_id, $admin['id'], $subject, $message, $priority, $attachmentFile, $attachmentOriginalName, $attachmentMimeType, $attachmentSize]);
 $success = 'Сообщение отправлено';
            
 // Перенаправление для избежания повторной отправки
 header('Location: user-view.php?id=' . $user_id . '&success=1&return_url=' . urlencode($userReturnUrl));
 exit;
 }
 }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
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
</style>

<div class="user-view-actions mb-lg">
    <a href="<?= htmlspecialchars($userReturnUrl) ?>" class="btn btn--ghost">
        <i class="fas fa-arrow-left"></i> Назад
    </a>
    <button type="button" class="btn btn--primary" onclick="openMessageModal()">
        <i class="fas fa-envelope"></i> Отправить сообщение
    </button>
</div>

<?php if ($hasSuccessMessage): ?>
<div class="alert alert--success mb-lg">
    <i class="fas fa-check-circle alert__icon"></i>
    <div class="alert__content">
        <div class="alert__message">Сообщение успешно отправлено пользователю.</div>
    </div>
</div>
<?php endif; ?>

<div class="user-view-stats mb-lg">
    <div class="user-view-stat">
        <div class="user-view-stat__label">Заявок</div>
        <div class="user-view-stat__value"><?= count($applications) ?></div>
    </div>
    <div class="user-view-stat">
        <div class="user-view-stat__label">Сообщений</div>
        <div class="user-view-stat__value"><?= count($messages) ?></div>
    </div>
    <div class="user-view-stat">
        <div class="user-view-stat__label">Дата регистрации</div>
        <div class="user-view-stat__value user-view-stat__value--small"><?= date('d.m.Y', strtotime($user['created_at'])) ?></div>
    </div>
</div>

<!-- Информация о пользователе -->
<div class="card mb-lg">
<div class="card__body">
<div class="user-view-profile">
 <?php if ($userAvatar['url'] !== ''): ?>
<img src="<?= htmlspecialchars($userAvatar['url']) ?>" class="user-view-avatar" alt="<?= htmlspecialchars($userAvatar['label']) ?>">
 <?php else: ?>
<div class="user-view-avatar user-view-avatar--placeholder">
<span class="user-view-avatar__initials"><?= htmlspecialchars($userAvatar['initials']) ?></span>
</div>
 <?php endif; ?>
            
<div class="user-view-profile__content">
<h2><?= htmlspecialchars(getUserDisplayName($user ?? [], true)) ?></h2>
 <?php if ($user['is_admin']): ?>
<span class="badge badge--primary mb-md">Администратор</span>
 <?php endif; ?>
                
<div class="form-row mt-lg">
<div class="form-group">
<label class="form-label">VK ID</label>
<p><?= htmlspecialchars($user['vk_id'] ?? 'Не указан') ?></p>
</div>
<div class="form-group">
<label class="form-label">Email</label>
<p><?= htmlspecialchars($user['email'] ?: 'Не указан') ?></p>
</div>
<div class="form-group">
<label class="form-label">Тип профиля</label>
<p><?= htmlspecialchars(getUserTypeLabel((string) ($user['user_type'] ?? 'parent'))) ?></p>
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
<label class="form-label">Контактная информация организации</label>
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
<div class="card__body card__body--compact">
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
<td data-label="ID">#<?= $app['id'] ?></td>
<td data-label="Конкурс"><?= htmlspecialchars($app['contest_title']) ?></td>
<td data-label="Участников"><?= $app['participants_count'] ?></td>
<td data-label="Статус">
<span class="badge <?= $app['status'] === 'submitted' ? 'badge--success' : 'badge--warning' ?>">
 <?= $app['status'] === 'submitted' ? 'Не обработанная заявка' : 'Черновик' ?>
</span>
</td>
<td data-label="Дата"><?= date('d.m.Y', strtotime($app['created_at'])) ?></td>
<td data-label="Действия">
<a href="/admin/application/<?= $app['id'] ?>?return_url=<?= urlencode($currentUserViewUri) ?>" class="btn btn--ghost btn--sm">
<i class="fas fa-eye"></i>
</a>
</td>
</tr>
 <?php endforeach; ?>
                
 <?php if (empty($applications)): ?>
<tr>
<td colspan="6" class="text-center text-secondary user-view-empty">
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
<div class="card__body card__body--compact">
 <?php if (!empty($messages)): ?>
<div class="user-view-messages">
 <?php foreach ($messages as $msg): ?>
<article class="user-view-message user-view-message--<?= htmlspecialchars($msg['priority']) ?>">
<div class="user-view-message__head">
<div class="user-view-message__title-wrap">
 <?php if ($msg['priority'] === 'critical'): ?>
<span class="badge user-view-badge user-view-badge--critical">Критическое</span>
 <?php elseif ($msg['priority'] === 'important'): ?>
<span class="badge user-view-badge user-view-badge--important">Важное</span>
 <?php else: ?>
<span class="badge user-view-badge user-view-badge--normal">Обычное</span>
 <?php endif; ?>
<strong class="user-view-message__subject"><?= htmlspecialchars($msg['subject']) ?></strong>
</div>
<span class="text-secondary user-view-message__date">
 <?= date('d.m.Y H:i', strtotime($msg['created_at'])) ?>
</span>
</div>
<p class="user-view-message__text"><?= nl2br(htmlspecialchars($msg['message'])) ?></p>
<div class="text-secondary user-view-message__author">
 От: <?= htmlspecialchars(($msg['admin_name'] ?? 'Админ') . ' ' . ($msg['admin_surname'] ?? '')) ?>
</div>
</article>
 <?php endforeach; ?>
</div>
 <?php else: ?>
<div class="text-center text-secondary user-view-empty">
 Сообщений нет
</div>
 <?php endif; ?>
</div>
</div>

<!-- Модальное окно отправки сообщения -->
<div class="modal" id="messageModal">
<div class="modal__content user-view-modal message-compose-modal">
<div class="modal__header">
<h3>Отправить сообщение</h3>
<button type="button" class="modal__close" onclick="closeMessageModal()">&times;</button>
</div>
<form method="POST" action="user-view.php?id=<?= e($user_id) ?>" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="action" value="send_message">
<div class="modal__body">
<div class="message-compose">
<div class="message-compose__intro">
<div class="message-compose__intro-icon"><i class="fas fa-paper-plane"></i></div>
<div>
<div class="message-compose__intro-title">Личное сообщение пользователю</div>
<div class="message-compose__intro-text">Это окно теперь выглядит и ощущается так же, как другие формы отправки сообщений в админке.</div>
</div>
</div>
<div class="message-compose__section">
<label class="form-label">Приоритет сообщения</label>
<div class="message-compose__priority-grid">
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
<div class="message-compose__section">
<label class="form-label">Тема сообщения</label>
<input type="text" name="subject" class="form-input" required placeholder="Введите тему">
</div>
<div class="message-compose__section">
<label class="form-label">Текст сообщения</label>
<textarea name="message" class="form-textarea" rows="5" placeholder="Введите текст сообщения"></textarea>
</div>
<div class="message-compose__section">
<label class="form-label">Вложение</label>
<input type="file" name="attachment" class="form-input js-message-attachment-input" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.doc,.docx,.rtf,.xls,.xlsx,.csv,.zip,image/*,application/pdf,text/plain,text/csv">
<div class="form-help">Изображение можно сразу просмотреть, а файл отправится вложением. До 10 МБ.</div>
<div class="message-attachment-preview js-message-attachment-preview" hidden></div>
</div>
</div>
<div class="modal__footer flex gap-md">
<div class="message-compose__footer">
<div class="message-compose__footer-note">Поясняющий текст сделан компактнее, чтобы форма выглядела чище.</div>
<div class="flex gap-md">
<button type="button" class="btn btn--ghost" onclick="closeMessageModal()">Отмена</button>
<button type="submit" class="btn btn--primary">
<i class="fas fa-paper-plane"></i> Отправить
</button>
</div>
</div>
</div>
</form>
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

<script>
function openMessageModal() {
 const modal = document.getElementById('messageModal');
 if (!modal) return;
 modal.classList.add('active');
 document.body.style.overflow = 'hidden';
 const subjectInput = modal.querySelector('input[name="subject"]');
 const attachmentInput = modal.querySelector('input[name="attachment"]');
 const attachmentPreview = modal.querySelector('.js-message-attachment-preview');
 if (subjectInput) {
  subjectInput.focus();
 }
 if (attachmentInput) {
  attachmentInput.value = '';
 }
 if (attachmentPreview) {
  attachmentPreview.innerHTML = '';
  attachmentPreview.hidden = true;
 }
}

function closeMessageModal() {
 document.getElementById('messageModal').classList.remove('active');
 document.body.style.overflow = '';
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
 const otherModal = document.querySelector('.modal.active:not(#messageImagePreviewModal)');
 document.body.style.overflow = otherModal ? 'hidden' : '';
}

function escapeAttachmentHtml(value) {
 return String(value || '')
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&#039;');
}

function buildMessageAttachmentPreviewMarkup(file) {
 if (!file) return '';
 const fileName = file.name || 'Файл';
 const safeFileName = escapeAttachmentHtml(fileName);
 if (String(file.type || '').startsWith('image/')) {
  const objectUrl = URL.createObjectURL(file);
  return `
   <button type="button" class="message-attachment-preview__image-button js-local-image-preview" data-image-src="${objectUrl}" data-image-title="${safeFileName}">
    <img src="${objectUrl}" alt="${safeFileName}" class="message-attachment-preview__thumb">
    <span class="message-attachment-preview__caption"><i class="fas fa-search-plus"></i> Предпросмотр</span>
   </button>
  `;
 }
 return `<div class="message-attachment-preview__file"><i class="fas fa-paperclip"></i><span>${safeFileName}</span></div>`;
}

function initMessageAttachmentInput(input) {
 if (!input) return;
 const preview = input.parentElement?.querySelector('.js-message-attachment-preview');
 if (!preview) return;
 input.addEventListener('change', () => {
  const file = input.files && input.files[0] ? input.files[0] : null;
  preview.innerHTML = '';
  preview.hidden = !file;
  if (!file) return;
  preview.innerHTML = buildMessageAttachmentPreviewMarkup(file);
  preview.querySelectorAll('.js-local-image-preview').forEach((button) => {
   button.addEventListener('click', () => {
    openMessageImagePreview(
     encodeURIComponent(button.dataset.imageSrc || ''),
     encodeURIComponent(button.dataset.imageTitle || 'Предпросмотр изображения')
    );
   });
  });
 });
}

function selectPriority(value) {
 document.querySelectorAll('.priority-btn').forEach(btn => btn.classList.remove('selected'));
 document.querySelector(`.priority-btn--${value}`).classList.add('selected');
 document.querySelector(`input[value="${value}"]`).checked = true;
}

// Инициализация при загрузке
document.addEventListener('DOMContentLoaded', function() {
 selectPriority('normal');
 document.querySelectorAll('.js-message-attachment-input').forEach(initMessageAttachmentInput);
});

// Закрытие по Escape
document.addEventListener('keydown', function(e) {
 if (e.key === 'Escape') {
  closeMessageModal();
  closeMessageImagePreview();
 }
});

// Закрытие по клику вне модального окна
document.getElementById('messageImagePreviewModal')?.addEventListener('click', function(e) {
 if (e.target === this) {
  closeMessageImagePreview();
 }
});
document.getElementById('messageModal').addEventListener('click', function(e) {
 if (e.target === this) {
 closeMessageModal();
 }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
