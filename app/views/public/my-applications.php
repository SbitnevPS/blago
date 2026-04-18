<?php
// my-applications.php - Дашборд заявок пользователя
require_once dirname(__DIR__, 3) . '/config.php';

if (!isAuthenticated()) {
    redirect('/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/contests'));
}

function getStatusClassByAdminStatus(string $status): string {
    // legacy helper: Tailwind-based status styles used to live here.
    return 'badge--secondary';
}

function getApplicationCardStatusMeta(array $application): array {
    $statusMeta = getApplicationDisplayMeta($application);
    $statusCode = (string) ($statusMeta['status_code'] ?? 'draft');

    return [
        'status_code' => $statusCode,
        'status_label' => (string) ($statusMeta['label'] ?? $statusCode),
        'badge_class' => (string) ($statusMeta['badge_class'] ?? 'badge--secondary'),
    ];
}

function getStatusGroup(array $application): string {
    $statusCode = (string) (getApplicationCardStatusMeta($application)['status_code'] ?? 'draft');

    return match ($statusCode) {
        'draft' => 'draft',
        'submitted', 'partial_reviewed', 'reviewed', 'corrected' => 'pending',
        'revision' => 'revision',
        'approved' => 'accepted',
        'rejected', 'cancelled' => 'rejected',
        default => 'other',
    };
}

$user = getCurrentUser();
$applications = getUserApplications($user['id']);
$unreadByApplication = getUserUnreadCountsByApplication((int)$user['id']);
$currentPage = 'applications';

$stats = [
    'total' => count($applications),
    'draft' => 0,
    'pending' => 0,
    'revision' => 0,
    'accepted' => 0,
    'rejected' => 0,
];

foreach ($applications as $application) {
    $group = getStatusGroup($application);
    if (isset($stats[$group])) {
        $stats[$group]++;
    }
}

$activeFilter = (string)($_GET['status'] ?? 'all');
$allowedFilters = ['all', 'draft', 'pending', 'revision', 'accepted', 'rejected'];
if (!in_array($activeFilter, $allowedFilters, true)) {
    $activeFilter = 'all';
}

$filterConfig = [
    'all' => [
        'label' => 'Все',
        'href' => '/my-applications',
        'count' => (int) ($stats['total'] ?? 0),
        'always_show' => true,
    ],
    'draft' => [
        'label' => 'Черновики',
        'href' => '/my-applications?status=draft',
        'count' => (int) ($stats['draft'] ?? 0),
    ],
    'pending' => [
        'label' => 'На рассмотрении',
        'href' => '/my-applications?status=pending',
        'count' => (int) ($stats['pending'] ?? 0),
    ],
    'revision' => [
        'label' => 'Требует исправлений',
        'href' => '/my-applications?status=revision',
        'count' => (int) ($stats['revision'] ?? 0),
    ],
    'accepted' => [
        'label' => 'Приняты',
        'href' => '/my-applications?status=accepted',
        'count' => (int) ($stats['accepted'] ?? 0),
    ],
    'rejected' => [
        'label' => 'Отклонены',
        'href' => '/my-applications?status=rejected',
        'count' => (int) ($stats['rejected'] ?? 0),
    ],
];

$visibleFilters = array_filter(
    $filterConfig,
    static fn(array $filter): bool => !empty($filter['always_show']) || (int) ($filter['count'] ?? 0) > 0
);

