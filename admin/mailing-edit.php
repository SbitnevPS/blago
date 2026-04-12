<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (!isAdmin()) {
    redirect('/admin/login');
}

$admin = getCurrentUser();
$currentPage = 'mailings';
$pageTitle = 'Редактор рассылки';
$breadcrumb = 'Рассылки / Редактор';
$pageStyles = ['admin-mailings.css'];

$mailingId = max(0, (int) ($_GET['id'] ?? 0));
$duplicateId = max(0, (int) ($_GET['duplicate'] ?? 0));

$mailing = null;
if ($mailingId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM mailing_campaigns WHERE id = ? LIMIT 1');
    $stmt->execute([$mailingId]);
    $mailing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$mailing && $duplicateId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM mailing_campaigns WHERE id = ? LIMIT 1');
    $stmt->execute([$duplicateId]);
    $mailing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($mailing) {
        $mailing['id'] = 0;
        $mailing['subject'] = 'Копия: ' . (string) ($mailing['subject'] ?? '');
        $mailing['status'] = 'draft';
    }
}

if (!$mailing) {
    $mailing = [
        'id' => 0,
        'subject' => '',
        'body' => '',
        'filters_json' => json_encode(['contest_id' => 0, 'min_participants' => 0, 'include_blacklist' => 0], JSON_UNESCAPED_UNICODE),
        'selection_mode' => 'all',
        'exclusions_json' => '[]',
        'inclusions_json' => '[]',
        'total_recipients' => 0,
        'status' => 'draft',
    ];
}

$filters = json_decode((string) ($mailing['filters_json'] ?? '{}'), true);
if (!is_array($filters)) {
    $filters = ['contest_id' => 0, 'min_participants' => 0, 'include_blacklist' => 0];
}

