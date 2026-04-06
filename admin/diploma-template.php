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
        $ctx = getWorkDiplomaContext($workId);
        if ($ctx && ($ctx['work_status'] ?? '') === 'pending') {
            updateWorkStatus($workId, $selectedTemplate['template_type'] === 'encouragement' ? 'reviewed' : 'accepted');
        }
        $diploma = generateWorkDiploma($workId, true);
        $file = ROOT_PATH . '/' . $diploma['file_path'];
        if (is_file($file)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="preview.pdf"');
            readfile($file);
            exit;
        }
    }
}

$layout = json_decode((string)($selectedTemplate['layout_json'] ?? ''), true);
if (!is_array($layout) || !$layout) {
    $layout = defaultDiplomaLayout();
}

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
        <form method="POST" id="templateEditorForm">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="template_id" value="<?= (int)$selectedTemplateId ?>">
            <input type="hidden" name="layout_json" id="layoutJsonInput" value='<?= e(json_encode($layout, JSON_UNESCAPED_UNICODE)) ?>'>
            <input type="hidden" name="styles_json" id="stylesJsonInput" value='<?= e((string)($selectedTemplate['styles_json'] ?? '{}')) ?>'>
            <input type="hidden" name="assets_json" id="assetsJsonInput" value='<?= e((string)($selectedTemplate['assets_json'] ?? '{}')) ?>'>

            <div class="grid grid--2">
                <div class="form-group"><label class="form-label">Название шаблона</label><input class="form-input" name="title" value="<?= e($selectedTemplate['title']) ?>"></div>
                <div class="form-group"><label class="form-label">Тип</label><input class="form-input" value="<?= e($templateTypes[$selectedTemplate['template_type']] ?? $selectedTemplate['template_type']) ?>" disabled></div>
                <div class="form-group"><label class="form-label">Заголовок</label><input class="form-input" name="subtitle" value="<?= e($selectedTemplate['subtitle']) ?>"></div>
                <div class="form-group"><label class="form-label">Префикс номера</label><input class="form-input" name="diploma_prefix" value="<?= e($selectedTemplate['diploma_prefix']) ?>"></div>
                <div class="form-group"><label class="form-label">Формулировка награждения</label><textarea class="form-textarea" name="award_text" rows="2"><?= e($selectedTemplate['award_text']) ?></textarea></div>
                <div class="form-group"><label class="form-label">Название конкурса (текст)</label><input class="form-input" name="contest_name_text" value="<?= e($selectedTemplate['contest_name_text']) ?>"></div>
                <div class="form-group"><label class="form-label">Основной текст</label><textarea class="form-textarea" name="body_text" rows="4"><?= e($selectedTemplate['body_text']) ?></textarea></div>
                <div class="form-group"><label class="form-label">Дружелюбное сообщение</label><textarea class="form-textarea" name="easter_text" rows="4"><?= e($selectedTemplate['easter_text']) ?></textarea></div>
                <div class="form-group"><label class="form-label">Подпись 1</label><input class="form-input" name="signature_1" value="<?= e($selectedTemplate['signature_1']) ?>"></div>
                <div class="form-group"><label class="form-label">Подпись 2</label><input class="form-input" name="signature_2" value="<?= e($selectedTemplate['signature_2']) ?>"></div>
                <div class="form-group"><label class="form-label">Должность 1</label><input class="form-input" name="position_1" value="<?= e($selectedTemplate['position_1']) ?>"></div>
                <div class="form-group"><label class="form-label">Должность 2</label><input class="form-input" name="position_2" value="<?= e($selectedTemplate['position_2']) ?>"></div>
                <div class="form-group"><label class="form-label">Footer</label><textarea class="form-textarea" name="footer_text" rows="3"><?= e($selectedTemplate['footer_text']) ?></textarea></div>
                <div class="form-group"><label class="form-label">Город</label><input class="form-input" name="city" value="<?= e($selectedTemplate['city']) ?>"></div>
                <div class="form-group"><label class="form-label">Дата</label><input type="date" class="form-input" name="issue_date" value="<?= e($selectedTemplate['issue_date']) ?>"></div>
                <div class="form-group">
                    <label class="form-label">Фон/рамка (assets_json)</label>
                    <textarea class="form-textarea" name="assets_json_manual" id="assetsJsonManual" rows="3"><?= e((string)($selectedTemplate['assets_json'] ?? '{}')) ?></textarea>
                </div>
            </div>

            <div class="flex gap-lg" style="margin:16px 0; flex-wrap:wrap;">
                <label><input type="checkbox" name="show_date" value="1" <?= (int)$selectedTemplate['show_date'] === 1 ? 'checked' : '' ?>> Показывать дату</label>
                <label><input type="checkbox" name="show_number" value="1" <?= (int)$selectedTemplate['show_number'] === 1 ? 'checked' : '' ?>> Показывать номер</label>
                <label><input type="checkbox" name="show_signatures" value="1" <?= (int)$selectedTemplate['show_signatures'] === 1 ? 'checked' : '' ?>> Подписи</label>
                <label><input type="checkbox" name="show_background" value="1" <?= (int)$selectedTemplate['show_background'] === 1 ? 'checked' : '' ?>> Фон</label>
                <label><input type="checkbox" name="show_frame" value="1" <?= (int)$selectedTemplate['show_frame'] === 1 ? 'checked' : '' ?>> Рамка</label>
            </div>

            <div style="display:grid;grid-template-columns: minmax(0, 2fr) minmax(320px, 1fr);gap:18px;align-items:start;">
                <div>
                    <h4 class="mb-md">Холст A4 (предпросмотр)</h4>
                    <div id="diplomaCanvas" style="position:relative;width:100%;aspect-ratio:1/1.414;background:linear-gradient(180deg,#FFF9E6 0%, #F7FBFF 50%, #FFF1F2 100%);border:6px solid #EAB308;border-radius:8px;overflow:hidden;">
                    </div>
                </div>
                <div>
                    <h4 class="mb-md">Элементы и свойства</h4>
                    <div class="form-group">
                        <label class="form-label">Элемент</label>
                        <select id="elementSelect" class="form-select"></select>
                    </div>
                    <div id="elementProps" class="grid grid--2"></div>
                    <div class="alert" style="margin-top:10px;background:#EEF2FF;border:1px solid #C7D2FE;color:#3730A3;">
                        Поддержка кириллицы в PDF: используйте шрифт <strong>dejavusans</strong> (по умолчанию).
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
const defaults = <?= json_encode(defaultDiplomaLayout(), JSON_UNESCAPED_UNICODE) ?>;
let layout = JSON.parse(document.getElementById('layoutJsonInput').value || '{}');
if (!layout || typeof layout !== 'object') layout = {...defaults};

