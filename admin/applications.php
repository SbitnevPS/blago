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
$searchUserId = max(0, (int) ($_GET['search_user_id'] ?? 0));
$participantId = max(0, (int) ($_GET['participant_id'] ?? 0));
$participantQuery = trim((string) ($_GET['participant_query'] ?? ''));
$queue = $_GET['queue'] ?? '';
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
                $imagePath = getParticipantDrawingWebPath((string) ($promptApplication['applicant_email'] ?? ''), $imageFile);
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

if ($status) {
    if ($status === 'declined') {
        $status = 'rejected';
    }
    if ($status === 'revision') {
        $where[] = "a.allow_edit = 1 AND a.status <> 'approved'";
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

if ($searchUserId > 0) {
    $where[] = 'a.user_id = ?';
    $params[] = $searchUserId;
} elseif ($search) {
    $where[] = '(u.name LIKE ? OR u.surname LIKE ? OR u.email LIKE ? OR a.id LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}

if ($participantId > 0) {
    $where[] = 'a.id IN (SELECT p.application_id FROM participants p WHERE p.id = ?)';
    $params[] = $participantId;
} elseif ($participantQuery !== '') {
    $where[] = 'EXISTS (
        SELECT 1
        FROM participants p
        JOIN applications a2 ON a2.id = p.application_id
        JOIN users pu ON pu.id = a2.user_id
        WHERE p.application_id = a.id
          AND (p.fio LIKE ? OR p.region LIKE ? OR pu.email LIKE ? OR CAST(p.id AS CHAR) LIKE ?)
    )';
    $participantTerm = '%' . $participantQuery . '%';
    $params[] = $participantTerm;
    $params[] = $participantTerm;
    $params[] = $participantTerm;
    $params[] = $participantTerm;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Пагинация
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Получаем общее количество
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM applications a LEFT JOIN users u ON a.user_id = u.id $whereClause");
$countStmt->execute($params);
$totalApps = $countStmt->fetchColumn();
$totalPages = ceil($totalApps / $perPage);

// Получаем заявки
$stmt = $pdo->prepare("
    SELECT a.*, c.title as contest_title, u.name, u.surname, u.email, u.avatar_url,
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
$contests = $pdo->query("SELECT id, title FROM contests ORDER BY created_at DESC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<?php if (!empty($_SESSION['success_message'])): ?>
    <div class="alert alert--success mb-lg"><i class="fas fa-check-circle"></i> <?= e($_SESSION['success_message']) ?></div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['error_message'])): ?>
    <div class="alert alert--error mb-lg"><i class="fas fa-exclamation-circle"></i> <?= e($_SESSION['error_message']) ?></div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

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
                    <option value="corrected" <?= $status === 'corrected' ? 'selected' : '' ?>>Исправленные</option>
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
                            <?= htmlspecialchars($c['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex: 1; min-width: 200px; max-width: 300px; position:relative;">
                <label class="form-label">Поиск</label>
                <input type="text" id="applicationsSearchInput" name="search" class="form-input" 
                       placeholder="ID, имя, email..." value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="search_user_id" id="applicationsSearchUserId" value="<?= (int) $searchUserId ?>">
                <div id="applicationsSearchResults" class="user-results"></div>
            </div>
            <div style="flex:1; min-width: 260px; max-width: 380px; position:relative;">
                <label class="form-label">Поиск по участнику</label>
                <input
                    type="text"
                    name="participant_query"
                    id="participantSearchInput"
                    class="form-input"
                    placeholder="ID, ФИО участника, регион или email заявителя"
                    value="<?= htmlspecialchars($participantQuery) ?>"
                    autocomplete="off">
                <input type="hidden" name="participant_id" id="participantId" value="<?= (int) $participantId ?>">
                <div id="participantSearchResults" class="user-results"></div>
            </div>
            <button type="submit" class="btn btn--primary">
                <i class="fas fa-filter"></i> Фильтр
            </button>
            <?php if ($status || $contest_id || $search || $searchUserId > 0 || $participantId > 0 || $participantQuery !== '' || $queue): ?>
                <a href="applications.php" class="btn btn--ghost">Сбросить</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Статистика -->
<div class="flex gap-lg mb-lg" style="flex-wrap: wrap;">
    <?php
    $statCounts = [
        'all' => $pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn(),
        'submitted' => $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'submitted'")->fetchColumn(),
        'revision' => $pdo->query("SELECT COUNT(*) FROM applications WHERE allow_edit = 1 AND status <> 'approved'")->fetchColumn(),
        'corrected' => $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'corrected'")->fetchColumn(),
    ];
    if ($hasOpenedByAdminColumn) {
        $statCounts['new'] = $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'submitted' AND opened_by_admin = 0")->fetchColumn();
        $statCounts['work'] = $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'submitted' AND opened_by_admin = 1")->fetchColumn();
    }
    ?>
    <a href="applications.php" class="stat-pill <?= !$status ? 'stat-pill--active' : '' ?>">
        Все <span class="stat-pill__count"><?= $statCounts['all'] ?></span>
    </a>
    <?php if ($hasOpenedByAdminColumn): ?>
    <a href="?queue=new" class="stat-pill <?= $queue === 'new' ? 'stat-pill--active' : '' ?>">
        Новые <span class="stat-pill__count"><?= (int) $statCounts['new'] ?></span>
    </a>
    <a href="?queue=work" class="stat-pill <?= $queue === 'work' ? 'stat-pill--active' : '' ?>">
        В работе <span class="stat-pill__count"><?= (int) $statCounts['work'] ?></span>
    </a>
    <?php else: ?>
    <a href="?status=submitted" class="stat-pill <?= $status === 'submitted' ? 'stat-pill--active' : '' ?>">
        В работе <span class="stat-pill__count"><?= $statCounts['submitted'] ?></span>
    </a>
    <?php endif; ?>
    <a href="?status=revision" class="stat-pill <?= $status === 'revision' ? 'stat-pill--active' : '' ?>">
        Требует исправлений <span class="stat-pill__count"><?= $statCounts['revision'] ?></span>
    </a>
    <a href="?status=corrected" class="stat-pill <?= $status === 'corrected' ? 'stat-pill--active' : '' ?>">
        Исправленные <span class="stat-pill__count"><?= $statCounts['corrected'] ?></span>
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
                    $vkStatus = $applicationVkStatuses[(int) $app['id']] ?? getApplicationVkPublicationStatus((int) $app['id']);
                ?>
                <article class="admin-list-card admin-list-card--application">
                    <div class="admin-list-card__accent <?= e((string) ($statusMeta['badge_class'] ?? 'badge--secondary')) ?>"></div>
                    <div class="admin-list-card__header">
                        <div class="admin-list-card__title-wrap">
                            <h4 class="admin-list-card__title">Заявка #<?= (int) $app['id'] ?></h4>
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
                            <span class="badge <?= e((string) ($vkStatus['badge_class'] ?? 'badge--secondary')) ?>">
                                VK: <?= e((string) ($vkStatus['status_code'] === 'partial' ? 'Частично' : ($vkStatus['status_code'] === 'published' ? 'Опублик.' : ($vkStatus['status_code'] === 'failed' ? 'Ошибка' : 'Не опублик.')))) ?>
                            </span>
                        </div>
                    </div>
                    <div class="admin-list-card__meta">
                        <span><strong>Конкурс:</strong> <?= htmlspecialchars($app['contest_title'] ?? '—') ?></span>
                        <span><strong>Участников:</strong> <?= (int) $app['participants_count'] ?></span>
                        <span><strong>VK:</strong> <?= (int) ($vkStatus['published_count'] ?? 0) ?>/<?= (int) ($vkStatus['total_count'] ?? 0) ?></span>
                        <span><strong>Подача:</strong> <?= date('d.m.Y H:i', strtotime($app['created_at'])) ?></span>
                    </div>
                    <div class="admin-list-card__actions">
                        <label class="select-col" style="display:none;">
                            <input type="checkbox" class="application-select-checkbox" value="<?= (int) $app['id'] ?>">
                        </label>
                        <a href="/admin/application/<?= (int) $app['id'] ?>" class="btn btn--primary btn--sm">
                            <i class="fas fa-eye"></i> Открыть заявку
                        </a>
                        <?php if (($app['status'] ?? '') === 'approved'): ?>
                        <button
                            type="button"
                            class="btn btn--secondary btn--sm js-open-vk-publish-modal"
                            style="margin-left:auto;"
                            data-application-id="<?= (int) $app['id'] ?>"
                            data-has-publications="<?= ((int) ($vkStatus['published_count'] ?? 0) > 0 || (int) ($vkStatus['failed_count'] ?? 0) > 0) ? '1' : '0' ?>"
                        >
                            <?= (int) ($vkStatus['published_count'] ?? 0) > 0 ? 'VK повтор' : 'VK Публикация' ?>
                        </button>
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
                    <a href="?page=<?= $page - 1 ?>&status=<?= e($status) ?>&contest_id=<?= e($contest_id) ?>&search=<?= urlencode($search) ?>&search_user_id=<?= (int) $searchUserId ?>&participant_id=<?= (int) $participantId ?>&participant_query=<?= urlencode($participantQuery) ?>&queue=<?= e($queue) ?>" class="btn btn--ghost btn--sm">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&status=<?= e($status) ?>&contest_id=<?= e($contest_id) ?>&search=<?= urlencode($search) ?>&search_user_id=<?= (int) $searchUserId ?>&participant_id=<?= (int) $participantId ?>&participant_query=<?= urlencode($participantQuery) ?>&queue=<?= e($queue) ?>" class="btn btn--ghost btn--sm">
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
        </div>
        <div class="modal__body">
            <div id="vkPublishModalSummary" class="text-secondary" style="margin-bottom:10px;"></div>
            <div id="vkPublishPreview" style="display:grid; gap:8px; max-height:320px; overflow:auto; padding-right:4px;"></div>
            <div style="margin-top: 14px; border: 1px solid #E5E7EB; border-radius: 12px; padding: 12px;">
                <div style="font-weight: 600; margin-bottom: 8px;">Донаты VK</div>
                <label class="form-checkbox" style="margin-bottom:8px;">
                    <input type="checkbox" id="vkDonationEnabled" value="1">
                    <span class="form-checkbox__mark"></span>
                    <span>Прикрепить донат</span>
                </label>
                <div class="form-group" style="margin-bottom:8px;">
                    <label class="form-label" for="vkDonationGoalSelect">Цель доната</label>
                    <select class="form-select" id="vkDonationGoalSelect" disabled>
                        <option value="">Выберите цель доната</option>
                    </select>
                </div>
            </div>
            <div id="vkPublishPromptStatus" class="alert" style="display:none; margin-top:12px;"></div>
        </div>
        <div class="modal__footer" style="display:flex; justify-content:flex-end; gap:8px;">
            <button type="button" class="btn btn--primary" id="vkPublishPromptRun">Опубликовать</button>
            <button type="button" class="btn btn--secondary" id="vkPublishPromptSkip">Отмена</button>
        </div>
    </div>
</div>

<script>
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
        form.action = '/admin/applications.php';

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
    const statusBox = document.getElementById('vkPublishPromptStatus');
    const previewBox = document.getElementById('vkPublishPreview');
    const summaryBox = document.getElementById('vkPublishModalSummary');
    const donationEnabledCheckbox = document.getElementById('vkDonationEnabled');
    const donationGoalSelect = document.getElementById('vkDonationGoalSelect');
    let applicationId = <?= (int) $publishPromptApplicationId ?>;
    const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;
    let publishInProgress = false;

    const showStatus = (message, type = 'success') => {
        if (!statusBox) return;
        statusBox.className = `alert ${type === 'error' ? 'alert--error' : 'alert--success'}`;
        statusBox.style.display = 'block';
        statusBox.textContent = message;
    };

    const closeModal = () => {
        if (publishInProgress) return;
        modal.classList.remove('active');
    };

    if (skipButton) {
        skipButton.addEventListener('click', closeModal);
    }

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
                    <div>${item.work_title || 'Без названия'}</div>
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
        donationGoalSelect.innerHTML = '<option value="">Выберите цель доната</option>';
        (data.donation_goals || []).forEach((goal) => {
            const option = document.createElement('option');
            option.value = String(goal.id || '');
            option.textContent = String(goal.title || '');
            donationGoalSelect.appendChild(option);
        });
    };

    document.querySelectorAll('.js-open-vk-publish-modal').forEach((button) => {
        button.addEventListener('click', async () => {
            applicationId = Number(button.dataset.applicationId || 0);
            modal.classList.add('active');
            if (statusBox) statusBox.style.display = 'none';
            if (donationEnabledCheckbox) donationEnabledCheckbox.checked = false;
            donationGoalSelect.disabled = true;
            try {
                await loadPublishData();
            } catch (error) {
                showStatus(error.message, 'error');
            }
        });
    });

    donationEnabledCheckbox?.addEventListener('change', () => {
        donationGoalSelect.disabled = !donationEnabledCheckbox.checked;
    });

    if (publishButton) {
        publishButton.addEventListener('click', async () => {
            if (publishInProgress || !applicationId) return;
            const donationEnabled = !!donationEnabledCheckbox?.checked;
            const donationGoalId = Number(donationGoalSelect?.value || 0);
            if (donationEnabled && !donationGoalId) {
                showStatus('Выберите цель доната.', 'error');
                return;
            }
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
                        donation_enabled: donationEnabled ? 1 : 0,
                        donation_goal_id: donationGoalId,
                        csrf_token: csrfToken,
                    }),
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    showStatus(data.error || 'Не удалось выполнить публикацию.', 'error');
                    publishButton.disabled = false;
                    return;
                }

                showStatus(`Публикация завершена: опубликовано ${data.published}/${data.total}.`, 'success');
                location.reload();
            } catch (e) {
                showStatus('Ошибка сети при публикации. Попробуйте ещё раз.', 'error');
            } finally {
                publishInProgress = false;
                publishButton.disabled = false;
                if (skipButton) skipButton.disabled = false;
            }
        });
    }

    if (applicationId > 0) {
        modal.classList.add('active');
        loadPublishData().catch((error) => showStatus(error.message, 'error'));
    }
})();

