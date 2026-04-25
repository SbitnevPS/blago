<?php
// admin/participant-view.php - Просмотр участника
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (!isAdmin()) {
    redirect('/admin/login');
}

check_csrf();

$admin = getCurrentUser();
$participantId = (int) ($_GET['id'] ?? 0);
$currentRequestUri = (string) ($_SERVER['REQUEST_URI'] ?? ('/admin/participant/' . $participantId));
$rawParticipantReturnUrl = trim((string) ($_GET['return_url'] ?? ''));
$participantReturnUrl = '/admin/participants';
if ($rawParticipantReturnUrl !== '' && str_starts_with($rawParticipantReturnUrl, '/admin/')) {
    $participantReturnUrl = $rawParticipantReturnUrl;
} else {
    $refererPath = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
    $refererQuery = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_QUERY);
    if ($refererPath !== '' && !str_starts_with($refererPath, '/admin/participant/') && str_starts_with($refererPath, '/admin/')) {
        $participantReturnUrl = $refererPath . ($refererQuery !== '' ? ('?' . $refererQuery) : '');
    }
}

$stmt = $pdo->prepare("\n    SELECT p.*,\n           a.id AS application_id, a.status AS application_status, a.allow_edit, a.parent_fio, a.source_info, a.colleagues_info,\n           c.id AS contest_id, c.title AS contest_title,\n           u.id AS user_id, u.name AS user_name, u.surname AS user_surname, u.patronymic AS user_patronymic, u.email AS user_email,\n           u.organization_region AS user_organization_region, u.organization_name AS user_organization_name, u.organization_address AS user_organization_address, u.user_type AS user_type,\n           w.id AS work_id, w.status AS work_status, w.title AS work_title\n    FROM participants p\n    INNER JOIN applications a ON p.application_id = a.id\n    LEFT JOIN contests c ON a.contest_id = c.id\n    LEFT JOIN users u ON a.user_id = u.id\n    LEFT JOIN works w ON w.participant_id = p.id AND w.application_id = p.application_id\n    WHERE p.id = ?\n");
$stmt->execute([$participantId]);
$participant = $stmt->fetch();

