<?php
// my-applications.php - Дашборд заявок пользователя
require_once dirname(__DIR__, 3) . '/config.php';

if (!isAuthenticated()) {
    redirect('/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/contests'));
}

$user = getCurrentUser();
$applications = getUserApplications($user['id']);
$unreadByApplication = getUserUnreadCountsByApplication((int)$user['id']);
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
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="container page-section">
<div class="page-header">
    <div>
        <h1>Мои заявки</h1>
        <p class="text-secondary" style="margin-top:6px;">Здесь вы видите статус каждой заявки, работ, дипломов и сообщений.</p>
    </div>
    <a href="/contests" class="btn btn--primary">
        <i class="fas fa-trophy"></i> Выбрать конкурс
    </a>
</div>

<?php if (empty($applications)): ?>
<div class="empty-state">
    <div class="empty-state__icon">
        <i class="fas fa-file-circle-plus"></i>
    </div>
    <h3 class="empty-state__title">Пока нет заявок</h3>
    <p class="empty-state__text">Выберите конкурс и отправьте первую заявку — мы сразу покажем её статус и дальнейшие шаги.</p>
    <a href="/contests" class="btn btn--primary">
        <i class="fas fa-trophy"></i> Перейти к конкурсам
    </a>
</div>
<?php else: ?>
<div class="cards-grid">
<?php foreach ($applications as $app): ?>
<?php
    $statusMeta = getApplicationStatusMeta($app['status']);
    $works = getApplicationWorks((int)$app['id']);
    $summary = buildApplicationWorkSummary($works);
    $uiStatusMeta = getApplicationUiStatusMeta($summary);

    $hasDiplomas = $summary['diplomas'] > 0;
    $hasVkPublished = $summary['vk_published'] > 0;
    $vkLinks = [];
    foreach ($works as $work) {
        if (!empty($work['vk_post_url'])) {
            $vkLinks[] = (string)$work['vk_post_url'];
        }
    }
    $vkLinks = array_values(array_unique($vkLinks));

    $actionBase = '/application/' . (int)$app['id'];
?>
<div class="application-card application-card--dashboard">
    <div class="application-card__header">
        <div>
            <div class="application-card__badges-row">
                <span class="badge application-card__status <?= htmlspecialchars($statusMeta['badge_class']) ?>">
                    <?= htmlspecialchars($statusMeta['label']) ?>
                </span>
                <span class="badge <?= htmlspecialchars($uiStatusMeta['badge_class']) ?>">
                    <?= htmlspecialchars($uiStatusMeta['label']) ?>
                </span>
                <?php if (!empty($unreadByApplication[(int)$app['id']])): ?>
                    <span class="badge badge--error">Новые сообщения: <?= (int)$unreadByApplication[(int)$app['id']] ?></span>
                <?php endif; ?>
            </div>
            <h3 class="application-card__title">Заявка #<?= (int)$app['id'] ?></h3>
            <div class="application-card__contest"><?= htmlspecialchars($app['contest_title']) ?></div>
        </div>
        <div class="application-card__info-item application-card__info-item--compact">
            <span class="application-card__info-label">Подана</span>
            <span class="application-card__info-value"><?= date('d.m.Y', strtotime($app['created_at'])) ?></span>
        </div>
    </div>

    <div class="application-card__body">
        <div class="application-card__metrics">
            <span class="badge badge--secondary">Работ: <?= (int)$summary['total'] ?></span>
            <span class="badge badge--success">Принято: <?= (int)$summary['accepted'] ?></span>
            <span class="badge badge--reviewed">Рассмотрено: <?= (int)$summary['reviewed'] ?></span>
            <span class="badge badge--warning">На рассмотрении: <?= (int)$summary['pending'] ?></span>
            <span class="badge badge--info">Дипломы: <?= (int)$summary['diplomas'] ?></span>
            <span class="badge <?= $hasVkPublished ? 'badge--primary' : 'badge--secondary' ?>">
                ВК: <?= $hasVkPublished ? 'опубликовано' : 'не опубликовано' ?>
            </span>
        </div>
    </div>

    <div class="application-card__footer application-card__footer--actions">
        <a href="<?= $actionBase ?>" class="btn btn--primary btn--sm btn--open-application">
            <i class="fas fa-eye"></i> Открыть заявку
        </a>

        <?php if ($hasDiplomas): ?>
            <form method="POST" action="<?= $actionBase ?>">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="diploma_download_all">
                <button class="btn btn--ghost btn--sm" type="submit" title="Скачает все дипломы, которые уже доступны.">
                    <i class="fas fa-file-archive"></i> Скачать дипломы
                </button>
            </form>
            <form method="POST" action="<?= $actionBase ?>">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="diploma_links_all">
                <button class="btn btn--ghost btn--sm" type="submit" title="Покажет ссылки на все доступные дипломы.">
                    <i class="fas fa-link"></i> Получить ссылки
                </button>
            </form>
        <?php endif; ?>

        <?php if (!empty($vkLinks)): ?>
            <a href="<?= htmlspecialchars($vkLinks[0]) ?>" target="_blank" rel="noopener" class="btn btn--secondary btn--sm" title="Открыть публикации работ в VK.">
                <i class="fab fa-vk"></i> Открыть публикации
            </a>
        <?php endif; ?>
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