(() => {
    const input = document.getElementById('participantSearchInput');
    const hiddenInput = document.getElementById('participantId');
    const results = document.getElementById('participantSearchResults');
    if (!input || !hiddenInput || !results) return;

    let timer = null;

    const hideResults = () => {
        results.style.display = 'none';
        results.innerHTML = '';
    };

    const renderItems = (items) => {
        if (!Array.isArray(items) || items.length === 0) {
            results.innerHTML = '<div class="user-results__empty">Ничего не найдено</div>';
            results.style.display = 'block';
            return;
        }

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');

        results.innerHTML = items.map((item) => {
            const fullName = `${item.fio || ''}`.trim() || 'Без имени';
            const region = item.region ? `Регион: ${item.region}` : 'Регион: —';
            const email = item.email || 'Email не указан';
            const labelName = `#${item.id} · ${fullName}`;
            const safeName = escapeHtml(labelName);
            const safeRegion = escapeHtml(region);
            const safeEmail = escapeHtml(email);
            return `
                <button type="button" class="user-results__item" data-id="${item.id}" data-name="${safeName}">
                    <div class="user-results__name">${safeName}</div>
                    <div class="user-results__email">${safeRegion} · ${safeEmail}</div>
                </button>
            `;
        }).join('');
        results.style.display = 'block';
    };

    input.addEventListener('input', () => {
        hiddenInput.value = '';
        const query = input.value.trim();
        if (timer) clearTimeout(timer);
        if (query.length < 2) {
            hideResults();
            return;
        }

        timer = setTimeout(async () => {
            try {
                const response = await fetch(`/admin/search-participants.php?q=${encodeURIComponent(query)}`);
                const data = await response.json();
                renderItems(data);
            } catch (error) {
                hideResults();
            }
        }, 220);
    });

    results.addEventListener('click', (event) => {
        const item = event.target.closest('.user-results__item');
        if (!item) return;
        hiddenInput.value = item.dataset.id || '';
        input.value = item.dataset.name || '';
        hideResults();
    });

    document.addEventListener('click', (event) => {
        if (!results.contains(event.target) && event.target !== input) {
            hideResults();
        }
    });
})();