if (!$participant) {
    redirect('/admin/participants');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Ошибка безопасности';
        redirect('/admin/participant/' . $participantId);
    }

	    try {
            if ($_POST['action'] === 'toggle_participant_vk_publication') {
                $workId = (int) ($participant['work_id'] ?? 0);
                if ($workId <= 0) {
                    throw new RuntimeException('Работа участника не найдена.');
                }
                if ((string) ($participant['work_status'] ?? 'pending') !== 'accepted') {
                    throw new RuntimeException('Публикация доступна только для статуса «Рисунок принят».');
                }

                $isPublished = (int) ($_POST['is_published'] ?? 0) === 1;
                if ($isPublished) {
                    $pdo->prepare("UPDATE works SET vk_published_at = NOW(), updated_at = NOW() WHERE id = ? LIMIT 1")->execute([$workId]);
                    $_SESSION['success_message'] = 'Статус публикации: «Опубликовано».';
                } else {
                    $pdo->prepare("UPDATE works SET vk_published_at = NULL, vk_post_id = NULL, vk_post_url = NULL, updated_at = NOW() WHERE id = ? LIMIT 1")->execute([$workId]);
                    $_SESSION['success_message'] = 'Статус публикации: «Не опубликовано».';
                }
                redirect('/admin/participant/' . $participantId);
            }

            if ($_POST['action'] === 'publish_participant_vk') {
                $workId = (int) ($participant['work_id'] ?? 0);
                if ($workId <= 0) {
                    throw new RuntimeException('Работа участника не найдена.');
                }
                if ((string) ($participant['work_status'] ?? 'pending') !== 'accepted') {
                    throw new RuntimeException('Публикация доступна только для статуса «Рисунок принят».');
                }

                ensureVkPublicationSchema();
                $readiness = verifyVkPublicationReadiness(true, true, 'publish_text');
                if (empty($readiness['ok'])) {
                    $issues = $readiness['issues'] ?? [];
                    $message = is_array($issues) && !empty($issues) ? (string) $issues[0] : 'VK не готов к публикации.';
                    throw new RuntimeException($message);
                }

                $filters = normalizeVkTaskFilters([
                    'work_status' => 'accepted',
                    'exclude_vk_published' => 1,
                    'required_data_only' => 1,
                    'work_ids_raw' => (string) $workId,
                ]);
                $preview = buildVkTaskPreview($filters, null);
                if ((int) ($preview['ready_items'] ?? 0) <= 0) {
                    throw new RuntimeException('Нет готовых данных для публикации участника в VK.');
                }

                $taskId = createVkTaskFromPreview(
                    'Публикация участника #' . $participantId . ' от ' . date('d.m.Y H:i'),
                    (int) getCurrentAdminId(),
                    $preview,
                    'immediate'
                );
                $result = publishVkTask($taskId);
                if ((int) ($result['published'] ?? 0) > 0 && (int) ($result['failed'] ?? 0) === 0) {
                    $_SESSION['success_message'] = 'Публикация в VK выполнена успешно.';
                } else {
                    throw new RuntimeException(trim((string) ($result['error'] ?? '')) ?: 'Публикация в VK завершилась с ошибкой.');
                }
                redirect('/admin/participant/' . $participantId);
            }

	        if ($_POST['action'] === 'update_participant_profile') {
            $fio = trim((string) ($_POST['fio'] ?? ''));
            $age = (int) ($_POST['age'] ?? 0);
            if ($fio === '') {
                throw new RuntimeException('Укажите ФИО участника.');
            }
            if ($age < 5 || $age > 17) {
                throw new RuntimeException('Возраст участника должен быть от 5 до 17 лет.');
            }

            $pdo->prepare("UPDATE participants SET fio = ?, age = ? WHERE id = ? LIMIT 1")
                ->execute([$fio, $age, $participantId]);

            // Keep work title in sync when it matches a trivial placeholder.
            $pdo->prepare("
                UPDATE works
                SET title = ?
                WHERE participant_id = ?
                  AND application_id = ?
                  AND (title IS NULL OR TRIM(title) = '' OR title LIKE 'Рисунок участника #%')
            ")->execute([$fio, $participantId, (int) ($participant['application_id'] ?? 0)]);

            if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (string) ($_POST['ajax'] ?? '') === '1') {
                jsonResponse([
                    'success' => true,
                    'fio' => $fio,
                    'age' => $age,
                ]);
            }

	            $_SESSION['success_message'] = 'Данные участника обновлены';
	            redirect('/admin/participant/' . $participantId);
	        }

	        if ($_POST['action'] === 'generate_diploma') {
	            $workId = (int) ($participant['work_id'] ?? 0);
	            if ($workId <= 0) {
	                throw new RuntimeException('У участника нет работы для формирования диплома.');
	            }
	            if (!canShowIndividualDiplomaActions(['status' => (string) ($participant['work_status'] ?? 'pending')])) {
	                throw new RuntimeException('Для этой работы диплом недоступен.');
	            }
	            $requestedTemplateId = (int) ($_POST['template_id'] ?? 0);
	            $diploma = generateWorkDiploma($workId, false, $requestedTemplateId > 0 ? $requestedTemplateId : null);
	            $template = getDiplomaTemplateById((int) ($diploma['template_id'] ?? 0));
	            $templateTitle = trim((string) ($template['title'] ?? ''));
	            $_SESSION['success_message'] = 'Диплом сформирован' . ($templateTitle !== '' ? ': ' . $templateTitle : '');
	            redirect('/admin/participant/' . $participantId . '#diploma-actions');
	        }

	        if ($_POST['action'] === 'download_diploma') {
	            if (!canShowIndividualDiplomaActions(['status' => (string) ($participant['work_status'] ?? 'pending')])) {
	                throw new RuntimeException('Для этой работы диплом недоступен.');
	            }
	            $requestedTemplateId = (int) ($_POST['template_id'] ?? 0);
	            $diploma = findExistingWorkDiploma((int) ($participant['work_id'] ?? 0), $requestedTemplateId > 0 ? $requestedTemplateId : null);
	            if (!$diploma) {
	                throw new RuntimeException('Выбранный вариант диплома ещё не сформирован. Сначала нажмите «Сформировать диплом».');
	            }
	            $file = ROOT_PATH . '/' . $diploma['file_path'];
	            header('Content-Type: application/pdf');
	            header('Content-Disposition: attachment; filename="diploma_participant_' . $participantId . '.pdf"');
            readfile($file);
            exit;
        }

	        if ($_POST['action'] === 'send_diploma_email') {
	            if (!canShowIndividualDiplomaActions(['status' => (string) ($participant['work_status'] ?? 'pending')])) {
	                throw new RuntimeException('Для этой работы диплом недоступен.');
	            }
	            $requestedTemplateId = (int) ($_POST['template_id'] ?? 0);
	            $diploma = findExistingWorkDiploma((int) ($participant['work_id'] ?? 0), $requestedTemplateId > 0 ? $requestedTemplateId : null);
	            if (!$diploma) {
	                throw new RuntimeException('Выбранный вариант диплома ещё не сформирован. Сначала нажмите «Сформировать диплом».');
	            }
	            $ctx = getWorkDiplomaContext((int) ($participant['work_id'] ?? 0));
	            $ok = $ctx && sendDiplomaByEmail($ctx, $diploma);
	            $_SESSION['success_message'] = $ok ? 'Диплом отправлен по почте' : 'Не удалось отправить диплом';
            redirect('/admin/participant/' . $participantId);
        }

	        if ($_POST['action'] === 'get_diploma_link') {
	            if (!canShowIndividualDiplomaActions(['status' => (string) ($participant['work_status'] ?? 'pending')])) {
	                throw new RuntimeException('Для этой работы диплом недоступен.');
	            }
	            $requestedTemplateId = (int) ($_POST['template_id'] ?? 0);
	            $diploma = findExistingWorkDiploma((int) ($participant['work_id'] ?? 0), $requestedTemplateId > 0 ? $requestedTemplateId : null);
	            if (!$diploma) {
	                throw new RuntimeException('Выбранный вариант диплома ещё не сформирован. Сначала нажмите «Сформировать диплом».');
	            }
	            $_SESSION['success_message'] = 'Публичная ссылка: ' . getPublicDiplomaUrl($diploma['public_token']);
	            redirect('/admin/participant/' . $participantId);
	        }
    } catch (Throwable $e) {
        if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (string) ($_POST['ajax'] ?? '') === '1') {
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        }
        $_SESSION['error_message'] = $e->getMessage();
        redirect('/admin/participant/' . $participantId);
    }
}

