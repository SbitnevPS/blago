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
$searchUserId = (int) ($_GET['user_id'] ?? 0);
$where = '';
$params = [];

if ($searchUserId > 0) {
    $where = 'WHERE u.id = ?';
    $params = [$searchUserId];
} elseif ($search) {
    $searchValue = trim((string) $search);
    if ($searchValue !== '' && ctype_digit($searchValue)) {
        $where = "WHERE u.id = ?";
        $params = [(int) $searchValue];
    } else {
        $term = '%' . $searchValue . '%';
        $fioConditions = function_exists('build_user_search_conditions')
            ? build_user_search_conditions('u')
            : ['u.name LIKE ?', 'u.surname LIKE ?', 'u.email LIKE ?'];

        $where = 'WHERE (' . implode(' OR ', $fioConditions) . ')';
        $params = array_fill(0, count($fioConditions), $term);
    }
}

// Пагинация
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Получаем общее количество
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
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
<div class="card card--allow-overflow mb-lg">
    <div class="card__body">
        <form method="GET" class="admin-users-search">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <div
                class="admin-users-search__field"
                <?= admin_live_search_attrs([
                    'endpoint' => '/admin/search-users',
                    'primary_template' => '{{surname + name + patronymic||Без имени}}',
                    'secondary_template' => '#{{id}} · {{email||Email не указан}}',
                    'value_template' => '{{surname + name + patronymic||Без имени}}',
                    'select_url_field' => 'profile_url',
                    'empty_text' => 'Пользователи не найдены',
                    'min_length' => 2,
                    'min_length_numeric' => 1,
                    'debounce' => 220,
                    'show_more_label' => 'Показать все результаты поиска',
                    'show_more_action' => 'submit',
                ]) ?>>
                <label class="form-label">Поиск по пользователю или ID</label>
                <input type="text" name="search" class="form-input"
                       data-live-search-input
                       placeholder="ID пользователя, ФИО или email"
                       value="<?= htmlspecialchars($search) ?>"
                       autocomplete="off">
                <input type="hidden" name="user_id" value="<?= (int) $searchUserId ?>" data-live-search-hidden data-live-search-hidden-field="id">
                <div class="user-results" data-live-search-results></div>
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
        <div class="admin-list-cards admin-users-list">
            <?php foreach ($users as $user): ?>
                <article class="admin-list-card admin-list-card--user" data-user-card-url="/admin/user/<?= (int) $user['id'] ?>" tabindex="0" role="link" aria-label="Открыть профиль пользователя <?= htmlspecialchars(trim(($user['surname'] ?? '') . ' ' . ($user['name'] ?? '') . ' ' . ($user['patronymic'] ?? '')) ?: ('#' . (int) $user['id']), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="admin-user-row__identity">
                            <?php if (!empty($user['avatar_url'])): ?>
                                <img src="<?= htmlspecialchars($user['avatar_url']) ?>" class="admin-list-card__avatar" alt="Аватар">
                            <?php else: ?>
                                <div class="admin-list-card__avatar admin-list-card__avatar--empty"><i class="fas fa-user"></i></div>
                            <?php endif; ?>
                            <div class="admin-user-row__name">
                                <h4 class="admin-list-card__title"><?= htmlspecialchars(trim(($user['surname'] ?? '') . ' ' . ($user['name'] ?? '') . ' ' . ($user['patronymic'] ?? '')) ?: 'Без имени') ?></h4>
                                <div class="admin-list-card__subtitle"><?= htmlspecialchars($user['email'] ?: 'Email не указан') ?></div>
                            </div>
                    </div>
                    <div class="admin-user-row__stats">
                        <span class="admin-user-row__stat"><strong><?= (int) $user['applications_count'] ?></strong><small>заявок</small></span>
                        <span class="admin-user-row__stat"><strong><?= date('d.m.Y', strtotime($user['created_at'])) ?></strong><small>регистрация</small></span>
                    </div>
                    <div class="admin-user-row__role">
                        <?php if ((int) $user['is_admin'] === 1): ?>
                            <span class="badge badge--primary">Админ</span>
                        <?php else: ?>
                            <span class="badge badge--secondary">Пользователь</span>
                        <?php endif; ?>
                    </div>
                    <div class="admin-list-card__actions admin-user-row__actions">
                        <a href="/admin/user/<?= (int) $user['id'] ?>" class="btn btn--primary btn--sm"><i class="fas fa-eye"></i> Профиль</a>
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

<script>
document.querySelectorAll('[data-user-card-url]').forEach((card) => {
    const openCard = () => {
        const url = card.dataset.userCardUrl || '';
        if (url !== '') {
            window.location.href = url;
        }
    };

    card.addEventListener('click', (event) => {
        if (event.target.closest('a, button, input, select, textarea')) {
            return;
        }
        openCard();
    });

    card.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        if (event.target.closest('a, button, input, select, textarea')) {
            return;
        }
        event.preventDefault();
        openCard();
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
