<?php
// admin/contests.php - Управление конкурсами
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (!isAdmin()) {
    redirect('/admin/login');
}

check_csrf();

$admin = getCurrentUser();
$currentPage = 'contests';
$pageTitle = 'Конкурсы';
$breadcrumb = 'Управление конкурсами';
$pageStyles = ['admin-contests.css'];

function contestsRedirectWithQuery(): void
{
    $query = $_SERVER['QUERY_STRING'] ?? '';
    redirect('/admin/contests' . ($query !== '' ? ('?' . $query) : ''));
}

function isAjaxRequest(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

if (isPostRequest() && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        if (isAjaxRequest()) {
            jsonResponse(['success' => false, 'message' => 'Ошибка безопасности: неверный CSRF токен'], 403);
        }
        $_SESSION['error_message'] = 'Ошибка безопасности: неверный CSRF токен';
        contestsRedirectWithQuery();
    }

    $contestId = (int) ($_POST['contest_id'] ?? 0);

    if ($_POST['action'] === 'toggle_publish') {
        $stmt = $pdo->prepare('UPDATE contests SET is_published = NOT is_published WHERE id = ?');
        $stmt->execute([$contestId]);
        if (isAjaxRequest()) {
            $contestStateStmt = $pdo->prepare('SELECT is_published FROM contests WHERE id = ? LIMIT 1');
            $contestStateStmt->execute([$contestId]);
            jsonResponse([
                'success' => true,
                'message' => 'Статус публикации изменён',
                'is_published' => (int) $contestStateStmt->fetchColumn() === 1,
            ]);
        }
        $_SESSION['success_message'] = 'Статус публикации изменён';
    } elseif ($_POST['action'] === 'toggle_archive') {
        $stmt = $pdo->prepare('UPDATE contests SET is_archived = NOT COALESCE(is_archived, 0), updated_at = NOW() WHERE id = ?');
        $stmt->execute([$contestId]);
        if (isAjaxRequest()) {
            $contestStateStmt = $pdo->prepare('SELECT COALESCE(is_archived, 0) AS is_archived FROM contests WHERE id = ? LIMIT 1');
            $contestStateStmt->execute([$contestId]);
            $contestState = $contestStateStmt->fetch() ?: ['is_archived' => 0];
            $isArchived = (int) ($contestState['is_archived'] ?? 0) === 1;

            jsonResponse([
                'success' => true,
                'message' => $isArchived ? 'Конкурс переведён в архив' : 'Конкурс возвращён в активные',
                'is_archived' => $isArchived,
                'archive_badge_label' => $isArchived ? 'Архивный' : 'Активный',
                'archive_badge_class' => $isArchived ? 'contest-badge--archived' : 'contest-badge--active-soft',
                'archive_button_label' => $isArchived ? 'Вернуть в активные' : 'В архив',
                'archive_button_icon' => $isArchived ? 'box-open' : 'box-archive',
            ]);
        }
        $_SESSION['success_message'] = 'Статус конкурса обновлён';
    } elseif ($_POST['action'] === 'delete') {
        $coverStmt = $pdo->prepare('SELECT cover_image FROM contests WHERE id = ?');
        $coverStmt->execute([$contestId]);
        $coverImage = $coverStmt->fetchColumn();

        $pdo->prepare('DELETE FROM participants WHERE application_id IN (SELECT id FROM applications WHERE contest_id = ?)')->execute([$contestId]);
        $pdo->prepare('DELETE FROM applications WHERE contest_id = ?')->execute([$contestId]);
        $pdo->prepare('DELETE FROM contests WHERE id = ?')->execute([$contestId]);

        if (!empty($coverImage) && file_exists(CONTEST_COVERS_PATH . '/' . $coverImage)) {
            unlink(CONTEST_COVERS_PATH . '/' . $coverImage);
        }

        $_SESSION['success_message'] = 'Конкурс удалён';
    }

    contestsRedirectWithQuery();
}

$search = trim((string) ($_GET['search'] ?? ''));
$searchContestId = max(0, (int) ($_GET['search_contest_id'] ?? 0));
$statusFilter = (string) ($_GET['status'] ?? 'all');
$sort = (string) ($_GET['sort'] ?? 'newest');