$currentPage = 'participants';
$pageTitle = 'Участник #' . getParticipantDisplayNumber((array) $participant);
$breadcrumb = 'Участники / Просмотр';
$pageStyles = ['admin-participant.css'];
$headerBackUrl = $participantReturnUrl;
$headerBackLabel = 'Назад';

$rawWorkStatus = (string) ($participant['work_status'] ?? 'pending');
$statusForDisplay = in_array($rawWorkStatus, ['reviewed', 'reviewed_non_competitive'], true)
    ? 'reviewed_non_competitive'
    : $rawWorkStatus;
$statusMeta = getWorkUiStatusMeta($statusForDisplay);
$participantName = trim((string) ($participant['fio'] ?? '')) ?: '—';
$applicantName = trim((($participant['user_surname'] ?? '') . ' ' . ($participant['user_name'] ?? '') . ' ' . ($participant['user_patronymic'] ?? ''))) ?: '—';
$participantEmail = trim((string) ($participant['user_email'] ?: ($participant['organization_email'] ?? '')));
$organizationName = trim((string) ($participant['organization_name'] ?: ($participant['user_organization_name'] ?? ''))) ?: '—';
$organizationAddress = trim((string) ($participant['organization_address'] ?: ($participant['user_organization_address'] ?? ''))) ?: '—';
$organizationRegion = trim((string) ($participant['user_organization_region'] ?? '')) ?: '—';
$participantRegion = trim((string) ($participant['region'] ?? '')) ?: '—';
$participantAge = (int) ($participant['age'] ?? 0);
$participantViewsCount = max(0, (int) ($participant['views_count'] ?? 0));
$drawingFileName = trim((string) ($participant['drawing_file'] ?? ''));
$drawingUrl = $drawingFileName !== '' ? getParticipantDrawingWebPath($participant['user_email'] ?? '', $drawingFileName) : '';
$drawingPreviewUrl = $drawingFileName !== '' ? getParticipantDrawingPreviewWebPath($participant['user_email'] ?? '', $drawingFileName) : '';
	$emailHint = $participantEmail !== '' ? $participantEmail : null;
	$canShowDiplomaActions = canShowIndividualDiplomaActions(['status' => (string) ($participant['work_status'] ?? 'pending')]);
	$currentContestId = (int) ($participant['contest_id'] ?? 0);
	$availableDiplomaTemplates = array_values(array_filter(
	    getAvailableContestDiplomaTemplatesForWorkStatus($currentContestId, (string) ($participant['work_status'] ?? 'pending')),
	    static function (array $template) use ($currentContestId): bool {
	        return (int) ($template['contest_id'] ?? 0) === $currentContestId;
	    }
	));
	$existingWorkDiploma = null;
	$existingWorkDiplomaTemplateId = 0;
	$existingWorkDiplomaLabel = '';
	if ($canShowDiplomaActions && (int) ($participant['work_id'] ?? 0) > 0) {
	    $existingWorkDiploma = findExistingWorkDiploma((int) ($participant['work_id'] ?? 0), null);
	    $existingWorkDiplomaTemplateId = (int) ($existingWorkDiploma['template_id'] ?? 0);
	    if ($existingWorkDiplomaTemplateId > 0) {
	        $existingTemplate = getDiplomaTemplateById($existingWorkDiplomaTemplateId);
	        $existingWorkDiplomaLabel = trim((string) ($existingTemplate['title'] ?? ''));
	    }
	}
	$selectedDiplomaTemplateId = 0;
	foreach ($availableDiplomaTemplates as $templateOption) {
	    if ((int) ($templateOption['id'] ?? 0) === $existingWorkDiplomaTemplateId) {
	        $selectedDiplomaTemplateId = $existingWorkDiplomaTemplateId;
	        break;
	    }
	}
	if ($selectedDiplomaTemplateId <= 0) {
	    $selectedDiplomaTemplateId = (int) ($availableDiplomaTemplates[0]['id'] ?? 0);
	}
	$showDiplomaActionButtons = $existingWorkDiplomaTemplateId > 0
	    && $selectedDiplomaTemplateId > 0
	    && $selectedDiplomaTemplateId === $existingWorkDiplomaTemplateId;
