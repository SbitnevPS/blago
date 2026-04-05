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
$contest_id = $_GET['contest_id'] ?? '';
$search = $_GET['search'] ?? '';

$where = [];
$params = [];

if ($status) {
    $where[] = 'a.status = ?';
    $params[] = $status;
}

if ($contest_id) {
    $where[] = 'a.contest_id = ?';
    $params[] = $contest_id;
}

if ($search) {
    $where[] = '(u.name LIKE ? OR u.surname LIKE ? OR u.email LIKE ? OR a.id LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
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
    SELECT a.*, c.title as contest_title, u.name, u.surname, u.avatar_url,
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

// Список конкурсов для фильтра
$contests = $pdo->query("SELECT id, title FROM contests ORDER BY created_at DESC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Фильтры -->
<div class="card mb-lg">
    <div class="card__body">
        <form method="GET" class="flex gap-md" style="flex-wrap: wrap; align-items: flex-end;">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <div style="min-width: 200px;">
                <label class="form-label">Статус</label>
                <select name="status" class="form-select">
                    <option value="">Все статусы</option>
                    <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Черновики</option>
                    <option value="submitted" <?= $status === 'submitted' ? 'selected' : '' ?>>Отправленные</option>
                    <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Принятые</option>
                    <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Отклонённые/отменённые</option>
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
            <div style="flex: 1; min-width: 200px; max-width: 300px;">
                <label class="form-label">Поиск</label>
                <input type="text" name="search" class="form-input" 
                       placeholder="ID, имя, email..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <button type="submit" class="btn btn--primary">
                <i class="fas fa-filter"></i> Фильтр
            </button>
            <?php if ($status || $contest_id || $search): ?>
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
        'draft' => $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'draft'")->fetchColumn()
    ];
    ?>
    <a href="applications.php" class="stat-pill <?= !$status ? 'stat-pill--active' : '' ?>">
        Все <span class="stat-pill__count"><?= $statCounts['all'] ?></span>
    </a>
    <a href="?status=submitted" class="stat-pill <?= $status === 'submitted' ? 'stat-pill--active' : '' ?>">
        Отправленные <span class="stat-pill__count"><?= $statCounts['submitted'] ?></span>
    </a>
    <a href="?status=draft" class="stat-pill <?= $status === 'draft' ? 'stat-pill--active' : '' ?>">
        Черновики <span class="stat-pill__count"><?= $statCounts['draft'] ?></span>
    </a>
</div>

<!-- Список заявок -->
<div class="card">
    <div class="card__header">
        <h3>Заявки (<?= e($totalApps) ?>)</h3>
    </div>
    <div class="card__body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Пользователь</th>
                    <th>Конкурс</th>
                    <th>Участников</th>
                    <th>Статус</th>
                    <th>Дата</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $app): ?>
                <?php
                    $statusLabels = [
                        'draft' => 'Черновик',
                        'submitted' => 'Отправлена',
                        'approved' => 'Заявка принята',
                        'rejected' => 'Отклонена/отменена',
                    ];
                    $statusClasses = [
                        'draft' => 'badge--warning',
                        'submitted' => 'badge--success',
                        'approved' => 'badge--success',
                        'rejected' => 'badge--warning',
                    ];
                    $rowStyle = '';
                    $isRevisionState = isset($app['allow_edit']) && (int) $app['allow_edit'] === 1 && $app['status'] !== 'approved';
                    if ($app['status'] === 'approved') {
                        $rowStyle = 'background:#ECFDF5;';
                    } elseif ($isRevisionState) {
                        $rowStyle = 'background:#FEF9C3;';
                    } elseif ($app['status'] === 'rejected') {
                        $rowStyle = 'background:#FEE2E2;';
                    }
                ?>
                <tr style="<?= $rowStyle ?>">
                    <td>#<?= $app['id'] ?></td>
                    <td>
                        <div class="flex items-center gap-md">
                            <?php if (!empty($app['avatar_url'])): ?>
                                <img src="<?= htmlspecialchars($app['avatar_url']) ?>" 
                                     style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                                <div style="width: 32px; height: 32px; border-radius: 50%; background: #EEF2FF; display: flex; align-items: center; justify-content: center; color: #6366F1;">
                                    <i class="fas fa-user" style="font-size: 12px;"></i>
                                </div>
                            <?php endif; ?>
                            <span><?= htmlspecialchars(($app['name'] ?? '') . ' ' . ($app['surname'] ?? '')) ?></span>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($app['contest_title'] ?? '—') ?></td>
                    <td><?= $app['participants_count'] ?></td>
                    <td>
                        <span class="badge <?= $statusClasses[$app['status']] ?? 'badge--warning' ?>">
                            <?= htmlspecialchars($isRevisionState ? 'На корректировке' : ($statusLabels[$app['status']] ?? ucfirst((string) $app['status']))) ?>
                        </span>
                    </td>
                    <td><?= date('d.m.Y H:i', strtotime($app['created_at'])) ?></td>
                    <td>
                        <a href="/admin/application/<?= $app['id'] ?>" class="btn btn--ghost btn--sm">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($applications)): ?>
                <tr>
                    <td colspan="7" class="text-center text-secondary" style="padding: 40px;">
                        Заявки не найдены
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Пагинация -->
        <?php if ($totalPages > 1): ?>
        <div class="flex justify-between items-center" style="padding: 16px 20px; border-top: 1px solid var(--color-border);">
            <div class="text-secondary" style="font-size: 14px;">
                Страница <?= e($page) ?> из <?= e($totalPages) ?>
            </div>
            <div class="flex gap-sm">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&status=<?= e($status) ?>&contest_id=<?= e($contest_id) ?>&search=<?= urlencode($search) ?>" class="btn btn--ghost btn--sm">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&status=<?= e($status) ?>&contest_id=<?= e($contest_id) ?>&search=<?= urlencode($search) ?>" class="btn btn--ghost btn--sm">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