$attachments = [];
if ((int) ($mailing['id'] ?? 0) > 0) {
    $stmt = $pdo->prepare('SELECT * FROM mailing_attachments WHERE mailing_id = ? ORDER BY id ASC');
    $stmt->execute([(int) $mailing['id']]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$contests = $pdo->query('SELECT id, title FROM contests ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$minOptions = array_merge([0], range(5, 50, 5));

require_once __DIR__ . '/includes/header.php';
?>

<div class="mailing-editor" data-mailing-id="<?= (int) ($mailing['id'] ?? 0) ?>">
    <input type="hidden" id="csrfToken" value="<?= htmlspecialchars(csrf_token()) ?>">

    <section class="card mb-lg">
        <div class="card__header"><h3>Сообщение рассылки</h3></div>
        <div class="card__body">
            <div class="form-group">
                <label class="form-label form-label--required">Тема письма</label>
                <input type="text" class="form-input" id="mailingSubject" value="<?= htmlspecialchars((string) ($mailing['subject'] ?? '')) ?>" placeholder="Введите тему письма">
            </div>
            <div class="form-group">
                <label class="form-label form-label--required">Текст письма</label>
                <textarea id="mailingBody" class="form-textarea js-rich-editor" rows="12" data-editor-upload-url="/admin/ckeditor-image-upload"><?= htmlspecialchars((string) ($mailing['body'] ?? '')) ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Вложения</label>
                <div id="existingAttachments" class="mailing-attachments-list">
                    <?php foreach ($attachments as $a): ?>
                        <div class="mailing-attachment-item" data-attachment-id="<?= (int) $a['id'] ?>">
                            <span><?= htmlspecialchars((string) $a['original_name']) ?> (<?= round(((int)$a['file_size']) / 1024, 1) ?> KB)</span>
                            <button type="button" class="btn btn--ghost btn--sm js-remove-existing-attachment"><i class="fas fa-times"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="attachmentInputs"></div>
                <button type="button" class="btn btn--secondary btn--sm" id="addAttachmentInput"><i class="fas fa-plus"></i> Добавить файл</button>
                <p class="form-help">Допустимые форматы: pdf, doc, docx, xls, xlsx, jpg, jpeg, png, zip. До 10MB на файл.</p>
            </div>
        </div>
    </section>

    <section class="card mb-lg">
        <div class="card__header"><h3>Фильтры получателей</h3></div>
        <div class="card__body mailing-filters-grid">
            <div class="form-group">
                <label class="form-label">Конкурс</label>
                <select id="filterContest" class="form-input">
                    <option value="0">Все</option>
                    <?php foreach ($contests as $contest): ?>
                        <option value="<?= (int) $contest['id'] ?>" <?= (int) ($filters['contest_id'] ?? 0) === (int) $contest['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $contest['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Минимум участников в заявке</label>
                <select id="filterMinParticipants" class="form-input">
                    <?php foreach ($minOptions as $option): ?>
                        <option value="<?= $option ?>" <?= (int) ($filters['min_participants'] ?? 0) === $option ? 'selected' : '' ?>><?= $option === 0 ? 'Не учитывать' : ('От ' . $option . ' участников') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mailing-filter-checkbox">
                <label><input type="checkbox" id="filterIncludeBlacklist" <?= !empty($filters['include_blacklist']) ? 'checked' : '' ?>> Даже тем, кто в чёрном списке</label>
            </div>
            <div class="mailing-filter-actions">
                <button type="button" class="btn btn--primary" id="applyFiltersBtn"><i class="fas fa-filter"></i> Применить фильтрацию</button>
                <button type="button" class="btn btn--secondary" id="resetFiltersBtn">Сброс</button>
            </div>
        </div>
    </section>

    <section class="card mb-lg">
        <div class="card__body">
            <div class="mailing-selection-toolbar">
                <input type="text" class="form-input" id="recipientSearch" placeholder="Поиск по ФИО или email">
                <button type="button" class="btn btn--secondary" id="resetSearchBtn">Сброс</button>
                <button type="button" class="btn btn--ghost" id="selectAllBtn">Выделить все</button>
                <button type="button" class="btn btn--ghost" id="clearAllBtn">Снять выделение</button>
            </div>
            <div id="selectionInfo" class="mailing-info-card" style="display:none;"></div>
            <div id="recipientsContainer" class="mailing-recipients-list">
                <div class="mailing-empty">Список пока пуст. Выберите фильтры и нажмите «Применить фильтрацию».</div>
            </div>
            <div class="mailing-pagination" id="recipientsPagination" style="display:none;">
                <div>
                    <label>На странице:
                        <select id="perPageSelect" class="form-input form-input--sm">
                            <option value="20">20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="200">200</option>
                        </select>
                    </label>
                </div>
                <div class="mailing-pagination__buttons">
                    <button type="button" class="btn btn--ghost btn--sm" id="prevPageBtn">Назад</button>
                    <span id="pageInfo">Страница 1</span>
                    <button type="button" class="btn btn--ghost btn--sm" id="nextPageBtn">Вперёд</button>
                </div>
            </div>
        </div>
    </section>

    <section class="mailing-actions mb-lg">
        <button type="button" class="btn btn--primary" id="startMailingBtn">Начать рассылку</button>
        <button type="button" class="btn btn--secondary" id="saveMailingBtn">Сохранить рассылку и закрыть</button>
        <button type="button" class="btn btn--ghost" id="closeWithoutChangesBtn">Закрыть без изменений</button>
        <button type="button" class="btn btn--ghost" id="downloadListBtn">Скачать список рассылки</button>
    </section>
</div>

<div class="mailing-progress-modal" id="mailingProgressModal" style="display:none;">
    <div class="mailing-progress-modal__dialog card">
        <div class="card__header"><h3>Процесс рассылки</h3></div>
        <div class="card__body">
            <p id="progressSummary">Подготовка…</p>
            <div class="mailing-progress"><div class="mailing-progress__bar" id="progressBar"></div></div>
            <div class="mailing-progress-stats" id="progressStats"></div>
            <div class="mt-md">
                <button type="button" class="btn btn--danger" id="stopMailingBtn">Остановить рассылку</button>
                <button type="button" class="btn btn--secondary" id="closeProgressBtn">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@5.10.9/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(() => {
    const state = {
        mailingId: Number(document.querySelector('.mailing-editor')?.dataset.mailingId || 0),
        filtersApplied: false,
        filters: {
            contest_id: Number(document.getElementById('filterContest').value || 0),
            min_participants: Number(document.getElementById('filterMinParticipants').value || 0),
            include_blacklist: document.getElementById('filterIncludeBlacklist').checked ? 1 : 0,
        },
        selection_mode: <?= json_encode((string) ($mailing['selection_mode'] ?? 'all')) ?>,
        exclusions: <?= json_encode(mailingDecodeIdsJson($mailing['exclusions_json'] ?? '[]')) ?>,
        inclusions: <?= json_encode(mailingDecodeIdsJson($mailing['inclusions_json'] ?? '[]')) ?>,
        page: 1,
        perPage: 20,
        total: <?= (int) ($mailing['total_recipients'] ?? 0) ?>,
        query: '',
        hasChanges: false,
        running: false,
    };

    const csrfToken = document.getElementById('csrfToken').value;

    const recContainer = document.getElementById('recipientsContainer');
    const infoBlock = document.getElementById('selectionInfo');
    const pagination = document.getElementById('recipientsPagination');

    const markChanged = () => { state.hasChanges = true; };

    const readFilters = () => ({
        contest_id: Number(document.getElementById('filterContest').value || 0),
        min_participants: Number(document.getElementById('filterMinParticipants').value || 0),
        include_blacklist: document.getElementById('filterIncludeBlacklist').checked ? 1 : 0,
    });

    const selectedByDefault = (userId) => {
        const id = Number(userId);
        return state.selection_mode === 'all'
            ? !state.exclusions.includes(id)
            : state.inclusions.includes(id);
    };

    const renderInfo = () => {
        infoBlock.style.display = 'block';
        infoBlock.innerHTML = `
            <strong>Выбраны все пользователи, подходящие под условия фильтрации.</strong><br>
            Конкурс: ${state.filters.contest_id > 0 ? 'ID ' + state.filters.contest_id : 'Все'} ·
            Минимум участников: ${state.filters.min_participants > 0 ? state.filters.min_participants : 'Не учитывать'} ·
            Blacklist: ${state.filters.include_blacklist ? 'учитывать' : 'исключать'} ·
            Найдено: ${state.total}
        `;
    };

    const renderRecipients = (rows) => {
        if (!rows.length) {
            recContainer.innerHTML = '<div class="mailing-empty">По текущим условиям ничего не найдено.</div>';
            return;
        }

        recContainer.innerHTML = rows.map((row) => {
            const checked = row.is_selected ? 'checked' : '';
            const muted = row.is_selected ? '' : ' mailing-recipient--muted';
            return `
                <label class="mailing-recipient${muted}" data-user-id="${row.id}">
                    <input type="checkbox" class="recipient-toggle" data-user-id="${row.id}" ${checked}>
                    <div>
                        <div class="mailing-recipient__name">${(row.display_name || 'Без имени')}</div>
                        <div class="mailing-recipient__email">${row.email || ''}</div>
                        <div class="mailing-recipient__meta">Участников: ${row.max_participants || 0} · Blacklist: ${Number(row.is_blacklisted) ? 'да' : 'нет'}</div>
                    </div>
                </label>
            `;
        }).join('');

        recContainer.querySelectorAll('.recipient-toggle').forEach((checkbox) => {
            checkbox.addEventListener('change', () => {
                const userId = Number(checkbox.dataset.userId || 0);
                const checked = checkbox.checked;
                const label = checkbox.closest('.mailing-recipient');
                if (label) label.classList.toggle('mailing-recipient--muted', !checked);

                if (state.selection_mode === 'all') {
                    state.exclusions = checked ? state.exclusions.filter((id) => id !== userId) : Array.from(new Set([...state.exclusions, userId]));
                } else {
                    state.inclusions = checked ? Array.from(new Set([...state.inclusions, userId])) : state.inclusions.filter((id) => id !== userId);
                }
                markChanged();
            });
        });
    };

    const loadRecipients = async () => {
        if (!state.filtersApplied) return;
        recContainer.innerHTML = '<div class="mailing-empty">Загрузка…</div>';

        const payload = {
            action: 'recipients',
            filters: state.filters,
            selection_mode: state.selection_mode,
            exclusions: state.exclusions,
            inclusions: state.inclusions,
            page: state.page,
            per_page: state.perPage,
            query: state.query,
        };

        const response = await fetch('/admin/mailings-api', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await response.json();
        state.total = Number(data.total || 0);
        renderInfo();
        renderRecipients(Array.isArray(data.items) ? data.items : []);
        pagination.style.display = 'flex';
        document.getElementById('pageInfo').textContent = `Страница ${state.page} из ${Math.max(1, Math.ceil(state.total / state.perPage))}`;
    };

    const buildSaveFormData = (withClose) => {
        const fd = new FormData();
        fd.append('action', 'save');
        fd.append('csrf_token', csrfToken);
        fd.append('id', String(state.mailingId));
        fd.append('subject', document.getElementById('mailingSubject').value.trim());
        fd.append('body', tinymce.get('mailingBody')?.getContent() || document.getElementById('mailingBody').value || '');
        fd.append('filters_json', JSON.stringify(state.filters));
        fd.append('selection_mode', state.selection_mode);
        fd.append('exclusions_json', JSON.stringify(state.exclusions));
        fd.append('inclusions_json', JSON.stringify(state.inclusions));
        fd.append('total_recipients', String(state.total));
        fd.append('status', 'saved');
        fd.append('with_close', withClose ? '1' : '0');

        document.querySelectorAll('input[name="attachments[]"]').forEach((input) => {
            if (input.files?.[0]) fd.append('attachments[]', input.files[0]);
        });

        document.querySelectorAll('.mailing-attachment-item[data-attachment-id].is-marked-delete').forEach((row) => {
            fd.append('delete_attachment_ids[]', row.dataset.attachmentId || '0');
        });

        return fd;
    };

    const saveMailing = async (withClose = false) => {
        const subject = document.getElementById('mailingSubject').value.trim();
        const body = tinymce.get('mailingBody')?.getContent({ format: 'text' }).trim() || '';
        if (!subject || !body) {
            alert('Заполните тему и текст письма.');
            return null;
        }

        const response = await fetch('/admin/mailings-api', { method: 'POST', body: buildSaveFormData(withClose) });
        const data = await response.json();
        if (!data.success) {
            alert(data.message || 'Не удалось сохранить.');
            return null;
        }

        state.mailingId = Number(data.id || state.mailingId);
        state.hasChanges = false;
        if (withClose) {
            window.location.href = '/admin/mailings';
        }

        return data;
    };

    const processBatch = async () => {
        if (!state.running || !state.mailingId) return;

        const resp = await fetch('/admin/mailings-api', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'process_batch', id: state.mailingId }),
        });
        const data = await resp.json();

        const total = Number(data.total || 0);
        const sent = Number(data.sent || 0);
        const failed = Number(data.failed || 0);
        const percent = total > 0 ? Math.round((sent / total) * 100) : 0;

        document.getElementById('progressBar').style.width = percent + '%';
        document.getElementById('progressSummary').textContent = `Отправлено ${sent} из ${total}`;
        document.getElementById('progressStats').textContent = `Осталось: ${Math.max(total - sent - failed, 0)} · Ошибок: ${failed}`;

        if (data.status === 'completed' || data.status === 'stopped') {
            state.running = false;
            alert(data.status === 'completed' ? 'Рассылка успешно завершена.' : 'Рассылка остановлена.');
            return;
        }

        setTimeout(processBatch, 500);
    };

    document.getElementById('applyFiltersBtn').addEventListener('click', async () => {
        state.filters = readFilters();
        state.selection_mode = 'all';
        state.exclusions = [];
        state.inclusions = [];
        state.page = 1;
        state.query = '';
        state.filtersApplied = true;
        markChanged();
        await loadRecipients();
    });

    document.getElementById('resetFiltersBtn').addEventListener('click', () => {
        document.getElementById('filterContest').value = '0';
        document.getElementById('filterMinParticipants').value = '0';
        document.getElementById('filterIncludeBlacklist').checked = false;
        state.filtersApplied = false;
        state.filters = { contest_id: 0, min_participants: 0, include_blacklist: 0 };
        state.selection_mode = 'all';
        state.exclusions = [];
        state.inclusions = [];
        state.total = 0;
        recContainer.innerHTML = '<div class="mailing-empty">Список пока пуст. Выберите фильтры и нажмите «Применить фильтрацию».</div>';
        infoBlock.style.display = 'none';
        pagination.style.display = 'none';
        markChanged();
    });

    document.getElementById('recipientSearch').addEventListener('input', async (e) => {
        state.query = e.target.value.trim();
        state.page = 1;
        await loadRecipients();
    });

    document.getElementById('resetSearchBtn').addEventListener('click', async () => {
        document.getElementById('recipientSearch').value = '';
        state.query = '';
        state.page = 1;
        await loadRecipients();
    });

    document.getElementById('perPageSelect').addEventListener('change', async (e) => {
        state.perPage = Number(e.target.value || 20);
        state.page = 1;
        await loadRecipients();
    });

    document.getElementById('prevPageBtn').addEventListener('click', async () => {
        state.page = Math.max(1, state.page - 1);
        await loadRecipients();
    });

    document.getElementById('nextPageBtn').addEventListener('click', async () => {
        const maxPage = Math.max(1, Math.ceil(state.total / state.perPage));
        state.page = Math.min(maxPage, state.page + 1);
        await loadRecipients();
    });

    document.getElementById('selectAllBtn').addEventListener('click', async () => {
        state.selection_mode = 'all';
        state.exclusions = [];
        state.inclusions = [];
        markChanged();
        await loadRecipients();
    });

    document.getElementById('clearAllBtn').addEventListener('click', async () => {
        state.selection_mode = 'none';
        state.exclusions = [];
        state.inclusions = [];
        markChanged();
        await loadRecipients();
    });

    document.getElementById('saveMailingBtn').addEventListener('click', async () => {
        await saveMailing(true);
    });

    document.getElementById('closeWithoutChangesBtn').addEventListener('click', () => {
        if (state.hasChanges && !confirm('Есть несохранённые изменения. Закрыть без сохранения?')) {
            return;
        }
        window.location.href = '/admin/mailings';
    });

    document.getElementById('downloadListBtn').addEventListener('click', async () => {
        if (!state.mailingId) {
            const saved = await saveMailing(false);
            if (!saved?.id) return;
        }
        window.open(`/admin/mailings-api?action=download&id=${state.mailingId}`, '_blank');
    });

    document.getElementById('startMailingBtn').addEventListener('click', async () => {
        const saved = await saveMailing(false);
        if (!saved?.id) return;

        const resp = await fetch('/admin/mailings-api', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'start', id: state.mailingId }),
        });
        const data = await resp.json();
        if (!data.success) {
            alert(data.message || 'Не удалось запустить рассылку.');
            return;
        }

        document.getElementById('mailingProgressModal').style.display = 'flex';
        state.running = true;
        processBatch();
    });

    document.getElementById('stopMailingBtn').addEventListener('click', async () => {
        if (!state.mailingId) return;
        await fetch('/admin/mailings-api', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'stop', id: state.mailingId }),
        });
        state.running = false;
    });

    document.getElementById('closeProgressBtn').addEventListener('click', () => {
        document.getElementById('mailingProgressModal').style.display = 'none';
    });

    document.getElementById('addAttachmentInput').addEventListener('click', () => {
        const wrap = document.createElement('div');
        wrap.className = 'mailing-attachment-item';
        wrap.innerHTML = `<input type="file" name="attachments[]" class="form-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip,.txt"><button type="button" class="btn btn--ghost btn--sm"><i class="fas fa-times"></i></button>`;
        wrap.querySelector('button').addEventListener('click', () => wrap.remove());
        document.getElementById('attachmentInputs').appendChild(wrap);
    });

    document.querySelectorAll('.js-remove-existing-attachment').forEach((btn) => {
        btn.addEventListener('click', () => {
            const row = btn.closest('.mailing-attachment-item');
            if (!row) return;
            row.classList.toggle('is-marked-delete');
            row.style.opacity = row.classList.contains('is-marked-delete') ? '0.5' : '1';
            markChanged();
        });
    });

    document.getElementById('mailingSubject').addEventListener('input', markChanged);
    document.getElementById('filterContest').addEventListener('change', markChanged);
    document.getElementById('filterMinParticipants').addEventListener('change', markChanged);
    document.getElementById('filterIncludeBlacklist').addEventListener('change', markChanged);

    if (window.tinymce) {
        tinymce.init({
            selector: '#mailingBody',
            menubar: false,
            branding: false,
            language: 'ru',
            plugins: 'link image lists table code fullscreen',
            toolbar: 'undo redo | bold italic underline | bullist numlist | alignleft aligncenter alignright | link image | code fullscreen',
            images_upload_url: '/admin/ckeditor-image-upload',
            convert_urls: false,
            setup: (editor) => {
                editor.on('change input undo redo', () => {
                    editor.save();
                    markChanged();
                });
            },
        });
    }

    if (state.total > 0) {
        state.filtersApplied = true;
        loadRecipients();
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