$isWorkAccepted = $rawWorkStatus === 'accepted';
$isVkPublished = !empty($participant['vk_published_at']);

$detailsMap = [
    'Участник' => [
        ['label' => 'ФИО участника', 'value' => $participantName],
        ['label' => 'Возраст', 'value' => $participantAge > 0 ? $participantAge . ' лет' : '—'],
        ['label' => 'Регион', 'value' => $participantRegion],
    ],
    'Заявитель' => [
        ['label' => 'ФИО заявителя', 'value' => $applicantName],
        ['label' => 'Email заявителя', 'value' => $participantEmail !== '' ? '<a href="mailto:' . e($participantEmail) . '">' . e($participantEmail) . '</a>' : '—', 'is_html' => true],
        ['label' => 'Тип профиля', 'value' => getUserTypeLabel((string) ($participant['user_type'] ?? 'parent'))],
    ],
    'Организация' => [
        ['label' => 'Название и адрес образовательного учреждения', 'value' => $organizationName],
        ['label' => 'Контактная информация организации', 'value' => $organizationAddress],
        ['label' => 'Регион организации', 'value' => $organizationRegion],
    ],
    'Конкурс и заявка' => [
        ['label' => 'Конкурс', 'value' => trim((string) ($participant['contest_title'] ?? '')) ?: '—'],
        ['label' => 'Заявка', 'value' => '<a class="participant-card-link" href="/admin/application/' . (int) $participant['application_id'] . '">Перейти к заявке #' . (int) $participant['application_id'] . '</a>', 'is_html' => true],
        ['label' => 'Статус рисунка', 'value' => '<span class="badge ' . e($statusMeta['badge_class']) . '">' . e($statusMeta['label']) . '</span>', 'is_html' => true],
    ],
];

require_once __DIR__ . '/includes/header.php';
?>

<nav class="participant-breadcrumbs" aria-label="Хлебные крошки">
    <a href="/admin/">Главная</a>
    <span>/</span>
    <a href="/admin/participants">Участники</a>
    <span>/</span>
    <span>Участник #<?= e(getParticipantDisplayNumber((array) $participant)) ?></span>
</nav>

