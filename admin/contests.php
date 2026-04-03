<?php
// admin/contests.php - Управление конкурсами
require_once __DIR__ . '/../config.php';

// Проверка авторизации админа
if (!isAdmin()) {
 redirect('/admin/login');
}

$admin = getCurrentUser();
$currentPage = 'contests';
$pageTitle = 'Конкурсы';
$breadcrumb = 'Управление конкурсами';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $contest_id = intval($_POST['contest_id'] ?? 0);
    
    if ($_POST['action'] === 'toggle_publish') {
        $stmt = $pdo->prepare("UPDATE contests SET is_published = NOT is_published WHERE id = ?");
        $stmt->execute([$contest_id]);
        $_SESSION['success_message'] = 'Статус публикации изменён';
    } elseif ($_POST['action'] === 'delete') {
        // Удаляем связанные данные
        $pdo->prepare("DELETE FROM participants WHERE application_id IN (SELECT id FROM applications WHERE contest_id = ?)")->execute([$contest_id]);
        $pdo->prepare("DELETE FROM applications WHERE contest_id = ?")->execute([$contest_id]);
        $pdo->prepare("DELETE FROM contests WHERE id = ?")->execute([$contest_id]);
        $_SESSION['success_message'] = 'Конкурс удалён';
    }
    
    redirect('contests.php');
}

// Получаем конкурсы
$contests = $pdo->query("
    SELECT c.*,
           (SELECT COUNT(*) FROM applications WHERE contest_id = c.id) as applications_count
    FROM contests c
    ORDER BY c.created_at DESC
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Сообщения -->
<?php if (isset($_SESSION['success_message'])): ?>
<div class="alert alert--success alert--permanent mb-lg">
    <i class="fas fa-check-circle alert__icon"></i>
    <div class="alert__content">
        <div class="alert__message"><?= htmlspecialchars($_SESSION['success_message']) ?></div>
    </div>
    <button type="button" class="btn-close"></button>
</div>
<?php unset($_SESSION['success_message']); endif; ?>

<!-- Кнопка добавления -->
<div class="flex justify-between items-center mb-lg">
    <h1>Конкурсы</h1>
    <a href="contest-edit.php" class="btn btn--primary">
        <i class="fas fa-plus"></i> Новый конкурс
    </a>
</div>

<!-- Список конкурсов -->
<?php if (empty($contests)): ?>
    <div class="card">
        <div class="card__body text-center" style="padding: 60px 20px;">
            <div style="font-size: 48px; color: var(--color-text-muted); margin-bottom: 16px;">
                <i class="fas fa-trophy"></i>
            </div>
            <h3>Конкурсов пока нет</h3>
            <p class="text-secondary mt-sm">Создайте первый конкурс</p>
            <a href="contest-edit.php" class="btn btn--primary mt-lg">
                <i class="fas fa-plus"></i> Создать конкурс
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="cards-grid">
        <?php foreach ($contests as $contest): ?>
        <div class="card">
            <div class="card__header">
                <div class="flex justify-between items-start">
                    <div>
                        <h3><?= htmlspecialchars($contest['title']) ?></h3>
                        <span class="badge <?= $contest['is_published'] ? 'badge--success' : 'badge--secondary' ?>">
                            <?= $contest['is_published'] ? 'Опубликован' : 'Не опубликован' ?>
                        </span>
                    </div>
                    <div class="flex gap-sm">
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="contest_id" value="<?= $contest['id'] ?>">
                            <input type="hidden" name="action" value="toggle_publish">
                            <button type="submit" class="btn btn--ghost btn--sm" title="Переключить публикацию">
                                <i class="fas fa-<?= $contest['is_published'] ? 'eye-slash' : 'eye' ?>"></i>
                            </button>
                        </form>
                        <a href="contest-edit.php?id=<?= $contest['id'] ?>" class="btn btn--ghost btn--sm" title="Редактировать">
                            <i class="fas fa-edit"></i>
                        </a>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Вы уверены? Все заявки этого конкурса будут удалены.');">
                            <input type="hidden" name="contest_id" value="<?= $contest['id'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="btn btn--ghost btn--sm" style="color: var(--color-error);" title="Удалить">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="card__body">
                <p class="text-secondary" style="font-size: 14px; line-height: 1.6;">
                    <?= htmlspecialchars(mb_substr(strip_tags($contest['description'] ?? ''), 0, 200)) ?>...
                </p>
                
                <div class="flex gap-lg mt-md" style="font-size: 13px; color: var(--color-text-secondary);">
                    <?php if ($contest['date_from']): ?>
                        <span><i class="fas fa-calendar"></i> С <?= date('d.m.Y', strtotime($contest['date_from'])) ?></span>
                    <?php endif; ?>
                    <?php if ($contest['date_to']): ?>
                        <span><i class="fas fa-calendar"></i> По <?= date('d.m.Y', strtotime($contest['date_to'])) ?></span>
                    <?php endif; ?>
                    <span><i class="fas fa-file-alt"></i> <?= $contest['applications_count'] ?> заявок</span>
                </div>
            </div>
            <div class="card__footer">
                <a href="application-list.php?contest_id=<?= $contest['id'] ?>" class="btn btn--secondary btn--sm">
                    <i class="fas fa-file-alt"></i> Заявки (<?= $contest['applications_count'] ?>)
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
