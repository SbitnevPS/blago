<?php
// contests.php - Список конкурсов
require_once dirname(__DIR__, 3) . '/config.php';

$currentPage = 'contests';
$isGuest = !isAuthenticated();
$userId = (int) (getCurrentUserId() ?? 0);
$contests = getActiveContests();
$submittedContestIds = $isGuest ? [] : array_flip(getUserSubmittedContestIds($userId));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Конкурсы - ДетскиеКонкурсы.рф</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="container" style="padding: var(--space-xl) var(--space-lg);">
<div class="flex justify-between items-center mb-lg">
<h1>Конкурсы</h1>
<?php if (!$isGuest): ?>
    <a href="/my-applications" class="btn btn--secondary">
        <i class="fas fa-file-alt"></i> Мои заявки
    </a>
<?php endif; ?>
</div>

<?php if (empty($contests)): ?>
<div class="empty-state">
    <div class="empty-state__icon">
        <i class="fas fa-calendar-xmark"></i>
    </div>
    <h3 class="empty-state__title">Сейчас нет активных конкурсов</h3>
    <p class="empty-state__text">Проверьте страницу позже — новые конкурсы появляются регулярно.</p>
</div>
<?php else: ?>
<div class="cards-grid cards-grid--2">
<?php foreach ($contests as $contest): ?>
<?php
    $isApplied = isset($submittedContestIds[(int)$contest['id']]);
    $hasDates = !empty($contest['date_from']) || !empty($contest['date_to']);
?>
<div class="contest-card slide-up">
    <div class="contest-card__content">
        <div class="application-card__badges-row" style="margin-bottom:8px;">
            <span class="badge badge--success">Идёт приём заявок</span>
            <?php if ($isApplied): ?>
                <span class="badge badge--info">Заявка уже подана</span>
            <?php endif; ?>
        </div>
        <h3 class="contest-card__title"><?= htmlspecialchars($contest['title']) ?></h3>
        <div class="contest-card__description">
            <?= htmlspecialchars(mb_substr(strip_tags($contest['description'] ?? ''), 0, 200)) ?>...
        </div>
        <?php if ($hasDates): ?>
            <div class="contest-card__dates">
                <i class="fas fa-calendar"></i>
                <?php if (!empty($contest['date_from']) && !empty($contest['date_to'])): ?>
                    <?= date('d.m.Y', strtotime($contest['date_from'])) ?> - <?= date('d.m.Y', strtotime($contest['date_to'])) ?>
                <?php elseif (!empty($contest['date_from'])): ?>
                    С <?= date('d.m.Y', strtotime($contest['date_from'])) ?>
                <?php else: ?>
                    До <?= date('d.m.Y', strtotime($contest['date_to'])) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="contest-card__actions">
        <a href="/contest/<?= (int)$contest['id'] ?>" class="btn btn--outline">
            <i class="fas fa-info-circle"></i> Подробнее
        </a>
        <?php if ($isGuest): ?>
            <a
                href="/application-form?contest_id=<?= (int)$contest['id'] ?>"
                class="btn btn--primary"
                data-auth-required="1"
                data-target-url="/application-form?contest_id=<?= (int)$contest['id'] ?>"
            >
                <i class="fas fa-paper-plane"></i> Подать заявку
            </a>
        <?php else: ?>
            <a href="/application-form?contest_id=<?= (int)$contest['id'] ?>" class="btn btn--primary">
                <i class="fas fa-paper-plane"></i> Подать заявку
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</main>

<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
</body>
</html>
