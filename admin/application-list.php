<?php
// admin/application-list.php - Список заявок конкурса
require_once __DIR__ . '/../config.php';

// Проверка авторизации админа
if (!isAdmin()) {
 redirect('/admin/login');
}

$admin = getCurrentUser();
$contest_id = $_GET['contest_id'] ??0;

// Получаем конкурс
$stmt = $pdo->prepare("SELECT * FROM contests WHERE id = ?");
$stmt->execute([$contest_id]);
$contest = $stmt->fetch();

if (!$contest) {
 redirect('contests.php');
}

// Получаем заявки
$stmt = $pdo->prepare("
 SELECT a.*, c.requires_payment_receipt AS contest_requires_payment_receipt, u.name, u.surname, u.email, u.avatar_url,
 (SELECT COUNT(*) FROM participants WHERE application_id = a.id) as participants_count
 FROM applications a
 LEFT JOIN contests c ON c.id = a.contest_id
 LEFT JOIN users u ON a.user_id = u.id
 WHERE a.contest_id = ?
 ORDER BY a.created_at DESC
");
$stmt->execute([$contest_id]);
$applications = $stmt->fetchAll();

$currentPage = 'contests';
$pageTitle = 'Заявки конкурса';
$breadcrumb = 'Конкурсы / ' . htmlspecialchars($contest['title']) . ' / Заявки';

require_once __DIR__ . '/includes/header.php';
?>

<div class="flex items-center gap-md mb-lg">
<a href="contests.php" class="btn btn--ghost">
<i class="fas fa-arrow-left"></i> К конкурсам
</a>
</div>

<!-- Информация о конкурсе -->
<div class="card mb-lg">
<div class="card__body">
<div class="flex justify-between items-center">
<div>
<h2><?= htmlspecialchars($contest['title']) ?></h2>
<p class="text-secondary mt-sm">Всего заявок: <?= count($applications) ?></p>
</div>
<a href="/admin/contest/<?= $contest_id ?>" class="btn btn--secondary">
<i class="fas fa-edit"></i> Редактировать
</a>
</div>
</div>
</div>

<!-- Список заявок -->
<div class="card">
<div class="card__header">
<h3>Заявки (<?= count($applications) ?>)</h3>
</div>
<div class="card__body" style="padding:0;">
<table class="table">
<thead>
<tr>
<th>ID</th>
<th>Пользователь</th>
<th>Участников</th>
<th>Статус</th>
<th>Дата подачи</th>
<th></th>
</tr>
</thead>
<tbody>
 <?php foreach ($applications as $app): ?>
<?php
    $statusMeta = getApplicationDisplayMeta($app);
    $receiptMeta = getApplicationPaymentReceiptMeta($app);
?>
<tr>
<td data-label="ID">#<?= $app['id'] ?></td>
<td data-label="Пользователь">
<div class="flex items-center gap-md">
 <?php if (!empty($app['avatar_url'])): ?>
<img src="<?= htmlspecialchars($app['avatar_url']) ?>" 
 style="width:32px; height:32px; border-radius:50%; object-fit: cover;">
 <?php else: ?>
<div style="width:32px; height:32px; border-radius:50%; background: #EEF2FF; display: flex; align-items: center; justify-content: center; color: #6366F1;">
<i class="fas fa-user" style="font-size:12px;"></i>
</div>
 <?php endif; ?>
<div>
<div><?= htmlspecialchars(trim(($app['name'] ?? '') . ' ' . ($app['surname'] ?? '')) ?: 'Без имени') ?></div>
 <?php if (!empty($app['email'])): ?>
<div class="text-secondary" style="font-size:12px; margin-top:2px;"><?= htmlspecialchars($app['email']) ?></div>
 <?php endif; ?>
</div>
</div>
</td>
<td data-label="Участников"><?= $app['participants_count'] ?></td>
<td data-label="Статус">
<div class="flex gap-sm" style="flex-wrap:wrap;">
<span class="badge <?= e((string) ($statusMeta['badge_class'] ?? 'badge--secondary')) ?>">
 <?= e((string) ($statusMeta['label'] ?? '—')) ?>
</span>
<span class="badge <?= e((string) ($receiptMeta['badge_class'] ?? 'badge--secondary')) ?>">
 <?= e((string) ($receiptMeta['label'] ?? '—')) ?>
</span>
</div>
</td>
<td data-label="Дата подачи"><?= date('d.m.Y H:i', strtotime($app['created_at'])) ?></td>
<td data-label="Действия">
<?php if (!empty($receiptMeta['file_url'])): ?>
<a href="<?= e((string) $receiptMeta['file_url']) ?>" target="_blank" class="btn btn--ghost btn--sm">
<i class="fas fa-receipt"></i>
</a>
<?php endif; ?>
<a href="/admin/application/<?= $app['id'] ?>" class="btn btn--ghost btn--sm">
<i class="fas fa-eye"></i>
</a>
</td>
</tr>
 <?php endforeach; ?>
                
 <?php if (empty($applications)): ?>
<tr>
<td colspan="6" class="text-center text-secondary" style="padding:40px;">
 Заявок пока нет
</td>
</tr>
 <?php endif; ?>
</tbody>
</table>
</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