<section class="participant-hero card">
    <div class="participant-hero__body">
        <div class="participant-hero__summary">
            <p class="participant-hero__eyebrow" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <span><i class="fas fa-id-badge"></i> Карточка участника</span>
                <button type="button" class="btn btn--ghost btn--sm" id="openParticipantEditBtn" title="Редактировать ФИО и возраст">
                    <i class="fas fa-pen"></i>
                </button>
                <span class="badge badge--secondary"><i class="fas fa-eye"></i> <?= $participantViewsCount ?></span>
            </p>
            <h2 class="participant-hero__title" id="participantFioTitle"><?= e($participantName) ?></h2>
            <p class="participant-hero__meta">
                <span><i class="fas fa-child"></i> <span id="participantAgeHero"><?= $participantAge > 0 ? (int) $participantAge . ' лет' : 'Возраст не указан' ?></span></span>
                <span><i class="fas fa-map-marker-alt"></i> <?= e($participantRegion) ?></span>
            </p>
            <p class="participant-hero__contest"><i class="fas fa-trophy"></i> <?= e(trim((string) ($participant['contest_title'] ?? '')) ?: 'Конкурс не указан') ?></p>
            <div class="participant-hero__chips">
                <span class="badge <?= e($statusMeta['badge_class']) ?>"><?= e($statusMeta['label']) ?></span>
                <?php if ($isWorkAccepted): ?>
                    <form method="POST" style="display:inline-flex;">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="toggle_participant_vk_publication">
                        <input type="hidden" name="is_published" value="<?= $isVkPublished ? '0' : '1' ?>">
                        <button type="submit" class="btn btn--sm <?= $isVkPublished ? 'btn--secondary' : 'btn--ghost' ?>">
                            <?= $isVkPublished ? 'Опубликовано' : 'Не опубликовано' ?>
                        </button>
                    </form>
                <?php endif; ?>
                <span class="participant-chip">Номер участника: #<?= e(getParticipantDisplayNumber((array) $participant)) ?></span>
                <span class="participant-chip">ID заявки: #<?= (int) $participant['application_id'] ?></span>
            </div>
        </div>

        <div class="participant-hero__actions">
            <a href="/admin/application/<?= (int) $participant['application_id'] ?>" class="btn btn--secondary">
                <i class="fas fa-file-alt"></i> Перейти к заявке
            </a>
            <?php if ($isWorkAccepted): ?>
                <form method="POST" style="display:inline-flex;">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="publish_participant_vk">
                    <button type="submit" class="btn btn--secondary"><i class="fab fa-vk"></i> Опубликовать в ВК</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if (!empty($_SESSION['success_message'])): ?><div class="alert alert--success participant-flash"><?= e($_SESSION['success_message']) ?></div><?php unset($_SESSION['success_message']); endif; ?>
<?php if (!empty($_SESSION['error_message'])): ?><div class="alert alert--error participant-flash"><?= e($_SESSION['error_message']) ?></div><?php unset($_SESSION['error_message']); endif; ?>

