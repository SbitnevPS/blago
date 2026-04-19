<?php
// admin/users.php - Управление пользователями
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

// Проверка авторизации админа
if (!isAdmin()) {
    redirect('/admin/login');
}

check_csrf();

$admin = getCurrentUser();
$currentPage = 'users';
$pageTitle = 'Пользователи';
$breadcrumb = 'Управление пользователями';
$usersReturnUrl = (string) ($_SERVER['REQUEST_URI'] ?? '/admin/users');

// Поиск
$search = $_GET['search'] ?? '';
$where = '';
$params = [];

if ($search) {
    $searchValue = trim((string) $search);
    if ($searchValue !== '' && ctype_digit($searchValue)) {
        $where = "WHERE u.id = ?";
        $params = [(int) $searchValue];
    } else {
        $term = '%' . $searchValue . '%';
        $hasPatronymic = function_exists('db_table_has_column') ? db_table_has_column('users', 'patronymic') : false;

        $fioConditions = [
            'u.name LIKE ?',
            'u.surname LIKE ?',
            "CONCAT_WS(' ', u.surname, u.name" . ($hasPatronymic ? ', u.patronymic' : '') . ") LIKE ?",
            "CONCAT_WS(' ', u.name, u.surname" . ($hasPatronymic ? ', u.patronymic' : '') . ") LIKE ?",
        ];
        if ($hasPatronymic) {
            $fioConditions[] = 'u.patronymic LIKE ?';
        }

        $where = 'WHERE (' . implode(' OR ', $fioConditions) . ')';
        $params = array_fill(0, count($fioConditions), $term);
    }
}

// Пагинация
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Получаем общее количество
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users $where");
$countStmt->execute($params);
$totalUsers = $countStmt->fetchColumn();
$totalPages = ceil($totalUsers / $perPage);

// Получаем пользователей
$stmt = $pdo->prepare("
    SELECT u.*, 
           (SELECT COUNT(*) FROM applications WHERE user_id = u.id) as applications_count
    FROM users u
    $where
    ORDER BY u.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Поиск и фильтры -->
<div class="card mb-lg">
    <div class="card__body">
        <form method="GET" class="flex gap-md" style="align-items: flex-end;">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <div style="flex: 1; max-width: 400px;">
                <label class="form-label">Поиск по пользователю или ID</label>
                <input type="text" name="search" class="form-input" 
                       placeholder="ID пользователя или ФИО" 
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <button type="submit" class="btn btn--primary">
                <i class="fas fa-search"></i> Найти
            </button>
            <?php if ($search): ?>
                <a href="users.php" class="btn btn--ghost">Сбросить</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Список пользователей -->
<div class="card">
    <div class="card__header">
        <div class="flex justify-between items-center">
            <h3>Пользователи (<?= e($totalUsers) ?>)</h3>
        </div>
    </div>
    <div class="card__body">
        <?php if (empty($users)): ?>
            <div class="text-center text-secondary" style="padding:40px;">Пользователи не найдены</div>
        <?php else: ?>
        <div class="admin-list-cards">
            <?php foreach ($users as $user): ?>
                <article class="admin-list-card">
                    <div class="admin-list-card__header">
                        <div class="admin-list-card__title-wrap" style="display:flex; gap:10px; align-items:center;">
                            <?php if (!empty($user['avatar_url'])): ?>
                                <img src="<?= htmlspecialchars($user['avatar_url']) ?>" class="admin-list-card__avatar" alt="Аватар">
                            <?php else: ?>
                                <div class="admin-list-card__avatar admin-list-card__avatar--empty"><i class="fas fa-user"></i></div>
                            <?php endif; ?>
                            <div>
                                <h4 class="admin-list-card__title"><?= htmlspecialchars(trim(($user['surname'] ?? '') . ' ' . ($user['name'] ?? '') . ' ' . ($user['patronymic'] ?? '')) ?: 'Без имени') ?></h4>
                                <div class="admin-list-card__subtitle"><?= htmlspecialchars($user['email'] ?: 'Email не указан') ?></div>
                            </div>
                        </div>
                        <?php if ((int) $user['is_admin'] === 1): ?>
                            <span class="badge badge--primary">Админ</span>
                        <?php endif; ?>
                    </div>
                    <div class="admin-list-card__meta">
                        <span><strong>Заявок:</strong> <?= (int) $user['applications_count'] ?></span>
                        <span><strong>Регистрация:</strong> <?= date('d.m.Y', strtotime($user['created_at'])) ?></span>
                    </div>
                    <div class="admin-list-card__actions">
                        <a href="/admin/user/<?= (int) $user['id'] ?>?return_url=<?= urlencode($usersReturnUrl) ?>" class="btn btn--primary btn--sm"><i class="fas fa-eye"></i> Профиль</a>
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
                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" class="btn btn--ghost btn--sm">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" class="btn btn--ghost btn--sm">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
