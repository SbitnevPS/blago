<?php
// my-application-view.php - Просмотр заявки пользователем
require_once dirname(__DIR__, 3) . '/config.php';

// Проверка авторизации
if (!isAuthenticated()) {
 redirect('/login');
}
check_csrf();

$applicationId = intval($_GET['id'] ??0);
$userId = getCurrentUserId();
$user = getCurrentUser();

// Получаем заявку
$stmt = $pdo->prepare("
 SELECT a.*, c.title as contest_title, c.id as contest_id
 FROM applications a
 JOIN contests c ON a.contest_id = c.id
 WHERE a.id = ? AND a.user_id = ?
");
$stmt->execute([$applicationId, $userId]);
$application = $stmt->fetch();

if (!$application) {
 redirect('/my-applications');
}

$disputeChatSubject = 'Оспаривание решения по заявке #' . $applicationId;
$isDisputeChatClosed = false;
try {
    $closedStmt = $pdo->prepare("SELECT dispute_chat_closed FROM applications WHERE id = ? LIMIT 1");
    $closedStmt->execute([$applicationId]);
    $isDisputeChatClosed = (int) $closedStmt->fetchColumn() === 1;
} catch (Exception $e) {
    $isDisputeChatClosed = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dispute_reply') {
    $isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (string) ($_POST['ajax'] ?? '') === '1';
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Ошибка безопасности. Обновите страницу.';
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 403);
        }
    } else {
        $reason = trim($_POST['dispute_reason'] ?? '');
        if (!in_array($application['status'], ['declined', 'rejected'], true)) {
            $_SESSION['error_message'] = 'Оспорить можно только отклонённую заявку.';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 422);
            }
        } elseif ($isDisputeChatClosed) {
            $_SESSION['error_message'] = 'Чат завершён. Отправка новых сообщений недоступна.';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 423);
            }
        } elseif ($reason === '') {
            $_SESSION['error_message'] = 'Укажите причину оспаривания.';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 422);
            }
        } else {
            try {
                $stmt = $pdo->prepare("
                INSERT INTO messages (user_id, application_id, title, content, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $user['id'],
                    $applicationId,
                    $disputeChatSubject,
                    $reason,
                    $user['id'],
                ]);
                $_SESSION['success_message'] = 'Сообщение отправлено администратору.';
                if ($isAjaxRequest) {
                    $userLabel = trim(($user['surname'] ?? '') . ' ' . ($user['name'] ?? '') . ' ' . ($user['patronymic'] ?? ''));
                    if ($userLabel === '') {
                        $userLabel = 'Пользователь';
                    }
                    jsonResponse([
                        'success' => true,
                        'message' => [
                            'content' => $reason,
                            'created_at' => date('d.m.Y H:i'),
                            'author_label' => $userLabel,
                            'from_admin' => false,
                        ],
                    ]);
                }
            } catch (Exception $e) {
                $_SESSION['error_message'] = 'Не удалось отправить сообщение.';
                if ($isAjaxRequest) {
                    jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 500);
                }
            }
        }
    }
    redirect('/application/' . $applicationId . '#dispute-chat');
}

// Получаем участников
$stmt = $pdo->prepare("SELECT * FROM participants WHERE application_id = ?");
$stmt->execute([$applicationId]);
$participants = $stmt->fetchAll();

// Получаем корректировки
$corrections = getApplicationCorrections($applicationId);
$unresolvedCorrections = array_filter($corrections, function($c) {
 return $c['is_resolved'] ==0;
});

// Проверяем, разрешено ли редактирование
$canEdit = $application['allow_edit'] ==1 && $application['status'] !== 'approved';
$statusMeta = getApplicationStatusMeta($application['status']);
$statusClass = str_replace('badge--', '', $statusMeta['badge_class']);
$participantCorrections = [];
foreach ($unresolvedCorrections as $correction) {
    $participantId = (int) ($correction['participant_id'] ?? 0);
    if ($participantId > 0) {
        $participantCorrections[$participantId][] = $correction;
    }
}

