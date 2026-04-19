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
$ageCategory = trim((string) ($_GET['age_category'] ?? ''));
$showArchived = (int) ($_GET['show_archived'] ?? 0) === 1;
$sameParticipantsThreshold = (int) ($_GET['same_participants'] ?? 0);
$supportsContestArchive = contest_archive_column_exists();

$sameParticipantsOptions = [
    0 => 'Все участники',
    2 => 'От 2-х',
    3 => 'От 3-х',
];

if (!array_key_exists($sameParticipantsThreshold, $sameParticipantsOptions)) {
    $sameParticipantsThreshold = 0;
}

$hasParticipantOvzColumn = false;
try {
    $hasParticipantOvzColumn = (bool) $pdo->query("SHOW COLUMNS FROM participants LIKE 'has_ovz'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
}

$participantCategoryFilters = [
    '' => ['label' => 'Все', 'type' => 'default'],
    '5-7' => ['label' => '5-7 лет', 'type' => 'age'],
    '8-10' => ['label' => '8-10 лет', 'type' => 'age'],
    '11-13' => ['label' => '11-13 лет', 'type' => 'age'],
    '14-17' => ['label' => '14-17 лет', 'type' => 'age'],
    'ovz' => ['label' => 'С ОВЗ', 'type' => 'special'],
];

if (!array_key_exists($ageCategory, $participantCategoryFilters)) {
    $ageCategory = '';
}

$isDocxExportRequest = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (string) ($_POST['action'] ?? '') === 'export_participants_docx';

if ($isDocxExportRequest) {
    $exportContestId = max(0, (int) ($_POST['contest_id'] ?? 0));
    $onlyAccepted = (int) ($_POST['only_accepted'] ?? 0) === 1;

    $contestTitle = 'Все конкурсы';
    if ($exportContestId > 0) {
        $titleStmt = $pdo->prepare('SELECT title FROM contests WHERE id = ? LIMIT 1');
        $titleStmt->execute([$exportContestId]);
        $contestTitle = (string) ($titleStmt->fetchColumn() ?: ('Конкурс #' . $exportContestId));
    }

    $rows = fetchParticipantsRowsForDocxExport([
        'contest_id' => $exportContestId,
        'only_accepted' => $onlyAccepted,
    ]);

    $title = 'Участники: ' . $contestTitle . ($onlyAccepted ? ' (только принятые рисунки)' : '');
    $bytes = buildParticipantsDocxBytes($rows, $title);

    $safeContestSlug = preg_replace('/[^a-zA-Z0-9_\\-]+/', '_', (string) ($exportContestId > 0 ? $exportContestId : 'all'));
    $fileName = 'participants_' . $safeContestSlug . ($onlyAccepted ? '_accepted' : '') . '_' . date('Ymd_His') . '.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

$where = [];
$params = [];
$duplicateWhere = ["TRIM(COALESCE(p2.fio, '')) <> ''"];
$duplicateParams = [];

if ($contest_id !== '' && ctype_digit((string) $contest_id)) {
    $where[] = 'a.contest_id = ?';
    $params[] = (int) $contest_id;
    $duplicateWhere[] = 'a2.contest_id = ?';
    $duplicateParams[] = (int) $contest_id;
}

if ($supportsContestArchive && !$showArchived) {
    $where[] = 'COALESCE(c.is_archived, 0) = 0';
    $duplicateWhere[] = 'COALESCE(c2.is_archived, 0) = 0';
}

if ($participantId > 0) {
    $where[] = 'p.id = ?';
    $params[] = $participantId;
} elseif ($participantQuery !== '') {
    $where[] = '(p.fio LIKE ? OR p.region LIKE ? OR u.email LIKE ? OR p.public_number LIKE ? OR CAST(p.id AS CHAR) LIKE ?)';
    $searchTerm = '%' . $participantQuery . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($ageCategory !== '') {
    switch ($ageCategory) {
        case '5-7':
            $where[] = 'p.age BETWEEN 5 AND 7';
            $duplicateWhere[] = 'p2.age BETWEEN 5 AND 7';
            break;
        case '8-10':
            $where[] = 'p.age BETWEEN 8 AND 10';
            $duplicateWhere[] = 'p2.age BETWEEN 8 AND 10';
            break;
        case '11-13':
            $where[] = 'p.age BETWEEN 11 AND 13';
            $duplicateWhere[] = 'p2.age BETWEEN 11 AND 13';
            break;
        case '14-17':
            $where[] = 'p.age BETWEEN 14 AND 17';
            $duplicateWhere[] = 'p2.age BETWEEN 14 AND 17';
            break;
        case 'ovz':
            if ($hasParticipantOvzColumn) {
                $where[] = 'COALESCE(p.has_ovz, 0) = 1';
                $duplicateWhere[] = 'COALESCE(p2.has_ovz, 0) = 1';
            } else {
                $ovzPatterns = ['%овз%', '%ограничен%', '%инвалид%'];
                $where[] = '('
                    . 'LOWER(COALESCE(a.recommendations_wishes, \'\')) LIKE ? OR '
                    . 'LOWER(COALESCE(a.source_info, \'\')) LIKE ? OR '
                    . 'LOWER(COALESCE(a.colleagues_info, \'\')) LIKE ? OR '
                    . 'LOWER(COALESCE(p.organization_name, \'\')) LIKE ? OR '
                    . 'LOWER(COALESCE(p.organization_address, \'\')) LIKE ?'
                    . ')';
                $params[] = $ovzPatterns[0];
                $params[] = $ovzPatterns[1];
                $params[] = $ovzPatterns[2];
                $params[] = $ovzPatterns[0];
                $params[] = $ovzPatterns[1];
                $duplicateWhere[] = '('
                    . 'LOWER(COALESCE(a2.recommendations_wishes, \'\')) LIKE ? OR '
                    . 'LOWER(COALESCE(a2.source_info, \'\')) LIKE ? OR '
                    . 'LOWER(COALESCE(a2.colleagues_info, \'\')) LIKE ? OR '
                    . 'LOWER(COALESCE(p2.organization_name, \'\')) LIKE ? OR '
                    . 'LOWER(COALESCE(p2.organization_address, \'\')) LIKE ?'
                    . ')';
                $duplicateParams[] = $ovzPatterns[0];
                $duplicateParams[] = $ovzPatterns[1];
                $duplicateParams[] = $ovzPatterns[2];
                $duplicateParams[] = $ovzPatterns[0];
                $duplicateParams[] = $ovzPatterns[1];
            }
            break;
    }
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$duplicateWhereClause = 'WHERE ' . implode(' AND ', $duplicateWhere);
$duplicateJoin = '';
$duplicateSelect = '1 AS same_participants_count,';

if ($sameParticipantsThreshold > 0) {
    $duplicateJoin = "\n    INNER JOIN (\n        SELECT LOWER(TRIM(p2.fio)) AS fio_key, COUNT(*) AS same_participants_count\n        FROM participants p2\n        INNER JOIN applications a2 ON p2.application_id = a2.id\n        LEFT JOIN contests c2 ON a2.contest_id = c2.id\n        $duplicateWhereClause\n        GROUP BY LOWER(TRIM(p2.fio))\n        HAVING COUNT(*) >= ?\n    ) same_participants ON same_participants.fio_key = LOWER(TRIM(p.fio))\n";
    $duplicateSelect = 'same_participants.same_participants_count,';
    $duplicateParams[] = $sameParticipantsThreshold;
}

$buildParticipantsUrl = static function (array $overrides = []) use ($contest_id, $participantQuery, $participantId, $ageCategory, $sameParticipantsThreshold, $showArchived): string {
    $query = [
        'contest_id' => $contest_id !== '' ? (string) $contest_id : null,
        'participant_query' => $participantQuery !== '' ? $participantQuery : null,
        'participant_id' => $participantId > 0 ? $participantId : null,
        'age_category' => $ageCategory !== '' ? $ageCategory : null,
        'same_participants' => $sameParticipantsThreshold > 0 ? (string) $sameParticipantsThreshold : null,
        'show_archived' => $showArchived ? '1' : null,
        'page' => null,
    ];

    foreach ($overrides as $key => $value) {
        $query[$key] = $value;
    }

    $query = array_filter($query, static fn ($value) => $value !== null && $value !== '');
    $queryString = http_build_query($query);

    return '/admin/participants' . ($queryString !== '' ? '?' . $queryString : '');
};

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$participantsReturnUrl = (string) ($_SERVER['REQUEST_URI'] ?? '/admin/participants');

$countStmt = $pdo->prepare("\n    SELECT COUNT(*)\n    FROM participants p\n    INNER JOIN applications a ON p.application_id = a.id\n    LEFT JOIN contests c ON a.contest_id = c.id\n    $duplicateJoin    $whereClause\n");
$countStmt->execute(array_merge($duplicateParams, $params));
$totalParticipants = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalParticipants / $perPage));

$ovzSelect = $hasParticipantOvzColumn ? 'COALESCE(p.has_ovz, 0) AS has_ovz,' : '0 AS has_ovz,';
$listStmt = $pdo->prepare("\n    SELECT p.id, p.public_number, p.fio, p.age, p.region, p.organization_email, p.created_at, p.application_id, p.drawing_file,\n           $ovzSelect\n           $duplicateSelect\n           a.status AS application_status, a.allow_edit,\n           c.title AS contest_title, COALESCE(c.is_archived, 0) AS contest_is_archived,\n           u.email AS applicant_email\n    FROM participants p\n    INNER JOIN applications a ON p.application_id = a.id\n    LEFT JOIN contests c ON a.contest_id = c.id\n    LEFT JOIN users u ON a.user_id = u.id\n    $duplicateJoin    $whereClause\n    ORDER BY p.fio ASC, p.id DESC\n    LIMIT $perPage OFFSET $offset\n");
$listStmt->execute(array_merge($duplicateParams, $params));
$participants = $listStmt->fetchAll();

$contests = $pdo->query('SELECT id, title, COALESCE(is_archived, 0) AS is_archived FROM contests ORDER BY COALESCE(is_archived, 0) ASC, created_at DESC')->fetchAll();

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
                            <?= htmlspecialchars($contest['title']) ?><?= (int) ($contest['is_archived'] ?? 0) === 1 ? ' · Архивный' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="contest-archive-filter-toggle" for="participantsShowArchived">
                <input type="checkbox" name="show_archived" id="participantsShowArchived" value="1" <?= $showArchived ? 'checked' : '' ?>>
                <span>Показать архивные конкурсы</span>
            </label>
            <div style="min-width: 220px;">
                <label class="form-label">Показать одинаковых участников</label>
                <select name="same_participants" class="form-select">
                    <?php foreach ($sameParticipantsOptions as $optionValue => $optionLabel): ?>
                        <option value="<?= (int) $optionValue ?>" <?= $sameParticipantsThreshold === (int) $optionValue ? 'selected' : '' ?>>
                            <?= e($optionLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div
                style="flex:1; min-width: 260px; max-width: 420px; position:relative;"
                <?= admin_live_search_attrs([
                    'endpoint' => '/admin/search-participants',
                    'primary_template' => '#{{public_number||id}} · {{fio||Без имени}}',
                    'secondary_template' => 'Регион: {{region||—}} · {{email||Email не указан}}',
                    'value_template' => '#{{public_number||id}} · {{fio||Без имени}}',
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
                    placeholder="Номер/ID участника, ФИО, регион или email заявителя"
                    value="<?= htmlspecialchars($participantQuery) ?>"
                    autocomplete="off">
                <input type="hidden" name="participant_id" id="participantId" data-live-search-hidden value="<?= (int) $participantId ?>">
                <div id="participantSearchResults" class="user-results" data-live-search-results></div>
            </div>
            <button type="submit" class="btn btn--primary"><i class="fas fa-filter"></i> Применить</button>
            <?php if ($contest_id !== '' || $participantQuery !== '' || $participantId > 0 || $ageCategory !== '' || $sameParticipantsThreshold > 0 || $showArchived): ?>
                <a href="/admin/participants" class="btn btn--ghost">Сбросить</a>
            <?php endif; ?>
        </form>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;">
            <?php foreach ($participantCategoryFilters as $filterKey => $filterMeta): ?>
                <?php
                    $isActiveFilter = $ageCategory === $filterKey;
                    $buttonStyle = $isActiveFilter
                        ? 'background:#0F766E;color:#fff;border-color:#0F766E;'
                        : 'background:#fff;color:#0F172A;border-color:#CBD5E1;';
                ?>
                <a
                    href="<?= e($buildParticipantsUrl(['age_category' => $filterKey !== '' ? $filterKey : null])) ?>"
                    class="btn btn--sm"
                    style="<?= $buttonStyle ?>">
                    <?= e((string) $filterMeta['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="form-hint" style="margin-top:8px;">
            <?= $hasParticipantOvzColumn
                ? 'Фильтр `С ОВЗ` работает по отдельной отметке участника в заявке.'
                : 'Фильтр `С ОВЗ` ищет отметки в тексте заявки по ключевым словам: `ОВЗ`, `ограничен`, `инвалид`.' ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card__header">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
	            <h3 style="margin:0;">Участники (<?= $totalParticipants ?>)</h3>
	            <button type="button" class="btn btn--secondary btn--sm" id="exportParticipantsDocxBtn">
	                <i class="fas fa-file-word"></i> Скачать список участников
	            </button>
	        </div>
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
                    $isArchivedContest = (int) ($participant['contest_is_archived'] ?? 0) === 1;
                    $participantAge = (int) ($participant['age'] ?? 0);
                    $participantAgeCategory = getParticipantAgeCategoryLabel($participantAge);
                    $drawingUrl = !empty($participant['drawing_file'])
                        ? getParticipantDrawingPreviewWebPath((string) ($participant['applicant_email'] ?? ''), (string) $participant['drawing_file'])
                        : '';
                    $isDiplomaEnabled = (string) ($statusMeta['status_code'] ?? '') === 'approved';
                    $sameParticipantsCount = max(1, (int) ($participant['same_participants_count'] ?? 1));
                ?>
                <article class="admin-list-card admin-list-card--participant <?= $isArchivedContest ? 'admin-list-card--archived' : '' ?>">
                    <div class="admin-list-card__header">
                        <div class="admin-list-card__title-wrap" style="display:flex; gap:12px; align-items:center;">
                            <?php if ($drawingUrl !== ''): ?>
                                <img src="<?= htmlspecialchars($drawingUrl) ?>" alt="Рисунок участника" loading="lazy" class="admin-list-card__thumb">
                            <?php else: ?>
                                <div class="admin-list-card__thumb admin-list-card__thumb--empty"><i class="fas fa-image"></i></div>
                            <?php endif; ?>
                            <div>
                                <h4 class="admin-list-card__title"><?= htmlspecialchars($participant['fio'] ?: 'Без имени') ?></h4>
                                <div class="admin-list-card__subtitle">
                                    #<?= e(getParticipantDisplayNumber($participant)) ?>
                                    ·
                                    <a href="/admin/application/<?= (int) ($participant['application_id'] ?? 0) ?>?return_url=<?= urlencode($participantsReturnUrl) ?>" style="color:#7C3AED;text-decoration:none;">
                                        Заявка #<?= (int) ($participant['application_id'] ?? 0) ?>
                                    </a>
                                    · <?= htmlspecialchars($participant['contest_title'] ?: 'Конкурс не указан') ?>
                                </div>
                            </div>
                        </div>
                        <div class="admin-list-card__statuses">
                            <span class="badge <?= e((string) ($statusMeta['badge_class'] ?? 'badge--secondary')) ?>">
                                <?= e((string) ($statusMeta['label'] ?? '—')) ?>
                            </span>
                            <?php if ($sameParticipantsCount >= 2): ?>
                                <span class="badge badge--secondary">Совпадений: <?= $sameParticipantsCount ?></span>
                            <?php endif; ?>
                            <?php if ($isArchivedContest): ?>
                                <span class="badge badge--secondary">Архивный конкурс</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="admin-list-card__meta">
                        <span><strong>Возраст:</strong> <?= $participantAge ?: '—' ?></span>
                        <?php if ($participantAgeCategory !== ''): ?>
                            <span><strong>Категория:</strong> <?= e($participantAgeCategory) ?></span>
                        <?php endif; ?>
                        <span><strong>ОВЗ:</strong> <?= !empty($participant['has_ovz']) ? 'Да' : 'Нет' ?></span>
                        <span><strong>Регион:</strong> <?= htmlspecialchars($participant['region'] ?: '—') ?></span>
                    </div>
                    <div class="admin-list-card__actions">
                        <a href="/admin/participant/<?= (int) $participant['id'] ?>?return_url=<?= urlencode($participantsReturnUrl) ?>" class="btn btn--ghost btn--sm"><i class="fas fa-eye"></i> Открыть</a>
                        <?php if ($isDiplomaEnabled): ?>
                            <a href="/admin/participant/<?= (int) $participant['id'] ?>?return_url=<?= urlencode($participantsReturnUrl) ?>#diploma-actions" class="btn btn--primary btn--sm"><i class="fas fa-award"></i> Диплом</a>
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
                        <a class="btn btn--ghost btn--sm" href="<?= e($buildParticipantsUrl(['page' => $page - 1])) ?>"><i class="fas fa-chevron-left"></i></a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="btn btn--ghost btn--sm" href="<?= e($buildParticipantsUrl(['page' => $page + 1])) ?>"><i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal" id="participantsDocxModal" aria-hidden="true">
    <div class="modal__content" style="max-width:720px;">
        <div class="modal__header">
            <h3 class="modal__title">Экспорт участников в DOCX</h3>
            <button type="button" class="modal__close" aria-label="Закрыть" id="participantsDocxModalClose">&times;</button>
        </div>
        <div class="modal__body">
            <form method="POST" id="participantsDocxForm" target="_blank">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="export_participants_docx">

                <div class="form-group">
                    <label class="form-label">Конкурс</label>
                    <select name="contest_id" class="form-select" id="participantsDocxContest">
                        <option value="0">Все конкурсы</option>
                        <?php foreach ($contests as $contest): ?>
                        <option value="<?= (int) $contest['id'] ?>" <?= (string) $contest_id === (string) $contest['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $contest['title']) ?><?= (int) ($contest['is_archived'] ?? 0) === 1 ? ' · Архивный' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">Экспортирует участников заявок выбранного конкурса.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Какие работы включить</label>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <label class="btn btn--secondary btn--sm" style="display:inline-flex; align-items:center; gap:8px;">
                            <input type="radio" name="only_accepted" value="0" checked>
                            <span>Все</span>
                        </label>
                        <label class="btn btn--secondary btn--sm" style="display:inline-flex; align-items:center; gap:8px;">
                            <input type="radio" name="only_accepted" value="1">
                            <span>Только принятые рисунки</span>
                        </label>
                    </div>
                    <div class="form-hint">Если выбрать “только принятые”, в таблицу попадут участники, у которых статус работы = “Рисунок принят”.</div>
                </div>
            </form>

            <div id="participantsDocxStatus" class="alert alert--success" style="display:none; margin: 0;">
                Файл сформирован. Можете закрыть окно.
            </div>
        </div>
        <div class="modal__footer" style="display:flex; justify-content:flex-end; gap:8px;">
            <button type="button" class="btn btn--ghost" id="participantsDocxModalCancel">Отмена</button>
            <button type="submit" form="participantsDocxForm" class="btn btn--primary" id="participantsDocxModalSubmit">
                <i class="fas fa-download"></i> Скачать
            </button>
        </div>
    </div>
</div>

<script>
(() => {
    const modal = document.getElementById('participantsDocxModal');
    const openBtn = document.getElementById('exportParticipantsDocxBtn');
    const closeBtn = document.getElementById('participantsDocxModalClose');
    const cancelBtn = document.getElementById('participantsDocxModalCancel');
    const submitBtn = document.getElementById('participantsDocxModalSubmit');
    const form = document.getElementById('participantsDocxForm');
    const status = document.getElementById('participantsDocxStatus');
    if (!modal || !openBtn) return;

    const initialCancelText = cancelBtn?.textContent || 'Отмена';

    const resetUi = () => {
        if (status) status.style.display = 'none';
        if (submitBtn) {
            submitBtn.style.display = '';
            submitBtn.disabled = false;
        }
        if (cancelBtn) {
            cancelBtn.textContent = initialCancelText;
            cancelBtn.disabled = false;
        }
        if (closeBtn) closeBtn.disabled = false;
        if (form) {
            form.querySelectorAll('input, select, textarea, button').forEach((el) => {
                // Keep the footer buttons controlled separately.
                if (el === submitBtn || el === cancelBtn || el === closeBtn) return;
                if (el instanceof HTMLInputElement && el.type === 'hidden') return;
                el.disabled = false;
            });
        }
    };

    const setBusy = (busy) => {
        if (submitBtn) submitBtn.disabled = busy;
        if (cancelBtn) cancelBtn.disabled = busy;
        if (closeBtn) closeBtn.disabled = busy;
        if (form) {
            form.querySelectorAll('input, select, textarea, button').forEach((el) => {
                if (el === submitBtn || el === cancelBtn || el === closeBtn) return;
                // Keep hidden fields (csrf/action) enabled so they are included in FormData.
                if (el instanceof HTMLInputElement && el.type === 'hidden') return;
                el.disabled = busy;
            });
        }
    };

    const close = () => {
        modal.classList.remove('active');
        document.body.style.overflow = '';
        modal.setAttribute('aria-hidden', 'true');
        resetUi();
    };
    const open = () => {
        resetUi();
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        modal.setAttribute('aria-hidden', 'false');
    };

    openBtn.addEventListener('click', open);
    closeBtn?.addEventListener('click', close);
    cancelBtn?.addEventListener('click', close);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) close();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('active')) close();
    });

    const guessFilename = (response) => {
        const raw = response.headers.get('content-disposition') || '';
        // RFC 6266-ish: filename="..."
        const match = raw.match(/filename=\"?([^\";]+)\"?/i);
        return match && match[1] ? match[1] : 'participants.docx';
    };

    const downloadBlob = (blob, filename) => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    };

    form?.addEventListener('submit', async (e) => {
        e.preventDefault();

        resetUi();
        const formData = new FormData(form);
        setBusy(true);
        if (status) {
            status.className = 'alert alert--success';
            status.style.display = 'none';
        }

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            });

            if (!response.ok) {
                const text = (await response.text()).trim();
                throw new Error(text || `HTTP ${response.status}`);
            }

            const blob = await response.blob();
            downloadBlob(blob, guessFilename(response));

            if (status) {
                status.className = 'alert alert--success';
                status.textContent = 'Файл сформирован и скачивание началось. Можете закрыть окно.';
                status.style.display = 'block';
            }
            if (submitBtn) submitBtn.style.display = 'none';
            if (cancelBtn) cancelBtn.textContent = 'Закрыть';
        } catch (error) {
            if (status) {
                status.className = 'alert alert--error';
                const details = (error && error.message) ? String(error.message).trim() : '';
                status.textContent = details !== ''
                    ? `Не удалось сформировать файл: ${details}`
                    : 'Не удалось сформировать файл. Попробуйте ещё раз.';
                status.style.display = 'block';
            }
        } finally {
            setBusy(false);
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