$filteredApplications = array_values(array_filter(
    $applications,
    static function (array $application) use ($activeFilter): bool {
        if ($activeFilter === 'all') {
            return true;
        }
        return getStatusGroup($application) === $activeFilter;
    }
));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Мои заявки - КОНКУРСЫ/ПРОЕКТЫ - ИА ДОБРОЕ ИНФО</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="page-section">
    <div class="container">
        <div class="flex items-center justify-between gap-md mb-lg" style="flex-wrap:wrap;">
            <div>
                <h1 style="margin:0;">Мои заявки</h1>
            </div>
            <a href="/contests" class="btn btn--primary btn--lg">
                <i class="fas fa-plus"></i> Подать новую заявку
            </a>
        </div>

        <nav class="my-applications-filters" aria-label="Фильтры">
            <?php foreach ($visibleFilters as $filterKey => $filter): ?>
                <?php $isActive = $activeFilter === $filterKey; ?>
                <a href="<?= htmlspecialchars((string) $filter['href']) ?>" class="my-applications-filter<?= $isActive ? ' is-active' : '' ?>">
                    <span class="my-applications-filter__label"><?= htmlspecialchars((string) $filter['label']) ?></span>
                    <span class="my-applications-filter__count"><?= (int) ($filter['count'] ?? 0) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if (empty($filteredApplications)): ?>
            <section class="card my-applications-empty">
                <div class="card__body">
                    <div class="my-applications-empty__icon"><i class="fas fa-folder-open"></i></div>
                    <h2 class="my-applications-empty__title">Заявок пока нет</h2>
                    <p class="my-applications-empty__subtitle">Выберите конкурс и подайте первую заявку. Это займёт несколько минут.</p>
                    <a href="/contests" class="btn btn--primary btn--lg"><i class="fas fa-plus"></i> Подать заявку</a>
                </div>
            </section>
        <?php else: ?>
            <div class="my-applications-grid">
                <?php foreach ($filteredApplications as $app): ?>
                    <?php
                        $works = getApplicationWorks((int) $app['id']);
                        $imagePath = '/public/contest-hero-placeholder.svg';

                        foreach ($works as $work) {
                            if ($imagePath === '/public/contest-hero-placeholder.svg' && !empty($work['drawing_file'])) {
                                $imagePath = (string) (getParticipantDrawingPreviewWebPath($user['email'] ?? '', (string) $work['drawing_file']) ?? $imagePath);
                            }
                        }

                        $statusMeta = getApplicationCardStatusMeta($app);
                        $statusLabel = (string) ($statusMeta['status_label'] ?? '—');
                        $badgeClass = (string) ($statusMeta['badge_class'] ?? 'badge--secondary');
                        $statusCode = (string) ($statusMeta['status_code'] ?? '');
                        $isRevision = getStatusGroup($app) === 'revision';
                        $isCorrected = $statusCode === 'corrected';
                        $unreadCount = (int) ($unreadByApplication[(int) ($app['id'] ?? 0)] ?? 0);
                    ?>
                    <article class="application-card application-card--dashboard my-application-card<?= $isRevision ? ' my-application-card--revision' : '' ?><?= $isCorrected ? ' my-application-card--corrected' : '' ?>">
                        <a class="my-application-card__media" href="/application/<?= (int) $app['id'] ?>" aria-label="Открыть заявку #<?= (int) ($app['id'] ?? 0) ?>">
                            <img src="<?= htmlspecialchars($imagePath) ?>" alt="<?= htmlspecialchars((string) $app['contest_title']) ?>" loading="lazy">
                        </a>

                        <div class="my-application-card__body">
                            <div class="my-application-card__top">
                                <div class="application-card__badges-row">
                                    <span class="badge <?= e($badgeClass) ?>"><?= e($statusLabel) ?></span>
                                    <?php if ($unreadCount > 0): ?>
                                        <span class="badge badge--error">Сообщения: <?= (int) $unreadCount ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="my-application-card__id">Заявка #<?= (int) ($app['id'] ?? 0) ?></div>
                            </div>

                            <h3 class="my-application-card__title"><?= htmlspecialchars((string) $app['contest_title']) ?></h3>

                            <?php if ($isRevision): ?>
                                <div class="my-application-card__note my-application-card__note--revision">Нужны исправления: откройте заявку и следуйте комментариям организатора.</div>
                            <?php elseif ($isCorrected): ?>
                                <div class="my-application-card__note my-application-card__note--corrected">Исправления отправлены. Заявка снова на проверке.</div>
                            <?php endif; ?>

                            <div class="my-application-card__meta">
                                <div class="my-application-card__meta-item">
                                    <div class="my-application-card__meta-label">Участников</div>
                                    <div class="my-application-card__meta-value"><?= (int) ($app['participants_count'] ?? 0) ?></div>
                                </div>
                                <div class="my-application-card__meta-item">
                                    <div class="my-application-card__meta-label">Дата</div>
                                    <div class="my-application-card__meta-value"><?= date('d.m.Y', strtotime((string) $app['created_at'])) ?></div>
                                </div>
                            </div>

                            <div class="my-application-card__actions">
                                <a href="/application/<?= (int) $app['id'] ?>" class="btn btn--primary btn--open-application">
                                    Открыть заявку <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
</div>
</main>

<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
</body>
</html>
