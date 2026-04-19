<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (!isAdmin()) {
    redirect('/admin/login');
}
check_csrf();

$contestId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM contests WHERE id = ?');
$stmt->execute([$contestId]);
$contest = $stmt->fetch();
if (!$contest) {
    redirect('/admin/diplomas');
}

$templateTypes = diplomaTemplateTypes();
$templates = listContestDiplomaTemplates($contestId);
$selectedTemplateId = (int)($_GET['template_id'] ?? ($templates[0]['id'] ?? 0));
$activeEditorTab = (string)($_GET['tab'] ?? 'diploma-layout');
if (!in_array($activeEditorTab, ['diploma-layout', 'email-template'], true)) {
    $activeEditorTab = 'diploma-layout';
}
if ($selectedTemplateId <= 0 && !empty($templates)) {
    $selectedTemplateId = (int)$templates[0]['id'];
}
$buildEditorUrl = static function (int $contestId, int $templateId = 0, string $tab = 'diploma-layout'): string {
    $params = [];
    if ($templateId > 0) {
        $params['template_id'] = $templateId;
    }
    if ($tab !== '') {
        $params['tab'] = $tab;
    }

    $query = http_build_query($params);
    return '/admin/diploma-template/' . $contestId . ($query !== '' ? '?' . $query : '');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Ошибка CSRF токена';
        redirect($buildEditorUrl($contestId, $selectedTemplateId, (string)($_POST['editor_tab'] ?? $activeEditorTab)));
    }

    $action = (string)($_POST['action'] ?? 'save');
    $submittedEditorTab = (string)($_POST['editor_tab'] ?? $activeEditorTab);
    if (!in_array($submittedEditorTab, ['diploma-layout', 'email-template'], true)) {
        $submittedEditorTab = 'diploma-layout';
    }

    try {
        if ($action === 'create_template') {
	        $newType = (string)($_POST['template_type'] ?? 'contest_participant');
	        if (!array_key_exists($newType, $templateTypes)) {
	            $newType = 'contest_participant';
	        }
	        $newTitle = trim((string) ($_POST['template_title'] ?? ''));
	        if ($newTitle === '') {
	            $newTitle = (string) ($templateTypes[$newType] ?? 'Шаблон');
	        }

	        ensureDiplomaSchema();
	        $defaults = diplomaTemplateDefaults($newType);

	        $newId = 0;
	        try {
	            $pdo->prepare("INSERT INTO diploma_templates (
	                contest_id, template_type, title, subtitle, body_text, award_text, contest_name_text, easter_text,
	                signature_1, signature_2, position_1, position_2, footer_text, city, issue_date, diploma_prefix,
	                show_date, show_number, show_signatures, show_background, show_frame,
	                layout_json, styles_json, assets_json, created_at, updated_at
	            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
	            ->execute([
	                $contestId,
	                $newType,
	                $newTitle,
	                $defaults['subtitle'],
	                $defaults['body_text'],
	                $defaults['award_text'],
	                $defaults['contest_name_text'],
	                $defaults['easter_text'],
	                $defaults['signature_1'],
	                $defaults['signature_2'],
	                $defaults['position_1'],
	                $defaults['position_2'],
	                $defaults['footer_text'],
	                $defaults['city'],
	                $defaults['issue_date'],
	                $defaults['diploma_prefix'],
	                $defaults['show_date'],
	                $defaults['show_number'],
	                $defaults['show_signatures'],
	                $defaults['show_background'],
	                $defaults['show_frame'],
	                $defaults['layout_json'],
	                $defaults['styles_json'],
	                $defaults['assets_json'],
	            ]);
	            $newId = (int) $pdo->lastInsertId();
	        } catch (Throwable $e) {
	            // If unique constraint (contest_id, template_type) exists, reuse existing template.
	            $existing = getOrCreateDiplomaTemplate($contestId, $newType);
	            $newId = (int) ($existing['id'] ?? 0);
	            if ($newId > 0) {
	                saveDiplomaTemplate($newId, ['title' => $newTitle]);
	            }
	        }

	        if ($newId <= 0) {
	            throw new RuntimeException('Не удалось создать шаблон');
	        }

	        $_SESSION['success_message'] = 'Шаблон создан';
	        redirect($buildEditorUrl($contestId, $newId, $submittedEditorTab));
        }

        if ($action === 'duplicate_template') {
	        $dupFrom = (int)($_POST['template_id'] ?? 0);
	        $newId = duplicateDiplomaTemplate($dupFrom);
	        $_SESSION['success_message'] = 'Шаблон продублирован';
	        redirect($buildEditorUrl($contestId, $newId, $submittedEditorTab));
        }

        if ($action === 'delete_template') {
	        $deleteId = (int) ($_POST['template_id'] ?? 0);
	        if ($deleteId <= 0) {
	            throw new RuntimeException('Шаблон не найден');
	        }

	        ensureDiplomaSchema();
	        $tplStmt = $pdo->prepare('SELECT * FROM diploma_templates WHERE id = ? AND contest_id = ? LIMIT 1');
	        $tplStmt->execute([$deleteId, $contestId]);
	        $toDelete = $tplStmt->fetch();
	        if (!$toDelete) {
	            throw new RuntimeException('Шаблон не найден');
	        }

	        // Detach already generated diplomas from this template, keep files/links intact.
	        $pdo->prepare('UPDATE participant_diplomas SET template_id = NULL, updated_at = NOW() WHERE template_id = ?')
	            ->execute([$deleteId]);

	        $pdo->prepare('DELETE FROM diploma_templates WHERE id = ? LIMIT 1')->execute([$deleteId]);
	        $_SESSION['success_message'] = 'Шаблон удалён';

	        $remaining = listContestDiplomaTemplates($contestId);
	        $nextId = (int) (($remaining[0]['id'] ?? 0));
	        $redirectUrl = $buildEditorUrl($contestId, $nextId, $submittedEditorTab);
	        redirect($redirectUrl);
        }

        if ($action === 'save_email_template') {
            $emailTemplateSettings = [
                'subject' => trim((string) ($_POST['email_subject'] ?? '')),
                'blocks' => [],
            ];

            foreach (diplomaEmailEditorBlocks() as $blockKey => $meta) {
                $emailTemplateSettings['blocks'][$blockKey] = [
                    'enabled' => isset($_POST['email_blocks'][$blockKey]['enabled']) ? 1 : 0,
                    'text' => trim((string) ($_POST['email_blocks'][$blockKey]['text'] ?? '')),
                ];
            }

            saveContestDiplomaEmailTemplateSettings($contestId, $emailTemplateSettings);
            $_SESSION['success_message'] = 'Шаблон письма сохранён';
            redirect($buildEditorUrl($contestId, $selectedTemplateId, 'email-template'));
        }

        if ($action === 'save') {
	        $tid = (int)($_POST['template_id'] ?? 0);
	        $templateRow = null;
	        foreach ($templates as $tpl) {
            if ((int)$tpl['id'] === $tid) {
                $templateRow = $tpl;
                break;
            }
        }

        $assets = json_decode((string)($_POST['assets_json'] ?? '{}'), true);
        if (!is_array($assets)) {
            $assets = [];
        }

        if (!empty($_FILES['background_image']['name'] ?? '')) {
            ensureDiplomaStorage();
            $uploadResult = uploadFile($_FILES['background_image'], DIPLOMAS_PATH, ['jpg', 'jpeg', 'png', 'webp']);
            if (empty($uploadResult['success'])) {
                throw new RuntimeException((string)($uploadResult['message'] ?? 'Не удалось загрузить фон шаблона'));
            }
            $assets['background_image'] = (string)$uploadResult['filename'];
        }

        if (isset($_POST['remove_background_image'])) {
            $assets['background_image'] = null;
        }

        $layoutInput = json_decode((string)($_POST['layout_json'] ?? '{}'), true);
        if (!is_array($layoutInput)) {
            $layoutInput = [];
        }
        if (!isset($layoutInput['participant_name']) || !is_array($layoutInput['participant_name'])) {
            $layoutInput['participant_name'] = defaultDiplomaLayout((string)($templateRow['template_type'] ?? 'contest_participant'))['participant_name'] ?? [];
        }

        if (($templateRow['template_type'] ?? '') === 'participant_certificate') {
            foreach ($layoutInput as $key => $cfg) {
                if (!is_array($cfg)) {
                    continue;
                }
                $layoutInput[$key]['visible'] = $key === 'participant_name' ? 1 : 0;
            }
            unset($layoutInput['name_line']);
        }

        $layoutInput['participant_name']['y'] = (float)($_POST['name_top'] ?? ($layoutInput['participant_name']['y'] ?? 38));
        $layoutInput['participant_name']['x'] = (float)($_POST['name_left'] ?? ($layoutInput['participant_name']['x'] ?? 10));
        $layoutInput['participant_name']['w'] = (float)($_POST['name_width'] ?? ($layoutInput['participant_name']['w'] ?? 80));
        $layoutInput['participant_name']['font_size'] = (int)($_POST['name_font_size'] ?? ($layoutInput['participant_name']['font_size'] ?? 18));
        $layoutInput['participant_name']['line_height'] = (float)($_POST['name_line_height'] ?? ($layoutInput['participant_name']['line_height'] ?? 1.25));
        $layoutInput['participant_name']['align'] = trim((string)($_POST['name_text_align'] ?? ($layoutInput['participant_name']['align'] ?? 'center')));
        $layoutInput['participant_name']['font_family'] = trim((string)($_POST['name_font_family'] ?? ($layoutInput['participant_name']['font_family'] ?? 'dejavuserif')));
        $layoutInput['participant_name']['color'] = trim((string)($_POST['name_color'] ?? ($layoutInput['participant_name']['color'] ?? '#334155')));

        $styles = json_decode((string)($_POST['styles_json'] ?? '{}'), true);
        if (!is_array($styles)) {
            $styles = [];
        }
        $styles['sheet_rotation'] = ((int)($_POST['sheet_rotation'] ?? 0) === 90) ? 90 : 0;

        $_POST['layout_json'] = json_encode($layoutInput, JSON_UNESCAPED_UNICODE);
        $_POST['assets_json'] = json_encode($assets, JSON_UNESCAPED_UNICODE);
        $_POST['styles_json'] = json_encode($styles, JSON_UNESCAPED_UNICODE);
        $_POST['show_background'] = '1';
        unset($_POST['show_frame'], $_POST['show_date'], $_POST['show_number'], $_POST['show_signatures']);
        saveDiplomaTemplate($tid, $_POST);
	        $_SESSION['success_message'] = 'Шаблон диплома сохранён';
	        redirect($buildEditorUrl($contestId, $tid, 'diploma-layout'));
        }
    } catch (Throwable $e) {
        $_SESSION['error_message'] = $e->getMessage();
        redirect($buildEditorUrl($contestId, $selectedTemplateId, $submittedEditorTab));
    }
}

