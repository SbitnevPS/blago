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
            $newId = createVkTaskFromPreview('Копия: ' . $task['title'], (int) getCurrentAdminId(), $preview, 'manual');
            $_SESSION['success_message'] = 'Создана копия задания #' . $newId;
        }
    } elseif ($taskId > 0 && $action === 'archive') {
        $pdo->prepare("UPDATE vk_publication_tasks SET task_status = 'archived', updated_at = NOW() WHERE id = ?")
            ->execute([$taskId]);
        $_SESSION['success_message'] = 'Задание архивировано';
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
        <h3>Задания на публикацию (<?= count($tasks) ?>)</h3>
    </div>
    <div class="card__body" style="padding: 0;">
        <table class="table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Название</th>
                <th>Конкурс</th>
                <th>Создал</th>
                <th>Работ</th>
                <th>Опубликовано</th>
                <th>Ошибки</th>
                <th>Статус</th>
                <th>Создано</th>
                <th>Публикация</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($tasks as $task): ?>
                <?php $statusMeta = getVkTaskStatusMeta((string) $task['task_status']); ?>
                <tr>
                    <td data-label="ID">#<?= (int) $task['id'] ?></td>
                    <td data-label="Название"><?= e($task['title']) ?></td>
                    <td data-label="Конкурс"><?= e($task['contest_title'] ?: 'Все конкурсы') ?></td>
                    <td data-label="Создал"><?= e(trim(($task['creator_name'] ?? '') . ' ' . ($task['creator_surname'] ?? ''))) ?></td>
                    <td data-label="Работ"><?= (int) $task['total_items'] ?></td>
                    <td data-label="Опубликовано"><?= (int) $task['published_items'] ?></td>
                    <td data-label="Ошибки"><?= (int) $task['failed_items'] ?></td>
                    <td data-label="Статус"><span class="badge <?= e($statusMeta['badge_class']) ?>"><?= e($statusMeta['label']) ?></span></td>
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
                <tr><td colspan="11" class="text-center text-secondary" style="padding: 36px;">Заданий пока нет.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
