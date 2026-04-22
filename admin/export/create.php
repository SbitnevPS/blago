<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/export-archive.php';

if (!isAdmin()) {
    redirect('/admin/login');
}

check_csrf();

$admin = getCurrentUser();
$currentPage = 'export-archive';
$pageTitle = 'Создание задания';
$breadcrumb = 'Выгрузка рисунков / Создание задания';
$headerBackUrl = '/admin/export';
$headerBackLabel = 'К списку заданий';

$contests = $pdo->query('SELECT id, title FROM contests ORDER BY created_at DESC')->fetchAll() ?: [];
$errors = [];
$initError = null;
if (!exportArchiveEnsureTable($pdo, $initError)) {
    $errors[] = (string) $initError;
}
$summary = null;
$listRows = [];
$showList = false;
$perPageOptions = [10, 25, 50, 100];
$perPage = (int) ($_POST['per_page'] ?? 10);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 10;
}
$page = max(1, (int) ($_POST['page'] ?? 1));

$filters = exportArchiveNormalizeFilters($_POST + $_GET);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'build');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Ошибка безопасности (CSRF).';
    } else {
        if ($initError !== null) {
            $errors[] = (string) $initError;
        }

        $summary = exportArchiveGetSelectionSummary($pdo, $filters);

        if ($action === 'show_list') {
            $showList = true;
            $offset = ($page - 1) * $perPage;
            $listRows = exportArchiveGetSelectionRows($pdo, $filters, $perPage, $offset);
        } elseif ($action === 'create_job') {
            try {
                $jobId = exportArchiveCreateJob($pdo, $filters, (int) getCurrentAdminId());
                @exec('php ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/process-export-archive-jobs.php') . ' > /dev/null 2>&1 &');
                redirect('/admin/export/job/' . $jobId);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

$conditionsText = exportArchiveBuildConditionsText($pdo, $filters);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header"><h3>Фильтры</h3></div>
    <div class="card__body">
        <?php if ($errors): ?>
            <div class="alert alert--error" style="margin-bottom:16px;"><?= htmlspecialchars(implode(' ', $errors), ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" class="grid" style="gap:12px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="search_application_id" id="searchApplicationId" value="<?= (int) $filters['search_application_id'] ?>">
            <input type="hidden" name="participant_id" id="searchParticipantId" value="<?= (int) $filters['participant_id'] ?>">

            <div class="form-group">
                <label class="form-label">Конкурс</label>
                <select name="contest_id" class="form-input">
                    <option value="0">Все конкурсы</option>
                    <?php foreach ($contests as $contest): ?>
                        <option value="<?= (int) $contest['id'] ?>" <?= (int) $filters['contest_id'] === (int) $contest['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $contest['title'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group js-live-search"
                 <?= admin_live_search_attrs([
                     'endpoint' => '/admin/search-applications',
                     'query_param' => 'q',
                     'id_field' => 'id',
                     'primary_template' => '{{id}} · {{contest_title||Без конкурса}}',
                     'secondary_template' => '{{surname+name||Без имени}} · {{email||без email}}',
                     'value_template' => '#{{id}} {{surname+name||}}',
                     'empty_text' => 'Заявки не найдены',
                     'min_length' => 2,
                     'min_length_numeric' => 1,
                     'debounce' => 220,
                 ]) ?>>
                <label class="form-label">Поиск по заявке</label>
                <input type="text" class="form-input" name="search_application_label" value="<?= htmlspecialchars((string) $filters['search_application_label'], ENT_QUOTES, 'UTF-8') ?>" data-live-search-input placeholder="Введите ID или ФИО пользователя">
                <input type="hidden" name="search_application_id" value="<?= (int) $filters['search_application_id'] ?>" data-live-search-hidden data-live-search-hidden-field="id">
                <div class="user-results" data-live-search-results style="display:none;"></div>
            </div>

            <div class="form-group js-live-search"
                 <?= admin_live_search_attrs([
                     'endpoint' => '/admin/search-participants',
                     'query_param' => 'q',
                     'id_field' => 'id',
                     'primary_template' => '{{fio||Без имени}}',
                     'secondary_template' => '#{{public_number||—}} · {{email||без email}}',
                     'value_template' => '#{{id}} {{fio||}}',
                     'empty_text' => 'Участники не найдены',
                     'min_length' => 2,
                     'min_length_numeric' => 1,
                     'debounce' => 220,
                 ]) ?>>
                <label class="form-label">Поиск по участнику</label>
                <input type="text" class="form-input" name="participant_label" value="<?= htmlspecialchars((string) $filters['participant_label'], ENT_QUOTES, 'UTF-8') ?>" data-live-search-input placeholder="Введите номер или ФИО участника">
                <input type="hidden" name="participant_id" value="<?= (int) $filters['participant_id'] ?>" data-live-search-hidden data-live-search-hidden-field="id">
                <div class="user-results" data-live-search-results style="display:none;"></div>
            </div>

            <div>
                <button type="submit" name="action" value="build" class="btn btn--primary">Сформировать список для выгрузки</button>
            </div>
        </form>

        <p class="text-secondary" style="margin-top:12px;">Выборка всегда формируется только по статусу рисунка «Рисунок принят».</p>
    </div>
</div>

<?php if (is_array($summary)): ?>
<div class="card mt-xl">
    <div class="card__header"><h3>Сводка</h3></div>
    <div class="card__body">
        <p><strong>Найдено заявок:</strong> <?= (int) $summary['applications_count'] ?></p>
        <p><strong>Найдено участников:</strong> <?= (int) $summary['participants_count'] ?></p>
        <p><strong>Условия выборки:</strong> <?= htmlspecialchars($conditionsText, ENT_QUOTES, 'UTF-8') ?></p>

        <form method="POST" style="display:flex;gap:12px;flex-wrap:wrap;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="contest_id" value="<?= (int) $filters['contest_id'] ?>">
            <input type="hidden" name="search_application_id" value="<?= (int) $filters['search_application_id'] ?>">
            <input type="hidden" name="search_application_label" value="<?= htmlspecialchars((string) $filters['search_application_label'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="participant_id" value="<?= (int) $filters['participant_id'] ?>">
            <input type="hidden" name="participant_label" value="<?= htmlspecialchars((string) $filters['participant_label'], ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" name="action" value="show_list" class="btn btn--secondary">Показать сформированный список</button>
            <button type="submit" name="action" value="create_job" class="btn btn--primary">Сохранить и скачать архив</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($showList): ?>
<div class="card mt-xl">
    <div class="card__header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
        <h3>Участники</h3>
        <form method="POST" style="display:flex;align-items:center;gap:8px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="show_list">
            <input type="hidden" name="contest_id" value="<?= (int) $filters['contest_id'] ?>">
            <input type="hidden" name="search_application_id" value="<?= (int) $filters['search_application_id'] ?>">
            <input type="hidden" name="search_application_label" value="<?= htmlspecialchars((string) $filters['search_application_label'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="participant_id" value="<?= (int) $filters['participant_id'] ?>">
            <input type="hidden" name="participant_label" value="<?= htmlspecialchars((string) $filters['participant_label'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="page" value="1">
            <label for="perPage" class="text-secondary">На странице:</label>
            <select id="perPage" name="per_page" class="form-input" onchange="this.form.submit()">
                <?php foreach ($perPageOptions as $option): ?>
                    <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="card__body" style="padding:0;">
        <table class="table">
            <thead>
                <tr>
                    <th>ID участника</th>
                    <th>ФИО</th>
                    <th>Возраст</th>
                    <th>Заявка</th>
                    <th>Email</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($listRows as $row): ?>
                <tr>
                    <td>#<?= (int) $row['id'] ?></td>
                    <td><strong><?= htmlspecialchars((string) ($row['fio'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td><?= (int) ($row['age'] ?? 0) ?></td>
                    <td>#<?= (int) ($row['application_id'] ?? 0) ?></td>
                    <td><strong><?= htmlspecialchars((string) ($row['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$listRows): ?>
                <tr><td colspan="5" class="text-center text-secondary" style="padding:24px;">Нет данных для отображения.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php
    $totalItems = (int) ($summary['participants_count'] ?? 0);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    ?>
    <?php if ($totalPages > 1): ?>
    <div class="card__body" style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="show_list">
                <input type="hidden" name="contest_id" value="<?= (int) $filters['contest_id'] ?>">
                <input type="hidden" name="search_application_id" value="<?= (int) $filters['search_application_id'] ?>">
                <input type="hidden" name="search_application_label" value="<?= htmlspecialchars((string) $filters['search_application_label'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="participant_id" value="<?= (int) $filters['participant_id'] ?>">
                <input type="hidden" name="participant_label" value="<?= htmlspecialchars((string) $filters['participant_label'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="per_page" value="<?= $perPage ?>">
                <input type="hidden" name="page" value="<?= $i ?>">
                <button class="btn <?= $i === $page ? 'btn--primary' : 'btn--secondary' ?> btn--sm" type="submit"><?= $i ?></button>
            </form>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script src="/js/admin-live-search.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
