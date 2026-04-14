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
if ($selectedTemplateId <= 0 && !empty($templates)) {
    $selectedTemplateId = (int)$templates[0]['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Ошибка CSRF токена';
        redirect('/admin/diploma-template/' . $contestId . '?template_id=' . $selectedTemplateId);
    }

    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'create_template') {
        $newType = (string)($_POST['template_type'] ?? 'contest_participant');
        if (!array_key_exists($newType, $templateTypes)) {
            $newType = 'contest_participant';
        }
        $new = getOrCreateDiplomaTemplate($contestId, $newType);
        $_SESSION['success_message'] = 'Шаблон создан';
        redirect('/admin/diploma-template/' . $contestId . '?template_id=' . (int)$new['id']);
    }

    if ($action === 'duplicate_template') {
        $dupFrom = (int)($_POST['template_id'] ?? 0);
        $newId = duplicateDiplomaTemplate($dupFrom);
        $_SESSION['success_message'] = 'Шаблон продублирован';
        redirect('/admin/diploma-template/' . $contestId . '?template_id=' . $newId);
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
        redirect('/admin/diploma-template/' . $contestId . '?template_id=' . $tid);
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
if (!$selectedTemplate) {
    $selectedTemplate = $templates[0] ?? getOrCreateDiplomaTemplate($contestId, 'contest_participant');
}
$selectedTemplateId = (int)$selectedTemplate['id'];

if (isset($_GET['preview']) && (int)$_GET['preview'] === 1) {
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

$currentPage = 'diplomas';
$pageTitle = 'Шаблоны дипломов';
$breadcrumb = 'Дипломы / Шаблоны';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card mb-lg">
    <div class="card__header"><h3>Шаблоны дипломов: <?= e($contest['title']) ?></h3></div>
    <div class="card__body">
        <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="action" value="create_template">
            <label class="form-label" style="margin:0;">Создать шаблон
                <select class="form-select" name="template_type">
                    <?php foreach ($templateTypes as $type => $label): ?>
                        <option value="<?= e($type) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn--primary" type="submit"><i class="fas fa-plus"></i> Создать</button>
        </form>

        <div style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;">
            <?php foreach ($templates as $tpl): ?>
                <div class="card" style="border:1px solid #E5E7EB;">
                    <div class="card__body" style="padding:12px;">
                        <div class="font-semibold"><?= e($tpl['title']) ?></div>
                        <div class="text-secondary" style="font-size:12px;"><?= e($templateTypes[$tpl['template_type']] ?? $tpl['template_type']) ?></div>
                        <div style="margin-top:8px;display:flex;gap:8px;">
                            <a class="btn btn--ghost btn--sm" href="/admin/diploma-template/<?= (int)$contestId ?>?template_id=<?= (int)$tpl['id'] ?>">Редактировать</a>
                            <form method="POST">
                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                <input type="hidden" name="action" value="duplicate_template">
                                <input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>">
                                <button class="btn btn--secondary btn--sm" type="submit">Дублировать</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card__header"><h3>Визуальный редактор шаблона</h3></div>
    <div class="card__body">
        <form method="POST" id="templateEditorForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="action" value="save">
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
                <a class="btn btn--ghost" href="/admin/diploma-template/<?= (int)$contestId ?>?template_id=<?= (int)$selectedTemplateId ?>&preview=1" target="_blank" rel="noopener">Предпросмотр PDF</a>
                <a class="btn btn--ghost" href="/admin/diplomas">Назад</a>
            </div>
        </form>
    </div>
</div>

<script>
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
</script>

<style>
.tpl-item--selected {box-shadow:0 0 0 1px #2563EB inset;}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
