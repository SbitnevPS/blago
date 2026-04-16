<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (!isAdmin()) {
    redirect('/admin/login');
}

check_csrf();
ensureVkPublicationSchema();

$admin = getCurrentUser();
$currentPage = 'vk-publications';
$pageTitle = 'Создать новое задание на публикацию';
$breadcrumb = 'Публикации в ВК / Новое задание';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'CSRF токен не прошел проверку'], 403);
    }

    $filters = normalizeVkTaskFilters($_POST);
    $template = trim((string) ($_POST['post_template'] ?? ''));
    $preview = buildVkTaskPreview($filters, $template);

    jsonResponse([
        'success' => true,
        'summary' => [
            'total_items' => (int) $preview['total_items'],
            'ready_items' => (int) $preview['ready_items'],
            'skipped_items' => (int) $preview['skipped_items'],
            'skip_reasons' => $preview['skip_reasons'],
            'sample_post_text' => (string) $preview['sample_post_text'],
        ],
        'filters' => $filters,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_task') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Ошибка безопасности (CSRF).';
        redirect('/admin/vk-publication-create');
    }

    $filtersJson = (string) ($_POST['filters_json'] ?? '{}');
    $filters = json_decode($filtersJson, true);
    if (!is_array($filters)) {
        $filters = normalizeVkTaskFilters($_POST);
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $template = trim((string) ($_POST['post_template'] ?? ''));
    $mode = (string) ($_POST['publish_mode'] ?? 'draft');

    $preview = buildVkTaskPreview($filters, $template);

    $taskId = createVkTaskFromPreview($title, (int) getCurrentAdminId(), $preview, $mode === 'publish' ? 'immediate' : 'manual');

    if ($mode === 'publish') {
        publishVkTask($taskId);
        $_SESSION['success_message'] = 'Задание создано и публикация запущена.';
    } else {
        $_SESSION['success_message'] = 'Задание сохранено как черновик.';
    }

    redirect('/admin/vk-publication/' . $taskId);
}

$contests = $pdo->query('SELECT id, title FROM contests ORDER BY created_at DESC')->fetchAll() ?: [];
$settings = getVkPublicationSettings();

require_once __DIR__ . '/includes/header.php';
?>

<?php if (!empty($_SESSION['error_message'])): ?>
    <div class="alert alert--error mb-lg"><i class="fas fa-exclamation-circle"></i> <?= e($_SESSION['error_message']) ?></div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<div class="card">
    <div class="card__header"><h3>Фильтры работ для публикации</h3></div>
    <div class="card__body">
        <form method="POST" id="vkTaskCreateForm">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="csrf_token" id="csrfToken" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="create_task">
            <input type="hidden" name="filters_json" id="filtersJson" value="{}">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Название задания</label>
                    <input type="text" name="title" class="form-input" placeholder="Например: Май 2026 / Принятые работы" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Конкурс</label>
                    <select name="contest_id" class="form-select">
                        <option value="0">Все конкурсы</option>
                        <?php foreach ($contests as $contest): ?>
                            <option value="<?= (int) $contest['id'] ?>"><?= e($contest['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Статус заявки</label>
                    <select name="application_status" class="form-select">
                        <option value="">Любой</option>
                        <?php foreach (getApplicationStatusConfig() as $statusKey => $statusMeta): ?>
                            <option value="<?= e($statusKey) ?>"><?= e($statusMeta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Статус работы</label>
                    <input type="hidden" name="work_status" value="accepted">
                    <input type="text" class="form-input" value="Рисунок принят" disabled>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Регион</label>
                    <input type="text" name="region" class="form-input" placeholder="Например: Московская область">
                </div>
                <div class="form-group">
                    <label class="form-label">Образовательное учреждение</label>
                    <input type="text" name="organization_name" class="form-input" placeholder="Часть названия">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Дата подачи заявки: от</label>
                    <input type="date" name="submitted_from" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Дата подачи заявки: до</label>
                    <input type="date" name="submitted_to" class="form-input">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Дата рассмотрения работы: от</label>
                    <input type="date" name="reviewed_from" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Дата рассмотрения работы: до</label>
                    <input type="date" name="reviewed_to" class="form-input">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Наличие изображения</label>
                    <select name="has_image" class="form-select">
                        <option value="">Не важно</option>
                        <option value="yes">Только с изображением</option>
                        <option value="no">Только без изображения</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Ручной выбор работ (ID через запятую)</label>
                    <input type="text" name="work_ids_raw" class="form-input" placeholder="12, 15, 18">
                </div>
            </div>

            <div class="form-group">
                <label class="form-checkbox">
                    <input type="checkbox" name="exclude_vk_published" value="1" checked>
                    <span class="form-checkbox__mark"></span>
                    <span>Исключать уже опубликованные в VK работы</span>
                </label>
            </div>
            <div class="form-group">
                <label class="form-checkbox">
                    <input type="checkbox" name="required_data_only" value="1">
                    <span class="form-checkbox__mark"></span>
                    <span>Только записи с обязательными данными (ФИО + изображение)</span>
                </label>
            </div>
            <div class="form-group">
                <label class="form-checkbox">
                    <input type="checkbox" name="only_without_vk_errors" value="1">
                    <span class="form-checkbox__mark"></span>
                    <span>Исключать работы с предыдущими ошибками публикации</span>
                </label>
            </div>

            <div class="form-group">
                <label class="form-label">Шаблон текста поста</label>
                <textarea name="post_template" id="postTemplate" class="form-input" rows="8"><?= e($settings['post_template']) ?></textarea>
                <div class="form-hint">Переменные: {participant_name}, {participant_full_name}, {organization_name}, {region_name}, {contest_title}, {participant_age}, {age_category}. Поддерживается legacy-переменная: {nomination}.</div>
                <div class="form-hint" style="margin-top:8px;">Компактный вариант (для ручной вставки):</div>
                <pre style="white-space:pre-wrap; background:#F8FAFC; padding:10px; border-radius:8px; border:1px solid #E2E8F0; margin-top:6px; font-size:12px;"><?= e(compactVkPostTemplate()) ?></pre>
                <div style="margin-top:12px;">
                    <label class="form-label" style="margin-bottom:6px;">Живой предпросмотр шаблона</label>
                    <div id="liveTemplatePreview" style="white-space:pre-wrap; background:#FFFFFF; padding:14px; border-radius:10px; border:1px solid #D1D5DB; line-height:1.5;"></div>
                </div>
            </div>

            <div class="flex gap-md">
                <button type="button" class="btn btn--secondary" id="previewButton"><i class="fas fa-eye"></i> Предпросмотр</button>
                <a href="/admin/vk-publications" class="btn btn--ghost">Отмена</a>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="previewModal">
    <div class="modal__content" style="max-width: 820px;">
        <div class="modal__header">
            <h3 class="modal__title">Сводка перед созданием задания</h3>
            <button type="button" class="modal__close" onclick="closePreviewModal()">&times;</button>
        </div>
        <div class="modal__body">
            <div id="previewSummary" class="text-secondary">Загрузка...</div>
            <hr style="margin:16px 0; border:none; border-top:1px solid #E5E7EB;">
            <div>
                <h4 style="margin-bottom:8px;">Пример текста поста</h4>
                <div id="previewPostText" style="white-space:pre-wrap; background:#FFFFFF; padding:14px; border-radius:10px; border:1px solid #D1D5DB; line-height:1.5;"></div>
            </div>
        </div>
        <div class="modal__footer" style="display:flex; justify-content:flex-end; gap:8px;">
            <button type="button" class="btn btn--ghost" onclick="closePreviewModal()">Отменить</button>
            <button type="button" class="btn btn--secondary" onclick="submitTask('draft')">Сохранить, но не публиковать</button>
            <button type="button" class="btn btn--primary" onclick="submitTask('publish')">Опубликовать</button>
        </div>
    </div>
</div>

<script>
const form = document.getElementById('vkTaskCreateForm');
const previewButton = document.getElementById('previewButton');
const previewModal = document.getElementById('previewModal');
const previewSummary = document.getElementById('previewSummary');
const previewPostText = document.getElementById('previewPostText');
const filtersJsonInput = document.getElementById('filtersJson');
const postTemplateInput = document.getElementById('postTemplate');
const liveTemplatePreview = document.getElementById('liveTemplatePreview');

function closePreviewModal() {
    previewModal.classList.remove('active');
}

async function fetchPreview() {
    const formData = new FormData(form);
    formData.set('action', 'preview');

    const response = await fetch('/admin/vk-publication-create', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    });

    const data = await response.json();
    if (!data.success) {
        throw new Error(data.error || 'Не удалось получить предпросмотр');
    }

    filtersJsonInput.value = JSON.stringify(data.filters || {});

    const summary = data.summary || {};
    const reasons = summary.skip_reasons || {};
    const reasonLines = Object.keys(reasons).map((reason) => `<li>${reason}: ${reasons[reason]}</li>`).join('');

    previewSummary.innerHTML = `
        <div><strong>Всего найдено работ:</strong> ${summary.total_items || 0}</div>
        <div><strong>Готово к публикации:</strong> ${summary.ready_items || 0}</div>
        <div><strong>Будет пропущено:</strong> ${summary.skipped_items || 0}</div>
        ${reasonLines ? `<div style="margin-top:8px;"><strong>Причины пропуска:</strong><ul>${reasonLines}</ul></div>` : ''}
        <div style="margin-top:8px;"><strong>Предупреждение:</strong> если у работы нет изображения или обязательных данных, она будет пропущена и останется в задании со статусом «Пропущено».</div>
    `;

    previewPostText.textContent = summary.sample_post_text || 'Нет данных для примера';
    previewModal.classList.add('active');
}

function renderTemplatePreview(template, values) {
    const lines = String(template || '').split(/\r?\n/u);
    const rendered = [];

    for (const rawLine of lines) {
        const line = rawLine.trim();
        if (!line) {
            rendered.push('');
            continue;
        }

        const tokens = Array.from(line.matchAll(/\{([a-z0-9_]+)\}/giu)).map((item) => item[1]);
        if (tokens.length > 0) {
            const allEmpty = tokens.every((token) => String(values[token] || '').trim() === '');
            if (allEmpty) {
                continue;
            }
        }

        let replaced = line.replace(/\{([a-z0-9_]+)\}/giu, (_, token) => String(values[token] || ''));
        replaced = replaced.trim();
        if (!replaced || /[:：]\s*$/u.test(replaced)) {
            continue;
        }
        const core = replaced.replace(/[\p{Z}\p{P}\p{S}]+/gu, '');
        if (!core) {
            continue;
        }
        rendered.push(replaced);
    }

    const collapsed = [];
    let prevEmpty = true;
    for (const line of rendered) {
        const isEmpty = String(line).trim() === '';
        if (isEmpty) {
            if (prevEmpty) {
                continue;
            }
            collapsed.push('');
            prevEmpty = true;
            continue;
        }
        collapsed.push(line);
        prevEmpty = false;
    }

    return collapsed.join('\n').trim();
}

function updateLiveTemplatePreview() {
    const sampleValues = {
        participant_name: 'Анна',
        participant_full_name: 'Иванова Анна Сергеевна',
        organization_name: 'МБУ ДО «Детская школа искусств №1»',
        region_name: 'Московская область',
        contest_title: 'Мир глазами детей',
        participant_age: '9',
        age_category: '7-10 лет',
        nomination: '',
    };
    const text = renderTemplatePreview(postTemplateInput.value, sampleValues);
    liveTemplatePreview.textContent = text || 'Введите шаблон, чтобы увидеть пример поста.';
}

previewButton.addEventListener('click', async () => {
    try {
        await fetchPreview();
    } catch (error) {
        alert(error.message || 'Ошибка предпросмотра');
    }
});

postTemplateInput.addEventListener('input', updateLiveTemplatePreview);
updateLiveTemplatePreview();

function submitTask(mode) {
    const publishModeInput = document.createElement('input');
    publishModeInput.type = 'hidden';
    publishModeInput.name = 'publish_mode';
    publishModeInput.value = mode;
    form.appendChild(publishModeInput);
    form.submit();
}

window.addEventListener('click', (event) => {
    if (event.target === previewModal) {
        closePreviewModal();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
