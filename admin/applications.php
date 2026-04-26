<?php
// admin/applications.php - Список заявок
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

// Проверка авторизации админа
if (!isAdmin()) {
    redirect('/admin/login');
}

check_csrf();

$admin = getCurrentUser();
$currentPage = 'applications';
$pageTitle = 'Заявки';
$breadcrumb = 'Управление заявками';

// Фильтры
$status = $_GET['status'] ?? '';
if ($status === 'draft') {
    $status = 'revision';
}
$contest_id = $_GET['contest_id'] ?? '';
$search = $_GET['search'] ?? '';
$searchApplicationId = max(0, (int) ($_GET['search_application_id'] ?? 0));
$searchUserId = max(0, (int) ($_GET['search_user_id'] ?? 0));
$participantId = max(0, (int) ($_GET['participant_id'] ?? 0));
$participantQuery = trim((string) ($_GET['participant_query'] ?? ''));
$queue = $_GET['queue'] ?? '';
$showArchived = (int) ($_GET['show_archived'] ?? 0) === 1;
$supportsContestArchive = contest_archive_column_exists();
$publishPromptApplicationId = max(0, (int) ($_SESSION['vk_publish_prompt_application_id'] ?? 0));
$publishPromptData = null;
unset($_SESSION['vk_publish_prompt_application_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'publish_application_works') {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            jsonResponse(['success' => false, 'error' => 'Ошибка безопасности (CSRF).'], 403);
        }

        $applicationIdForPublish = max(0, (int) ($_POST['application_id'] ?? 0));
        if ($applicationIdForPublish <= 0) {
            jsonResponse(['success' => false, 'error' => 'Некорректный идентификатор заявки.'], 422);
        }

        $workIdsStmt = $pdo->prepare("
            SELECT w.id
            FROM works w
            INNER JOIN participants p ON p.id = w.participant_id
            WHERE p.application_id = ?
            ORDER BY w.id ASC
        ");
        $workIdsStmt->execute([$applicationIdForPublish]);
        $workIds = array_map('intval', array_column($workIdsStmt->fetchAll() ?: [], 'id'));

        if (empty($workIds)) {
            jsonResponse(['success' => false, 'error' => 'В заявке нет работ для публикации.'], 422);
        }

        ensureVkPublicationSchema();
        $filters = normalizeVkTaskFilters([
            'application_status' => 'approved',
            'work_status' => 'accepted',
            'exclude_vk_published' => 1,
            'required_data_only' => 1,
            'work_ids_raw' => implode(',', $workIds),
        ]);

        $preview = buildVkTaskPreview($filters, null);
        if ((int) ($preview['ready_items'] ?? 0) <= 0) {
            jsonResponse([
                'success' => false,
                'error' => 'Нет готовых работ для публикации. Проверьте изображения и данные участников.',
                'summary' => [
                    'total' => (int) ($preview['total_items'] ?? 0),
                    'ready' => (int) ($preview['ready_items'] ?? 0),
                    'skipped' => (int) ($preview['skipped_items'] ?? 0),
                ],
            ], 422);
        }

        $taskId = createVkTaskFromPreview(
            'Публикация по заявке #' . $applicationIdForPublish . ' от ' . date('d.m.Y H:i'),
            (int) getCurrentAdminId(),
            $preview,
            'immediate'
        );
        $publishResult = publishVkTask($taskId);

        jsonResponse([
            'success' => true,
            'task_id' => $taskId,
            'published' => (int) ($publishResult['published'] ?? 0),
            'failed' => (int) ($publishResult['failed'] ?? 0),
            'total' => (int) ($publishResult['total'] ?? 0),
            'error' => trim((string) ($publishResult['error'] ?? '')),
            'task_url' => '/admin/vk-publication/' . $taskId,
        ]);
    } elseif ($action === 'delete_selected') {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error_message'] = 'Ошибка безопасности (CSRF).';
            redirect('/admin/applications');
        }

        $selectedRaw = $_POST['selected'] ?? [];
        if (!is_array($selectedRaw) || empty($selectedRaw)) {
            $_SESSION['error_message'] = 'Не выбраны заявки для удаления';
            redirect('/admin/applications');
        }

        $selectedIds = [];
        foreach ($selectedRaw as $selectedId) {
            $id = (int) $selectedId;
            if ($id > 0) {
                $selectedIds[] = $id;
            }
        }
        $selectedIds = array_values(array_unique($selectedIds));
        if (empty($selectedIds)) {
            $_SESSION['error_message'] = 'Не выбраны заявки для удаления';
            redirect('/admin/applications');
        }

        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM participants WHERE application_id IN ($placeholders)")
                ->execute($selectedIds);
            $deleteStmt = $pdo->prepare("DELETE FROM applications WHERE id IN ($placeholders)");
            $deleteStmt->execute($selectedIds);
            $pdo->commit();
            $_SESSION['success_message'] = 'Удалено заявок: ' . (int) $deleteStmt->rowCount();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error_message'] = 'Не удалось удалить выбранные заявки.';
        }

        redirect('/admin/applications');
    }
}