const samples = {
  title: 'Благодарственный диплом',
  subtitle: 'Награждается',
  participant_name: 'Иванов Иван Иванович',
  award_text: 'за творческое старание и фантазию',
  body_text: 'Спасибо за участие в конкурсе детского рисунка!',
  contest_title: 'Весенняя палитра',
  diploma_number: '№ TEST-00001',
  date: '01.04.2026',
  city: 'Москва',
  signatures: 'Председатель / Куратор',
  footer: 'Доступен благодарственный диплом',
};

const canvas = document.getElementById('diplomaCanvas');
const elementSelect = document.getElementById('elementSelect');
const elementProps = document.getElementById('elementProps');
const layoutInput = document.getElementById('layoutJsonInput');
const assetsManual = document.getElementById('assetsJsonManual');

let selectedKey = Object.keys(layout)[0] || 'title';

function ensureLayout() {
  Object.keys(defaults).forEach((key) => {
    if (!layout[key]) layout[key] = {...defaults[key]};
  });
}

function persistLayout() {
  layoutInput.value = JSON.stringify(layout);
  if (assetsManual) {
    document.getElementById('assetsJsonInput').value = assetsManual.value || '{}';
  }
}

function renderCanvas() {
  ensureLayout();
  canvas.innerHTML = '';
  Object.entries(layout).forEach(([key, cfg]) => {
    const el = document.createElement('div');
    el.dataset.key = key;
    el.className = 'tpl-item' + (key === selectedKey ? ' tpl-item--selected' : '');
    el.style.position = 'absolute';
    el.style.left = `${cfg.x}%`;
    el.style.top = `${cfg.y}%`;
    el.style.width = `${cfg.w}%`;
    el.style.height = `${cfg.h}%`;
    el.style.fontFamily = cfg.font_family || 'dejavusans';
    el.style.fontSize = `${cfg.font_size || 12}px`;
    el.style.color = cfg.color || '#334155';
    el.style.textAlign = cfg.align || 'left';
    el.style.lineHeight = cfg.line_height || 1.2;
    el.style.cursor = 'move';
    el.style.userSelect = 'none';
    el.style.overflow = 'hidden';
    el.style.padding = '2px';
    el.style.border = key === selectedKey ? '1px dashed #2563EB' : '1px dashed transparent';
    el.textContent = samples[key] || key;

    if ((cfg.visible ?? 1) !== 1) {
      el.style.opacity = '0.25';
    }

    let dragging = false;
    let startX = 0;
    let startY = 0;

    el.addEventListener('mousedown', (e) => {
      selectedKey = key;
      dragging = true;
      startX = e.clientX;
      startY = e.clientY;
      e.preventDefault();
      renderAll();
    });

    window.addEventListener('mousemove', (e) => {
      if (!dragging || selectedKey !== key) return;
      const rect = canvas.getBoundingClientRect();
      const dx = ((e.clientX - startX) / rect.width) * 100;
      const dy = ((e.clientY - startY) / rect.height) * 100;
      layout[key].x = Math.max(0, Math.min(99, Number(layout[key].x) + dx));
      layout[key].y = Math.max(0, Math.min(99, Number(layout[key].y) + dy));
      startX = e.clientX;
      startY = e.clientY;
      persistLayout();
      renderAll();
    });

    window.addEventListener('mouseup', () => { dragging = false; });
    canvas.appendChild(el);
  });
}

