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

$stmt = $pdo->prepare("\n    SELECT p.*,\n           a.id AS application_id, a.status AS application_status, a.parent_fio, a.source_info, a.colleagues_info,\n           c.id AS contest_id, c.title AS contest_title,\n           u.id AS user_id, u.name AS user_name, u.surname AS user_surname, u.patronymic AS user_patronymic, u.email AS user_email,\n           u.organization_region AS user_organization_region, u.organization_name AS user_organization_name, u.organization_address AS user_organization_address,\n           w.title AS work_title\n    FROM participants p\n    INNER JOIN applications a ON p.application_id = a.id\n    LEFT JOIN contests c ON a.contest_id = c.id\n    LEFT JOIN users u ON a.user_id = u.id\n    LEFT JOIN works w ON w.participant_id = p.id AND w.application_id = p.application_id\n    WHERE p.id = ?\n");
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
        if ($_POST['action'] === 'download_diploma') {
            $diploma = generateParticipantDiploma($participantId, false);
            $file = ROOT_PATH . '/' . $diploma['file_path'];
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="diploma_participant_' . $participantId . '.pdf"');
            readfile($file);
            exit;
        }

        if ($_POST['action'] === 'send_diploma_email') {
            $diploma = generateParticipantDiploma($participantId, false);
            $ok = sendDiplomaByEmail($participant, $diploma);
            $_SESSION['success_message'] = $ok ? 'Диплом отправлен по почте' : 'Не удалось отправить диплом';
            redirect('/admin/participant/' . $participantId);
        }

        if ($_POST['action'] === 'get_diploma_link') {
            $diploma = generateParticipantDiploma($participantId, false);
            $_SESSION['success_message'] = 'Публичная ссылка: ' . getPublicDiplomaUrl($diploma['public_token']);
            redirect('/admin/participant/' . $participantId);
        }
    } catch (Throwable $e) {
        $_SESSION['error_message'] = $e->getMessage();
        redirect('/admin/participant/' . $participantId);
    }
}

$currentPage = 'participants';
$pageTitle = 'Участник #' . $participantId;
$breadcrumb = 'Участники / Просмотр';
$pageStyles = ['admin-participant.css'];

$statusMeta = getApplicationStatusMeta($participant['application_status']);
$participantName = trim((string) ($participant['fio'] ?? '')) ?: '—';
$applicantName = trim((($participant['user_surname'] ?? '') . ' ' . ($participant['user_name'] ?? '') . ' ' . ($participant['user_patronymic'] ?? ''))) ?: '—';
$participantEmail = trim((string) ($participant['user_email'] ?: ($participant['organization_email'] ?? '')));
$organizationName = trim((string) ($participant['organization_name'] ?: ($participant['user_organization_name'] ?? ''))) ?: '—';
$organizationAddress = trim((string) ($participant['organization_address'] ?: ($participant['user_organization_address'] ?? ''))) ?: '—';
$organizationRegion = trim((string) ($participant['user_organization_region'] ?? '')) ?: '—';
$participantRegion = trim((string) ($participant['region'] ?? '')) ?: '—';
$participantAge = (int) ($participant['age'] ?? 0);
$workTitle = trim((string) ($participant['work_title'] ?? ''));
$drawingFileName = trim((string) ($participant['drawing_file'] ?? ''));
$drawingUrl = $drawingFileName !== '' ? getParticipantDrawingWebPath($participant['user_email'] ?? '', $drawingFileName) : '';
$emailHint = $participantEmail !== '' ? $participantEmail : null;

$detailsMap = [
    'Участник' => [
        ['label' => 'ФИО участника', 'value' => $participantName],
        ['label' => 'Возраст', 'value' => $participantAge > 0 ? $participantAge . ' лет' : '—'],
        ['label' => 'Регион', 'value' => $participantRegion],
        ['label' => 'Название рисунка', 'value' => $workTitle !== '' ? $workTitle : '—'],
    ],
    'Заявитель' => [
        ['label' => 'ФИО заявителя', 'value' => $applicantName],
        ['label' => 'Email заявителя', 'value' => $participantEmail !== '' ? '<a href="mailto:' . e($participantEmail) . '">' . e($participantEmail) . '</a>' : '—', 'is_html' => true],
    ],
    'Организация' => [
        ['label' => 'Название организации', 'value' => $organizationName],
        ['label' => 'Адрес организации', 'value' => $organizationAddress],
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
    <span>Участник #<?= (int) $participant['id'] ?></span>
</nav>

<section class="participant-hero card">
    <div class="participant-hero__body">
        <div class="participant-hero__summary">
            <p class="participant-hero__eyebrow"><i class="fas fa-id-badge"></i> Карточка участника</p>
            <h2 class="participant-hero__title"><?= e($participantName) ?></h2>
            <p class="participant-hero__meta">
                <span><i class="fas fa-child"></i> <?= $participantAge > 0 ? (int) $participantAge . ' лет' : 'Возраст не указан' ?></span>
                <span><i class="fas fa-map-marker-alt"></i> <?= e($participantRegion) ?></span>
            </p>
            <p class="participant-hero__contest"><i class="fas fa-trophy"></i> <?= e(trim((string) ($participant['contest_title'] ?? '')) ?: 'Конкурс не указан') ?></p>
            <div class="participant-hero__chips">
                <span class="badge <?= e($statusMeta['badge_class']) ?>"><?= e($statusMeta['label']) ?></span>
                <span class="participant-chip">ID участника: #<?= (int) $participant['id'] ?></span>
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
                        src="<?= e($drawingUrl) ?>"
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
                                    <?= e($item['value'] !== '' ? $item['value'] : '—') ?>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <section class="card participant-diploma-card" id="diploma-actions">
            <div class="card__header participant-section-title">
                <h3><i class="fas fa-award"></i> Действия с дипломом</h3>
                <p>Управляйте дипломом участника: скачивайте, отправляйте на почту или получайте публичную ссылку.</p>
            </div>
            <div class="card__body">
                <div class="participant-diploma-actions">
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="download_diploma">
                        <button class="btn btn--primary participant-diploma-actions__btn" type="submit"><i class="fas fa-download"></i> Скачать диплом</button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="send_diploma_email">
                        <button class="btn btn--secondary participant-diploma-actions__btn" type="submit"><i class="fas fa-envelope"></i> Отправить по почте</button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="get_diploma_link">
                        <button class="btn btn--ghost participant-diploma-actions__btn" type="submit"><i class="fas fa-link"></i> Получить публичную ссылку</button>
                    </form>
                </div>
                <?php if ($emailHint): ?>
                    <p class="participant-diploma-card__hint">Диплом будет отправлен на email: <a href="mailto:<?= e($emailHint) ?>"><?= e($emailHint) ?></a></p>
                <?php endif; ?>
            </div>
        </section>
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

<script>
(() => {
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
        modalImage.src = previewImage.src;
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
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
