<?php
// my-application-view.php - Просмотр заявки пользователем
require_once dirname(__DIR__, 3) . '/config.php';

// Проверка авторизации
if (!isAuthenticated()) {
 redirect('/login');
}

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

$currentPage = 'applications';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Заявка #<?= $applicationId ?> - ДетскиеКонкурсы.рф</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="container" style="padding: var(--space-xl) var(--space-lg);">
<div class="application-detail">
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
<span class="badge badge--<?= $application['status'] === 'submitted' ? 'success' : ($application['status'] === 'approved' ? 'success' : 'warning') ?>">
 <?= $application['status'] === 'submitted' ? 'Отправлена' : ($application['status'] === 'approved' ? 'Одобрена' : 'Черновик') ?>
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
<div class="participant-card">
<div class="participant-card__title">
<span class="participant-card__number"><?= $index +1 ?></span>
 Участник <?= $index +1 ?>
</div>
            
<div class="form-row">
<div class="participant-card__field">
<div class="participant-card__label">ФИО</div>
<div class="participant-card__value"><?= htmlspecialchars($participant['fio'] ?? '—') ?></div>
</div>
<div class="participant-card__field">
<div class="participant-card__label">Возраст</div>
<div class="participant-card__value"><?= htmlspecialchars($participant['age'] ?? '—') ?></div>
</div>
</div>
            
<div class="participant-card__field">
<div class="participant-card__label">Регион</div>
<div class="participant-card__value"><?= htmlspecialchars($participant['region'] ?? '—') ?></div>
</div>
            
<div class="participant-card__field">
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
            
 <?php if (!empty($participant['drawing_file'])): ?>
<div class="drawing-preview">
<div class="participant-card__label">Рисунок</div>
<?php $drawingSrc = getParticipantDrawingWebPath($user['email'] ?? '', $participant['drawing_file']); ?>
<img src="<?= htmlspecialchars($drawingSrc) ?>" 
 alt="Рисунок" class="drawing-preview__image drawing-preview__image--large js-gallery-image"
 data-gallery-index="<?= $index ?>">
</div>
 <?php endif; ?>
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
<img id="galleryImage" src="" alt="Рисунок" style="width:100%; max-height:70vh; object-fit:contain; border-radius:12px; background:#111;">
<div class="flex gap-sm mt-md" id="galleryThumbs" style="overflow:auto;"></div>
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
</script>
<?php endif; ?>
</body>
</html>