function renderSelector() {
  elementSelect.innerHTML = '';
  Object.keys(layout).forEach((key) => {
    const option = document.createElement('option');
    option.value = key;
    option.textContent = key;
    if (key === selectedKey) option.selected = true;
    elementSelect.appendChild(option);
  });
}

function buildField(label, key, type = 'number', step = '1') {
  const cfg = layout[selectedKey];
  const wrap = document.createElement('div');
  wrap.className = 'form-group';
  const lbl = document.createElement('label');
  lbl.className = 'form-label';
  lbl.textContent = label;
  const inp = document.createElement('input');
  inp.className = 'form-input';
  inp.type = type;
  if (type === 'number') inp.step = step;
  inp.value = cfg[key] ?? '';
  inp.addEventListener('input', () => {
    let value = inp.value;
    if (type === 'number') value = Number(value);
    layout[selectedKey][key] = value;
    persistLayout();
    renderCanvas();
  });
  wrap.appendChild(lbl);
  wrap.appendChild(inp);
  return wrap;
}

function renderProps() {
  elementProps.innerHTML = '';
  elementProps.appendChild(buildField('X (%)', 'x', 'number', '0.1'));
  elementProps.appendChild(buildField('Y (%)', 'y', 'number', '0.1'));
  elementProps.appendChild(buildField('Width (%)', 'w', 'number', '0.1'));
  elementProps.appendChild(buildField('Height (%)', 'h', 'number', '0.1'));
  elementProps.appendChild(buildField('Font', 'font_family', 'text'));
  elementProps.appendChild(buildField('Size', 'font_size', 'number', '1'));
  elementProps.appendChild(buildField('Color (#RRGGBB)', 'color', 'text'));
  elementProps.appendChild(buildField('Align (left/center/right)', 'align', 'text'));
  elementProps.appendChild(buildField('Line-height', 'line_height', 'number', '0.1'));

  const visWrap = document.createElement('div');
  visWrap.className = 'form-group';
  const visLbl = document.createElement('label');
  visLbl.className = 'form-label';
  visLbl.textContent = 'Visible';
  const vis = document.createElement('input');
  vis.type = 'checkbox';
  vis.checked = Number(layout[selectedKey].visible ?? 1) === 1;
  vis.addEventListener('change', () => {
    layout[selectedKey].visible = vis.checked ? 1 : 0;
    persistLayout();
    renderCanvas();
  });
  visWrap.appendChild(visLbl);
  visWrap.appendChild(vis);
  elementProps.appendChild(visWrap);
}

function renderAll() {
  renderSelector();
  renderProps();
  renderCanvas();
}

elementSelect.addEventListener('change', () => {
  selectedKey = elementSelect.value;
  renderAll();
});

persistLayout();
renderAll();
</script>

<style>
.tpl-item--selected {box-shadow:0 0 0 1px #2563EB inset;}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
