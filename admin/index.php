<?php
// admin/index.php - Главная страница админ-панели
require_once __DIR__ . '/../config.php';

// Проверка авторизации админа
if (!isAdmin()) {
 redirect('/admin/login');
}

$admin = getCurrentUser();

// Статистика
$stats = [
 'users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
 'contests' => $pdo->query("SELECT COUNT(*) FROM contests")->fetchColumn(),
 'active_contests' => $pdo->query("SELECT COUNT(*) FROM contests WHERE is_published =1 AND (date_to IS NULL OR date_to >= CURDATE())")->fetchColumn(),
 'applications' => $pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn(),
 'pending_applications' => $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'submitted'")->fetchColumn(),
 'messages' => $pdo->query("SELECT COUNT(*) FROM admin_messages")->fetchColumn(),
];

$currentPage = 'dashboard';
$pageTitle = 'Главная';
$breadcrumb = 'Обзор системы';

require_once __DIR__ . '/includes/header.php';
?>

<!-- Статистические карточки -->
<div class="stats-grid stats-grid--dashboard">
<div class="stat-card stat-card--compact">
<div class="stat-card__icon" style="background: #EEF2FF; color: #6366F1;">
<i class="fas fa-users"></i>
</div>
<div class="stat-card__content">
<div class="stat-card__value"><?= $stats['users'] ?></div>
<div class="stat-card__label">Пользователей</div>
</div>
</div>

<div class="stat-card stat-card--compact">
<div class="stat-card__icon" style="background: #FEF3C7; color: #F59E0B;">
<i class="fas fa-trophy"></i>
</div>
<div class="stat-card__content">
<div class="stat-card__value"><?= $stats['active_contests'] ?></div>
<div class="stat-card__label">Активных конкурсов</div>
</div>
</div>

<div class="stat-card stat-card--compact stat-card--applications">
<div class="stat-card__icon" style="background: #E2E8F0; color: #475569;">
<i class="fas fa-file-alt"></i>
</div>
<div class="stat-card__content">
<a href="/admin/applications.php" class="stat-card__link-row">Всего заявок <span><?= (int) $stats['applications'] ?></span></a>
<a href="/admin/applications.php?status=submitted" class="stat-card__link-row">Из них новых <span><?= (int) $stats['pending_applications'] ?></span></a>
</div>
</div>

<div class="stat-card stat-card--compact" onclick="window.location.href='/admin/messages.php'" style="cursor:pointer;">
<div class="stat-card__icon" style="background: #E0E7FF; color: #4F46E5;">
<i class="fas fa-envelope"></i>
</div>
<div class="stat-card__content">
<div class="stat-card__value"><?= $stats['messages'] ?></div>
<div class="stat-card__label">Сообщений</div>
</div>
</div>
</div>

<!-- Последние заявки -->
<div class="card mt-xl">
    <div class="card__header">
        <div class="flex justify-between items-center w-100">
            <h3>Последние заявки</h3>
