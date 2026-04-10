<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (!isAdmin()) {
    redirect('/admin/login');
}

check_csrf();
ensureVkPublicationSchema();

$admin = getCurrentUser();
$currentPage = 'vk-publications';
$pageTitle = 'Публикации в ВК';
$breadcrumb = 'Конкурсы / Публикации в ВК';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Ошибка безопасности (CSRF).';
        redirect('/admin/vk-publications');
    }

    $action = (string) ($_POST['action'] ?? '');
    $taskId = (int) ($_POST['task_id'] ?? 0);

    if ($taskId > 0 && $action === 'publish') {
        $result = publishVkTask($taskId);
        $_SESSION['success_message'] = 'Публикация запущена: успешно ' . (int) $result['published'] . ', ошибок ' . (int) $result['failed'];
    } elseif ($taskId > 0 && $action === 'retry_failed') {
        $result = retryFailedVkTaskItems($taskId);
        $_SESSION['success_message'] = 'Повтор выполнен: успешно ' . (int) $result['published'] . ', ошибок ' . (int) $result['failed'];
    } elseif ($taskId > 0 && $action === 'duplicate') {
        $task = getVkTaskById($taskId);
        if ($task) {
            $preview = buildVkTaskPreview((array) ($task['filters'] ?? []), (string) (($task['summary']['sample_post_text'] ?? '') !== '' ? ($task['summary']['sample_post_text']) : ''));
            $newId = createVkTaskFromPreview('Копия: ' . $task['title'], (int) getCurrentAdminId(), $preview, 'manual', [
                'vk_donut_enabled' => (int) ($task['vk_donut_enabled'] ?? 0) === 1 ? 1 : 0,
                'vk_donut_paid_duration' => isset($task['vk_donut_paid_duration']) ? (int) $task['vk_donut_paid_duration'] : null,
                'vk_donut_can_publish_free_copy' => (int) ($task['vk_donut_can_publish_free_copy'] ?? 0) === 1 ? 1 : 0,
                'vk_donut_settings_snapshot' => !empty($task['vk_donut_settings_snapshot'])
                    ? (json_decode((string) $task['vk_donut_settings_snapshot'], true) ?: null)
                    : null,
            ]);
            $_SESSION['success_message'] = 'Создана копия задания #' . $newId;
        }
    } elseif ($taskId > 0 && $action === 'archive') {
        $pdo->prepare("UPDATE vk_publication_tasks SET task_status = 'archived', updated_at = NOW() WHERE id = ?")
            ->execute([$taskId]);
        $_SESSION['success_message'] = 'Задание архивировано';
    } elseif ($action === 'archive_selected') {
        $selectedRaw = $_POST['selected'] ?? [];
        if (!is_array($selectedRaw) || empty($selectedRaw)) {
            $_SESSION['error_message'] = 'Не выбраны задания для архивации';
            redirect('/admin/vk-publications');
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
            $_SESSION['error_message'] = 'Не выбраны задания для архивации';
            redirect('/admin/vk-publications');
        }

        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $archiveStmt = $pdo->prepare("UPDATE vk_publication_tasks SET task_status = 'archived', updated_at = NOW() WHERE id IN ($placeholders)");
        $archiveStmt->execute($selectedIds);
        $_SESSION['success_message'] = 'В архив перемещено заданий: ' . (int) $archiveStmt->rowCount();
    }

    redirect('/admin/vk-publications');
}

$status = trim((string) ($_GET['status'] ?? ''));
$contestId = (int) ($_GET['contest_id'] ?? 0);
$createdBy = (int) ($_GET['created_by'] ?? 0);
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$hasErrors = trim((string) ($_GET['has_errors'] ?? ''));
$mode = trim((string) ($_GET['mode'] ?? ''));

$where = [];
$params = [];