if ($publishPromptApplicationId > 0) {
    $promptStmt = $pdo->prepare("
        SELECT a.id AS application_id, u.email AS applicant_email
        FROM applications a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $promptStmt->execute([$publishPromptApplicationId]);
    $promptApplication = $promptStmt->fetch();

    if ($promptApplication) {
        $participantsStmt = $pdo->prepare("
            SELECT p.id AS participant_id, p.fio, p.drawing_file, w.id AS work_id, w.image_path
            FROM participants p
            LEFT JOIN works w ON w.participant_id = p.id
            WHERE p.application_id = ?
            ORDER BY p.id ASC
        ");
        $participantsStmt->execute([$publishPromptApplicationId]);
        $promptParticipants = $participantsStmt->fetchAll() ?: [];

        $publishPromptData = [
            'application_id' => (int) $publishPromptApplicationId,
            'participants' => [],
        ];

        foreach ($promptParticipants as $participantRow) {
            $imageFile = trim((string) ($participantRow['image_path'] ?? ''));
            if ($imageFile === '') {
                $imageFile = trim((string) ($participantRow['drawing_file'] ?? ''));
            }
            $imagePath = '';
            if ($imageFile !== '') {
                $imagePath = getParticipantDrawingPreviewWebPath((string) ($promptApplication['applicant_email'] ?? ''), $imageFile);
            }

            $publishPromptData['participants'][] = [
                'id' => (int) ($participantRow['participant_id'] ?? 0),
                'fio' => trim((string) ($participantRow['fio'] ?? '')) ?: 'Без имени',
                'preview_image' => (string) $imagePath,
            ];
        }
    }
}

$hasOpenedByAdminColumn = false;
try {
    $hasOpenedByAdminColumn = (bool) $pdo->query("SHOW COLUMNS FROM applications LIKE 'opened_by_admin'")->fetch();
} catch (Exception $e) {
    $hasOpenedByAdminColumn = false;
}

$where = [];
$params = [];

// Черновики не участвуют в админских сценариях обработки
$where[] = "a.status <> 'draft'";

if ($status) {
    if ($status === 'declined') {
        $status = 'rejected';
    }
    if ($status === 'revision') {
        $where[] = "a.allow_edit = 1 AND a.status <> 'approved' AND a.status <> 'draft'";
    } else {
        $where[] = 'a.status = ?';
        $params[] = $status;
    }
}

if ($hasOpenedByAdminColumn) {
    if ($queue === 'new') {
        $where[] = "a.status = 'submitted' AND a.opened_by_admin = 0";
    } elseif ($queue === 'work') {
        $where[] = "a.status = 'submitted' AND a.opened_by_admin = 1";
    }
}

if ($contest_id) {
    $where[] = 'a.contest_id = ?';
    $params[] = $contest_id;
}

if ($supportsContestArchive && !$showArchived) {
    $where[] = 'COALESCE(c.is_archived, 0) = 0';
}

if ($searchApplicationId > 0) {
    $where[] = 'a.id = ?';
    $params[] = $searchApplicationId;
} elseif ($searchUserId > 0) {
    $where[] = 'a.user_id = ?';
    $params[] = $searchUserId;
} elseif ($search) {
    $searchValue = trim((string) $search);
    if ($searchValue !== '' && ctype_digit($searchValue)) {
        $where[] = 'a.id = ?';
        $params[] = (int) $searchValue;
    } else {
        $term = '%' . $searchValue . '%';
        $where[] = "(
            u.name LIKE ?
            OR u.surname LIKE ?
            OR CONCAT(u.surname, ' ', u.name) LIKE ?
            OR CONCAT(u.name, ' ', u.surname) LIKE ?
        )";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }
}

if ($participantId > 0) {
    $where[] = 'a.id IN (SELECT p.application_id FROM participants p WHERE p.id = ?)';
    $params[] = $participantId;
} elseif ($participantQuery !== '') {
    $participantQueryNormalized = ltrim(trim((string) $participantQuery), "# \t\n\r\0\x0B");
    $isParticipantNumeric = $participantQueryNormalized !== '' && ctype_digit($participantQueryNormalized);

    if ($isParticipantNumeric) {
        $where[] = 'EXISTS (
            SELECT 1
            FROM participants p
            WHERE p.application_id = a.id
              AND p.public_number = ?
        )';
        $params[] = $participantQueryNormalized;
    } else {
        $where[] = 'EXISTS (
            SELECT 1
            FROM participants p
            JOIN applications a2 ON a2.id = p.application_id
            JOIN users pu ON pu.id = a2.user_id
            WHERE p.application_id = a.id
              AND (p.fio LIKE ? OR p.region LIKE ? OR pu.email LIKE ?)
        )';
        $participantTerm = '%' . $participantQueryNormalized . '%';
        $params[] = $participantTerm;
        $params[] = $participantTerm;
        $params[] = $participantTerm;
    }
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$buildApplicationsUrl = static function (array $overrides = []) use ($status, $contest_id, $search, $searchApplicationId, $searchUserId, $participantId, $participantQuery, $queue, $showArchived): string {
    $query = [
        'status' => $status !== '' ? $status : null,
        'contest_id' => $contest_id !== '' ? $contest_id : null,
        'search' => $search !== '' ? $search : null,
        'search_application_id' => $searchApplicationId > 0 ? $searchApplicationId : null,
        'search_user_id' => $searchUserId > 0 ? $searchUserId : null,
        'participant_id' => $participantId > 0 ? $participantId : null,
        'participant_query' => $participantQuery !== '' ? $participantQuery : null,
        'queue' => $queue !== '' ? $queue : null,
        'show_archived' => $showArchived ? '1' : null,
        'page' => null,
    ];

    foreach ($overrides as $key => $value) {
        $query[$key] = $value;
    }

    $query = array_filter($query, static fn ($value) => $value !== null && $value !== '');
    $queryString = http_build_query($query);

    return '/admin/applications' . ($queryString !== '' ? '?' . $queryString : '');
};

$buildApplicationViewUrl = static function (int $applicationId) use ($buildApplicationsUrl): string {
    $listUrl = $buildApplicationsUrl();
    $query = parse_url($listUrl, PHP_URL_QUERY);

    return '/admin/application/' . $applicationId . ($query ? ('?' . $query) : '');
};

// Пагинация
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Получаем общее количество
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM applications a LEFT JOIN contests c ON a.contest_id = c.id LEFT JOIN users u ON a.user_id = u.id $whereClause");
$countStmt->execute($params);
$totalApps = $countStmt->fetchColumn();
$totalPages = ceil($totalApps / $perPage);

// Получаем заявки
$stmt = $pdo->prepare("
    SELECT a.*, c.title as contest_title, c.requires_payment_receipt AS contest_requires_payment_receipt, COALESCE(c.is_archived, 0) AS contest_is_archived, u.name, u.surname, u.email, u.avatar_url,
           (SELECT COUNT(*) FROM participants WHERE application_id = a.id) as participants_count
    FROM applications a
    LEFT JOIN contests c ON a.contest_id = c.id
    LEFT JOIN users u ON a.user_id = u.id
    $whereClause
    ORDER BY a.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$applications = $stmt->fetchAll();

$applicationVkStatuses = [];
foreach ($applications as $appRow) {
    $applicationVkStatuses[(int) $appRow['id']] = getApplicationVkPublicationStatus((int) $appRow['id']);
}

// Список конкурсов для фильтра
$contests = $pdo->query("SELECT id, title, COALESCE(is_archived, 0) AS is_archived FROM contests ORDER BY COALESCE(is_archived, 0) ASC, created_at DESC")->fetchAll();

require_once __DIR__ . '/includes/header.php';

$successFlashMessage = '';
if (!empty($_SESSION['success_message'])) {
    $successFlashMessage = (string) $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

$errorFlashMessage = '';
if (!empty($_SESSION['error_message'])) {
    $errorFlashMessage = (string) $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>
<style>
    .applications-toast-stack {
        position: fixed;
        top: 16px;
        right: 16px;
        z-index: 2200;
        display: grid;
        gap: 8px;
        width: min(420px, calc(100vw - 24px));
        pointer-events: none;
    }

    .applications-toast {
        margin: 0;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.22);
        animation: applicationsToastIn .2s ease-out;
        pointer-events: auto;
    }

    .applications-toast.is-hiding {
        opacity: 0;
        transform: translateY(-8px);
        transition: opacity .2s ease, transform .2s ease;
    }

    @keyframes applicationsToastIn {
        from { opacity: 0; transform: translateY(-8px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .vk-publication-badge-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: none;
        background: #16a34a;
        color: #fff;
    }

    .vk-publication-badge-btn:hover,
    .vk-publication-badge-btn:focus-visible {
        background: #15803d;
        color: #fff;
    }

    .vk-publication-badge-note {
        font-size: 12px;
        color: #475569;
    }
</style>

<div
    id="applicationsToastStack"
    class="applications-toast-stack"
    data-success-message="<?= e($successFlashMessage) ?>"
    data-error-message="<?= e($errorFlashMessage) ?>"
    aria-live="polite"
    aria-atomic="true"></div>

<!-- Фильтры -->
<div class="card card--allow-overflow mb-lg">
    <div class="card__body">
        <form method="GET" class="flex gap-md" style="flex-wrap: wrap; align-items: flex-end;">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <?php if ($queue !== ''): ?>
                <input type="hidden" name="queue" value="<?= e($queue) ?>">
            <?php endif; ?>
            <div style="min-width: 200px;">
                <label class="form-label">Статус</label>
                <select name="status" class="form-select">
                    <option value="">Все статусы</option>
                    <option value="revision" <?= $status === 'revision' ? 'selected' : '' ?>>Требует исправлений</option>
                    <option value="corrected" <?= $status === 'corrected' ? 'selected' : '' ?>>Исправлена, и отправлена на проверку</option>
                    <option value="submitted" <?= $status === 'submitted' ? 'selected' : '' ?>>В работе</option>
                    <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Принятые</option>
                    <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Отклонённые</option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Отменённые</option>
                </select>
            </div>
            <div style="min-width: 200px;">
                <label class="form-label">Конкурс</label>
                <select name="contest_id" class="form-select">
                    <option value="">Все конкурсы</option>
                    <?php foreach ($contests as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $contest_id == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['title']) ?><?= (int) ($c['is_archived'] ?? 0) === 1 ? ' · Архивный' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="contest-archive-filter-toggle" for="applicationsShowArchived">
                <input type="checkbox" name="show_archived" id="applicationsShowArchived" value="1" <?= $showArchived ? 'checked' : '' ?>>
                <span>Показать даже те, которые в архиве</span>
            </label>
            <div
                style="flex: 1; min-width: 200px; max-width: 300px; position:relative;"
                data-select-url-field="view_url"
                <?= admin_live_search_attrs([
                    'endpoint' => '/admin/search-applications',
                    'primary_template' => '#{{id}} · {{surname + name||Без имени}}',
                    'secondary_template' => '{{contest_title||Конкурс не указан}} · {{email||Email не указан}}',
                    'value_template' => '#{{id}} · {{surname + name||Без имени}}',
                    'limit' => 7,
                    'min_length' => 2,
                    'min_length_numeric' => 1,
                    'debounce' => 220,
                ]) ?>>
                <label class="form-label">Поиск по заявителю или заявке</label>
                <input type="text" id="applicationsSearchInput" name="search" class="form-input" data-live-search-input
                       placeholder="Номер заявки или ФИО заявителя" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="search_application_id" id="applicationsSearchApplicationId" data-live-search-hidden data-live-search-hidden-field="id" value="<?= (int) $searchApplicationId ?>">
                <input type="hidden" name="search_user_id" id="applicationsSearchUserId" data-live-search-hidden data-live-search-hidden-field="user_id" value="<?= (int) $searchUserId ?>">
                <div id="applicationsSearchResults" class="user-results" data-live-search-results></div>
            </div>
            <div
                style="flex:1; min-width: 260px; max-width: 380px; position:relative;"
                <?= admin_live_search_attrs([
                    'endpoint' => '/admin/search-participants',
                    'primary_template' => '#{{public_number||id}} · {{fio||Без имени}}',
                    'secondary_template' => 'Регион: {{region||—}} · {{email||Email не указан}}',
                    'value_template' => '#{{public_number||id}} · {{fio||Без имени}}',
                    'empty_text' => 'Участники не найдены',
                    'min_length' => 2,
                    'min_length_numeric' => 1,
                    'debounce' => 220,
                    'preserve_input_on_select' => 1,
                    'show_more_label' => 'Показать остальные все результаты поиска',
                    'show_more_action' => 'submit',
                ]) ?>>
                <label class="form-label">Поиск по участнику</label>
                <input
                    type="text"
                    name="participant_query"
                    id="participantSearchInput"
                    data-live-search-input
                    class="form-input"
                    placeholder="Номер участника или ФИО"
                    value="<?= htmlspecialchars($participantQuery) ?>"
                    autocomplete="off">
                <input type="hidden" name="participant_id" id="participantId" data-live-search-hidden value="<?= (int) $participantId ?>">
                <div id="participantSearchResults" class="user-results" data-live-search-results></div>
            </div>
            <button type="submit" class="btn btn--primary">
                <i class="fas fa-filter"></i> Фильтр
            </button>
            <?php if ($status || $contest_id || $search || $searchApplicationId > 0 || $searchUserId > 0 || $participantId > 0 || $participantQuery !== '' || $queue || $showArchived): ?>
                <a href="/admin/applications" class="btn btn--ghost">Сбросить</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Статистика -->
<div class="flex gap-lg mb-lg" style="flex-wrap: wrap;">
    <?php
    $archiveFilterSql = $supportsContestArchive && !$showArchived ? ' AND COALESCE(c.is_archived, 0) = 0' : '';
    $statCounts = [
        'all' => $pdo->query("SELECT COUNT(*) FROM applications a LEFT JOIN contests c ON a.contest_id = c.id WHERE a.status <> 'draft'" . $archiveFilterSql)->fetchColumn(),
        'submitted' => $pdo->query("SELECT COUNT(*) FROM applications a LEFT JOIN contests c ON a.contest_id = c.id WHERE a.status = 'submitted'" . $archiveFilterSql)->fetchColumn(),
        'revision' => $pdo->query("SELECT COUNT(*) FROM applications a LEFT JOIN contests c ON a.contest_id = c.id WHERE a.allow_edit = 1 AND a.status <> 'approved'" . $archiveFilterSql)->fetchColumn(),
        'corrected' => $pdo->query("SELECT COUNT(*) FROM applications a LEFT JOIN contests c ON a.contest_id = c.id WHERE a.status = 'corrected'" . $archiveFilterSql)->fetchColumn(),
        'rejected' => $pdo->query("SELECT COUNT(*) FROM applications a LEFT JOIN contests c ON a.contest_id = c.id WHERE a.status = 'rejected'" . $archiveFilterSql)->fetchColumn(),
    ];
    if ($hasOpenedByAdminColumn) {
        $statCounts['new'] = $pdo->query("SELECT COUNT(*) FROM applications a LEFT JOIN contests c ON a.contest_id = c.id WHERE a.status = 'submitted' AND a.opened_by_admin = 0" . $archiveFilterSql)->fetchColumn();
        $statCounts['work'] = $pdo->query("SELECT COUNT(*) FROM applications a LEFT JOIN contests c ON a.contest_id = c.id WHERE a.status = 'submitted' AND a.opened_by_admin = 1" . $archiveFilterSql)->fetchColumn();
    }
    ?>
    <a href="<?= e($buildApplicationsUrl(['status' => null, 'queue' => null])) ?>" class="stat-pill <?= !$status && $queue === '' ? 'stat-pill--active' : '' ?>">
        Все <span class="stat-pill__count"><?= $statCounts['all'] ?></span>
    </a>
    <?php if ($hasOpenedByAdminColumn): ?>
    <a href="<?= e($buildApplicationsUrl(['status' => null, 'queue' => 'new'])) ?>" class="stat-pill <?= $queue === 'new' ? 'stat-pill--active' : '' ?>">
        Новые <span class="stat-pill__count"><?= (int) $statCounts['new'] ?></span>
    </a>
    <a href="<?= e($buildApplicationsUrl(['status' => null, 'queue' => 'work'])) ?>" class="stat-pill <?= $queue === 'work' ? 'stat-pill--active' : '' ?>">
        В работе <span class="stat-pill__count"><?= (int) $statCounts['work'] ?></span>
    </a>
    <?php else: ?>
    <a href="<?= e($buildApplicationsUrl(['status' => 'submitted', 'queue' => null])) ?>" class="stat-pill <?= $status === 'submitted' ? 'stat-pill--active' : '' ?>">
        В работе <span class="stat-pill__count"><?= $statCounts['submitted'] ?></span>
    </a>
    <?php endif; ?>
    <a href="<?= e($buildApplicationsUrl(['status' => 'revision', 'queue' => null])) ?>" class="stat-pill <?= $status === 'revision' ? 'stat-pill--active' : '' ?>">
        Требует исправлений <span class="stat-pill__count"><?= $statCounts['revision'] ?></span>
    </a>
    <a href="<?= e($buildApplicationsUrl(['status' => 'corrected', 'queue' => null])) ?>" class="stat-pill <?= $status === 'corrected' ? 'stat-pill--active' : '' ?>">
        Исправлена, и отправлена на проверку <span class="stat-pill__count"><?= $statCounts['corrected'] ?></span>
    </a>
    <a href="<?= e($buildApplicationsUrl(['status' => 'rejected', 'queue' => null])) ?>" class="stat-pill <?= $status === 'rejected' ? 'stat-pill--active' : '' ?>">
        Отклонённые заявки <span class="stat-pill__count"><?= $statCounts['rejected'] ?></span>
    </a>
</div>

<!-- Список заявок -->
<div class="card">
    <div class="card__header">
        <div class="flex justify-between items-center w-100">
            <h3>Заявки (<?= e($totalApps) ?>)</h3>
            <button type="button" class="btn btn--ghost" id="toggleSelectModeBtn">
                <i class="fas fa-check-square"></i> Выбрать
            </button>
        </div>
        <div class="flex items-center gap-md" id="bulkActionsBar" style="display:none; margin-top:12px; flex-wrap:nowrap;">
            <div class="text-secondary" style="white-space:nowrap;">Выбрано: <strong id="selectedCountValue">0</strong></div>
            <div class="flex gap-sm">
                <button type="button" class="btn btn--ghost btn--sm" id="clearSelectedBtn">Сбросить</button>
                <button type="button" class="btn btn--danger btn--sm" id="bulkDeleteBtn">
                    <i class="fas fa-trash"></i> Удалить выбранные
                </button>
            </div>
        </div>
    </div>
    <div class="card__body">
        <?php if (empty($applications)): ?>
            <div class="text-center text-secondary" style="padding: 40px;">Заявки не найдены</div>
        <?php else: ?>
        <div class="admin-list-cards">
            <?php foreach ($applications as $app): ?>
                <?php
                    $statusMeta = getApplicationDisplayMeta($app);
                    $receiptMeta = getApplicationPaymentReceiptMeta($app);
                    $vkStatus = $applicationVkStatuses[(int) $app['id']] ?? getApplicationVkPublicationStatus((int) $app['id']);
                    $isCorrected = (string) ($statusMeta['status_code'] ?? '') === 'corrected';
                    $isArchivedContest = (int) ($app['contest_is_archived'] ?? 0) === 1;
                ?>
                <article class="admin-list-card admin-list-card--application <?= $isCorrected ? 'admin-list-card--corrected' : '' ?> <?= $isArchivedContest ? 'admin-list-card--archived' : '' ?>">
                    <div class="admin-list-card__accent <?= e((string) ($statusMeta['badge_class'] ?? 'badge--secondary')) ?>"></div>
                    <div class="admin-list-card__header">
                        <div class="admin-list-card__title-wrap">
                            <div class="flex items-center gap-sm flex-wrap">
                                <h4 class="admin-list-card__title">Заявка #<?= (int) $app['id'] ?></h4>
                                <span class="text-secondary">Подача: <?= date('d.m.Y H:i', strtotime($app['created_at'])) ?></span>
                            </div>
                            <div class="admin-list-card__subtitle">
                                <?= htmlspecialchars(trim(($app['name'] ?? '') . ' ' . ($app['surname'] ?? '')) ?: 'Без имени') ?>
                                <?php if (!empty($app['email'])): ?>
                                    <span class="text-secondary">· <?= htmlspecialchars($app['email']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="admin-list-card__statuses">
                            <span class="badge <?= e((string) ($statusMeta['badge_class'] ?? 'badge--secondary')) ?>">
                                <?= e((string) ($statusMeta['label'] ?? '—')) ?>
                            </span>
                            <span class="badge <?= e((string) ($receiptMeta['badge_class'] ?? 'badge--secondary')) ?>">
                                <?= e((string) ($receiptMeta['label'] ?? '—')) ?>
                            </span>
                            <?php if ($isArchivedContest): ?>
                                <span class="badge badge--secondary">Архивный конкурс</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="admin-list-card__meta">
                        <?php if ($isCorrected): ?>
                            <span style="color:#0369A1;"><strong>Повторная проверка:</strong> после исправлений пользователя</span>
                        <?php endif; ?>
                        <span><strong>Конкурс:</strong> <?= htmlspecialchars($app['contest_title'] ?? '—') ?></span>
                        <span><strong>Участников:</strong> <?= (int) $app['participants_count'] ?></span>
                        <span><strong>Готово к публикации:</strong> <?= (int) ($vkStatus['ready_count'] ?? 0) ?> из <?= (int) ($vkStatus['total_count'] ?? 0) ?></span>
                        <span><strong>Уже опубликовано:</strong> <?= (int) ($vkStatus['published_count'] ?? 0) ?></span>
                    </div>
                    <div class="admin-list-card__actions">
                        <label class="select-col" style="display:none;">
                            <input type="checkbox" class="application-select-checkbox" value="<?= (int) $app['id'] ?>">
                        </label>
                        <a href="<?= e($buildApplicationViewUrl((int) $app['id'])) ?>" class="btn btn--primary btn--sm">
                            <i class="fas fa-eye"></i> Открыть заявку
                        </a>
                        <?php if (!empty($receiptMeta['file_url'])): ?>
                        <a href="<?= e((string) $receiptMeta['file_url']) ?>" target="_blank" class="btn btn--ghost btn--sm">
                            <i class="fas fa-receipt"></i> Квитанция
                        </a>
                        <?php endif; ?>
                        <?php $canShowVkPublicationBadge = in_array((string) ($statusMeta['status_code'] ?? ''), ['revision', 'approved'], true); ?>
                        <?php if ($canShowVkPublicationBadge): ?>
                        <button
                            type="button"
                            class="btn btn--sm js-open-vk-publish-modal vk-publication-badge-btn"
                            style="margin-left:auto;"
                            data-application-id="<?= (int) $app['id'] ?>"
                            data-has-publications="<?= ((int) ($vkStatus['published_count'] ?? 0) > 0 || (int) ($vkStatus['failed_count'] ?? 0) > 0) ? '1' : '0' ?>"
                        >
                            <i class="fab fa-vk" aria-hidden="true"></i>
                            Опубликовано в VK
                        </button>
                        <?php if ((int) ($vkStatus['awaiting_decision_count'] ?? 0) > 0): ?>
                            <span class="vk-publication-badge-note">
                                Опубликовано: <?= (int) ($vkStatus['published_count'] ?? 0) ?>,
                                ожидают решения: <?= (int) ($vkStatus['awaiting_decision_count'] ?? 0) ?>
                            </span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Пагинация -->
        <?php if ($totalPages > 1): ?>
        <div class="flex justify-between items-center" style="padding: 16px 20px; border-top: 1px solid var(--color-border);">
            <div class="text-secondary" style="font-size: 14px;">
                Страница <?= e($page) ?> из <?= e($totalPages) ?>
            </div>
            <div class="flex gap-sm">
                <?php if ($page > 1): ?>
                    <a href="<?= e($buildApplicationsUrl(['page' => $page - 1])) ?>" class="btn btn--ghost btn--sm">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= e($buildApplicationsUrl(['page' => $page + 1])) ?>" class="btn btn--ghost btn--sm">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal <?= $publishPromptApplicationId > 0 ? 'active' : '' ?>" id="vkPublishPromptModal">
    <div class="modal__content" style="max-width:700px;">
        <div class="modal__header">
            <h3 class="modal__title">Публикация в VK</h3>
            <button type="button" class="modal__close vk-publish-modal__close" aria-label="Закрыть" id="vkPublishPromptModalClose">&times;</button>
        </div>
        <div class="modal__body">
            <div id="vkPublishModalSummary" class="text-secondary" style="margin-bottom:10px;"></div>
            <div id="vkPublishPreview" style="display:grid; gap:8px; max-height:320px; overflow:auto; padding-right:4px;"></div>
            <div style="margin-top: 14px; border: 1px solid #E5E7EB; border-radius: 12px; padding: 12px;">
                <div style="font-weight: 600; margin-bottom: 8px;">Будут опубликованы только принятые и ещё не опубликованные рисунки</div>
            </div>
            <div id="vkPublishPromptStatus" class="alert" style="display:none; margin-top:12px;"></div>
        </div>
        <div class="modal__footer" style="display:flex; justify-content:flex-end; gap:8px;">
            <button type="button" class="btn btn--primary" id="vkPublishPromptRun">Опубликовать</button>
            <button type="button" class="btn btn--secondary" id="vkPublishPromptSkip">Отмена</button>
            <button type="button" class="btn btn--secondary is-hidden" id="vkPublishPromptClose">Закрыть</button>
        </div>
    </div>
</div>

<script>
(() => {
    const stack = document.getElementById('applicationsToastStack');
    if (!stack) {
        return;
    }

    const showToast = (message, type = 'success') => {
        if (!message) {
            return;
        }

        const toast = document.createElement('div');
        toast.className = `alert applications-toast ${type === 'error' ? 'alert--error' : 'alert--success'}`;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.innerHTML = `
            <i class="fas ${type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'} alert__icon" aria-hidden="true"></i>
            <div class="alert__content"><div class="alert__message"></div></div>
        `;
        toast.querySelector('.alert__message').textContent = message;
        stack.appendChild(toast);

        window.setTimeout(() => {
            toast.classList.add('is-hiding');
            window.setTimeout(() => {
                toast.remove();
            }, 220);
        }, 2500);
    };

    const successMessage = stack.dataset.successMessage || '';
    const errorMessage = stack.dataset.errorMessage || '';
    showToast(successMessage, 'success');
    showToast(errorMessage, 'error');
})();

(() => {
    const csrfTokenValue = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;
    let selectionModeEnabled = false;

    const updateBulkSelectionState = () => {
        const selected = Array.from(document.querySelectorAll('.application-select-checkbox:checked'));
        const selectedCount = selected.length;
        const selectedCounter = document.getElementById('selectedCountValue');
        const deleteButton = document.getElementById('bulkDeleteBtn');
        if (selectedCounter) {
            selectedCounter.textContent = String(selectedCount);
        }
        if (deleteButton) {
            deleteButton.disabled = selectedCount === 0;
        }
    };

    const clearSelectedApplications = () => {
        document.querySelectorAll('.application-select-checkbox').forEach((checkbox) => {
            checkbox.checked = false;
        });
        updateBulkSelectionState();
    };

    const toggleSelectionMode = (forceState = null) => {
        selectionModeEnabled = forceState === null ? !selectionModeEnabled : Boolean(forceState);
        const selectCols = document.querySelectorAll('.select-col');
        const bulkBar = document.getElementById('bulkActionsBar');
        const toggleBtn = document.getElementById('toggleSelectModeBtn');

        selectCols.forEach((col) => {
            col.style.display = selectionModeEnabled ? '' : 'none';
        });
        if (bulkBar) {
            bulkBar.style.display = selectionModeEnabled ? 'flex' : 'none';
        }
        if (toggleBtn) {
            toggleBtn.innerHTML = selectionModeEnabled
                ? '<i class="fas fa-times"></i> Отменить выбор'
                : '<i class="fas fa-check-square"></i> Выбрать';
        }

        if (!selectionModeEnabled) {
            clearSelectedApplications();
        }
        updateBulkSelectionState();
    };

    const deleteSelectedApplications = () => {
        const selected = Array.from(document.querySelectorAll('.application-select-checkbox:checked')).map((item) => item.value);
        if (!selected.length) {
            alert('Выберите хотя бы одну заявку');
            return;
        }
        if (!confirm('Удалить выбранные заявки? Это действие нельзя отменить.')) {
            return;
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/admin/applications';

        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = csrfTokenValue;
        form.appendChild(csrfInput);

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete_selected';
        form.appendChild(actionInput);

        selected.forEach((id) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected[]';
            input.value = id;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    };

    document.getElementById('toggleSelectModeBtn')?.addEventListener('click', () => {
        toggleSelectionMode();
    });
    document.getElementById('clearSelectedBtn')?.addEventListener('click', () => {
        clearSelectedApplications();
    });
    document.getElementById('bulkDeleteBtn')?.addEventListener('click', () => {
        deleteSelectedApplications();
    });
    document.querySelectorAll('.application-select-checkbox').forEach((checkbox) => {
        checkbox.addEventListener('change', updateBulkSelectionState);
    });
    updateBulkSelectionState();
})();

(() => {
    const modal = document.getElementById('vkPublishPromptModal');
    if (!modal) return;

    const publishButton = document.getElementById('vkPublishPromptRun');
    const skipButton = document.getElementById('vkPublishPromptSkip');
    const closeButton = document.getElementById('vkPublishPromptClose');
    const closeIconButton = document.getElementById('vkPublishPromptModalClose');
    const statusBox = document.getElementById('vkPublishPromptStatus');
    const previewBox = document.getElementById('vkPublishPreview');
    const summaryBox = document.getElementById('vkPublishModalSummary');
    let applicationId = <?= (int) $publishPromptApplicationId ?>;
    const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;
    let publishInProgress = false;
    let errorState = false;

    const showStatus = (message, type = 'success') => {
        if (!statusBox) return;
        statusBox.className = `alert ${type === 'error' ? 'alert--error' : 'alert--success'}`;
        statusBox.style.display = 'block';
        statusBox.textContent = message;
    };

    const closeModal = () => {
        if (publishInProgress) return;
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        setErrorState(false);
    };

    const setErrorState = (nextState) => {
        errorState = Boolean(nextState);
        if (!publishButton || !skipButton || !closeButton) {
            return;
        }
        publishButton.classList.toggle('is-hidden', errorState);
        skipButton.classList.toggle('is-hidden', errorState);
        closeButton.classList.toggle('is-hidden', !errorState);
        publishButton.disabled = false;
        skipButton.disabled = false;
        closeButton.disabled = false;
    };

    if (skipButton) {
        skipButton.addEventListener('click', closeModal);
    }
    if (closeButton) {
        closeButton.addEventListener('click', closeModal);
    }
    if (closeIconButton) {
        closeIconButton.addEventListener('click', closeModal);
    }
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('active')) {
            closeModal();
        }
    });

    const renderParticipants = (items) => {
        if (!previewBox) return;
        if (!Array.isArray(items) || items.length === 0) {
            previewBox.innerHTML = '<div class="text-secondary">Нет работ для публикации.</div>';
            return;
        }
        previewBox.innerHTML = items.map((item) => `
            <div style="display:flex; gap:10px; align-items:flex-start; border:1px solid #E5E7EB; border-radius:10px; padding:8px;">
                ${item.preview_image ? `<img src="${item.preview_image}" style="width:52px;height:52px;border-radius:8px;object-fit:cover;">` : '<div style="width:52px;height:52px;border-radius:8px;background:#EEF2FF;display:flex;align-items:center;justify-content:center;"><i class="fas fa-image"></i></div>'}
                <div style="display:grid; gap:4px;">
                    <strong>${item.fio || 'Без имени'}</strong>
                    <span class="badge ${item.is_ready_for_publish ? 'badge--success' : 'badge--warning'}">${item.is_ready_for_publish ? 'Готово к публикации' : 'Не готово'}</span>
                    ${item.skip_reason ? `<div class="text-secondary" style="font-size:12px;">${item.skip_reason}</div>` : ''}
                </div>
            </div>
        `).join('');
    };

    const loadPublishData = async () => {
        if (!applicationId) return;
        previewBox.innerHTML = '<div class="text-secondary">Загрузка...</div>';
        const response = await fetch(`/admin/api/get-publish-data.php?id=${applicationId}`);
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Ошибка загрузки данных публикации.');
        }
        renderParticipants(data.participants || []);
        const sum = data.summary || {};
        const vkStatus = data.application_vk_status || {};
        if (summaryBox) {
            let txt = `Всего участников: ${sum.total_items || 0} · Готово к публикации: ${sum.ready_items || 0} · Не готово: ${sum.skipped_items || 0}`;
            if (vkStatus.status_code && vkStatus.status_code !== 'not_published') {
                txt += `. Уже опубликовано: ${vkStatus.published_count || 0}, осталось: ${vkStatus.remaining_count || 0}.`;
            }
            summaryBox.textContent = txt;
        }
    };

    document.querySelectorAll('.js-open-vk-publish-modal').forEach((button) => {
        button.addEventListener('click', async () => {
            applicationId = Number(button.dataset.applicationId || 0);
            setErrorState(false);
            modal.classList.add('active');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            if (statusBox) statusBox.style.display = 'none';
            try {
                await loadPublishData();
            } catch (error) {
                showStatus(error.message, 'error');
                setErrorState(true);
            }
        });
    });

    if (publishButton) {
        publishButton.addEventListener('click', async () => {
            if (publishInProgress || !applicationId) return;
            publishInProgress = true;
            publishButton.disabled = true;
            if (skipButton) skipButton.disabled = true;
            showStatus('Публикация запущена, подождите...', 'success');

            try {
                const response = await fetch('/admin/api/publish-vk.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        application_id: applicationId,
                        publication_type: 'standard',
                        csrf_token: csrfToken,
                    }),
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    showStatus(data.error || 'Не удалось выполнить публикацию.', 'error');
                    setErrorState(true);
                    return;
                }

                showStatus(`Публикация завершена: опубликовано ${data.published}/${data.total}.`, 'success');
                location.reload();
            } catch (e) {
                showStatus('Ошибка сети при публикации. Попробуйте ещё раз.', 'error');
                setErrorState(true);
            } finally {
                publishInProgress = false;
                if (!errorState) {
                    publishButton.disabled = false;
                    if (skipButton) skipButton.disabled = false;
                }
                if (closeButton) {
                    closeButton.disabled = false;
                }
            }
        });
    }

    if (applicationId > 0) {
        setErrorState(false);
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        loadPublishData().catch((error) => {
            showStatus(error.message, 'error');
            setErrorState(true);
        });
    }
})();

</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