<a href="/admin/applications" class="btn btn--secondary btn--sm">
Все заявки<i class="fas fa-arrow-right"></i>
</a>
        </div>
    </div>
    <div class="card__body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Конкурс</th>
                    <th>Пользователь</th>
                    <th>Участников</th>
                    <th>Статус</th>
                    <th>Дата</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $recentApps = $pdo->query("
                    SELECT a.*, c.title as contest_title, u.name, u.surname,
                           (SELECT COUNT(*) FROM participants WHERE application_id = a.id) as participants_count
                    FROM applications a
                    LEFT JOIN contests c ON a.contest_id = c.id
                    LEFT JOIN users u ON a.user_id = u.id
                    ORDER BY a.created_at DESC
                    LIMIT 5
                ")->fetchAll();
                
                foreach ($recentApps as $app): ?>
                <tr>
                    <td data-label="ID">#<?= $app['id'] ?></td>
                    <td data-label="Конкурс"><?= htmlspecialchars($app['contest_title']) ?></td>
                    <td data-label="Пользователь"><?= htmlspecialchars(($app['name'] ?? '') . ' ' . ($app['surname'] ?? '')) ?></td>
                    <td data-label="Участников"><?= $app['participants_count'] ?></td>
                    <td data-label="Статус">
                        <span class="badge <?= $app['status'] === 'submitted' ? 'badge--success' : 'badge--warning' ?>">
                            <?= $app['status'] === 'submitted' ? 'Не обработанная заявка' : 'Черновик' ?>
                        </span>
                    </td>
                    <td data-label="Дата"><?= date('d.m.Y', strtotime($app['created_at'])) ?></td>
                    <td data-label="Действия">
                        <a href="/admin/application/<?= $app['id'] ?>?return_url=<?= urlencode((string) ($_SERVER['REQUEST_URI'] ?? '/admin/')) ?>" class="btn btn--ghost btn--sm">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($recentApps)): ?>
                <tr>
                    <td colspan="7" class="text-center text-secondary" style="padding: 40px;">
                        Заявок пока нет
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Быстрые действия -->
<div class="card mt-xl">
<div class="card__header">
<h3>Быстрые действия</h3>
</div>
<div class="card__body">
<div class="flex gap-md" style="flex-wrap: wrap;">
<a href="/admin/contest-edit" class="btn btn--primary">
<i class="fas fa-plus"></i> Новый конкурс
</a>
<a href="/admin/contests" class="btn btn--secondary">
<i class="fas fa-trophy"></i> Управление конкурсами
</a>
<a href="/admin/applications" class="btn btn--secondary">
<i class="fas fa-file-alt"></i> Заявки
</a>
<a href="/admin/users" class="btn btn--secondary">
<i class="fas fa-users"></i> Пользователи
</a>
<a href="/admin/messages" class="btn btn--secondary">
<i class="fas fa-envelope"></i> Сообщения
</a>
</div>
</div>
</div>

<!-- Последние сообщения -->
<div class="card mt-xl">
<div class="card__header">
<div class="flex justify-between items-center">
<h3>Последние сообщения</h3>
<a href="/admin/messages" class="btn btn--secondary btn--sm">
Все сообщения<i class="fas fa-arrow-right"></i>
</a>
</div>
</div>
<div class="card__body" style="padding:0;">
<table class="table">
<thead>
<tr>
<th>ID</th>
<th>Получатель</th>
<th>Тема</th>
<th>Приоритет</th>
<th>Дата</th>
</tr>
</thead>
<tbody>
<?php
$recentMessages = $pdo->query("
 SELECT am.*, u.name as user_name, u.surname as user_surname
 FROM admin_messages am
 LEFT JOIN users u ON am.user_id = u.id
 ORDER BY am.created_at DESC
 LIMIT 10
")->fetchAll();

// Фильтруем дубликаты broadcast (оставляем только последнее для каждого subject)
$shownBroadcast = [];
$filteredMessages = [];
foreach ($recentMessages as $msg) {
 $broadcastKey = $msg['admin_id'] . '-' . $msg['subject'];
 if (!empty($msg['is_broadcast'])) {
 if (isset($shownBroadcast[$broadcastKey])) continue;
 $shownBroadcast[$broadcastKey] = true;
 }
 $filteredMessages[] = $msg;
 if (count($filteredMessages) >=5) break;
}

foreach ($filteredMessages as $msg): ?>
<tr>
<td data-label="ID">#<?= $msg['id'] ?></td>
<td data-label="Получатель">
<?php if (!empty($msg['is_broadcast'])): ?>
<span style="color:#6366F1; font-weight:500;"><i class="fas fa-bullhorn"></i> Отправлено для всех</span>
<?php elseif (!empty($msg['user_name'])): ?>
<?= htmlspecialchars($msg['user_name'] . ' ' . $msg['user_surname']) ?>
<?php else: ?>
<span class="text-secondary">Пользователь удален</span>
<?php endif; ?>
</td>
<td data-label="Тема"><?= htmlspecialchars($msg['subject']) ?></td>
<td data-label="Приоритет">
<?php if ($msg['priority'] === 'critical'): ?>
<span class="badge" style="background:#EF4444; color:white;">Критическое</span>
<?php elseif ($msg['priority'] === 'important'): ?>
<span class="badge" style="background:#F59E0B; color:white;">Важное</span>
<?php else: ?>
<span class="badge" style="background:#6B7280; color:white;">Обычное</span>
<?php endif; ?>
</td>
<td data-label="Дата"><?= date('d.m.Y H:i', strtotime($msg['created_at'])) ?></td>
</tr>
<?php endforeach; ?>

<?php if (empty($recentMessages)): ?>
<tr>
<td colspan="5" class="text-center text-secondary" style="padding:40px;">
 Сообщений пока нет
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