<section class="participant-layout">
    <aside class="participant-layout__drawing">
        <div class="card">
            <div class="card__header participant-section-title"><h3><i class="fas fa-image"></i> Рисунок участника</h3></div>
            <div class="card__body">
                <?php if ($drawingUrl !== ''): ?>
                    <img
                        src="<?= e($drawingPreviewUrl) ?>"
                        data-full-src="<?= e($drawingUrl) ?>"
                        alt="Рисунок участника"
                        id="participantDrawingPreview"
                        class="participant-drawing-preview">

                    <div class="participant-drawing-panel">
                        <div class="participant-drawing-panel__filename" title="<?= e($drawingFileName) ?>">
                            <i class="fas fa-file-image"></i>
                            <span><?= e($drawingFileName) ?></span>
                        </div>
                        <div class="participant-drawing-panel__actions">
                            <button type="button" class="btn btn--ghost" id="participantDrawingOpenBtn"><i class="fas fa-expand"></i> Открыть в полном размере</button>
                            <a class="btn btn--secondary" href="<?= e($drawingUrl) ?>" download="<?= e($drawingFileName ?: 'drawing') ?>"><i class="fas fa-download"></i> Скачать изображение</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="participant-empty-drawing">
                        <i class="fas fa-image"></i>
                        <p>Файл рисунка не загружен</p>
                        <span>Загрузите рисунок в заявке, чтобы он появился в карточке участника.</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </aside>

    <div class="participant-layout__details">
	        <section class="card participant-diploma-card" id="diploma-actions" data-existing-diploma-template-id="<?= (int) $existingWorkDiplomaTemplateId ?>">
	            <div class="card__header participant-section-title">
	                <h3><i class="fas fa-award"></i> Действия с дипломом</h3>
	                <p>Управляйте дипломом участника: скачивайте, отправляйте на почту или получайте публичную ссылку.</p>
	            </div>
	            <div class="card__body">
	                <?php if ($canShowDiplomaActions): ?>
	                <?php if ($availableDiplomaTemplates): ?>
	                    <form method="POST" class="participant-diploma-generate" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;max-width:560px;margin-bottom:12px;">
	                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
	                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
	                        <input type="hidden" name="action" value="generate_diploma">
	                        <div class="form-group" style="min-width:320px;flex:1;margin:0;">
	                            <label class="form-label" for="participantDiplomaTemplate">Вариант диплома</label>
	                            <select class="form-select" id="participantDiplomaTemplate" name="template_id">
	                                <?php foreach ($availableDiplomaTemplates as $templateOption): ?>
	                                    <?php
	                                    $templateOptionId = (int) ($templateOption['id'] ?? 0);
	                                    $templateOptionTitle = trim((string) ($templateOption['title'] ?? ''));
	                                    $templateOptionLabel = $templateOptionTitle !== '' ? $templateOptionTitle : 'Без названия';
	                                    ?>
	                                    <option value="<?= $templateOptionId ?>" <?= $selectedDiplomaTemplateId === $templateOptionId ? 'selected' : '' ?>>
	                                        <?= e($templateOptionLabel) ?>
	                                    </option>
	                                <?php endforeach; ?>
	                            </select>
	                        </div>
	                        <button class="btn btn--primary" type="submit"><i class="fas fa-wand-magic-sparkles"></i> Сформировать диплом</button>
	                    </form>
	                    <p class="participant-diploma-card__hint" style="margin:-4px 0 12px;color:#475569;">
	                        В списке показаны только созданные варианты дипломов для конкурса «<?= e(trim((string) ($participant['contest_title'] ?? '')) ?: 'текущего конкурса') ?>» и только для текущего статуса работы.
	                    </p>
	                    <?php if ($existingWorkDiplomaTemplateId > 0): ?>
	                        <p class="text-secondary" style="margin:-4px 0 12px;">Сформирован: <?= e($existingWorkDiplomaLabel !== '' ? $existingWorkDiplomaLabel : 'Без названия') ?></p>
	                    <?php endif; ?>
	                <?php else: ?>
	                    <div class="alert" style="margin-bottom:12px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;">
	                        Для текущего статуса работы нет созданных вариантов дипломов. Создайте их в разделе шаблонов дипломов конкурса.
	                    </div>
	                <?php endif; ?>
	                <div class="participant-diploma-actions" data-diploma-action-buttons style="display:<?= $showDiplomaActionButtons ? 'flex' : 'none' ?>;">
	                    <form method="POST">
	                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
	                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
	                        <input type="hidden" name="action" value="download_diploma">
	                        <input type="hidden" name="template_id" value="<?= (int) $selectedDiplomaTemplateId ?>" data-diploma-template-input>
	                        <button class="btn btn--primary participant-diploma-actions__btn" type="submit"><i class="fas fa-download"></i> Скачать диплом</button>
	                    </form>
	                    <form method="POST">
	                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
	                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
	                        <input type="hidden" name="action" value="send_diploma_email">
	                        <input type="hidden" name="template_id" value="<?= (int) $selectedDiplomaTemplateId ?>" data-diploma-template-input>
	                        <button class="btn btn--secondary participant-diploma-actions__btn" type="submit"><i class="fas fa-envelope"></i> Отправить по почте</button>
	                    </form>
	                    <form method="POST">
	                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
	                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
	                        <input type="hidden" name="action" value="get_diploma_link">
	                        <input type="hidden" name="template_id" value="<?= (int) $selectedDiplomaTemplateId ?>" data-diploma-template-input>
	                        <button class="btn btn--ghost participant-diploma-actions__btn" type="submit"><i class="fas fa-link"></i> Получить публичную ссылку</button>
	                    </form>
	                </div>
	                <div class="alert" data-diploma-selection-hint style="display:<?= $selectedDiplomaTemplateId > 0 && !$showDiplomaActionButtons ? 'block' : 'none' ?>;margin-top:12px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;">
	                    Для выбранного варианта диплом ещё не сформирован. Сначала нажмите «Сформировать диплом».
	                </div>
	                <?php else: ?>
                    <p class="text-secondary">Дипломные действия станут доступны после рассмотрения этой работы.</p>
                <?php endif; ?>
                <?php if ($emailHint): ?>
                    <p class="participant-diploma-card__hint">Диплом будет отправлен на email: <a href="mailto:<?= e($emailHint) ?>"><?= e($emailHint) ?></a></p>
                <?php endif; ?>
            </div>
        </section>

        <?php foreach ($detailsMap as $sectionTitle => $items): ?>
            <section class="card participant-detail-card">
                <div class="card__header participant-section-title"><h3><?= e($sectionTitle) ?></h3></div>
                <div class="card__body participant-detail-grid">
                    <?php foreach ($items as $item): ?>
                        <article class="participant-detail-item">
                            <p class="participant-detail-item__label"><?= e($item['label']) ?></p>
                            <div class="participant-detail-item__value">
                                <?php if (!empty($item['is_html'])): ?>
                                    <?= $item['value'] ?>
                                <?php else: ?>
                                    <?php
                                        $valueAttrs = '';
                                        if ($sectionTitle === 'Участник' && (string) ($item['label'] ?? '') === 'ФИО участника') {
                                            $valueAttrs = ' id="participantFioValue"';
                                        } elseif ($sectionTitle === 'Участник' && (string) ($item['label'] ?? '') === 'Возраст') {
                                            $valueAttrs = ' id="participantAgeValue"';
                                        }
                                    ?>
                                    <span<?= $valueAttrs ?>><?= e($item['value'] !== '' ? $item['value'] : '—') ?></span>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</section>

