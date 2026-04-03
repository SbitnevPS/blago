<?php
// admin/users.php - Управление пользователями
require_once __DIR__ . '/../config.php';

// Проверка авторизации админа
if (!isAdmin()) {
    redirect('/admin/login');
}

$admin = getCurrentUser();
$currentPage = 'users';
$pageTitle = 'Пользователи';
$breadcrumb = 'Управление пользователями';

// Поиск
$search = $_GET['search'] ?? '';
$where = '';
$params = [];

if ($search) {
    $where = "WHERE name LIKE ? OR surname LIKE ? OR email LIKE ? OR vk_id LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
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
            <div style="flex: 1; max-width: 400px;">
                <label class="form-label">Поиск</label>
                <input type="text" name="search" class="form-input" 
                       placeholder="Имя, фамилия, email, VK ID..." 
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
            <h3>Пользователи (<?= $totalUsers ?>)</h3>
        </div>
    </div>
    <div class="card__body" style="padding: 0;">
<table class="table">
<thead>
<tr>
<th>ID</th>
<th>Пользователь</th>
<th>VK ID</th>
<th>Email</th>
<th>Заявок</th>
<th>Дата регистрации</th>
<th style="width:140px;"></th>
<th style="width:80px;"></th>
</tr>
</thead>
<tbody>
 <?php foreach ($users as $user): ?>
<tr>
<td>#<?= $user['id'] ?></td>
<td>
<div class="flex items-center gap-md">
 <?php if (!empty($user['avatar_url'])): ?>
<img src="<?= htmlspecialchars($user['avatar_url']) ?>" 
 style="width:36px; height:36px; border-radius:50%; object-fit: cover;">
 <?php else: ?>
<div style="width:36px; height:36px; border-radius:50%; background: #EEF2FF; display: flex; align-items: center; justify-content: center; color: #6366F1;">
<i class="fas fa-user"></i>
</div>
 <?php endif; ?>
<div>
<div class="font-semibold"><?= htmlspecialchars(($user['name'] ?? '') . ' ' . ($user['patronymic'] ?? '')) ?></div>
<div class="text-secondary" style="font-size:13px;"><?= htmlspecialchars($user['surname'] ?? '') ?></div>
</div>
</div>
</td>
<td><?= htmlspecialchars($user['vk_id'] ?? '') ?></td>
<td><?= htmlspecialchars($user['email'] ?: '—') ?></td>
<td><?= $user['applications_count'] ?></td>
<td><?= date('d.m.Y', strtotime($user['created_at'])) ?></td>
<td>
 <?php if ($user['is_admin']): ?>
<span class="badge badge--primary" style="font-size:10px;">Админ</span>
 <?php endif; ?>
</td>
<td>
<a href="user-view.php?id=<?= $user['id'] ?>" class="btn btn--primary" style="padding:10px16px; font-size:13px; display:flex; align-items:center; gap:6px; white-space:nowrap;">
<i class="fas fa-eye"></i> Профиль
</a>
</td>
</tr>
 <?php endforeach; ?>

 <?php if (empty($users)): ?>
<tr>
<td colspan="8" class="text-center text-secondary" style="padding:40px;">
 Пользователи не найдены
</td>
</tr>
 <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Пагинация -->
        <?php if ($totalPages > 1): ?>
        <div class="flex justify-between items-center" style="padding: 16px 20px; border-top: 1px solid var(--color-border);">
            <div class="text-secondary" style="font-size: 14px;">
                Страница <?= $page ?> из <?= $totalPages ?>
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
