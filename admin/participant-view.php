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

        if ($_POST['action'] === 'download_diploma') {
            if (!canShowIndividualDiplomaActions(['status' => (string) ($participant['work_status'] ?? 'pending')])) {
                throw new RuntimeException('Для этой работы диплом недоступен.');
            }
            $diploma = findExistingWorkDiploma((int) ($participant['work_id'] ?? 0), trim((string)($_POST['diploma_type'] ?? '')) ?: null);
            if (!$diploma) {
                throw new RuntimeException('Диплом ещё не сформирован в просмотре заявки.');
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
            $diploma = findExistingWorkDiploma((int) ($participant['work_id'] ?? 0), trim((string)($_POST['diploma_type'] ?? '')) ?: null);
            if (!$diploma) {
                throw new RuntimeException('Диплом ещё не сформирован в просмотре заявки.');
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
            $diploma = findExistingWorkDiploma((int) ($participant['work_id'] ?? 0), trim((string)($_POST['diploma_type'] ?? '')) ?: null);
            if (!$diploma) {
                throw new RuntimeException('Диплом ещё не сформирован в просмотре заявки.');
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

$statusMeta = getApplicationDisplayMeta([
    'status' => (string) ($participant['application_status'] ?? 'draft'),
    'allow_edit' => (int) ($participant['allow_edit'] ?? 0),
]);
$participantName = trim((string) ($participant['fio'] ?? '')) ?: '—';
$applicantName = trim((($participant['user_surname'] ?? '') . ' ' . ($participant['user_name'] ?? '') . ' ' . ($participant['user_patronymic'] ?? ''))) ?: '—';
$participantEmail = trim((string) ($participant['user_email'] ?: ($participant['organization_email'] ?? '')));
$organizationName = trim((string) ($participant['organization_name'] ?: ($participant['user_organization_name'] ?? ''))) ?: '—';
$organizationAddress = trim((string) ($participant['organization_address'] ?: ($participant['user_organization_address'] ?? ''))) ?: '—';
$organizationRegion = trim((string) ($participant['user_organization_region'] ?? '')) ?: '—';
$participantRegion = trim((string) ($participant['region'] ?? '')) ?: '—';
$participantAge = (int) ($participant['age'] ?? 0);
$drawingFileName = trim((string) ($participant['drawing_file'] ?? ''));
$drawingUrl = $drawingFileName !== '' ? getParticipantDrawingWebPath($participant['user_email'] ?? '', $drawingFileName) : '';
$drawingPreviewUrl = $drawingFileName !== '' ? getParticipantDrawingPreviewWebPath($participant['user_email'] ?? '', $drawingFileName) : '';
$emailHint = $participantEmail !== '' ? $participantEmail : null;
$canShowDiplomaActions = canShowIndividualDiplomaActions(['status' => (string) ($participant['work_status'] ?? 'pending')]);
$availableDiplomaTypes = getAllowedDiplomaTypesForWorkStatus((string) ($participant['work_status'] ?? 'pending'));
$templateLabels = diplomaTemplateTypes();

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
        ['label' => 'Статус заявки', 'value' => '<span class="badge ' . e($statusMeta['badge_class']) . '">' . e($statusMeta['label']) . '</span>', 'is_html' => true],
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
            </p>
            <h2 class="participant-hero__title" id="participantFioTitle"><?= e($participantName) ?></h2>
            <p class="participant-hero__meta">
                <span><i class="fas fa-child"></i> <span id="participantAgeHero"><?= $participantAge > 0 ? (int) $participantAge . ' лет' : 'Возраст не указан' ?></span></span>
                <span><i class="fas fa-map-marker-alt"></i> <?= e($participantRegion) ?></span>
            </p>
            <p class="participant-hero__contest"><i class="fas fa-trophy"></i> <?= e(trim((string) ($participant['contest_title'] ?? '')) ?: 'Конкурс не указан') ?></p>
            <div class="participant-hero__chips">
                <span class="badge <?= e($statusMeta['badge_class']) ?>"><?= e($statusMeta['label']) ?></span>
                <span class="participant-chip">Номер участника: #<?= e(getParticipantDisplayNumber((array) $participant)) ?></span>
                <span class="participant-chip">ID заявки: #<?= (int) $participant['application_id'] ?></span>
            </div>
        </div>

        <div class="participant-hero__actions">
            <a href="/admin/participants" class="btn btn--ghost"><i class="fas fa-arrow-left"></i> Назад к списку</a>
            <a href="/admin/application/<?= (int) $participant['application_id'] ?>" class="btn btn--secondary">
                <i class="fas fa-file-alt"></i> Перейти к заявке
            </a>
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
        <section class="card participant-diploma-card" id="diploma-actions">
            <div class="card__header participant-section-title">
                <h3><i class="fas fa-award"></i> Действия с дипломом</h3>
                <p>Управляйте дипломом участника: скачивайте, отправляйте на почту или получайте публичную ссылку.</p>
            </div>
            <div class="card__body">
                <?php if ($canShowDiplomaActions): ?>
                <?php if ($availableDiplomaTypes): ?>
                    <div class="form-group" style="max-width:320px;margin-bottom:12px;">
                        <label class="form-label" for="participantDiplomaType">Шаблон документа</label>
                        <select class="form-select" id="participantDiplomaType" name="diploma_type">
                            <?php foreach ($availableDiplomaTypes as $type): ?>
                                <option value="<?= e($type) ?>"><?= e($templateLabels[$type] ?? $type) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="participant-diploma-actions">
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="download_diploma">
                        <input type="hidden" name="diploma_type" value="<?= e($availableDiplomaTypes[0] ?? '') ?>" data-diploma-type-input>
                        <button class="btn btn--primary participant-diploma-actions__btn" type="submit"><i class="fas fa-download"></i> Скачать диплом</button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="send_diploma_email">
                        <input type="hidden" name="diploma_type" value="<?= e($availableDiplomaTypes[0] ?? '') ?>" data-diploma-type-input>
                        <button class="btn btn--secondary participant-diploma-actions__btn" type="submit"><i class="fas fa-envelope"></i> Отправить по почте</button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="get_diploma_link">
                        <input type="hidden" name="diploma_type" value="<?= e($availableDiplomaTypes[0] ?? '') ?>" data-diploma-type-input>
                        <button class="btn btn--ghost participant-diploma-actions__btn" type="submit"><i class="fas fa-link"></i> Получить публичную ссылку</button>
                    </form>
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
    const diplomaTypeSelect = document.getElementById('participantDiplomaType');
    const diplomaTypeInputs = document.querySelectorAll('[data-diploma-type-input]');
    const syncDiplomaType = () => {
        const value = diplomaTypeSelect?.value || '';
        diplomaTypeInputs.forEach((input) => {
            input.value = value;
        });
    };
    diplomaTypeSelect?.addEventListener('change', syncDiplomaType);
    syncDiplomaType();

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
