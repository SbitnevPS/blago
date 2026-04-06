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
$taskId = (int) ($_GET['id'] ?? 0);

if ($taskId <= 0) {
    redirect('/admin/vk-publications');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Ошибка безопасности (CSRF).';
        redirect('/admin/vk-publication/' . $taskId);
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'publish_task') {
        $result = publishVkTask($taskId);
        $_SESSION['success_message'] = 'Публикация выполнена: успешно ' . (int) $result['published'] . ', ошибок ' . (int) $result['failed'];
    } elseif ($action === 'retry_failed') {
        $result = retryFailedVkTaskItems($taskId);
        $_SESSION['success_message'] = 'Повтор выполнен: успешно ' . (int) $result['published'] . ', ошибок ' . (int) $result['failed'];
    } elseif ($action === 'publish_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $result = publishVkTaskItem($itemId);
        if (!empty($result['success'])) {
            $_SESSION['success_message'] = 'Элемент опубликован.';
        } else {
            $_SESSION['error_message'] = 'Ошибка публикации: ' . (string) ($result['error'] ?? 'неизвестно');
        }
    } elseif ($action === 'exclude_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $pdo->prepare("UPDATE vk_publication_task_items SET item_status = 'skipped', skip_reason = 'Исключено администратором', updated_at = NOW() WHERE id = ? AND task_id = ? AND item_status <> 'published'")
            ->execute([$itemId, $taskId]);
        refreshVkTaskCounters($taskId);
        $_SESSION['success_message'] = 'Элемент исключён из задания.';
    }

    redirect('/admin/vk-publication/' . $taskId);
}

$task = getVkTaskById($taskId);
if (!$task) {
    redirect('/admin/vk-publications');
}

$pageTitle = 'Задание #' . $taskId . ' / Публикации в ВК';
$breadcrumb = 'Публикации в ВК / Просмотр задания';

$statusMeta = getVkTaskStatusMeta((string) $task['task_status']);
$items = getVkTaskItems($taskId);

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
    <div class="card__header"><h3>Общая информация</h3></div>
    <div class="card__body">
        <div class="flex gap-lg" style="flex-wrap: wrap;">
            <div><strong>ID:</strong> #<?= (int) $task['id'] ?></div>
            <div><strong>Название:</strong> <?= e($task['title']) ?></div>
            <div><strong>Статус:</strong> <span class="badge <?= e($statusMeta['badge_class']) ?>"><?= e($statusMeta['label']) ?></span></div>
            <div><strong>Создал:</strong> <?= e(trim(($task['creator_name'] ?? '') . ' ' . ($task['creator_surname'] ?? ''))) ?></div>
            <div><strong>Создано:</strong> <?= e(date('d.m.Y H:i', strtotime((string) $task['created_at']))) ?></div>
            <div><strong>Опубликовано:</strong> <?= $task['published_at'] ? e(date('d.m.Y H:i', strtotime((string) $task['published_at']))) : '—' ?></div>
        </div>

        <hr style="margin:14px 0; border:none; border-top:1px solid #E5E7EB;">

        <div class="flex gap-lg" style="flex-wrap: wrap;">
            <div><strong>Найдено:</strong> <?= (int) $task['total_items'] ?></div>
            <div><strong>Готово:</strong> <?= (int) $task['ready_items'] ?></div>
            <div><strong>Опубликовано:</strong> <?= (int) $task['published_items'] ?></div>
            <div><strong>Ошибки:</strong> <?= (int) $task['failed_items'] ?></div>
            <div><strong>Пропущено:</strong> <?= (int) $task['skipped_items'] ?></div>
        </div>

        <div class="flex gap-md mt-lg" style="margin-top:16px; flex-wrap: wrap;">
            <form method="POST" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="publish_task">
                <button type="submit" class="btn btn--primary"><i class="fab fa-vk"></i> Опубликовать задание</button>
            </form>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="retry_failed">
                <button type="submit" class="btn btn--secondary"><i class="fas fa-rotate"></i> Повторить failed</button>
            </form>
            <a href="/admin/vk-publications" class="btn btn--ghost">К списку заданий</a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card__header"><h3>Элементы задания</h3></div>
    <div class="card__body" style="padding: 0;">
        <table class="table">
            <thead>
            <tr>
                <th>#</th>
                <th>Работа</th>
                <th>Участник</th>
                <th>Организация / Регион</th>
                <th>Конкурс</th>
                <th>Текст поста</th>
                <th>Статус</th>
                <th>Результат</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <?php $itemStatus = getVkItemStatusMeta((string) $item['item_status']); ?>
                <tr>
                    <td data-label="#">#<?= (int) $item['id'] ?></td>
                    <td data-label="Работа">
                        <?php if (!empty($item['work_image_web_path'])): ?>
                            <img src="<?= e($item['work_image_web_path']) ?>" alt="work" style="width:72px; height:72px; object-fit:cover; border-radius:8px; border:1px solid #E5E7EB;">
                        <?php else: ?>
                            <span class="text-secondary">Нет изображения</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Участник"><?= e($item['participant_fio'] ?: '—') ?></td>
                    <td data-label="Организация / Регион">
                        <div><?= e($item['organization_name'] ?: '—') ?></div>
                        <div class="text-secondary"><?= e($item['region'] ?: '—') ?></div>
                    </td>
                    <td data-label="Конкурс"><?= e($item['contest_title'] ?: '—') ?></td>
                    <td data-label="Текст поста" style="max-width: 320px;"><div style="white-space: pre-wrap;"><?= e($item['post_text']) ?></div></td>
                    <td data-label="Статус"><span class="badge <?= e($itemStatus['badge_class']) ?>"><?= e($itemStatus['label']) ?></span></td>
                    <td data-label="Результат">
                        <?php if (!empty($item['vk_post_url'])): ?>
                            <a href="<?= e($item['vk_post_url']) ?>" target="_blank" class="btn btn--ghost btn--sm"><i class="fas fa-up-right-from-square"></i> Пост</a>
                        <?php elseif (!empty($item['error_message'])): ?>
                            <div class="text-secondary" style="max-width:220px;"><?= e($item['error_message']) ?></div>
                        <?php elseif (!empty($item['skip_reason'])): ?>
                            <div class="text-secondary" style="max-width:220px;"><?= e($item['skip_reason']) ?></div>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td data-label="Действия" style="display:flex; gap:6px; flex-wrap:wrap;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="publish_item">
                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                            <button type="submit" class="btn btn--primary btn--sm" title="Опубликовать"><i class="fab fa-vk"></i></button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Исключить элемент из задания?');">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="exclude_item">
                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                            <button type="submit" class="btn btn--ghost btn--sm" title="Исключить"><i class="fas fa-ban"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?>
                <tr><td colspan="9" class="text-center text-secondary" style="padding: 36px;">Элементов нет.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