if ($status !== '') {
    $where[] = 't.task_status = ?';
    $params[] = $status;
}
if ($contestId > 0) {
    $where[] = 't.contest_id = ?';
    $params[] = $contestId;
}
if ($createdBy > 0) {
    $where[] = 't.created_by = ?';
    $params[] = $createdBy;
}
if ($dateFrom !== '') {
    $where[] = 'DATE(t.created_at) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'DATE(t.created_at) <= ?';
    $params[] = $dateTo;
}
if ($hasErrors === '1') {
    $where[] = 't.failed_items > 0';
}
if ($mode === 'draft') {
    $where[] = "t.task_status = 'draft'";
}
if ($mode === 'published') {
    $where[] = 't.published_items > 0';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$tasksStmt = $pdo->prepare("SELECT
        t.*, c.title AS contest_title,
        u.name AS creator_name, u.surname AS creator_surname
    FROM vk_publication_tasks t
    LEFT JOIN contests c ON c.id = t.contest_id
    LEFT JOIN users u ON u.id = t.created_by
    $whereSql
    ORDER BY t.created_at DESC, t.id DESC");
$tasksStmt->execute($params);
$tasks = $tasksStmt->fetchAll() ?: [];

$contests = $pdo->query('SELECT id, title FROM contests ORDER BY created_at DESC')->fetchAll() ?: [];
$admins = $pdo->query('SELECT id, name, surname FROM users WHERE is_admin = 1 ORDER BY name, surname')->fetchAll() ?: [];

require_once __DIR__ . '/includes/header.php';
?>
<style>
.vk-publications-mobile { display:none; }
@media (max-width: 900px) {
    .vk-publications-table-wrap { display:none; }
    .vk-publications-mobile { display:grid; gap:12px; padding:12px; }
    .vk-task-card { border:1px solid #E5E7EB; border-radius:12px; padding:12px; display:grid; gap:8px; }
    .vk-task-card__row { display:flex; justify-content:space-between; gap:10px; font-size:13px; }
    .vk-task-card__label { color:#6B7280; }
    .vk-task-card__actions { display:flex; flex-wrap:wrap; gap:6px; }
}
</style>

<?php if (!empty($_SESSION['success_message'])): ?>
    <div class="alert alert--success mb-lg"><i class="fas fa-check-circle"></i> <?= e($_SESSION['success_message']) ?></div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['error_message'])): ?>
    <div class="alert alert--error mb-lg"><i class="fas fa-exclamation-circle"></i> <?= e($_SESSION['error_message']) ?></div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<div class="card mb-lg">
    <div class="card__body">
        <form method="GET" class="flex gap-md" style="flex-wrap: wrap; align-items: flex-end;">
            <div>
                <label class="form-label">Конкурс</label>
                <select name="contest_id" class="form-select">
                    <option value="">Все</option>
                    <?php foreach ($contests as $contest): ?>
                        <option value="<?= (int) $contest['id'] ?>" <?= $contestId === (int) $contest['id'] ? 'selected' : '' ?>>
                            <?= e($contest['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Статус</label>
                <select name="status" class="form-select">
                    <option value="">Все</option>
                    <?php foreach (getVkTaskStatusConfig() as $key => $meta): ?>
                        <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Автор</label>
                <select name="created_by" class="form-select">
                    <option value="">Все</option>
                    <?php foreach ($admins as $author): ?>
                        <option value="<?= (int) $author['id'] ?>" <?= $createdBy === (int) $author['id'] ? 'selected' : '' ?>>
                            <?= e(trim(($author['name'] ?? '') . ' ' . ($author['surname'] ?? ''))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">От</label>
                <input type="date" name="date_from" class="form-input" value="<?= e($dateFrom) ?>">
            </div>
            <div>
                <label class="form-label">До</label>
                <input type="date" name="date_to" class="form-input" value="<?= e($dateTo) ?>">
            </div>
            <div>
                <label class="form-label">Ошибки</label>
                <select name="has_errors" class="form-select">
                    <option value="">Все</option>
                    <option value="1" <?= $hasErrors === '1' ? 'selected' : '' ?>>Только с ошибками</option>
                </select>
            </div>
            <div>
                <label class="form-label">Режим</label>
                <select name="mode" class="form-select">
                    <option value="">Все</option>
                    <option value="draft" <?= $mode === 'draft' ? 'selected' : '' ?>>Черновики</option>
                    <option value="published" <?= $mode === 'published' ? 'selected' : '' ?>>Опубликованные</option>
                </select>
            </div>
            <button type="submit" class="btn btn--primary"><i class="fas fa-filter"></i> Фильтр</button>
            <a href="/admin/vk-publications" class="btn btn--ghost">Сброс</a>
            <a href="/admin/vk-publication-create" class="btn btn--secondary"><i class="fas fa-plus"></i> Создать новое задание</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card__header">
        <div class="flex justify-between items-center w-100">
            <h3>Задания на публикацию (<?= count($tasks) ?>)</h3>
            <button type="button" class="btn btn--ghost" id="toggleSelectModeBtn">
                <i class="fas fa-check-square"></i> Выбрать
            </button>
        </div>
        <div class="flex items-center gap-md" id="bulkActionsBar" style="display:none; margin-top:12px; flex-wrap:nowrap;">
            <div class="text-secondary" style="white-space:nowrap;">Выбрано: <strong id="selectedCountValue">0</strong></div>
            <div class="flex gap-sm">
                <button type="button" class="btn btn--ghost btn--sm" id="clearSelectedBtn">Сбросить</button>
                <button type="button" class="btn btn--secondary btn--sm" id="bulkArchiveBtn">
                    <i class="fas fa-box-archive"></i> Архивировать
                </button>
            </div>
        </div>
    </div>
    <div class="card__body" style="padding: 0;">
        <div class="vk-publications-table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th class="select-col" style="display:none; width:54px;"></th>
                <th>ID</th>
                <th>Название</th>
                <th>Конкурс</th>
                <th>Создал</th>
                <th>Работ</th>
                <th>Опубликовано</th>
                <th>Ошибки</th>
                <th>Статус</th>
                <th>Тип</th>
                <th>Создано</th>
                <th>Публикация</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($tasks as $task): ?>
                <?php $statusMeta = getVkTaskStatusMeta((string) $task['task_status']); ?>
                <tr class="task-row" data-task-id="<?= (int) $task['id'] ?>">
                    <td class="select-col" style="display:none;" data-label="Выбор">
                        <input type="checkbox" class="task-select-checkbox" value="<?= (int) $task['id'] ?>">
                    </td>
                    <td data-label="ID">#<?= (int) $task['id'] ?></td>
                    <td data-label="Название"><?= e($task['title']) ?></td>
                    <td data-label="Конкурс"><?= e($task['contest_title'] ?: 'Все конкурсы') ?></td>
                    <td data-label="Создал"><?= e(trim(($task['creator_name'] ?? '') . ' ' . ($task['creator_surname'] ?? ''))) ?></td>
                    <td data-label="Работ"><?= (int) $task['total_items'] ?></td>
                    <td data-label="Опубликовано"><?= (int) $task['published_items'] ?></td>
                    <td data-label="Ошибки"><?= (int) $task['failed_items'] ?></td>
                    <td data-label="Статус"><span class="badge <?= e($statusMeta['badge_class']) ?>"><?= e($statusMeta['label']) ?></span></td>
                    <td data-label="Тип">
                        <span class="badge <?= (int) ($task['vk_donut_enabled'] ?? 0) === 1 ? 'badge--warning' : 'badge--secondary' ?>">
                            <?= (int) ($task['vk_donut_enabled'] ?? 0) === 1 ? 'VK Donut' : 'Обычный пост' ?>
                        </span>
                    </td>
                    <td data-label="Создано"><?= e(date('d.m.Y H:i', strtotime((string) $task['created_at']))) ?></td>
                    <td data-label="Публикация"><?= !empty($task['published_at']) ? e(date('d.m.Y H:i', strtotime((string) $task['published_at']))) : '—' ?></td>
                    <td data-label="Действия" style="display:flex; gap:6px; flex-wrap:wrap;">
                        <a href="/admin/vk-publication/<?= (int) $task['id'] ?>" class="btn btn--ghost btn--sm" title="Открыть"><i class="fas fa-eye"></i></a>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                            <input type="hidden" name="action" value="publish">
                            <button type="submit" class="btn btn--primary btn--sm" title="Опубликовать"><i class="fab fa-vk"></i></button>
                        </form>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                            <input type="hidden" name="action" value="retry_failed">
                            <button type="submit" class="btn btn--secondary btn--sm" title="Повторить неудачные"><i class="fas fa-rotate"></i></button>
                        </form>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                            <input type="hidden" name="action" value="duplicate">
                            <button type="submit" class="btn btn--ghost btn--sm" title="Дублировать"><i class="fas fa-copy"></i></button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Архивировать задание?');">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                            <input type="hidden" name="action" value="archive">
                            <button type="submit" class="btn btn--ghost btn--sm" title="Архив"><i class="fas fa-box-archive"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$tasks): ?>
                <tr><td colspan="12" class="text-center text-secondary" style="padding: 36px;">Заданий пока нет.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <div class="vk-publications-mobile">
            <?php foreach ($tasks as $task): ?>
                <?php $statusMeta = getVkTaskStatusMeta((string) $task['task_status']); ?>
                <div class="vk-task-card">
                    <div class="vk-task-card__row"><span class="vk-task-card__label">ID</span><strong>#<?= (int) $task['id'] ?></strong></div>
                    <div class="vk-task-card__row"><span class="vk-task-card__label">Название</span><span><?= e($task['title']) ?></span></div>
                    <div class="vk-task-card__row"><span class="vk-task-card__label">Конкурс</span><span><?= e($task['contest_title'] ?: 'Все конкурсы') ?></span></div>
                    <div class="vk-task-card__row"><span class="vk-task-card__label">Автор</span><span><?= e(trim(($task['creator_name'] ?? '') . ' ' . ($task['creator_surname'] ?? ''))) ?></span></div>
                    <div class="vk-task-card__row"><span class="vk-task-card__label">Работ</span><span><?= (int) $task['total_items'] ?></span></div>
                    <div class="vk-task-card__row"><span class="vk-task-card__label">Опубликовано</span><span><?= (int) $task['published_items'] ?></span></div>
                    <div class="vk-task-card__row"><span class="vk-task-card__label">Ошибки</span><span><?= (int) $task['failed_items'] ?></span></div>
                    <div class="vk-task-card__row"><span class="vk-task-card__label">Статус</span><span class="badge <?= e($statusMeta['badge_class']) ?>"><?= e($statusMeta['label']) ?></span></div>
                    <div class="vk-task-card__row"><span class="vk-task-card__label">Тип</span><span><?= (int) ($task['vk_donut_enabled'] ?? 0) === 1 ? 'VK Donut' : 'Обычный пост' ?></span></div>
                    <div class="vk-task-card__row"><span class="vk-task-card__label">Создано</span><span><?= e(date('d.m.Y H:i', strtotime((string) $task['created_at']))) ?></span></div>
                    <div class="vk-task-card__row"><span class="vk-task-card__label">Публикация</span><span><?= !empty($task['published_at']) ? e(date('d.m.Y H:i', strtotime((string) $task['published_at']))) : '—' ?></span></div>
                    <div class="vk-task-card__actions">
                        <a href="/admin/vk-publication/<?= (int) $task['id'] ?>" class="btn btn--ghost btn--sm">Открыть</a>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                            <input type="hidden" name="action" value="publish">
                            <button type="submit" class="btn btn--primary btn--sm">Опубликовать</button>
                        </form>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                            <input type="hidden" name="action" value="retry_failed">
                            <button type="submit" class="btn btn--secondary btn--sm">Повторить ошибки</button>
                        </form>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                            <input type="hidden" name="action" value="duplicate">
                            <button type="submit" class="btn btn--ghost btn--sm">Дублировать</button>
                        </form>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                            <input type="hidden" name="action" value="archive">
                            <button type="submit" class="btn btn--ghost btn--sm">Архивировать</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
const csrfTokenValue = document.querySelector('input[name="csrf_token"]')?.value || '';
let selectionModeEnabled = false;

function updateBulkSelectionState() {
    const selected = Array.from(document.querySelectorAll('.task-select-checkbox:checked'));
    const selectedCount = selected.length;
    const selectedCounter = document.getElementById('selectedCountValue');
    const archiveButton = document.getElementById('bulkArchiveBtn');
    if (selectedCounter) {
        selectedCounter.textContent = String(selectedCount);
    }
    if (archiveButton) {
        archiveButton.disabled = selectedCount === 0;
    }
}

function clearSelectedTasks() {
    document.querySelectorAll('.task-select-checkbox').forEach((checkbox) => {
        checkbox.checked = false;
    });
    updateBulkSelectionState();
}

function toggleSelectionMode(forceState = null) {
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
        clearSelectedTasks();
    }
    updateBulkSelectionState();
}

function archiveSelectedTasks() {
    const selected = Array.from(document.querySelectorAll('.task-select-checkbox:checked')).map((item) => item.value);
    if (!selected.length) {
        alert('Выберите хотя бы одно задание');
        return;
    }
    if (!confirm('Архивировать выбранные задания?')) {
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/admin/vk-publications';

    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = csrfTokenValue;
    form.appendChild(csrfInput);

    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'archive_selected';
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
}

document.getElementById('toggleSelectModeBtn')?.addEventListener('click', function() {
    toggleSelectionMode();
});
document.getElementById('clearSelectedBtn')?.addEventListener('click', function() {
    clearSelectedTasks();
});
document.getElementById('bulkArchiveBtn')?.addEventListener('click', function() {
    archiveSelectedTasks();
});
document.querySelectorAll('.task-select-checkbox').forEach((checkbox) => {
    checkbox.addEventListener('change', updateBulkSelectionState);
});
updateBulkSelectionState();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