$templates = listContestDiplomaTemplates($contestId);
$selectedTemplate = null;
foreach ($templates as $tpl) {
    if ((int)$tpl['id'] === $selectedTemplateId) {
        $selectedTemplate = $tpl;
        break;
    }
}
if (!$selectedTemplate && !empty($templates)) {
    $selectedTemplate = $templates[0];
}
$selectedTemplateId = (int)($selectedTemplate['id'] ?? 0);

if (isset($_GET['preview']) && (int)$_GET['preview'] === 1) {
    if (!$selectedTemplate || $selectedTemplateId <= 0) {
        $_SESSION['error_message'] = 'Сначала создайте вариант диплома.';
        redirect($buildEditorUrl($contestId, 0, 'diploma-layout'));
    }
    $selectedTemplate = getDiplomaTemplateById($selectedTemplateId) ?? $selectedTemplate;
    $workStmt = $pdo->prepare("SELECT w.id
        FROM works w
        INNER JOIN applications a ON a.id = w.application_id
        WHERE a.contest_id = ? AND w.status IN ('accepted','reviewed')
        ORDER BY w.id DESC
        LIMIT 1");
    $workStmt->execute([$contestId]);
    $workId = (int)$workStmt->fetchColumn();

    if ($workId <= 0) {
        ensureApplicationWorks((int)($pdo->query('SELECT id FROM applications WHERE contest_id = ' . (int)$contestId . ' ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0));
        $workStmt->execute([$contestId]);
        $workId = (int)$workStmt->fetchColumn();
    }

    if ($workId > 0) {
        $pdf = generateWorkDiplomaPreviewPdf($workId, $selectedTemplate);
        if ($pdf !== '') {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="preview.pdf"');
            echo $pdf;
            exit;
        }
    }
}

$layout = json_decode((string)($selectedTemplate['layout_json'] ?? ''), true);
if (!is_array($layout) || !$layout) {
    $layout = defaultDiplomaLayout((string)($selectedTemplate['template_type'] ?? 'contest_participant'));
}
$assets = json_decode((string)($selectedTemplate['assets_json'] ?? '{}'), true);
if (!is_array($assets)) {
    $assets = [];
}
$styles = getDiplomaTemplateStyles($selectedTemplate);
$sheetRotation = getDiplomaSheetRotation($selectedTemplate);
$participantNameLayout = is_array($layout['participant_name'] ?? null) ? $layout['participant_name'] : [];
$backgroundImageUrl = !empty($assets['background_image']) ? getDiplomaAssetWebPath((string)$assets['background_image']) : '';
$emailBlockDefinitions = diplomaEmailEditorBlocks();
$emailTemplateSettings = getContestDiplomaEmailTemplateSettings($contestId);
$emailPreviewData = [
    'diploma_type' => (string)($selectedTemplate['template_type'] ?? 'contest_participant'),
    'user_name' => 'Анна',
    'participant_name' => 'Иванов Иван Иванович',
    'contest_title' => trim((string)($contest['title'] ?? '')) !== '' ? (string)$contest['title'] : 'Конкурс',
    'diploma_number' => 'DIPL-00042',
    'diploma_url' => SITE_URL . '/diploma/demo-preview-' . $contestId,
    'site_url' => SITE_URL,
    'brand_name' => siteBrandName(),
    'brand_subtitle' => siteBrandSubtitle(),
    'attachment_name' => 'diploma.pdf',
    'email_template' => $emailTemplateSettings,
];
$emailPreviewHtml = buildDiplomaEmailTemplate($emailPreviewData);
$emailPreviewText = buildDiplomaEmailText($emailPreviewData);

$currentPage = 'diplomas';
$pageTitle = 'Шаблоны дипломов';
$breadcrumb = 'Дипломы / Шаблоны';
require_once __DIR__ . '/includes/header.php';

$successMessage = (string)($_SESSION['success_message'] ?? '');
$errorMessage = (string)($_SESSION['error_message'] ?? '');
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>

<?php if ($successMessage !== ''): ?>
    <div class="alert alert--success mb-lg">
        <i class="fas fa-check-circle"></i> <?= e($successMessage) ?>
    </div>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <div class="alert alert--error mb-lg">
        <i class="fas fa-exclamation-circle"></i> <?= e($errorMessage) ?>
    </div>
<?php endif; ?>

<div class="card mb-lg">
    <div class="card__header"><h3>Шаблоны дипломов: <?= e($contest['title']) ?></h3></div>
    <div class="card__body">
        <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="action" value="create_template">
            <input type="hidden" name="editor_tab" value="<?= e($activeEditorTab) ?>">
            <div class="form-group" style="min-width:260px;max-width:420px;flex:1;margin:0;">
                <label class="form-label" style="margin:0;">Название диплома / сертификата</label>
                <input class="form-input" type="text" name="template_title" required placeholder="Например: Диплом участника" value="">
            </div>
            <div class="form-group" style="margin:0;">
                <div class="form-label" style="margin:0 0 6px;">Тип</div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                    <?php foreach ($templateTypes as $type => $label): ?>
                        <label class="btn btn--ghost btn--sm" style="display:inline-flex;align-items:center;gap:6px;">
                            <input type="radio" name="template_type" value="<?= e($type) ?>" <?= $type === 'contest_participant' ? 'checked' : '' ?> style="margin:0;">
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <button class="btn btn--primary" type="submit"><i class="fas fa-plus"></i> Создать</button>
        </form>

        <div style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;">
            <?php if (!$templates): ?>
                <div class="alert" style="grid-column:1/-1;background:#f8fafc;border:1px dashed #cbd5e1;color:#475569;">
                    Варианты дипломов пока не созданы. После создания они появятся здесь списком и станут доступны для выбора в админке.
                </div>
            <?php endif; ?>
            <?php foreach ($templates as $tpl): ?>
                <div class="card" style="border:1px solid #E5E7EB;">
	                    <div class="card__body" style="padding:12px;">
	                        <div class="font-semibold" style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">
	                            <div style="min-width:0;">
	                                <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($tpl['title']) ?></div>
	                                <div class="text-secondary" style="font-size:12px;"><?= e($templateTypes[$tpl['template_type']] ?? $tpl['template_type']) ?></div>
	                            </div>
                            <button
                                type="button"
                                class="btn btn--ghost btn--sm"
                                title="Удалить"
                                data-delete-template
                                data-template-id="<?= (int) $tpl['id'] ?>"
                                data-template-title="<?= e($tpl['title']) ?>"
                                style="flex:0 0 auto;"
                            >
                                <i class="fas fa-trash"></i>
	                            </button>
	                        </div>
		                        <div style="margin-top:8px;display:flex;gap:8px;">
		                            <a class="btn btn--ghost btn--sm" href="<?= e($buildEditorUrl((int)$contestId, (int)$tpl['id'], $activeEditorTab)) ?>">Редактировать</a>
		                            <form method="POST">
                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                <input type="hidden" name="action" value="duplicate_template">
                                <input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>">
                                <input type="hidden" name="editor_tab" value="<?= e($activeEditorTab) ?>">
                                <button class="btn btn--secondary btn--sm" type="submit">Дублировать</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="diploma-editor-tabs mb-lg">
    <a href="<?= e($buildEditorUrl((int)$contestId, (int)$selectedTemplateId, 'diploma-layout')) ?>" class="diploma-editor-tabs__tab<?= $activeEditorTab === 'diploma-layout' ? ' is-active' : '' ?>">
        <i class="fas fa-award"></i> Шаблон диплома
    </a>
    <a href="<?= e($buildEditorUrl((int)$contestId, (int)$selectedTemplateId, 'email-template')) ?>" class="diploma-editor-tabs__tab<?= $activeEditorTab === 'email-template' ? ' is-active' : '' ?>">
        <i class="fas fa-envelope-open-text"></i> Письмо с дипломом
    </a>
</div>

<?php if ($activeEditorTab === 'diploma-layout'): ?>
    <div class="card">
        <div class="card__header"><h3>Визуальный редактор шаблона</h3></div>
        <div class="card__body">
            <?php if (!$selectedTemplate || $selectedTemplateId <= 0): ?>
                <div class="alert" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;">
                    Список вариантов пуст. Сначала создайте вариант диплома выше, и он появится в этом редакторе.
                </div>
            <?php else: ?>
            <form method="POST" id="templateEditorForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="editor_tab" value="diploma-layout">
                <input type="hidden" name="template_id" value="<?= (int)$selectedTemplateId ?>">
                <input type="hidden" name="layout_json" id="layoutJsonInput" value='<?= e(json_encode($layout, JSON_UNESCAPED_UNICODE)) ?>'>
                <input type="hidden" name="styles_json" id="stylesJsonInput" value='<?= e((string)($selectedTemplate['styles_json'] ?? '{}')) ?>'>
                <input type="hidden" name="assets_json" id="assetsJsonInput" value='<?= e((string)($selectedTemplate['assets_json'] ?? '{}')) ?>'>

                <input type="hidden" name="name_top" id="nameTopInput" value="<?= e((string)($participantNameLayout['y'] ?? 0)) ?>">
                <input type="hidden" name="name_left" id="nameLeftInput" value="<?= e((string)($participantNameLayout['x'] ?? 0)) ?>">
                <input type="hidden" name="name_width" value="<?= e((string)($participantNameLayout['w'] ?? 0)) ?>">
                <input type="hidden" name="name_line_height" value="<?= e((string)($participantNameLayout['line_height'] ?? 1.1)) ?>">
                <input type="hidden" name="name_text_align" value="<?= e((string)($participantNameLayout['align'] ?? 'center')) ?>">
                <input type="hidden" name="name_font_family" value="<?= e((string)($participantNameLayout['font_family'] ?? 'dejavuserif')) ?>">
                <input type="hidden" name="name_color" value="<?= e((string)($participantNameLayout['color'] ?? '#334155')) ?>">

                <div style="display:grid;grid-template-columns: minmax(0, 2fr) minmax(320px, 1fr);gap:18px;align-items:start;">
                    <div>
                        <h4 class="mb-md">Предпросмотр шаблона</h4>
                        <div class="text-secondary" style="margin-bottom:10px;font-size:13px;">Перетащите блок с ФИО мышкой, чтобы изменить его положение на сертификате.</div>
                        <div id="diplomaCanvas" style="position:relative;width:100%;aspect-ratio:<?= $sheetRotation === 90 ? '1.414/1' : '1/1.414' ?>;background:linear-gradient(180deg,#FFF9E6 0%, #F7FBFF 50%, #FFF1F2 100%);border:6px solid #EAB308;border-radius:8px;overflow:hidden;">
                        </div>
                    </div>
                    <div>
                        <div class="form-group">
                            <label class="form-label">Название диплома / сертификата</label>
                            <input type="text" class="form-input" name="title" value="<?= e((string) ($selectedTemplate['title'] ?? '')) ?>" required>
                            <div class="form-hint">Это название будет видно в админке и в статусе диплома.</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Фон шаблона</label>
                            <input type="file" class="form-input" name="background_image" accept=".jpg,.jpeg,.png,.webp">
                            <?php if ($backgroundImageUrl !== ''): ?>
                                <div class="form-hint" style="margin-top:6px;">
                                    Текущий фон: <a href="<?= e($backgroundImageUrl) ?>" target="_blank" rel="noopener">открыть изображение</a>
                                </div>
                                <label style="display:block;margin-top:6px;"><input type="checkbox" name="remove_background_image" value="1"> Удалить текущий фон</label>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Поворот листа</label>
                            <select class="form-select" name="sheet_rotation" id="sheetRotationInput">
                                <option value="0" <?= $sheetRotation === 0 ? 'selected' : '' ?>>Без поворота</option>
                                <option value="90" <?= $sheetRotation === 90 ? 'selected' : '' ?>>Повернуть на 90°</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Размер шрифта ФИО</label>
                            <input class="form-input" type="number" min="8" max="72" step="1" name="name_font_size" id="nameFontSizeInput" value="<?= e((string)($participantNameLayout['font_size'] ?? 18)) ?>">
                        </div>

                        <div class="alert" style="margin-top:10px;background:#EEF2FF;border:1px solid #C7D2FE;color:#3730A3;">
                            Доступно только: смена фона, поворот листа, перемещение блока ФИО и изменение размера шрифта.
                        </div>
                    </div>
                </div>

                <div class="flex gap-md mt-lg">
                    <button class="btn btn--primary" type="submit"><i class="fas fa-save"></i> Сохранить шаблон</button>
                    <a class="btn btn--ghost" href="<?= e($buildEditorUrl((int)$contestId, (int)$selectedTemplateId, 'diploma-layout')) ?>&preview=1" target="_blank" rel="noopener">Предпросмотр PDF</a>
                    <a class="btn btn--ghost" href="/admin/diplomas">Назад</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card__header"><h3>Шаблон письма с дипломом</h3></div>
        <div class="card__body">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="save_email_template">
                <input type="hidden" name="editor_tab" value="email-template">

                <div class="diploma-email-layout">
                    <div class="diploma-email-layout__main">
                        <section class="diploma-email-card">
                            <div class="diploma-email-card__header">
                                <div>
                                    <h4>Тема письма</h4>
                                    <p>Можно использовать переменные и собрать тему под конкретный конкурс.</p>
                                </div>
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label class="form-label" for="email_subject">Тема</label>
                                <input
                                    type="text"
                                    id="email_subject"
                                    name="email_subject"
                                    class="form-input"
                                    value="<?= e((string)($emailTemplateSettings['subject'] ?? '')) ?>"
                                    placeholder="Ваш {diploma_type_label_lower} готов"
                                >
                                <div class="form-hint">Переменные: <code>{recipient_name}</code>, <code>{participant_name}</code>, <code>{contest_title}</code>, <code>{diploma_type_label}</code>, <code>{diploma_type_label_lower}</code>.</div>
                            </div>
                        </section>

                        <section class="diploma-email-card">
                            <div class="diploma-email-card__header">
                                <div>
                                    <h4>Блоки письма</h4>
                                    <p>Можно отключить ненужные блоки и отредактировать текст каждого из них.</p>
                                </div>
                            </div>

                            <div class="diploma-email-blocks">
                                <?php foreach ($emailBlockDefinitions as $blockKey => $blockMeta): ?>
                                    <?php $blockState = $emailTemplateSettings['blocks'][$blockKey] ?? ['enabled' => 0, 'text' => '']; ?>
                                    <article class="diploma-email-block">
                                        <div class="diploma-email-block__header">
                                            <div>
                                                <h5><?= e((string)($blockMeta['label'] ?? $blockKey)) ?></h5>
                                                <p><?= e((string)($blockMeta['hint'] ?? '')) ?></p>
                                            </div>
                                            <label class="diploma-email-switch">
                                                <input
                                                    type="checkbox"
                                                    name="email_blocks[<?= e($blockKey) ?>][enabled]"
                                                    value="1"
                                                    <?= (int)($blockState['enabled'] ?? 0) === 1 ? 'checked' : '' ?>
                                                >
                                                <span>Показывать</span>
                                            </label>
                                        </div>

                                        <div class="form-group" style="margin:0;">
                                            <label class="form-label" for="email_block_<?= e($blockKey) ?>">Текст блока</label>
                                            <textarea
                                                id="email_block_<?= e($blockKey) ?>"
                                                name="email_blocks[<?= e($blockKey) ?>][text]"
                                                class="form-input"
                                                rows="<?= $blockKey === 'footer' || $blockKey === 'intro' ? '5' : '3' ?>"
                                            ><?= e((string)($blockState['text'] ?? '')) ?></textarea>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <div class="flex gap-md mt-lg">
                            <button class="btn btn--primary" type="submit"><i class="fas fa-save"></i> Сохранить шаблон письма</button>
                            <a class="btn btn--ghost" href="/admin/diplomas">Назад</a>
                        </div>
                    </div>

                    <aside class="diploma-email-preview">
                        <div class="diploma-email-preview__card">
                            <div class="diploma-email-preview__header">
                                <h4>Предпросмотр письма</h4>
                                <p>Показывает текущий сохранённый шаблон с тестовыми данными.</p>
                            </div>
                            <iframe
                                class="diploma-email-preview__frame"
                                title="Предпросмотр письма с дипломом"
                                srcdoc="<?= htmlspecialchars($emailPreviewHtml, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                            ></iframe>
                        </div>

                        <div class="diploma-email-preview__card">
                            <div class="diploma-email-preview__header">
                                <h4>Текстовая версия</h4>
                                <p>Автоматически уходит как альтернативный plain text.</p>
                            </div>
                            <pre class="diploma-email-preview__text"><?= e($emailPreviewText) ?></pre>
                        </div>
                    </aside>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="modal" id="deleteTemplateModal" aria-hidden="true">
    <div class="modal__content" style="max-width:520px;">
        <div class="modal__header">
            <h3 class="modal__title">Удалить шаблон</h3>
            <button type="button" class="modal__close" data-delete-template-close aria-label="Закрыть">&times;</button>
        </div>
        <div class="modal__body">
            <div class="alert alert--warning" style="margin:0;">
                <strong>Это действие необратимо.</strong>
                <div style="margin-top:6px;">Шаблон будет удалён: <span id="deleteTemplateTitle" style="font-weight:600;"></span></div>
            </div>
        </div>
        <div class="modal__footer" style="display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" class="btn btn--ghost" data-delete-template-cancel>Отмена</button>
            <form method="POST" id="deleteTemplateForm" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="delete_template">
                <input type="hidden" name="editor_tab" value="<?= e($activeEditorTab) ?>">
                <input type="hidden" name="template_id" id="deleteTemplateIdInput" value="">
                <button type="submit" class="btn btn--danger"><i class="fas fa-trash"></i> Удалить</button>
            </form>
        </div>
    </div>
</div>

<script>
(() => {
  const modal = document.getElementById('deleteTemplateModal');
  const titleEl = document.getElementById('deleteTemplateTitle');
  const idInput = document.getElementById('deleteTemplateIdInput');
  const close = () => {
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (idInput) idInput.value = '';
    if (titleEl) titleEl.textContent = '';
  };
  const open = (id, title) => {
    if (!modal) return;
    if (idInput) idInput.value = String(id || '');
    if (titleEl) titleEl.textContent = String(title || '');
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  };

  document.querySelectorAll('[data-delete-template]').forEach((btn) => {
    btn.addEventListener('click', () => {
      open(btn.dataset.templateId || '', btn.dataset.templateTitle || '');
    });
  });
  modal?.addEventListener('click', (e) => {
    if (e.target === modal) close();
  });
  document.querySelectorAll('[data-delete-template-close],[data-delete-template-cancel]').forEach((btn) => {
    btn.addEventListener('click', close);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal?.classList.contains('active')) close();
  });
})();

const templateEditorForm = document.getElementById('templateEditorForm');
if (templateEditorForm) {
  const defaults = <?= json_encode(defaultDiplomaLayout((string)($selectedTemplate['template_type'] ?? 'contest_participant')), JSON_UNESCAPED_UNICODE) ?>;
  let layout = JSON.parse(document.getElementById('layoutJsonInput').value || '{}');
  if (!layout || typeof layout !== 'object') layout = {...defaults};
  let assets = JSON.parse(document.getElementById('assetsJsonInput').value || '{}');
  if (!assets || typeof assets !== 'object') assets = {};
  let styles = JSON.parse(document.getElementById('stylesJsonInput').value || '{}');
  if (!styles || typeof styles !== 'object') styles = {};

  const samples = {
    participant_name: 'Иванов Иван Иванович',
  };

  const canvas = document.getElementById('diplomaCanvas');
  const layoutInput = document.getElementById('layoutJsonInput');
  const stylesInput = document.getElementById('stylesJsonInput');
  const nameTopInput = document.getElementById('nameTopInput');
  const nameLeftInput = document.getElementById('nameLeftInput');
  const nameFontSizeInput = document.getElementById('nameFontSizeInput');
  const sheetRotationInput = document.getElementById('sheetRotationInput');
  let dragState = null;

  function resolvePreviewFontFamily(fontFamily) {
    const normalized = String(fontFamily || '').trim().toLowerCase();
    if (normalized === 'dejavuserif') {
      return '"DejaVu Serif", Georgia, "Times New Roman", serif';
    }
    if (normalized === 'dejavusans') {
      return '"DejaVu Sans", "Segoe UI", Arial, sans-serif';
    }
    return fontFamily || '"DejaVu Serif", Georgia, "Times New Roman", serif';
  }

  function ensureLayout() {
    Object.keys(defaults).forEach((key) => {
      if (!layout[key]) layout[key] = {...defaults[key]};
    });
  }

  function persistLayout() {
    layoutInput.value = JSON.stringify(layout);
    stylesInput.value = JSON.stringify(styles);
    if (nameTopInput) nameTopInput.value = String(layout.participant_name?.y ?? 0);
    if (nameLeftInput) nameLeftInput.value = String(layout.participant_name?.x ?? 0);
  }

  function syncNameFontSize() {
    const value = Number(nameFontSizeInput?.value || layout.participant_name?.font_size || 18);
    if (layout.participant_name) {
      layout.participant_name.font_size = value;
    }
    persistLayout();
    renderCanvas();
  }

  function syncSheetRotation() {
    styles.sheet_rotation = Number(sheetRotationInput?.value || 0) === 90 ? 90 : 0;
    canvas.style.aspectRatio = styles.sheet_rotation === 90 ? '1.414 / 1' : '1 / 1.414';
    persistLayout();
  }

  function renderCanvas() {
    ensureLayout();
    canvas.innerHTML = '';
    syncSheetRotation();
    canvas.style.backgroundImage = assets.background_image ? `url(${<?= json_encode('/uploads/diplomas/', JSON_UNESCAPED_UNICODE) ?>}${encodeURIComponent(String(assets.background_image))})` : '';
    canvas.style.backgroundSize = assets.background_image ? '100% 100%' : '';
    canvas.style.backgroundPosition = assets.background_image ? 'center center' : '';
    ['participant_name'].forEach((key) => {
      const cfg = layout[key];
      if (!cfg || (cfg.visible ?? 1) !== 1) return;
      const el = document.createElement('div');
      el.dataset.key = key;
      el.className = 'tpl-item tpl-item--selected';
      el.style.position = 'absolute';
      el.style.left = `${cfg.x}%`;
      el.style.top = `${cfg.y}%`;
      el.style.width = `${cfg.w}%`;
      el.style.height = `${cfg.h}%`;
      el.style.fontFamily = resolvePreviewFontFamily(cfg.font_family);
      el.style.fontSize = `${cfg.font_size || 12}px`;
      el.style.color = cfg.color || '#334155';
      el.style.textAlign = cfg.align || 'left';
      el.style.lineHeight = cfg.line_height || 1.2;
      el.style.cursor = 'move';
      el.style.userSelect = 'none';
      el.style.overflow = 'hidden';
      el.style.padding = '0';
      el.style.outline = '1px dashed #2563EB';
      el.style.outlineOffset = '0';
      el.textContent = samples[key] || key;

      if ((cfg.visible ?? 1) !== 1) {
        el.style.opacity = '0.25';
      }

      el.addEventListener('mousedown', (e) => {
        dragState = { startX: e.clientX, startY: e.clientY };
        e.preventDefault();
      });
      canvas.appendChild(el);
    });
  }

  window.addEventListener('mousemove', (e) => {
    if (!dragState) return;
    const rect = canvas.getBoundingClientRect();
    const dx = ((e.clientX - dragState.startX) / rect.width) * 100;
    const dy = ((e.clientY - dragState.startY) / rect.height) * 100;
    layout.participant_name.x = Math.max(0, Math.min(99, Number(layout.participant_name.x) + dx));
    layout.participant_name.y = Math.max(0, Math.min(99, Number(layout.participant_name.y) + dy));
    dragState = { startX: e.clientX, startY: e.clientY };
    persistLayout();
    renderCanvas();
  });

  window.addEventListener('mouseup', () => {
    dragState = null;
  });
  nameFontSizeInput?.addEventListener('input', syncNameFontSize);
  sheetRotationInput?.addEventListener('change', () => {
    syncSheetRotation();
    renderCanvas();
  });

  persistLayout();
  renderCanvas();
}
</script>

<style>
.tpl-item--selected {box-shadow:0 0 0 1px #2563EB inset;}

.diploma-editor-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.diploma-editor-tabs__tab {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 11px 16px;
  border: 1px solid #dbe3f1;
  border-radius: 12px;
  background: #f8fafc;
  color: #334155;
  text-decoration: none;
  font-weight: 700;
  transition: .2s ease;
}

.diploma-editor-tabs__tab:hover {
  border-color: #93c5fd;
  color: #1d4ed8;
}

.diploma-editor-tabs__tab.is-active {
  background: linear-gradient(135deg, #eff6ff 0%, #ffffff 100%);
  border-color: #93c5fd;
  color: #1d4ed8;
  box-shadow: 0 12px 30px rgba(37, 99, 235, .1);
}

.diploma-email-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.35fr) minmax(320px, .9fr);
  gap: 18px;
  align-items: start;
}

.diploma-email-layout__main {
  min-width: 0;
}

.diploma-email-card,
.diploma-email-preview__card {
  border: 1px solid #e2e8f0;
  border-radius: 18px;
  background: #fff;
  padding: 18px;
}

.diploma-email-card + .diploma-email-card {
  margin-top: 16px;
}

.diploma-email-card__header,
.diploma-email-preview__header {
  margin-bottom: 16px;
}

.diploma-email-card__header h4,
.diploma-email-preview__header h4,
.diploma-email-block__header h5 {
  margin: 0;
  color: #0f172a;
}

.diploma-email-card__header p,
.diploma-email-preview__header p,
.diploma-email-block__header p {
  margin: 8px 0 0;
  color: #64748b;
  font-size: 14px;
  line-height: 1.5;
}

.diploma-email-blocks {
  display: grid;
  gap: 14px;
}

.diploma-email-block {
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  padding: 16px;
  background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
}

.diploma-email-block__header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
  margin-bottom: 14px;
}

.diploma-email-switch {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
  font-size: 14px;
  font-weight: 700;
  color: #334155;
}

.diploma-email-preview {
  display: grid;
  gap: 16px;
}

.diploma-email-preview__frame {
  width: 100%;
  min-height: 780px;
  border: 1px solid #dbe3f1;
  border-radius: 16px;
  background: #eef2f7;
}

.diploma-email-preview__text {
  margin: 0;
  padding: 16px;
  border-radius: 14px;
  background: #0f172a;
  color: #e2e8f0;
  font-size: 12px;
  line-height: 1.6;
  white-space: pre-wrap;
  overflow-x: auto;
}

@media (max-width: 1180px) {
  .diploma-email-layout {
    grid-template-columns: 1fr;
  }

  .diploma-email-preview__frame {
    min-height: 560px;
  }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