$disputeChatMessages = [];
if (in_array($application['status'], ['declined', 'rejected'], true)) {
    try {
        $stmt = $pdo->prepare("
        SELECT m.*, u.name, u.surname, u.patronymic, u.is_admin
        FROM messages m
        JOIN users u ON u.id = m.created_by
        WHERE m.user_id = ? AND m.application_id = ? AND m.title = ?
        ORDER BY m.created_at ASC
        ");
        $stmt->execute([$user['id'], $applicationId, $disputeChatSubject]);
        $disputeChatMessages = $stmt->fetchAll();

        // Помечаем ответы администратора прочитанными
        $stmt = $pdo->prepare("
        UPDATE messages m
        JOIN users u ON u.id = m.created_by
        SET m.is_read = 1
        WHERE m.user_id = ?
          AND m.application_id = ?
          AND m.title = ?
          AND m.is_read = 0
          AND u.is_admin = 1
        ");
        $stmt->execute([$user['id'], $applicationId, $disputeChatSubject]);
    } catch (Exception $e) {
        $disputeChatMessages = [];
    }
}

$currentPage = 'applications';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Заявка #<?= $applicationId ?> - ДетскиеКонкурсы.рф</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
<style>
.dispute-chat-modal {max-width: 760px; width: calc(100% - 32px);}
.dispute-chat-modal__body {display:flex; flex-direction:column; gap:16px;}
.dispute-chat-modal__messages {max-height:420px; overflow:auto; display:flex; flex-direction:column; gap:12px;}
.dispute-chat-message {display:flex;}
.dispute-chat-message--admin {justify-content:flex-end;}
.dispute-chat-message--user {justify-content:flex-start;}
.dispute-chat-message__bubble {max-width:80%; padding:12px 14px; border-radius:12px;}
.dispute-chat-message--admin .dispute-chat-message__bubble {background:#DCFCE7;}
.dispute-chat-message--user .dispute-chat-message__bubble {background:#EEF2FF;}
.dispute-chat-message__meta {font-size:12px; color:#6B7280; margin-bottom:6px; display:flex; gap:8px; flex-wrap:wrap;}
.dispute-chat-message__text {white-space:pre-wrap; line-height:1.5;}
</style>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="container" style="padding: var(--space-xl) var(--space-lg);">
<div class="application-detail">
<?php if (!empty($_SESSION['success_message'])): ?>
<div class="alert alert--success mb-lg js-toast-alert">
<i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
</div>
<?php unset($_SESSION['success_message']); endif; ?>

<?php if (!empty($_SESSION['error_message'])): ?>
<div class="alert alert--error mb-lg js-toast-alert">
<i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
</div>
<?php unset($_SESSION['error_message']); endif; ?>

<?php if ($application['status'] === 'revision'): ?>
<div class="alert mb-lg" style="background:#FEF9C3; border:1px solid #FDE68A; color:#92400E;">
<i class="fas fa-tools"></i> Заявка отправлена на корректировку. Исправьте замечания и отправьте повторно.
</div>
<?php elseif ($application['status'] === 'cancelled'): ?>
<div class="alert mb-lg" style="background:#FEE2E2; border:1px solid #FCA5A5; color:#991B1B;">
<i class="fas fa-ban"></i> Заявка отменена.
</div>
<?php elseif (in_array($application['status'], ['declined', 'rejected'], true)): ?>
<div class="alert mb-lg" style="background:#FEE2E2; border:1px solid #FCA5A5; color:#991B1B;">
<i class="fas fa-times-circle"></i> Заявка отклонена.
</div>
<?php endif; ?>

 <!-- Корректировки -->
 <?php if (!empty($unresolvedCorrections)): ?>
<div class="corrections-alert">
<div class="corrections-alert__title">
<i class="fas fa-exclamation-triangle"></i>
 Требуются исправления (<?= count($unresolvedCorrections) ?>)
</div>
<ul class="corrections-alert__list">
 <?php foreach ($unresolvedCorrections as $corr): ?>
<li class="corrections-alert__item">
<span class="corrections-alert__field"><?= htmlspecialchars($corr['field_name']) ?></span>
 <?php if (!empty($corr['comment'])): ?>
<div class="corrections-alert__comment"><?= htmlspecialchars($corr['comment']) ?></div>
 <?php endif; ?>
</li>
 <?php endforeach; ?>
</ul>
</div>
<?php if ($canEdit): ?>
<div class="mt-md">
<a href="/application-form?contest_id=<?= $application['contest_id'] ?>&edit=<?= $applicationId ?>" class="btn btn--primary">
<i class="fas fa-wrench"></i> Исправить замечания
</a>
</div>
<?php endif; ?>
 <?php endif; ?>
        
<div class="application-detail__header">
<div class="flex justify-between items-center">
<div>
<h1 class="application-detail__title">Заявка #<?= $applicationId ?></h1>
<div class="application-detail__meta">
<span><i class="fas fa-trophy"></i> <?= htmlspecialchars($application['contest_title']) ?></span>
<span><i class="fas fa-calendar"></i> <?= date('d.m.Y H:i', strtotime($application['created_at'])) ?></span>
<span class="badge badge--<?= $statusClass ?>">
 <?= htmlspecialchars($statusMeta['label']) ?>
</span>
</div>
</div>
<?php if ($canEdit): ?>
<a href="/application-form?contest_id=<?= $application['contest_id'] ?>&edit=<?= $applicationId ?>" class="btn btn--primary">
<i class="fas fa-edit"></i> Редактировать заявку
</a>
<?php endif; ?>
</div>
</div>

<?php if (in_array($application['status'], ['declined', 'rejected'], true)): ?>
<div class="card mb-lg" id="dispute-chat">
    <div class="card__header flex justify-between items-center">
        <h3>Оспаривание решения по заявке</h3>
        <button type="button" class="btn btn--ghost btn--sm" onclick="openDisputeChatModal()">
            <i class="fas fa-comments"></i> Открыть чат
        </button>
    </div>
    <div class="card__body">
        <p class="text-secondary" style="margin:0;">
            Просматривайте переписку с администратором и отправляйте ответы во всплывающем окне чата.
        </p>
        <?php if ($isDisputeChatClosed): ?>
            <div class="alert alert--warning" style="margin-top:12px;">
                <i class="fas fa-lock"></i> Чат завершён администратором. Доступен только просмотр сообщений.
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal" id="disputeChatModal">
    <div class="modal__content message-modal dispute-chat-modal">
        <div class="modal__header">
            <h3>Оспаривание заявки #<?= (int) $applicationId ?></h3>
            <button type="button" class="modal__close" onclick="closeDisputeChatModal()">&times;</button>
        </div>
        <div class="modal__body dispute-chat-modal__body">
            <div class="dispute-chat-modal__messages" id="disputeChatMessages">
                <?php if (!empty($disputeChatMessages)): ?>
                    <?php foreach ($disputeChatMessages as $chatMessage): ?>
                        <?php $isAdminMessage = (int) ($chatMessage['is_admin'] ?? 0) === 1; ?>
                        <div class="dispute-chat-message <?= $isAdminMessage ? 'dispute-chat-message--user' : 'dispute-chat-message--admin' ?>">
                            <div class="dispute-chat-message__bubble">
                                <div class="dispute-chat-message__meta">
                                    <?php if ($isAdminMessage): ?>
                                        <?php
                                            $chatAuthorName = trim(($chatMessage['surname'] ?? '') . ' ' . ($chatMessage['name'] ?? '') . ' ' . ($chatMessage['patronymic'] ?? ''));
                                        ?>
                                        <?= htmlspecialchars('Руководитель проекта — ' . trim($chatAuthorName)) ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars(trim(($user['surname'] ?? '') . ' ' . ($user['name'] ?? '') . ' ' . ($user['patronymic'] ?? ''))) ?>
                                    <?php endif; ?>
                                    <span>• <?= date('d.m.Y H:i', strtotime($chatMessage['created_at'])) ?></span>
                                </div>
                                <div class="dispute-chat-message__text"><?= htmlspecialchars($chatMessage['content']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-secondary">Сообщений пока нет.</p>
                <?php endif; ?>
            </div>

            <?php if (!$isDisputeChatClosed): ?>
                <form method="POST" class="dispute-chat-modal__composer">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="dispute_reply">
                    <div class="form-group">
                        <label class="form-label">Ваш ответ в чате</label>
                        <textarea name="dispute_reason" class="form-textarea js-chat-hotkey" rows="4" required placeholder="Напишите сообщение администратору..."></textarea>
                    </div>
                    <button type="submit" class="btn btn--primary">
                        <i class="fas fa-paper-plane"></i> Отправить
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
        
 <!-- Данные родителя -->
<div class="card mb-lg">
<div class="card__header"><h3>Данные родителя/куратора</h3></div>
<div class="card__body">
<div class="form-row">
<div class="form-group">
<div class="form-label">ФИО</div>
<div class="form-value"><?= htmlspecialchars($application['parent_fio'] ?? '—') ?></div>
</div>
</div>
</div>
</div>
        
 <!-- Участники -->
<h2 class="mb-lg">Участники (<?= count($participants) ?>)</h2>
        
 <?php foreach ($participants as $index => $participant): ?>
<?php $hasParticipantCorrection = !empty($participantCorrections[(int) $participant['id']]); ?>
<div class="participant-card<?= $hasParticipantCorrection ? ' participant-card--needs-fix' : '' ?>">
<div class="participant-card__title">
<span class="participant-card__number"><?= $index +1 ?></span>
 Участник <?= $index +1 ?>
</div>
<?php if ($hasParticipantCorrection): ?>
<div class="application-note" style="margin-bottom:12px;">
    <strong><i class="fas fa-tools"></i> Требует исправлений</strong>
    <?php foreach ($participantCorrections[(int) $participant['id']] as $participantCorrection): ?>
        <div>• <?= htmlspecialchars($participantCorrection['field_name']) ?><?= !empty($participantCorrection['comment']) ? ': ' . htmlspecialchars($participantCorrection['comment']) : '' ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div style="display:flex; gap:28px; align-items:flex-start; flex-wrap:wrap;">
<div style="flex:1; min-width:280px;">
<div class="form-row" style="gap:20px;">
<div class="participant-card__field">
<div class="participant-card__label">ФИО</div>
<div class="participant-card__value"><?= htmlspecialchars($participant['fio'] ?? '—') ?></div>
</div>
<div class="participant-card__field">
<div class="participant-card__label">Возраст</div>
<div class="participant-card__value"><?= htmlspecialchars($participant['age'] ?? '—') ?></div>
</div>
</div>
<div class="participant-card__field" style="margin-top:16px;">
<div class="participant-card__label">Регион</div>
<div class="participant-card__value"><?= htmlspecialchars($participant['region'] ?? '—') ?></div>
</div>
<div class="participant-card__field" style="margin-top:16px;">
<div class="participant-card__label">Организация</div>
<div class="participant-card__value"><?= htmlspecialchars($participant['organization_name'] ?? '—') ?></div>
</div>
            
 <?php if (!empty($participant['organization_address'])): ?>
<div class="participant-card__field">
<div class="participant-card__label">Адрес организации</div>
<div class="participant-card__value"><?= htmlspecialchars($participant['organization_address']) ?></div>
</div>
 <?php endif; ?>
            
 <?php if (!empty($participant['organization_email'])): ?>
<div class="participant-card__field">
<div class="participant-card__label">Email организации</div>
<div class="participant-card__value"><?= htmlspecialchars($participant['organization_email']) ?></div>
</div>
 <?php endif; ?>

 </div>
 <?php if (!empty($participant['drawing_file'])): ?>
<div class="drawing-preview" style="width:320px; margin-top:0;">
<div class="participant-card__label">Рисунок</div>
<?php $drawingSrc = getParticipantDrawingWebPath($user['email'] ?? '', $participant['drawing_file']); ?>
<img src="<?= htmlspecialchars($drawingSrc) ?>" 
 alt="Рисунок" class="drawing-preview__image drawing-preview__image--large js-gallery-image"
 data-gallery-index="<?= $index ?>">
</div>
 <?php endif; ?>
</div>
</div>
 <?php endforeach; ?>
        
 <!-- Дополнительная информация -->
 <?php if (!empty($application['source_info']) || !empty($application['colleagues_info'])): ?>
<div class="card mb-lg">
<div class="card__header"><h3>Дополнительная информация</h3></div>
<div class="card__body">
 <?php if (!empty($application['source_info'])): ?>
<div class="form-group">
<div class="form-label">Откуда узнали о конкурсе</div>
<div><?= htmlspecialchars($application['source_info']) ?></div>
</div>
 <?php endif; ?>
            
 <?php if (!empty($application['colleagues_info'])): ?>
<div class="form-group">
<div class="form-label">Проинформировали коллег</div>
<div><?= htmlspecialchars($application['colleagues_info']) ?></div>
</div>
 <?php endif; ?>
</div>
</div>
 <?php endif; ?>
        
<div class="mt-xl">
<a href="/my-applications" class="btn btn--secondary">
<i class="fas fa-arrow-left"></i> К списку заявок
</a>
</div>
</div>
</main>

<footer class="footer">
<div class="container">
<div class="footer__inner">
<p class="footer__text">© <?= date('Y') ?> ДетскиеКонкурсы.рф</p>
</div>
</div>
</footer>
<?php
$galleryImages = [];
foreach ($participants as $participant) {
    if (!empty($participant['drawing_file'])) {
        $galleryImages[] = [
            'src' => getParticipantDrawingWebPath($user['email'] ?? '', $participant['drawing_file']),
            'title' => $participant['fio'] ?? 'Рисунок',
        ];
    }
}
?>
<?php if (!empty($galleryImages)): ?>
<div class="modal" id="galleryModal">
<div class="modal__content" style="max-width: 1100px; width: 96%;">
<div class="modal__header">
<h3 id="galleryTitle">Просмотр рисунка</h3>
<button type="button" class="modal__close" onclick="closeGallery()">&times;</button>
</div>
<div class="modal__body">
<img id="galleryImage" src="" alt="Рисунок" style="width:100%; max-height:min(62vh, calc(100vh - 280px)); object-fit:contain; border-radius:12px; background:#111;">
<div class="flex gap-sm mt-md" id="galleryThumbs" style="overflow:auto; max-height:96px;"></div>
</div>
</div>
</div>
<script>
const galleryItems = <?= json_encode($galleryImages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let galleryCurrent = 0;

function renderGalleryThumbs() {
 const container = document.getElementById('galleryThumbs');
 container.innerHTML = '';
 galleryItems.forEach((item, i) => {
  const thumb = document.createElement('img');
  thumb.src = item.src;
  thumb.alt = item.title;
  thumb.style.cssText = `width:72px;height:72px;object-fit:cover;border-radius:8px;cursor:pointer;border:${i === galleryCurrent ? '3px solid #6366F1' : '2px solid #E5E7EB'}`;
  thumb.addEventListener('click', () => openGallery(i));
  container.appendChild(thumb);
 });
}

function openGallery(index) {
 galleryCurrent = index;
 const item = galleryItems[index];
 document.getElementById('galleryImage').src = item.src;
 document.getElementById('galleryTitle').textContent = item.title;
 document.getElementById('galleryModal').classList.add('active');
 document.body.style.overflow = 'hidden';
 renderGalleryThumbs();
}

function closeGallery() {
 document.getElementById('galleryModal').classList.remove('active');
 document.body.style.overflow = '';
}

document.querySelectorAll('.js-gallery-image').forEach((img, idx) => {
 img.style.cursor = 'zoom-in';
 img.addEventListener('click', () => openGallery(idx));
});

document.addEventListener('keydown', (e) => {
 if (!document.getElementById('galleryModal').classList.contains('active')) return;
 if (e.key === 'Escape') closeGallery();
 if (e.key === 'ArrowRight') openGallery((galleryCurrent + 1) % galleryItems.length);
 if (e.key === 'ArrowLeft') openGallery((galleryCurrent - 1 + galleryItems.length) % galleryItems.length);
});

document.getElementById('galleryModal').addEventListener('click', (event) => {
 if (event.target === event.currentTarget) {
  closeGallery();
 }
});
</script>
<?php endif; ?>
<script>
function openDisputeChatModal() {
 const modal = document.getElementById('disputeChatModal');
 if (!modal) return;
 modal.classList.add('active');
 document.body.style.overflow = 'hidden';
 const messagesContainer = document.getElementById('disputeChatMessages');
 if (messagesContainer) {
  messagesContainer.scrollTop = messagesContainer.scrollHeight;
 }
}

function closeDisputeChatModal() {
 const modal = document.getElementById('disputeChatModal');
 if (!modal) return;
 modal.classList.remove('active');
 document.body.style.overflow = '';
 if (window.location.hash === '#dispute-chat') {
  history.replaceState(null, '', window.location.pathname + window.location.search);
 }
}

function appendDisputeMessage(container, messageData) {
 if (!container || !messageData) return;
 const messageWrap = document.createElement('div');
 messageWrap.className = 'dispute-chat-message ' + (messageData.from_admin ? 'dispute-chat-message--user' : 'dispute-chat-message--admin');

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

document.getElementById('disputeChatModal')?.addEventListener('click', (event) => {
 if (event.target === event.currentTarget) {
  closeDisputeChatModal();
 }
});

if (window.location.hash === '#dispute-chat') {
 openDisputeChatModal();
}

const disputeReplyForm = document.querySelector('#disputeChatModal form.dispute-chat-modal__composer');
if (disputeReplyForm) {
 disputeReplyForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  const textarea = disputeReplyForm.querySelector('textarea[name="dispute_reason"]');
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

document.querySelectorAll('.js-chat-hotkey').forEach((textarea) => {
 textarea.addEventListener('keydown', (event) => {
  if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
   event.preventDefault();
   const form = textarea.closest('form');
   if (form && form.reportValidity()) form.requestSubmit();
  }
 });
});

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

document.querySelectorAll('.alert').forEach((alertEl) => {
 const type = alertEl.classList.contains('alert--error') ? 'error' : 'success';
 showToast(alertEl.textContent.trim(), type);
 alertEl.remove();
});

document.addEventListener('keydown', (event) => {
 if (event.key === 'Escape') {
  closeDisputeChatModal();
 }
});
</script>
</body>
</html>
