<?php
// my-applications.php - Список заявок пользователя
require_once __DIR__ . '/config.php';

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
    <?php include __DIR__ . '/includes/site-head.php'; ?>
    </head>
<body>
    <!-- Навигация -->
<?php include __DIR__ . '/header.php'; ?>

<main class="container" style="padding: var(--space-xl) var(--space-lg);">
<div class="flex justify-between items-center mb-lg">
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
<a href="/application/<?= $app['id'] ?>" class="application-card" style="text-decoration: none; color: inherit;">
                        <div class="application-card__header">
                            <div>
                                <h3 class="application-card__title">Заявка #<?= $app['id'] ?></h3>
                                <div class="application-card__contest"><?= htmlspecialchars($app['contest_title']) ?></div>
                            </div>
                            <span class="badge <?= $app['status'] === 'submitted' ? 'badge--success' : 'badge--warning' ?>">
                                <?= $app['status'] === 'submitted' ? 'Отправлена' : 'Черновик' ?>
                            </span>
                        </div>
                        <div class="application-card__body">
                            <div class="application-card__info">
                                <div class="application-card__info-item">
                                    <span class="application-card__info-label">Участников</span>
                                    <span class="application-card__info-value"><?= $app['participants_count'] ?></span>
                                </div>
                                <div class="application-card__info-item">
                                    <span class="application-card__info-label">Дата</span>
                                    <span class="application-card__info-value"><?= date('d.m.Y', strtotime($app['created_at'])) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="application-card__footer">
                            <span class="text-secondary" style="font-size: 14px;">
                                <i class="fas fa-eye"></i> Подробнее
                            </span>
                            <i class="fas fa-chevron-right" style="color: var(--color-text-muted);"></i>
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
