<?php
// my-applications.php - Список заявок пользователя
require_once dirname(__DIR__, 3) . '/config.php';

// Проверка авторизации
if (!isAuthenticated()) {
 redirect('/login');
}

$user = getCurrentUser();
$applications = getUserApplications($user['id']);
$currentPage = 'applications';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Мои заявки - ДетскиеКонкурсы.рф</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<!-- Навигация -->
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="container page-section">
<div class="page-header">
<h1>Мои заявки</h1>
<a href="/contests" class="btn btn--primary">
<i class="fas fa-plus"></i> Новый конкурс
</a>
</div>
        
<?php if (empty($applications)): ?>
<div class="empty-state">
<div class="empty-state__icon">
<i class="fas fa-file-alt"></i>
</div>
<h3 class="empty-state__title">У вас пока нет заявок</h3>
<p class="empty-state__text">Выберите конкурс и отправьте заявку на участие</p>
<a href="/contests" class="btn btn--primary">
<i class="fas fa-trophy"></i> Выбрать конкурс
</a>
</div>
<?php else: ?>
<div class="cards-grid">
<?php foreach ($applications as $app): ?>
<?php
    $statusLabels = [
        'draft' => 'Черновик',
        'submitted' => 'Отправлена',
        'revision' => 'На корректировке',
        'approved' => 'Заявка принята',
        'declined' => 'Заявка отклонена',
        'cancelled' => 'Отменена',
    ];
    $statusClass = in_array($app['status'], ['submitted', 'approved'], true) ? 'badge--success' : 'badge--warning';
    $cardStyle = '';
    if ($app['status'] === 'approved') {
        $cardStyle = 'background:#ECFDF5;';
    } elseif ($app['status'] === 'revision') {
        $cardStyle = 'background:#FEF9C3;';
    } elseif (in_array($app['status'], ['cancelled', 'declined'], true)) {
        $cardStyle = 'background:#FEE2E2;';
    }
?>
<a href="/application/<?= $app['id'] ?>" class="application-card application-card--link" style="<?= $cardStyle ?>">
<div class="application-card__header">
<div>
<h3 class="application-card__title">Заявка #<?= (int) $app['id'] ?></h3>
<div class="application-card__contest"><?= htmlspecialchars($app['contest_title']) ?></div>
</div>
<span class="badge <?= $statusClass ?>">
<?= htmlspecialchars($statusLabels[$app['status']] ?? ucfirst((string) $app['status'])) ?>
</span>
</div>
<div class="application-card__body">
<div class="application-card__info">
<div class="application-card__info-item">
<span class="application-card__info-label">Участников</span>
<span class="application-card__info-value"><?= (int) $app['participants_count'] ?></span>
</div>
<div class="application-card__info-item">
<span class="application-card__info-label">Дата</span>
<span class="application-card__info-value"><?= date('d.m.Y', strtotime($app['created_at'])) ?></span>
</div>
</div>
</div>
<div class="application-card__footer">
<span class="text-secondary application-card__more">
<i class="fas fa-eye"></i> Подробнее
</span>
<i class="fas fa-chevron-right application-card__arrow"></i>
</div>
</a>
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
