<?php
// admin/participants.php - Список участников
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (!isAdmin()) {
    redirect('/admin/login');
}

check_csrf();

$admin = getCurrentUser();
$currentPage = 'participants';
$pageTitle = 'Участники';
$breadcrumb = 'Конкурсы / Участники';

$contest_id = $_GET['contest_id'] ?? '';

$where = [];
$params = [];

if ($contest_id !== '' && ctype_digit((string) $contest_id)) {
    $where[] = 'a.contest_id = ?';
    $params[] = (int) $contest_id;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("\n    SELECT COUNT(*)\n    FROM participants p\n    INNER JOIN applications a ON p.application_id = a.id\n    $whereClause\n");
$countStmt->execute($params);
$totalParticipants = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalParticipants / $perPage));

$listStmt = $pdo->prepare("\n    SELECT p.id, p.fio, p.age, p.region, p.organization_email, p.created_at, p.application_id,\n           a.status AS application_status, a.allow_edit,\n           c.title AS contest_title,\n           u.email AS applicant_email\n    FROM participants p\n    INNER JOIN applications a ON p.application_id = a.id\n    LEFT JOIN contests c ON a.contest_id = c.id\n    LEFT JOIN users u ON a.user_id = u.id\n    $whereClause\n    ORDER BY p.created_at DESC, p.id DESC\n    LIMIT $perPage OFFSET $offset\n");
$listStmt->execute($params);
$participants = $listStmt->fetchAll();

$contests = $pdo->query('SELECT id, title FROM contests ORDER BY created_at DESC')->fetchAll();

function splitParticipantFio(array $participant): array {
    $fullName = trim((string) ($participant['fio'] ?? ''));
    if ($fullName === '') {
        return ['surname' => '—', 'name' => '—', 'patronymic' => '—'];
    }

    $parts = preg_split('/\s+/u', $fullName) ?: [];
    return [
        'surname' => $parts[0] ?? '—',
        'name' => $parts[1] ?? '—',
        'patronymic' => $parts[2] ?? '—',
    ];
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="card mb-lg">
    <div class="card__body">
        <form method="GET" class="flex gap-md" style="align-items: flex-end; flex-wrap: wrap;">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <div style="min-width: 280px;">
                <label class="form-label">Конкурс</label>
                <select name="contest_id" class="form-select">
                    <option value="">Все конкурсы</option>
                    <?php foreach ($contests as $contest): ?>
                        <option value="<?= (int) $contest['id'] ?>" <?= (string) $contest_id === (string) $contest['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($contest['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn--primary"><i class="fas fa-filter"></i> Применить</button>
            <?php if ($contest_id !== ''): ?>
                <a href="/admin/participants" class="btn btn--ghost">Сбросить</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card__header">
        <h3>Участники (<?= $totalParticipants ?>)</h3>
    </div>
    <div class="card__body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Фамилия</th>
                    <th>Имя</th>
                    <th>Отчество</th>
                    <th>Возраст</th>
                    <th>Email заявки</th>
                    <th>Регион</th>
                    <th>Статус заявки</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($participants as $participant): ?>
                    <?php
                        $fio = splitParticipantFio($participant);
                        $isRevisionState = isset($participant['allow_edit']) && (int) $participant['allow_edit'] === 1 && $participant['application_status'] !== 'approved';
                        $statusMeta = getApplicationStatusMeta($participant['application_status']);
                        $rowStyle = $isRevisionState ? 'background:#FEF9C3;' : ($statusMeta['row_style'] ?? '');
                    ?>
                    <tr style="<?= $rowStyle ?>">
                        <td data-label="Фамилия"><?= htmlspecialchars($fio['surname']) ?></td>
                        <td data-label="Имя"><?= htmlspecialchars($fio['name']) ?></td>
                        <td data-label="Отчество"><?= htmlspecialchars($fio['patronymic']) ?></td>
                        <td data-label="Возраст"><?= (int) ($participant['age'] ?? 0) ?: '—' ?></td>
                        <td data-label="Email заявки"><?= htmlspecialchars($participant['applicant_email'] ?: ($participant['organization_email'] ?: '—')) ?></td>
                        <td data-label="Регион"><?= htmlspecialchars($participant['region'] ?: '—') ?></td>
                        <td data-label="Статус заявки">
                            <span class="badge <?= $isRevisionState ? 'badge--warning' : $statusMeta['badge_class'] ?>">
                                <?= htmlspecialchars($isRevisionState ? 'Требует исправлений' : $statusMeta['label']) ?>
                            </span>
                        </td>
                        <td data-label="Действия" style="display:flex; gap:6px; flex-wrap:wrap;">
                            <a href="/admin/participant/<?= (int) $participant['id'] ?>" class="btn btn--ghost btn--sm" title="Открыть участника"><i class="fas fa-eye"></i></a>
                            <a href="/admin/participant/<?= (int) $participant['id'] ?>#diploma-actions" class="btn btn--primary btn--sm" title="Скачать диплом"><i class="fas fa-download"></i></a>
                            <a href="/admin/participant/<?= (int) $participant['id'] ?>#diploma-actions" class="btn btn--secondary btn--sm" title="Отправить диплом"><i class="fas fa-envelope"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($participants)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-secondary" style="padding: 36px;">Участники не найдены.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
            <div class="flex justify-between items-center" style="padding: 16px 20px; border-top: 1px solid var(--color-border);">
                <div class="text-secondary" style="font-size: 14px;">Страница <?= $page ?> из <?= $totalPages ?></div>
                <div class="flex gap-sm">
                    <?php if ($page > 1): ?>
                        <a class="btn btn--ghost btn--sm" href="?page=<?= $page - 1 ?>&contest_id=<?= urlencode((string) $contest_id) ?>"><i class="fas fa-chevron-left"></i></a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="btn btn--ghost btn--sm" href="?page=<?= $page + 1 ?>&contest_id=<?= urlencode((string) $contest_id) ?>"><i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