$allowedStatuses = ['all', 'active', 'archived', 'published', 'draft', 'finished'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$allowedSorts = ['newest', 'oldest', 'applications', 'end_date'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'newest';
}

$contests = $pdo->query(
    'SELECT c.*, (SELECT COUNT(*) FROM applications WHERE contest_id = c.id) AS applications_count FROM contests c'
)->fetchAll();

$today = new DateTimeImmutable('today');

$getContestTimeStatus = static function (array $contest) use ($today): array {
    $dateFromRaw = $contest['date_from'] ?? null;
    $dateToRaw = $contest['date_to'] ?? null;

    $dateFrom = $dateFromRaw ? new DateTimeImmutable($dateFromRaw) : null;
    $dateTo = $dateToRaw ? new DateTimeImmutable($dateToRaw) : null;

    if ($dateFrom && $dateFrom > $today) {
        return ['label' => 'Скоро старт', 'class' => 'upcoming'];
    }

    if ($dateTo && $dateTo < $today) {
        return ['label' => 'Завершён', 'class' => 'finished'];
    }

    if ($dateFrom || $dateTo) {
        return ['label' => 'Идёт приём заявок', 'class' => 'active'];
    }

    return ['label' => 'Без дат', 'class' => 'none'];
};

$formatPeriod = static function (?string $dateFrom, ?string $dateTo): string {
    if ($dateFrom && $dateTo) {
        return date('d.m.Y', strtotime($dateFrom)) . ' — ' . date('d.m.Y', strtotime($dateTo));
    }

    if ($dateFrom) {
        return 'С ' . date('d.m.Y', strtotime($dateFrom));
    }

    if ($dateTo) {
        return 'До ' . date('d.m.Y', strtotime($dateTo));
    }

    return 'Даты не заданы';
};

$filteredContests = array_values(array_filter($contests, static function (array $contest) use ($search, $searchContestId, $statusFilter, $today): bool {
    $isArchived = isContestArchived($contest);

    if ($searchContestId > 0 && (int) ($contest['id'] ?? 0) !== $searchContestId) {
        return false;
    }

    if ($search !== '' && mb_stripos((string) ($contest['title'] ?? ''), $search) === false) {
        return false;
    }

    if ($statusFilter === 'active') {
        return !$isArchived;
    }

    if ($statusFilter === 'archived') {
        return $isArchived;
    }

    if ($statusFilter === 'published') {
        return (int) $contest['is_published'] === 1;
    }

    if ($statusFilter === 'draft') {
        return (int) $contest['is_published'] === 0;
    }

    if ($statusFilter === 'finished') {
        if (empty($contest['date_to'])) {
            return false;
        }

        return new DateTimeImmutable((string) $contest['date_to']) < $today;
    }

    return true;
}));

usort($filteredContests, static function (array $a, array $b) use ($sort): int {
    $archiveCompare = ((int) isContestArchived($a)) <=> ((int) isContestArchived($b));
    if ($archiveCompare !== 0) {
        return $archiveCompare;
    }

    if ($sort === 'oldest') {
        return strtotime((string) ($a['created_at'] ?? '')) <=> strtotime((string) ($b['created_at'] ?? ''));
    }

    if ($sort === 'applications') {
        return ((int) ($b['applications_count'] ?? 0)) <=> ((int) ($a['applications_count'] ?? 0));
    }

    if ($sort === 'end_date') {
        $aTimestamp = !empty($a['date_to']) ? strtotime((string) $a['date_to']) : PHP_INT_MAX;
        $bTimestamp = !empty($b['date_to']) ? strtotime((string) $b['date_to']) : PHP_INT_MAX;

        if ($aTimestamp === $bTimestamp) {
            return strtotime((string) ($b['created_at'] ?? '')) <=> strtotime((string) ($a['created_at'] ?? ''));
        }

        return $aTimestamp <=> $bTimestamp;
    }

    return strtotime((string) ($b['created_at'] ?? '')) <=> strtotime((string) ($a['created_at'] ?? ''));
});

$totalCount = count($contests);
$publishedCount = count(array_filter($contests, static fn(array $contest): bool => (int) $contest['is_published'] === 1));
$draftCount = $totalCount - $publishedCount;
$archivedCount = count(array_filter($contests, static fn(array $contest): bool => isContestArchived($contest)));
$activeCount = $totalCount - $archivedCount;
$totalApplications = (int) array_sum(array_map(static fn(array $contest): int => (int) ($contest['applications_count'] ?? 0), $contests));

require_once __DIR__ . '/includes/header.php';
?>

<?php if (isset($_SESSION['success_message'])): ?>
<div class="alert alert--success alert--permanent mb-lg contest-alert" role="status" aria-live="polite">
    <i class="fas fa-check-circle alert__icon" aria-hidden="true"></i>
    <div class="alert__content">
        <div class="alert__message"><?= htmlspecialchars($_SESSION['success_message']) ?></div>
    </div>
    <button type="button" class="btn-close" aria-label="Закрыть уведомление"></button>
</div>
<?php unset($_SESSION['success_message']); endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
<div class="alert alert--error alert--permanent mb-lg contest-alert" role="alert" aria-live="assertive">
    <i class="fas fa-exclamation-circle alert__icon" aria-hidden="true"></i>
    <div class="alert__content">
        <div class="alert__message"><?= htmlspecialchars($_SESSION['error_message']) ?></div>
    </div>
    <button type="button" class="btn-close" aria-label="Закрыть уведомление"></button>
</div>
<?php unset($_SESSION['error_message']); endif; ?>

<section class="contests-toolbar mb-lg" aria-label="Панель управления конкурсами">
    <div class="contests-toolbar__head">
        <div>
            <h1 class="contests-toolbar__title">Конкурсы</h1>
            <p class="contests-toolbar__subtitle">Всего: <?= $totalCount ?></p>
        </div>
        <a href="/admin/contest-edit" class="btn btn--primary contests-toolbar__cta">
            <i class="fas fa-plus" aria-hidden="true"></i>
            <span>Новый конкурс</span>
        </a>
    </div>

    <div class="contests-stats" aria-label="Сводные показатели">
        <div class="contests-stats__item"><span>Всего конкурсов</span><strong><?= $totalCount ?></strong></div>
        <div class="contests-stats__item"><span>Активные</span><strong><?= $activeCount ?></strong></div>
        <div class="contests-stats__item"><span>Архивные</span><strong><?= $archivedCount ?></strong></div>
        <div class="contests-stats__item"><span>Опубликовано</span><strong><?= $publishedCount ?></strong></div>
        <div class="contests-stats__item"><span>Не опубликованы</span><strong><?= $draftCount ?></strong></div>
        <div class="contests-stats__item"><span>Всего заявок</span><strong><?= $totalApplications ?></strong></div>
    </div>

    <form class="contests-controls" method="get" action="/admin/contests" aria-label="Поиск, фильтры и сортировка">
        <div
            class="contests-controls__field contests-controls__field--search"
            style="position:relative;"
            <?= admin_live_search_attrs([
                'endpoint' => '/admin/search-contests',
                'primary_template' => '{{title||Без названия}}',
                'secondary_template' => '{{meta||}}',
                'value_template' => '{{title||Без названия}}',
                'limit' => 8,
                'min_length' => 2,
                'min_length_numeric' => 1,
                'debounce' => 220,
            ]) ?>>
            <label for="contestSearch">
                <span>Поиск по названию</span>
            </label>
            <input id="contestSearch" type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Введите название конкурса" data-live-search-input>
            <input type="hidden" name="search_contest_id" id="contestSearchId" data-live-search-hidden value="<?= (int) $searchContestId ?>">
            <div id="contestSearchResults" class="user-results" data-live-search-results></div>
        </div>

        <label class="contests-controls__field" for="contestStatus">
            <span>Статус</span>
            <select id="contestStatus" name="status">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Все</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Текущие</option>
                <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Архивные</option>
                <option value="published" <?= $statusFilter === 'published' ? 'selected' : '' ?>>Опубликованные</option>
                <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Не опубликованные</option>
                <option value="finished" <?= $statusFilter === 'finished' ? 'selected' : '' ?>>Завершённые</option>
            </select>
        </label>

        <label class="contests-controls__field" for="contestSort">
            <span>Сортировка</span>
            <select id="contestSort" name="sort">
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Сначала новые</option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Сначала старые</option>
                <option value="applications" <?= $sort === 'applications' ? 'selected' : '' ?>>По числу заявок</option>
                <option value="end_date" <?= $sort === 'end_date' ? 'selected' : '' ?>>По дате окончания</option>
            </select>
        </label>

        <div class="contests-controls__actions">
            <button class="btn btn--secondary" type="submit">Применить</button>
            <a href="/admin/contests" class="btn btn--ghost">Сбросить</a>
        </div>
    </form>
</section>

<?php if (empty($filteredContests)): ?>
    <div class="card contests-empty-state-card">
        <div class="card__body text-center contests-empty-state">
            <div class="contests-empty-state__icon" aria-hidden="true">
                <i class="fas fa-trophy"></i>
            </div>
            <h3>Пока нет конкурсов</h3>
            <p class="text-secondary mt-sm">Здесь будут отображаться все созданные конкурсы</p>
            <a href="/admin/contest-edit" class="btn btn--primary mt-lg">
                <i class="fas fa-plus" aria-hidden="true"></i>
                <span>Создать первый конкурс</span>
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="contests-grid" role="list" aria-label="Список конкурсов">
        <?php foreach ($filteredContests as $contest): ?>
            <?php
                $contestId = (int) $contest['id'];
                $applicationsCount = (int) $contest['applications_count'];
                $isPublished = (int) $contest['is_published'] === 1;
                $isArchived = isContestArchived($contest);
                $archiveBadge = getContestArchiveBadgeMeta($contest);
                $timeStatus = $isPublished ? $getContestTimeStatus($contest) : null;
                $descriptionPlain = trim(strip_tags((string) ($contest['description'] ?? '')));
            ?>
            <article class="contest-card <?= $isArchived ? 'contest-card--archived' : '' ?>" role="listitem" data-contest-card data-contest-id="<?= $contestId ?>" data-is-archived="<?= $isArchived ? '1' : '0' ?>">
                <?php
                    $coverUrl = !empty($contest['cover_image'])
                        ? '/uploads/contest-covers/' . rawurlencode((string) $contest['cover_image'])
                        : getContestThemePlaceholderPath((string) ($contest['theme_style'] ?? 'blue'));
                ?>
                <div class="contest-card__cover-wrap">
                    <img src="<?= htmlspecialchars($coverUrl) ?>" alt="Обложка конкурса <?= htmlspecialchars((string) $contest['title']) ?>" class="contest-card__cover">
                </div>
                <div class="contest-card__badges">
                    <span class="contest-badge <?= e((string) $archiveBadge['class']) ?>" data-contest-archive-badge>
                        <?= e((string) $archiveBadge['label']) ?>
                    </span>
                    <span class="contest-badge <?= $isPublished ? 'contest-badge--published' : 'contest-badge--draft' ?>">
                        <?= $isPublished ? 'Опубликован' : 'Не опубликован' ?>
                    </span>
                    <?php if ($timeStatus !== null): ?>
                        <span class="contest-badge contest-badge--time contest-badge--<?= htmlspecialchars($timeStatus['class']) ?>">
                            <?= htmlspecialchars($timeStatus['label']) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <header class="contest-card__header">
                    <div class="contest-card__heading-wrap">
                        <h2 class="contest-card__title" title="<?= htmlspecialchars((string) $contest['title']) ?>"><?= htmlspecialchars((string) $contest['title']) ?></h2>
                    </div>
                </header>

                <div class="contest-card__body">
                    <p class="contest-card__description <?= $descriptionPlain === '' ? 'contest-card__description--empty' : '' ?>">
                        <?= $descriptionPlain !== '' ? htmlspecialchars($descriptionPlain) : 'Описание не заполнено' ?>
                    </p>

                    <a href="/admin/applications?contest_id=<?= $contestId ?>" class="contest-card__applications-link" aria-label="Открыть список заявок конкурса <?= htmlspecialchars((string) $contest['title']) ?>">
                        <span class="contest-card__applications-label">Заявки</span>
                        <span class="contest-card__applications-count"><?= $applicationsCount ?></span>
                        <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i>
                    </a>

                    <dl class="contest-card__meta">
                        <div>
                            <dt>Период проведения</dt>
                            <dd><?= htmlspecialchars($formatPeriod($contest['date_from'] ?? null, $contest['date_to'] ?? null)) ?></dd>
                        </div>
                    </dl>
                </div>

                <footer class="contest-card__footer">
                    <div class="contest-card__primary-actions">
                        <a href="/admin/contest/<?= $contestId ?>" class="btn btn--secondary contest-btn-main" aria-label="Редактировать конкурс <?= htmlspecialchars((string) $contest['title']) ?>">
                            <i class="fas fa-pen-to-square" aria-hidden="true"></i>
                            <span>Редактировать</span>
                        </a>
                    </div>

                    <div class="contest-card__secondary-actions">
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                            <input type="hidden" name="contest_id" value="<?= $contestId ?>">
                            <input type="hidden" name="action" value="toggle_publish">
                            <button type="submit" class="btn btn--ghost contest-btn-publish" aria-label="<?= $isPublished ? 'Снять с публикации' : 'Опубликовать' ?> конкурс <?= htmlspecialchars((string) $contest['title']) ?>">
                                <i class="fas fa-<?= $isPublished ? 'eye-slash' : 'eye' ?>" aria-hidden="true"></i>
                                <span><?= $isPublished ? 'Снять с публикации' : 'Опубликовать' ?></span>
                            </button>
                        </form>

                        <form method="POST" class="inline-form" data-contest-archive-form>
                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                            <input type="hidden" name="contest_id" value="<?= $contestId ?>">
                            <input type="hidden" name="action" value="toggle_archive">
                            <button type="submit" class="btn btn--ghost contest-btn-archive" data-contest-archive-button aria-label="<?= $isArchived ? 'Вернуть из архива' : 'Перевести в архив' ?> конкурс <?= htmlspecialchars((string) $contest['title']) ?>">
                                <i class="fas fa-<?= $isArchived ? 'box-open' : 'box-archive' ?>" aria-hidden="true" data-contest-archive-icon></i>
                                <span data-contest-archive-label><?= $isArchived ? 'Вернуть в активные' : 'В архив' ?></span>
                            </button>
                        </form>

                        <button
                            type="button"
                            class="btn btn--ghost contest-btn-delete"
                            data-delete-trigger
                            data-contest-id="<?= $contestId ?>"
                            data-contest-title="<?= htmlspecialchars((string) $contest['title']) ?>"
                            data-applications-count="<?= $applicationsCount ?>"
                            aria-label="Удалить конкурс <?= htmlspecialchars((string) $contest['title']) ?>"
                        >
                            <i class="fas fa-trash" aria-hidden="true"></i>
                            <span>Удалить</span>
                        </button>
                    </div>
                </footer>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="contest-delete-modal is-hidden" id="contestDeleteModal" role="dialog" aria-modal="true" aria-labelledby="contestDeleteModalTitle" aria-describedby="contestDeleteModalDesc">
    <div class="contest-delete-modal__backdrop" data-delete-close></div>
    <div class="contest-delete-modal__dialog" role="document">
        <h3 id="contestDeleteModalTitle">Удалить конкурс?</h3>
        <p id="contestDeleteModalDesc">Будут удалены конкурс и все связанные заявки.</p>
        <p class="contest-delete-modal__meta" id="contestDeleteModalApplications"></p>

        <form method="POST" class="contest-delete-modal__actions">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="contest_id" id="contestDeleteModalContestId" value="">
            <input type="hidden" name="action" value="delete">
            <button type="button" class="btn btn--ghost" data-delete-close>Отмена</button>
            <button type="submit" class="btn btn--primary contest-delete-modal__danger">Удалить конкурс</button>
        </form>
    </div>
</div>

<script>
(function () {
    var currentStatusFilter = <?= json_encode($statusFilter, JSON_UNESCAPED_UNICODE) ?>;

    var removeAlert = function (alert) {
        if (!alert) {
            return;
        }
        alert.classList.add('is-hiding');
        window.setTimeout(function () {
            alert.remove();
        }, 250);
    };

    var showInlineAlert = function (message, type) {
        var oldAlerts = document.querySelectorAll('.contest-alert');
        oldAlerts.forEach(function (alert) {
            removeAlert(alert);
        });

        var alert = document.createElement('div');
        alert.className = 'alert alert--' + (type === 'error' ? 'error' : 'success') + ' alert--permanent mb-lg contest-alert';
        alert.setAttribute('role', type === 'error' ? 'alert' : 'status');
        alert.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
        alert.innerHTML = ''
            + '<i class="fas fa-' + (type === 'error' ? 'exclamation-circle' : 'check-circle') + ' alert__icon" aria-hidden="true"></i>'
            + '<div class="alert__content"><div class="alert__message"></div></div>'
            + '<button type="button" class="btn-close" aria-label="Закрыть уведомление"></button>';
        alert.querySelector('.alert__message').textContent = message;
        alert.querySelector('.btn-close').addEventListener('click', function () {
            removeAlert(alert);
        });

        var toolbar = document.querySelector('.contests-toolbar');
        if (toolbar && toolbar.parentNode) {
            toolbar.parentNode.insertBefore(alert, toolbar);
        }
    };

    document.querySelectorAll('.contest-alert .btn-close').forEach(function (button) {
        button.addEventListener('click', function () {
            removeAlert(button.closest('.contest-alert'));
        });
    });

    document.querySelectorAll('[data-contest-archive-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var card = form.closest('[data-contest-card]');
            var button = form.querySelector('[data-contest-archive-button]');
            var icon = form.querySelector('[data-contest-archive-icon]');
            var label = form.querySelector('[data-contest-archive-label]');
            var badge = card ? card.querySelector('[data-contest-archive-badge]') : null;

            if (!button || !card) {
                return;
            }

            var formData = new FormData(form);
            button.disabled = true;
            card.classList.add('is-updating');

            fetch('/admin/contests', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data || data.success !== true) {
                        throw new Error(data && data.message ? data.message : 'Не удалось обновить статус конкурса');
                    }
                    return data;
                });
            }).then(function (data) {
                var isArchived = !!data.is_archived;
                card.dataset.isArchived = isArchived ? '1' : '0';
                card.classList.toggle('contest-card--archived', isArchived);

                if (badge) {
                    badge.className = 'contest-badge ' + data.archive_badge_class;
                    badge.textContent = data.archive_badge_label;
                }

                if (icon) {
                    icon.className = 'fas fa-' + data.archive_button_icon;
                }

                if (label) {
                    label.textContent = data.archive_button_label;
                }

                button.setAttribute('aria-label', (isArchived ? 'Вернуть из архива' : 'Перевести в архив') + ' конкурс');

                if ((currentStatusFilter === 'active' && isArchived) || (currentStatusFilter === 'archived' && !isArchived)) {
                    card.classList.add('contest-card--removing');
                    window.setTimeout(function () {
                        card.remove();
                        if (!document.querySelector('[data-contest-card]')) {
                            window.location.reload();
                        }
                    }, 220);
                }

                showInlineAlert(data.message || 'Статус конкурса обновлён', 'success');
            }).catch(function (error) {
                showInlineAlert(error.message || 'Не удалось обновить статус конкурса', 'error');
            }).finally(function () {
                button.disabled = false;
                card.classList.remove('is-updating');
            });
        });
    });

    var modal = document.getElementById('contestDeleteModal');
    if (!modal) {
        return;
    }

    var contestIdInput = document.getElementById('contestDeleteModalContestId');
    var applicationsText = document.getElementById('contestDeleteModalApplications');
    var title = document.getElementById('contestDeleteModalTitle');
    var lastFocusedElement = null;

    var getFocusable = function () {
        return modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    };

    var closeModal = function () {
        modal.classList.add('is-hidden');
        document.body.classList.remove('is-modal-open');
        if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
            lastFocusedElement.focus();
        }
    };

    var openModal = function (trigger) {
        lastFocusedElement = trigger;
        var contestId = trigger.getAttribute('data-contest-id') || '';
        var contestTitle = trigger.getAttribute('data-contest-title') || '';
        var applicationsCount = parseInt(trigger.getAttribute('data-applications-count') || '0', 10);

        contestIdInput.value = contestId;
        title.textContent = 'Удалить конкурс «' + contestTitle + '»?';
        applicationsText.textContent = 'Количество связанных заявок: ' + applicationsCount;

        modal.classList.remove('is-hidden');
        document.body.classList.add('is-modal-open');

        var focusable = getFocusable();
        if (focusable.length > 0) {
            focusable[0].focus();
        }
    };

    document.querySelectorAll('[data-delete-trigger]').forEach(function (button) {
        button.addEventListener('click', function () {
            openModal(button);
        });
    });

    modal.querySelectorAll('[data-delete-close]').forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', function (event) {
        if (modal.classList.contains('is-hidden')) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        var focusable = Array.prototype.slice.call(getFocusable());
        if (focusable.length === 0) {
            return;
        }

        var first = focusable[0];
        var last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
