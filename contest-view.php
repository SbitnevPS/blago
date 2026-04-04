<?php
// contest-view.php - Подробнее о конкурсе
require_once __DIR__ . '/config.php';

// Проверка авторизации
if (!isAuthenticated()) {
 redirect('/login');
}

$contest_id = $_GET['id'] ??0;
$contest = getContestById($contest_id);

if (!$contest) {
 redirect('/contests');
}

$currentPage = 'contests';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($contest['title']) ?> - ДетскиеКонкурсы.рф</title>
<?php include __DIR__ . '/includes/site-head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<main class="container" style="padding: var(--space-xl) var(--space-lg);">
<div class="flex items-center gap-md mb-lg">
<a href="/contests" class="btn btn--ghost">
<i class="fas fa-arrow-left"></i> Назад
</a>
</div>
        
<div class="card">
<div class="card__header">
<div class="flex justify-between items-center">
<h1><?= htmlspecialchars($contest['title']) ?></h1>
<a href="/application-form?contest_id=<?= $contest['id'] ?>" class="btn btn--primary btn--lg">
<i class="fas fa-paper-plane"></i> Отправить заявку
</a>
</div>
</div>
<div class="card__body">
<?php if ($contest['date_from'] || $contest['date_to']): ?>
<div class="flex items-center gap-md mb-lg" style="color: var(--color-text-secondary);">
<i class="fas fa-calendar"></i>
<?php if ($contest['date_from'] && $contest['date_to']): ?>
<?= date('d.m.Y', strtotime($contest['date_from'])) ?> - <?= date('d.m.Y', strtotime($contest['date_to'])) ?>
<?php elseif ($contest['date_from']): ?>
Начало: <?= date('d.m.Y', strtotime($contest['date_from'])) ?>
<?php elseif ($contest['date_to']): ?>
Окончание: <?= date('d.m.Y', strtotime($contest['date_to'])) ?>
<?php endif; ?>
</div>
<?php endif; ?>
                
<div class="contest-description">
<?= $contest['description'] ?? '<p style="color: var(--color-text-muted);">Описание конкурса скоро появится.</p>' ?>
</div>
                
<?php if (!empty($contest['document_file'])): ?>
<div class="mt-lg">
<a href="/uploads/documents/<?= htmlspecialchars($contest['document_file']) ?>" 
 class="btn btn--secondary" 
 target="_blank"
 download>
<i class="fas fa-download"></i> Скачать положение о конкурсе
</a>
</div>
<?php endif; ?>
</div>
</div>
        
<div class="flex gap-md mt-lg">
<a href="/application-form?contest_id=<?= $contest['id'] ?>" class="btn btn--primary btn--lg" style="flex:1;">
<i class="fas fa-paper-plane"></i> Отправить заявку на участие
</a>
<a href="/contests" class="btn btn--secondary btn--lg">
К списку конкурсов
</a>
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
