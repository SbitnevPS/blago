<?php
// my-application-view.php - Просмотр заявки пользователем
require_once __DIR__ . '/config.php';

// Проверка авторизации
if (!isAuthenticated()) {
 redirect('/login');
}

$applicationId = intval($_GET['id'] ??0);
$userId = getCurrentUserId();

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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="/css/style.css">
<style>
 .application-detail { max-width:900px; margin:0 auto; }
 .application-detail__header { margin-bottom: var(--space-xl); }
 .application-detail__title { font-size: var(--font-size-2xl); margin-bottom: var(--space-sm); }
 .application-detail__meta { display: flex; gap: var(--space-lg); flex-wrap: wrap; color: var(--color-text-secondary); font-size: var(--font-size-sm); }
 .participant-card { background: var(--color-bg); border-radius: var(--radius-lg); padding: var(--space-lg); margin-bottom: var(--space-lg); border:1px solid var(--color-border); }
 .participant-card__title { font-size: var(--font-size-lg); font-weight: var(--font-weight-semibold); margin-bottom: var(--space-md); display: flex; align-items: center; gap: var(--space-sm); }
 .participant-card__number { background: var(--color-primary); color: white; width:24px; height:24px; border-radius:50%; display: flex; align-items: center; justify-content: center; font-size:12px; }
 .participant-card__field { margin-bottom: var(--space-sm); }
 .participant-card__label { font-size: var(--font-size-sm); color: var(--color-text-secondary); }
 .participant-card__value { font-weight: var(--font-weight-medium); }
 .drawing-preview { margin-top: var(--space-md); }
 .drawing-preview__image { width:200px; height:200px; object-fit: cover; border-radius: var(--radius-md); border:2px solid var(--color-border); }
</style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

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
<img src="/uploads/drawings/<?= urlencode($user['email']) ?>/<?= htmlspecialchars($participant['drawing_file']) ?>" 
 alt="Рисунок" class="drawing-preview__image">
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
</body>
</html>
