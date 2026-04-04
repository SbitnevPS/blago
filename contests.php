<?php
// contests.php - Список конкурсов
require_once __DIR__ . '/config.php';

// Проверка авторизации
if (!isAuthenticated()) {
 redirect('/login');
}

$currentPage = 'contests';
$contests = getActiveContests();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Конкурсы - ДетскиеКонкурсы.рф</title>
<?php include __DIR__ . '/includes/site-head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<main class="container" style="padding: var(--space-xl) var(--space-lg);">
<div class="flex justify-between items-center mb-lg">
<h1>Конкурсы</h1>
<a href="/my-applications" class="btn btn--secondary">
<i class="fas fa-file-alt"></i> Мои заявки
</a>
</div>
        
<?php if (empty($contests)): ?>
<div class="empty-state">
<div class="empty-state__icon">
<i class="fas fa-calendar-xmark"></i>
</div>
<h3 class="empty-state__title">Нет активных конкурсов</h3>
<p class="empty-state__text">Скоро появятся новые конкурсы. Следите за обновлениями!</p>
</div>
<?php else: ?>
<div class="cards-grid cards-grid--2">
<?php foreach ($contests as $contest): ?>
<div class="contest-card slide-up">
<div class="contest-card__content">
<h3 class="contest-card__title"><?= htmlspecialchars($contest['title']) ?></h3>
<div class="contest-card__description">
<?= htmlspecialchars(mb_substr(strip_tags($contest['description'] ?? ''),0,200)) ?>...
</div>
<?php if ($contest['date_from'] || $contest['date_to']): ?>
<div class="contest-card__dates">
<i class="fas fa-calendar"></i>
<?php if ($contest['date_from'] && $contest['date_to']): ?>
<?= date('d.m.Y', strtotime($contest['date_from'])) ?> - <?= date('d.m.Y', strtotime($contest['date_to'])) ?>
<?php elseif ($contest['date_from']): ?>
С <?= date('d.m.Y', strtotime($contest['date_from'])) ?>
<?php elseif ($contest['date_to']): ?>
До <?= date('d.m.Y', strtotime($contest['date_to'])) ?>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
<div class="contest-card__actions">
<a href="/contest/<?= $contest['id'] ?>" class="btn btn--outline">
<i class="fas fa-info-circle"></i> Подробнее о конкурсе
</a>
<a href="/application-form?contest_id=<?= $contest['id'] ?>" class="btn btn--primary">
<i class="fas fa-paper-plane"></i> Отправить заявку
</a>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
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
