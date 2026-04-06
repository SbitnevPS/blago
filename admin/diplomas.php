<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (!isAdmin()) {
    redirect('/admin/login');
}

$admin = getCurrentUser();
$currentPage = 'diplomas';
$pageTitle = 'Дипломы';
$breadcrumb = 'Конкурсы / Дипломы';

$stmt = $pdo->query("SELECT c.id, c.title,
    (SELECT COUNT(*) FROM participants p INNER JOIN applications a ON a.id = p.application_id WHERE a.contest_id = c.id) AS participants_count,
    (SELECT COUNT(*) FROM participant_diplomas pd WHERE pd.contest_id = c.id AND pd.generated_at IS NOT NULL) AS diplomas_count
    FROM contests c ORDER BY c.created_at DESC");
$contests = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card__header"><h3>Конкурсы</h3></div>
    <div class="card__body" style="padding:0;">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Конкурс</th>
                    <th>Участники</th>
                    <th>Сгенерировано</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contests as $contest): ?>
                    <tr>
                        <td><?= (int)$contest['id'] ?></td>
                        <td><?= e($contest['title']) ?></td>
                        <td><?= (int)$contest['participants_count'] ?></td>
                        <td><?= (int)$contest['diplomas_count'] ?></td>
                        <td style="display:flex; gap:8px; flex-wrap:wrap;">
                            <a class="btn btn--secondary btn--sm" href="/admin/diploma-template/<?= (int)$contest['id'] ?>"><i class="fas fa-sliders-h"></i> Настроить диплом</a>
                            <a class="btn btn--ghost btn--sm" href="/admin/participants?contest_id=<?= (int)$contest['id'] ?>"><i class="fas fa-users"></i> Открыть участников</a>
                            <a class="btn btn--ghost btn--sm" href="/admin/diploma-template/<?= (int)$contest['id'] ?>?preview=1" target="_blank" rel="noopener"><i class="fas fa-eye"></i> Предпросмотр</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
