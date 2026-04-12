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
$participantQuery = trim((string) ($_GET['participant_query'] ?? ''));
$participantId = (int) ($_GET['participant_id'] ?? 0);

$where = [];
$params = [];

if ($contest_id !== '' && ctype_digit((string) $contest_id)) {
    $where[] = 'a.contest_id = ?';
    $params[] = (int) $contest_id;
}

if ($participantId > 0) {
    $where[] = 'p.id = ?';
    $params[] = $participantId;
} elseif ($participantQuery !== '') {
    $where[] = '(p.fio LIKE ? OR p.region LIKE ? OR u.email LIKE ? OR CAST(p.id AS CHAR) LIKE ?)';
    $searchTerm = '%' . $participantQuery . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("\n    SELECT COUNT(*)\n    FROM participants p\n    INNER JOIN applications a ON p.application_id = a.id\n    $whereClause\n");
$countStmt->execute($params);
$totalParticipants = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalParticipants / $perPage));

$listStmt = $pdo->prepare("\n    SELECT p.id, p.fio, p.age, p.region, p.organization_email, p.created_at, p.application_id, p.drawing_file,\n           a.status AS application_status, a.allow_edit,\n           c.title AS contest_title,\n           u.email AS applicant_email\n    FROM participants p\n    INNER JOIN applications a ON p.application_id = a.id\n    LEFT JOIN contests c ON a.contest_id = c.id\n    LEFT JOIN users u ON a.user_id = u.id\n    $whereClause\n    ORDER BY p.created_at DESC, p.id DESC\n    LIMIT $perPage OFFSET $offset\n");
$listStmt->execute($params);
$participants = $listStmt->fetchAll();

$contests = $pdo->query('SELECT id, title FROM contests ORDER BY created_at DESC')->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="card card--allow-overflow mb-lg">
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
            <div
                style="flex:1; min-width: 260px; max-width: 420px; position:relative;"
                <?= admin_live_search_attrs([
                    'endpoint' => '/admin/search-participants',
                    'primary_template' => '#{{id}} · {{fio||Без имени}}',
                    'secondary_template' => 'Регион: {{region||—}} · {{email||Email не указан}}',
                    'value_template' => '#{{id}} · {{fio||Без имени}}',
                    'min_length' => 2,
                    'min_length_numeric' => 1,
                    'debounce' => 220,
                ]) ?>>
                <label class="form-label">Поиск по участнику</label>
                <input
                    type="text"
                    name="participant_query"
                    id="participantSearchInput"
                    data-live-search-input
                    class="form-input"
                    placeholder="ID, ФИО участника, регион или email заявителя"
                    value="<?= htmlspecialchars($participantQuery) ?>"
                    autocomplete="off">
                <input type="hidden" name="participant_id" id="participantId" data-live-search-hidden value="<?= (int) $participantId ?>">
                <div id="participantSearchResults" class="user-results" data-live-search-results></div>
            </div>
            <button type="submit" class="btn btn--primary"><i class="fas fa-filter"></i> Применить</button>
            <?php if ($contest_id !== '' || $participantQuery !== '' || $participantId > 0): ?>
                <a href="/admin/participants" class="btn btn--ghost">Сбросить</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card__header">
        <h3>Участники (<?= $totalParticipants ?>)</h3>
    </div>
    <div class="card__body">
        <?php if (empty($participants)): ?>
            <div class="text-center text-secondary" style="padding: 36px;">Участники не найдены.</div>
        <?php else: ?>
        <div class="admin-list-cards">
            <?php foreach ($participants as $participant): ?>
                <?php
                    $statusMeta = getApplicationDisplayMeta([
                        'status' => (string) ($participant['application_status'] ?? 'draft'),
                        'allow_edit' => (int) ($participant['allow_edit'] ?? 0),
                    ]);
                    $drawingUrl = !empty($participant['drawing_file'])
                        ? getParticipantDrawingPreviewWebPath((string) ($participant['applicant_email'] ?? ''), (string) $participant['drawing_file'])
                        : '';
                    $isDiplomaEnabled = (string) ($statusMeta['status_code'] ?? '') === 'approved';
                ?>
                <article class="admin-list-card admin-list-card--participant">
                    <div class="admin-list-card__header">
                        <div class="admin-list-card__title-wrap" style="display:flex; gap:12px; align-items:center;">
                            <?php if ($drawingUrl !== ''): ?>
                                <img src="<?= htmlspecialchars($drawingUrl) ?>" alt="Рисунок участника" loading="lazy" class="admin-list-card__thumb">
                            <?php else: ?>
                                <div class="admin-list-card__thumb admin-list-card__thumb--empty"><i class="fas fa-image"></i></div>
                            <?php endif; ?>
                            <div>
                                <h4 class="admin-list-card__title"><?= htmlspecialchars($participant['fio'] ?: 'Без имени') ?></h4>
                                <div class="admin-list-card__subtitle">#<?= (int) $participant['id'] ?> · <?= htmlspecialchars($participant['contest_title'] ?: 'Конкурс не указан') ?></div>
                            </div>
                        </div>
                        <span class="badge <?= e((string) ($statusMeta['badge_class'] ?? 'badge--secondary')) ?>">
                            <?= e((string) ($statusMeta['label'] ?? '—')) ?>
                        </span>
                    </div>
                    <div class="admin-list-card__meta">
                        <span><strong>Возраст:</strong> <?= (int) ($participant['age'] ?? 0) ?: '—' ?></span>
                        <span><strong>Регион:</strong> <?= htmlspecialchars($participant['region'] ?: '—') ?></span>
                    </div>
                    <div class="admin-list-card__actions">
                        <a href="/admin/participant/<?= (int) $participant['id'] ?>" class="btn btn--ghost btn--sm"><i class="fas fa-eye"></i> Открыть</a>
                        <?php if ($isDiplomaEnabled): ?>
                            <a href="/admin/participant/<?= (int) $participant['id'] ?>#diploma-actions" class="btn btn--primary btn--sm"><i class="fas fa-award"></i> Диплом</a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
            <div class="flex justify-between items-center" style="padding: 16px 20px; border-top: 1px solid var(--color-border);">
                <div class="text-secondary" style="font-size: 14px;">Страница <?= $page ?> из <?= $totalPages ?></div>
                <div class="flex gap-sm">
                    <?php if ($page > 1): ?>
                        <a class="btn btn--ghost btn--sm" href="?page=<?= $page - 1 ?>&contest_id=<?= urlencode((string) $contest_id) ?>&participant_id=<?= (int) $participantId ?>&participant_query=<?= urlencode($participantQuery) ?>"><i class="fas fa-chevron-left"></i></a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="btn btn--ghost btn--sm" href="?page=<?= $page + 1 ?>&contest_id=<?= urlencode((string) $contest_id) ?>&participant_id=<?= (int) $participantId ?>&participant_query=<?= urlencode($participantQuery) ?>"><i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
