<?php
// my-applications.php - Дашборд заявок пользователя
require_once dirname(__DIR__, 3) . '/config.php';

if (!isAuthenticated()) {
    redirect('/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/contests'));
}

check_csrf();

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

function isApplicationContestArchived(array $application): bool {
    return (int) ($application['contest_is_archived'] ?? 0) === 1;
}

$user = getCurrentUser();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'delete_application') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
        redirect('/my-applications');
    }

    $applicationIdToDelete = max(0, (int) ($_POST['application_id'] ?? 0));
    if ($applicationIdToDelete <= 0) {
        $_SESSION['error_message'] = 'Не удалось определить заявку для удаления.';
        redirect('/my-applications');
    }

    $stmt = $pdo->prepare("
        SELECT id, user_id, payment_receipt
        FROM applications
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$applicationIdToDelete, (int) $user['id']]);
    $applicationToDelete = $stmt->fetch();

    if (!$applicationToDelete) {
        $_SESSION['error_message'] = 'Заявка не найдена или уже удалена.';
        redirect('/my-applications');
    }

    $participantStmt = $pdo->prepare("SELECT drawing_file FROM participants WHERE application_id = ?");
    $participantStmt->execute([$applicationIdToDelete]);
    $participantsToDelete = $participantStmt->fetchAll() ?: [];

    $filesToDelete = [];
    foreach ($participantsToDelete as $participantRow) {
        $drawingFile = trim((string) ($participantRow['drawing_file'] ?? ''));
        if ($drawingFile !== '') {
            $filesToDelete[] = getParticipantDrawingFsPath($user['email'] ?? '', $drawingFile);
            $filesToDelete[] = getParticipantDrawingThumbFsPath($user['email'] ?? '', $drawingFile);
        }
    }

    $paymentReceipt = trim((string) ($applicationToDelete['payment_receipt'] ?? ''));
    if ($paymentReceipt !== '') {
        $filesToDelete[] = DOCUMENTS_PATH . '/' . $paymentReceipt;
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM participants WHERE application_id = ?")->execute([$applicationIdToDelete]);
        $pdo->prepare("DELETE FROM applications WHERE id = ? AND user_id = ?")->execute([$applicationIdToDelete, (int) $user['id']]);
        $pdo->commit();

        foreach ($filesToDelete as $filePath) {
            if (is_string($filePath) && $filePath !== '' && is_file($filePath)) {
                @unlink($filePath);
            }
        }

        $_SESSION['success_message'] = 'Заявка удалена.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = 'Не удалось удалить заявку. Попробуйте ещё раз.';
    }

    redirect('/my-applications');
}

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

$primaryApplications = array_values(array_filter(
    $filteredApplications,
    static fn(array $application): bool => !isApplicationContestArchived($application)
));

$archivedApplications = array_values(array_filter(
    $filteredApplications,
    static fn(array $application): bool => isApplicationContestArchived($application)
));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(sitePageTitle('Мои заявки'), ENT_QUOTES, 'UTF-8') ?></title>
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
            <?php
            $renderApplicationsGrid = static function (array $items) use ($user, $unreadByApplication): void {
                if (empty($items)) {
                    return;
                }
                ?>
                <div class="my-applications-grid">
                    <?php foreach ($items as $app): ?>
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
                            $isAgreementDeclined = (int) ($app['agreement_declined'] ?? 0) === 1;
                            $unreadCount = (int) ($unreadByApplication[(int) ($app['id'] ?? 0)] ?? 0);
                            $isArchivedContest = isApplicationContestArchived($app);
                        ?>
                        <article class="application-card application-card--dashboard my-application-card<?= $isRevision ? ' my-application-card--revision' : '' ?><?= $isCorrected ? ' my-application-card--corrected' : '' ?><?= $isArchivedContest ? ' my-application-card--archived-contest' : '' ?>">
                            <a class="my-application-card__media" href="/application/<?= (int) $app['id'] ?>" aria-label="Открыть заявку #<?= (int) ($app['id'] ?? 0) ?>">
                                <img src="<?= htmlspecialchars($imagePath) ?>" alt="<?= htmlspecialchars((string) $app['contest_title']) ?>" loading="lazy">
                            </a>

                            <div class="my-application-card__body">
                                <div class="my-application-card__top">
                                    <div class="application-card__badges-row">
                                        <span class="badge <?= e($badgeClass) ?>"><?= e($statusLabel) ?></span>
                                        <?php if ($isArchivedContest): ?>
                                            <span class="badge badge--secondary">Архивный конкурс</span>
                                        <?php endif; ?>
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
                                <?php if ($isAgreementDeclined): ?>
                                    <div class="my-application-card__agreement-note">Пользовательское соглашение не подписано</div>
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
                                <button
                                    type="button"
                                    class="btn btn--ghost my-application-card__delete"
                                    data-delete-application-trigger
                                    data-application-id="<?= (int) ($app['id'] ?? 0) ?>"
                                    data-application-title="<?= htmlspecialchars((string) $app['contest_title'], ENT_QUOTES, 'UTF-8') ?>"
                                >
                                    <i class="fas fa-trash"></i> Удалить заявку
                                </button>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php
            };
            ?>

            <?php if (!empty($primaryApplications)): ?>
                <?php $renderApplicationsGrid($primaryApplications); ?>
            <?php endif; ?>

            <?php if (!empty($archivedApplications)): ?>
                <section class="my-applications-archive-section">
                    <div class="my-applications-section-head">
                        <h2 class="my-applications-section-head__title">Мои заявки, предыдущих конкурсов</h2>
                        <p class="my-applications-section-head__subtitle">Здесь собраны заявки по конкурсам, которые уже завершены и переведены в архив.</p>
                    </div>
                    <?php $renderApplicationsGrid($archivedApplications); ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
</div>
</main>

<div class="modal" id="deleteApplicationModal" aria-hidden="true">
    <div class="modal__content my-applications-delete-modal">
        <div class="modal__body my-applications-delete-modal__body">
            <div class="my-applications-delete-modal__icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 class="my-applications-delete-modal__title">Подтвердите удаление заявки</h3>
            <p class="my-applications-delete-modal__text">Это действие удалит заявку из вашего списка. Если вы действительно уверены, подтвердите удаление.</p>
            <form method="POST" id="deleteApplicationForm" class="my-applications-delete-modal__form">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="delete_application">
                <input type="hidden" name="application_id" id="deleteApplicationIdInput" value="">
                <button type="submit" class="btn btn--danger btn--lg my-applications-delete-modal__submit">Да, я действительно хочу удалить заявку!</button>
                <button type="button" class="btn btn--secondary btn--lg my-applications-delete-modal__cancel" id="deleteApplicationCancel">Отмена</button>
            </form>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
<script>
(function () {
    const modal = document.getElementById('deleteApplicationModal');
    const applicationIdInput = document.getElementById('deleteApplicationIdInput');
    const cancelButton = document.getElementById('deleteApplicationCancel');

    function openModal(applicationId) {
        if (!modal || !applicationIdInput) return;
        applicationIdInput.value = String(applicationId || '');
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('[data-delete-application-trigger]').forEach((button) => {
        button.addEventListener('click', () => {
            openModal(button.getAttribute('data-application-id') || '');
        });
    });

    cancelButton?.addEventListener('click', closeModal);
    modal?.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });
})();
</script>
</body>
</html>