<div class="modal" id="participantDrawingModal">
    <div class="modal__content participant-drawing-modal">
        <div class="modal__header">
            <h3>Рисунок участника</h3>
            <button type="button" class="modal__close" id="participantDrawingModalClose">&times;</button>
        </div>
        <div class="modal__body participant-drawing-modal__body">
            <img src="" alt="Рисунок участника" id="participantDrawingModalImage" class="participant-drawing-modal__image">
        </div>
    </div>
</div>

<div class="modal" id="participantEditModal" aria-hidden="true">
    <div class="modal__content" style="max-width:560px;">
        <div class="modal__header">
            <h3 class="modal__title">Редактировать участника</h3>
            <button type="button" class="modal__close" id="participantEditModalClose" aria-label="Закрыть">&times;</button>
        </div>
        <div class="modal__body">
            <form id="participantEditForm">
                <input type="hidden" name="csrf_token" value="<?= e(generateCSRFToken()) ?>">
                <input type="hidden" name="action" value="update_participant_profile">
                <input type="hidden" name="ajax" value="1">
                <div class="form-group">
                    <label class="form-label form-label--required">ФИО участника</label>
                    <input type="text" class="form-input" name="fio" id="participantEditFio" value="<?= e($participantName) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label form-label--required">Возраст</label>
                    <input type="number" class="form-input" name="age" id="participantEditAge" value="<?= (int) $participantAge ?>" min="5" max="17" required>
                </div>
                <div class="form-hint">Сохранение происходит без перезагрузки страницы.</div>
            </form>
        </div>
        <div class="modal__footer" style="display:flex; justify-content:flex-end; gap:8px;">
            <button type="button" class="btn btn--ghost" id="participantEditCancelBtn">Отмена</button>
            <button type="button" class="btn btn--primary" id="participantEditSaveBtn">
                <i class="fas fa-save"></i> Сохранить
            </button>
        </div>
    </div>
