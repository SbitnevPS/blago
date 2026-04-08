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
    $where[] = '(p.fio LIKE ? OR p.region LIKE ? OR u.email LIKE ?)';
    $searchTerm = '%' . $participantQuery . '%';
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

$listStmt = $pdo->prepare("\n    SELECT p.id, p.fio, p.age, p.region, p.organization_email, p.created_at, p.application_id,\n           a.status AS application_status, a.allow_edit,\n           c.title AS contest_title,\n           u.email AS applicant_email\n    FROM participants p\n    INNER JOIN applications a ON p.application_id = a.id\n    LEFT JOIN contests c ON a.contest_id = c.id\n    LEFT JOIN users u ON a.user_id = u.id\n    $whereClause\n    ORDER BY p.created_at DESC, p.id DESC\n    LIMIT $perPage OFFSET $offset\n");
$listStmt->execute($params);
$participants = $listStmt->fetchAll();

$contests = $pdo->query('SELECT id, title FROM contests ORDER BY created_at DESC')->fetchAll();

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
            <div style="flex:1; min-width: 260px; max-width: 420px; position:relative;">
                <label class="form-label">Поиск по участнику</label>
                <input
                    type="text"
                    name="participant_query"
                    id="participantSearchInput"
                    class="form-input"
                    placeholder="ФИО участника, регион или email заявителя"
                    value="<?= htmlspecialchars($participantQuery) ?>"
                    autocomplete="off">
                <input type="hidden" name="participant_id" id="participantId" value="<?= (int) $participantId ?>">
                <div id="participantSearchResults" class="user-results"></div>
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
    <div class="card__body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>ФИО участника</th>
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
                        $isRevisionState = isset($participant['allow_edit']) && (int) $participant['allow_edit'] === 1 && $participant['application_status'] !== 'approved';
                        $statusMeta = getApplicationStatusMeta($participant['application_status']);
                        $rowStyle = $isRevisionState ? 'background:#FEF9C3;' : ($statusMeta['row_style'] ?? '');
                    ?>
                    <tr style="<?= $rowStyle ?>">
                        <td data-label="ФИО участника"><?= htmlspecialchars($participant['fio'] ?: '—') ?></td>
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
                        <td colspan="6" class="text-center text-secondary" style="padding: 36px;">Участники не найдены.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

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

<script>
(() => {
    const input = document.getElementById('participantSearchInput');
    const hiddenInput = document.getElementById('participantId');
    const results = document.getElementById('participantSearchResults');
    if (!input || !hiddenInput || !results) return;

    let timer = null;

    const hideResults = () => {
        results.style.display = 'none';
        results.innerHTML = '';
    };

    const renderItems = (items) => {
        if (!Array.isArray(items) || items.length === 0) {
            results.innerHTML = '<div class="user-results__empty">Ничего не найдено</div>';
            results.style.display = 'block';
            return;
        }

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');

        results.innerHTML = items.map((item) => {
            const fullName = `${item.fio || ''}`.trim() || 'Без имени';
            const region = item.region ? `Регион: ${item.region}` : 'Регион: —';
            const email = item.email || 'Email не указан';
            const safeName = escapeHtml(fullName);
            const safeRegion = escapeHtml(region);
            const safeEmail = escapeHtml(email);
            return `
                <button type="button" class="user-results__item" data-id="${item.id}" data-name="${safeName}">
                    <div class="user-results__name">${safeName}</div>
                    <div class="user-results__email">${safeRegion} · ${safeEmail}</div>
                </button>
            `;
        }).join('');
        results.style.display = 'block';
    };

    input.addEventListener('input', () => {
        hiddenInput.value = '';
        const query = input.value.trim();
        if (timer) clearTimeout(timer);
        if (query.length < 2) {
            hideResults();
            return;
        }

        timer = setTimeout(async () => {
            try {
                const response = await fetch(`/admin/search-participants.php?q=${encodeURIComponent(query)}`);
                const data = await response.json();
                renderItems(data);
            } catch (error) {
                hideResults();
            }
        }, 220);
    });

    results.addEventListener('click', (event) => {
        const item = event.target.closest('.user-results__item');
        if (!item) return;
        hiddenInput.value = item.dataset.id || '';
        input.value = item.dataset.name || '';
        hideResults();
    });

    document.addEventListener('click', (event) => {
        if (!results.contains(event.target) && event.target !== input) {
            hideResults();
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
