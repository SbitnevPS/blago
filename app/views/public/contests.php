<?php
// contests.php - Список конкурсов
require_once dirname(__DIR__, 3) . '/config.php';

$currentPage = 'contests';
$isGuest = !isAuthenticated();
$userId = (int) (getCurrentUserId() ?? 0);
$contests = getActiveContests();
$submittedContestIds = $isGuest ? [] : array_flip(getUserSubmittedContestIds($userId));
$isSingleContest = count($contests) === 1;
$settings = getSystemSettings();
$homepageHeroImage = trim((string)($settings['homepage_hero_image'] ?? ''));
$homepageHeroSrc = $homepageHeroImage !== '' ? '/uploads/site-banners/' . rawurlencode($homepageHeroImage) : '/public/contest-hero-placeholder.svg';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(sitePageTitle('Конкурсы'), ENT_QUOTES, 'UTF-8') ?></title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="contests-page">
<section class="contests-hero">
    <div class="container contests-hero__inner">
        <div class="contests-hero__content">
            <h1 class="contests-hero__title">Конкурсы рисунков</h1>
            <p class="contests-hero__subtitle">
                <?= htmlspecialchars(siteProjectsLabel(), ENT_QUOTES, 'UTF-8') ?><br>
                Открывайте новые творческие вызовы, вдохновляйтесь и отправляйте работы на любимые конкурсы.
            </p>
        </div>
        <div class="contests-hero__image-wrap">
            <img src="<?= htmlspecialchars($homepageHeroSrc) ?>" alt="Обложка главной страницы" class="contests-hero__image">
        </div>
    </div>
</section>

<section class="container contests-page__content">
<div class="flex justify-between items-center mb-lg">
<h2>Актуальные конкурсы</h2>
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
<div class="contest-list-grid <?= $isSingleContest ? 'contest-list-grid--single' : '' ?>">
<?php foreach ($contests as $contest): ?>
<?php $applicationUrl = getApplicationAccessUrl((int) $contest['id']); ?>
<?php
    $isApplied = isset($submittedContestIds[(int)$contest['id']]);
    $hasDates = !empty($contest['date_from']) || !empty($contest['date_to']);
    $themeStyle = normalizeContestThemeStyle($contest['theme_style'] ?? 'blue');
    $statusMeta = getContestPublicStatus($contest);
    $statusLabel = $statusMeta['label'] ?? 'Идёт приём работ';
    $statusClass = $statusMeta['class'] ?? 'contest-status--active';
    $coverImage = trim((string)($contest['cover_image'] ?? ''));
    $coverSrc = $coverImage !== ''
        ? '/uploads/contest-covers/' . rawurlencode($coverImage)
        : getContestThemePlaceholderPath($themeStyle);
?>
<article class="contest-card contest-theme--<?= htmlspecialchars($themeStyle) ?> slide-up">
    <div class="contest-card__image-box">
        <img src="<?= htmlspecialchars($coverSrc) ?>" alt="<?= htmlspecialchars($contest['title']) ?>" class="contest-card__image">
    </div>
    <div class="contest-card__content">
        <h3 class="contest-card__title"><?= htmlspecialchars($contest['title']) ?></h3>
        <div class="contest-card__badges">
            <span class="contest-status-badge <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusLabel) ?></span>
            <?php if ($isApplied): ?>
                <span class="badge badge--info">Заявка уже подана</span>
            <?php endif; ?>
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
        <div class="contest-card__actions">
        <a href="/contest/<?= (int)$contest['id'] ?>" class="btn btn--outline contest-card__btn-details">
            <i class="fas fa-info-circle"></i> Подробнее
        </a>
        <?php if ($isGuest): ?>
            <a
                href="<?= htmlspecialchars($applicationUrl) ?>"
                class="btn btn--primary"
                data-auth-required="1"
                data-target-url="/application-form?contest_id=<?= (int)$contest['id'] ?>"
            >
                <i class="fas fa-paper-plane"></i> Подать заявку
            </a>
        <?php else: ?>
            <a href="<?= htmlspecialchars($applicationUrl) ?>" class="btn btn--primary">
                <i class="fas fa-paper-plane"></i> Подать заявку
            </a>
        <?php endif; ?>
        </div>
    </div>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>

<section class="contests-benefits">
    <h2 class="contests-benefits__title">Почему стоит участвовать?</h2>
    <div class="contests-benefits__grid">
        <article class="contests-benefit-card">
            <div class="contests-benefit-card__icon"><i class="fas fa-lightbulb"></i></div>
            <h3>Прояви свой талант</h3>
            <p>Участвуйте в творческих конкурсах</p>
        </article>
        <article class="contests-benefit-card">
            <div class="contests-benefit-card__icon"><i class="fas fa-star"></i></div>
            <h3>Получите признание</h3>
            <p>Ваши работы увидят тысячи людей</p>
        </article>
        <article class="contests-benefit-card">
            <div class="contests-benefit-card__icon"><i class="fas fa-gift"></i></div>
            <h3>Выигрывайте призы</h3>
            <p>Победителей ждут ценные награды</p>
        </article>
    </div>
</section>
</section>
</main>

<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
</body>
</html>