</div>

	<script>
	(() => {
	    const diplomaTemplateSelect = document.getElementById('participantDiplomaTemplate');
	    const diplomaTemplateInputs = document.querySelectorAll('[data-diploma-template-input]');
	    const actionsRoot = document.getElementById('diploma-actions');
	    const actionButtons = document.querySelector('[data-diploma-action-buttons]');
	    const selectionHint = document.querySelector('[data-diploma-selection-hint]');
	    const existingTemplateId = actionsRoot?.dataset?.existingDiplomaTemplateId || '';
	    const syncDiplomaTemplate = () => {
	        const value = diplomaTemplateSelect?.value || '';
	        diplomaTemplateInputs.forEach((input) => {
	            input.value = value;
	        });

	        if (actionButtons) {
	            const shouldShow = Boolean(existingTemplateId && value && existingTemplateId === value);
	            actionButtons.style.display = shouldShow ? 'flex' : 'none';
	            if (selectionHint) {
	                selectionHint.style.display = value && !shouldShow ? 'block' : 'none';
	            }
	        }
	    };
	    diplomaTemplateSelect?.addEventListener('change', syncDiplomaTemplate);
	    syncDiplomaTemplate();

    const previewImage = document.getElementById('participantDrawingPreview');
    const openButton = document.getElementById('participantDrawingOpenBtn');
    const modal = document.getElementById('participantDrawingModal');
    const modalImage = document.getElementById('participantDrawingModalImage');
    const closeButton = document.getElementById('participantDrawingModalClose');
    if (!previewImage || !modal || !modalImage || !closeButton) return;

    const closeModal = () => {
        modal.classList.remove('active');
        modalImage.src = '';
        document.body.style.overflow = '';
    };

    const openModal = () => {
        modalImage.src = previewImage.dataset.fullSrc || previewImage.src;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    };

    previewImage.addEventListener('click', openModal);
    if (openButton) {
        openButton.addEventListener('click', openModal);
    }

    closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('active')) {
            closeModal();
        }
    });
})();

(() => {
    const openBtn = document.getElementById('openParticipantEditBtn');
    const modal = document.getElementById('participantEditModal');
    const closeBtn = document.getElementById('participantEditModalClose');
    const cancelBtn = document.getElementById('participantEditCancelBtn');
    const saveBtn = document.getElementById('participantEditSaveBtn');
    const form = document.getElementById('participantEditForm');
    const fioInput = document.getElementById('participantEditFio');
    const ageInput = document.getElementById('participantEditAge');
    if (!openBtn || !modal || !form || !fioInput || !ageInput || !saveBtn) return;

    const toast = (message, type = 'success') => {
        const el = document.createElement('div');
        el.className = 'alert ' + (type === 'error' ? 'alert--error' : 'alert--success');
        el.style.cssText = 'position:fixed; top:20px; right:20px; z-index:3200; min-width:260px; max-width:420px; box-shadow:0 12px 30px rgba(0,0,0,.12); opacity:0; transform:translateY(-8px); transition:opacity .25s ease, transform .25s ease;';
        el.textContent = message;
        document.body.appendChild(el);
        requestAnimationFrame(() => { el.style.opacity = '1'; el.style.transform = 'translateY(0)'; });
        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(-8px)';
            setTimeout(() => el.remove(), 260);
        }, 2600);
    };

    const open = () => {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => fioInput.focus(), 0);
    };
    const close = () => {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    };

    openBtn.addEventListener('click', open);
    closeBtn?.addEventListener('click', close);
    cancelBtn?.addEventListener('click', close);
    modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && modal.classList.contains('active')) close(); });

    const applyUi = (fio, age) => {
        const title = document.getElementById('participantFioTitle');
        const ageHero = document.getElementById('participantAgeHero');
        const fioValue = document.getElementById('participantFioValue');
        const ageValue = document.getElementById('participantAgeValue');
        if (title) title.textContent = fio;
        if (fioValue) fioValue.textContent = fio;
        if (ageHero) ageHero.textContent = `${age} лет`;
        if (ageValue) ageValue.textContent = `${age} лет`;
    };

    saveBtn.addEventListener('click', async () => {
        const fio = String(fioInput.value || '').trim();
        const age = Number(ageInput.value || 0);
        if (!fio) { toast('Укажите ФИО участника.', 'error'); fioInput.focus(); return; }
        if (!Number.isInteger(age) || age < 5 || age > 17) { toast('Возраст должен быть от 5 до 17 лет.', 'error'); ageInput.focus(); return; }

        const fd = new FormData(form);
        saveBtn.disabled = true;
        const oldHtml = saveBtn.innerHTML;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Сохраняем...';
        try {
            const resp = await fetch('/admin/participant/<?= (int) $participantId ?>', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd,
            });
            const data = await resp.json().catch(() => null);
            if (!resp.ok || !data || !data.success) {
                throw new Error((data && (data.error || data.message)) ? (data.error || data.message) : 'Не удалось сохранить');
            }
            applyUi(String(data.fio || fio), Number(data.age || age));
            toast('Данные участника обновлены', 'success');
            close();
        } catch (err) {
            toast(err.message || 'Не удалось сохранить', 'error');
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = oldHtml;
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