(() => {
    const input = document.getElementById('applicationsSearchInput');
    const hiddenInput = document.getElementById('applicationsSearchUserId');
    const results = document.getElementById('applicationsSearchResults');
    if (!input || !results || !hiddenInput) return;
    let timer = null;

    const hideResults = () => {
        results.style.display = 'none';
        results.innerHTML = '';
    };

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const renderItems = (items) => {
        if (!Array.isArray(items) || !items.length) {
            results.innerHTML = '<div class="user-results__empty">Ничего не найдено</div>';
            results.style.display = 'block';
            return;
        }
        results.innerHTML = items.map((item) => {
            const fullName = `${item.surname || ''} ${item.name || ''}`.trim() || 'Без имени';
            const email = item.email || 'Email не указан';
            const displayValue = `${fullName} · ${email}`;
            return `<button type="button" class="user-results__item" data-id="${Number(item.id || 0)}" data-value="${escapeHtml(displayValue)}">
                <div class="user-results__name">${escapeHtml(fullName)}</div>
                <div class="user-results__email">${escapeHtml(email)}</div>
            </button>`;
        }).join('');
        results.style.display = 'block';
    };

    input.addEventListener('input', () => {
        hiddenInput.value = '';
        const query = input.value.trim();
        if (timer) clearTimeout(timer);
        const isNumericQuery = /^\d+$/.test(query);
        if ((!isNumericQuery && query.length < 2) || (isNumericQuery && query.length < 1)) {
            hideResults();
            return;
        }
        timer = setTimeout(async () => {
            try {
                const response = await fetch(`/admin/search-users.php?q=${encodeURIComponent(query)}&limit=7`);
                const data = await response.json();
                renderItems(data);
            } catch (e) {
                hideResults();
            }
        }, 220);
    });

    results.addEventListener('click', (event) => {
        const item = event.target.closest('.user-results__item');
        if (!item) return;
        hiddenInput.value = item.dataset.id || '';
        input.value = item.dataset.value || '';
        hideResults();
    });

    document.addEventListener('click', (event) => {
        if (!results.contains(event.target) && event.target !== input) {
            hideResults();
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
