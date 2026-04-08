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

$stmt = $pdo->prepare("\n    SELECT p.*,\n           a.id AS application_id, a.status AS application_status, a.parent_fio, a.source_info, a.colleagues_info,\n           c.id AS contest_id, c.title AS contest_title,\n           u.id AS user_id, u.name AS user_name, u.surname AS user_surname, u.patronymic AS user_patronymic, u.email AS user_email,\n           u.organization_region AS user_organization_region, u.organization_name AS user_organization_name, u.organization_address AS user_organization_address\n    FROM participants p\n    INNER JOIN applications a ON p.application_id = a.id\n    LEFT JOIN contests c ON a.contest_id = c.id\n    LEFT JOIN users u ON a.user_id = u.id\n    WHERE p.id = ?\n");
$stmt->execute([$participantId]);
$participant = $stmt->fetch();

if (!$participant) {
    redirect('/admin/participants');
}

function splitFioString(?string $fio): array {
    $raw = trim((string) $fio);
    if ($raw === '') {
        return ['surname' => '—', 'name' => '—', 'patronymic' => '—'];
    }

    $parts = preg_split('/\s+/u', $raw) ?: [];
    return [
        'surname' => $parts[0] ?? '—',
        'name' => $parts[1] ?? '—',
        'patronymic' => $parts[2] ?? '—',
    ];
}

$parentFio = splitFioString($participant['parent_fio'] ?? '');

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

require_once __DIR__ . '/includes/header.php';
?>

<div class="flex items-center gap-md mb-lg">
    <a href="/admin/participants" class="btn btn--ghost"><i class="fas fa-arrow-left"></i> Назад к списку</a>
    <?php $statusMeta = getApplicationStatusMeta($participant['application_status']); ?>
    <span class="badge <?= htmlspecialchars($statusMeta['badge_class']) ?>" style="font-size: 13px;">
        <?= htmlspecialchars($statusMeta['label']) ?>
    </span>
</div>

<div class="card mb-lg">
    <div class="card__header"><h3>Данные участника</h3></div>
    <div class="card__body">
        <div class="grid grid--2">
            <div><strong>ФИО участника:</strong> <?= htmlspecialchars($participant['fio'] ?: '—') ?></div>
            <div><strong>ФИО заявителя:</strong> <?= htmlspecialchars(trim(($participant['user_surname'] ?? '') . ' ' . ($participant['user_name'] ?? '') . ' ' . ($participant['user_patronymic'] ?? '')) ?: '—') ?></div>
            <div><strong>Возраст:</strong> <?= (int) ($participant['age'] ?? 0) ?: '—' ?></div>
            <div><strong>Регион:</strong> <?= htmlspecialchars($participant['region'] ?: '—') ?></div>
            <div><strong>Email заявки:</strong> <?= htmlspecialchars($participant['user_email'] ?: ($participant['organization_email'] ?: '—')) ?></div>
            <div><strong>Email учреждения:</strong> <?= htmlspecialchars($participant['organization_email'] ?: '—') ?></div>
            <div><strong>Организация:</strong> <?= htmlspecialchars($participant['organization_name'] ?: ($participant['user_organization_name'] ?: '—')) ?></div>
            <div><strong>Адрес организации:</strong> <?= htmlspecialchars($participant['organization_address'] ?: ($participant['user_organization_address'] ?: '—')) ?></div>
            <div><strong>Регион организации:</strong> <?= htmlspecialchars($participant['user_organization_region'] ?: '—') ?></div>
            <div><strong>Конкурс:</strong> <?= htmlspecialchars($participant['contest_title'] ?: '—') ?></div>
            <div><strong>Заявка:</strong> <a href="/admin/application/<?= (int) $participant['application_id'] ?>">#<?= (int) $participant['application_id'] ?></a></div>
        </div>
    </div>
</div>

<div class="card mb-lg">
    <div class="card__header"><h3>Данные куратора / родителя</h3></div>
    <div class="card__body">
        <div class="grid grid--2">
            <div><strong>Родитель (Фамилия):</strong> <?= htmlspecialchars($parentFio['surname']) ?></div>
            <div><strong>Родитель (Имя):</strong> <?= htmlspecialchars($parentFio['name']) ?></div>
            <div><strong>Родитель (Отчество):</strong> <?= htmlspecialchars($parentFio['patronymic']) ?></div>
            <div><strong>Родитель (ФИО):</strong> <?= htmlspecialchars($participant['parent_fio'] ?: '—') ?></div>
            <div><strong>Куратор / педагог:</strong> <?= htmlspecialchars($participant['leader_fio'] ?: '—') ?></div>
            <div><strong>Куратор 1:</strong> <?= htmlspecialchars($participant['curator_1_fio'] ?: '—') ?></div>
            <div><strong>Куратор 2:</strong> <?= htmlspecialchars($participant['curator_2_fio'] ?: '—') ?></div>
            <div><strong>Источник информации:</strong> <?= htmlspecialchars($participant['source_info'] ?: '—') ?></div>
            <div><strong>Кто посоветовал:</strong> <?= htmlspecialchars($participant['colleagues_info'] ?: '—') ?></div>
        </div>
    </div>
</div>

<div class="card mb-lg">
    <div class="card__header"><h3>Рисунок</h3></div>
    <div class="card__body">
        <?php if (!empty($participant['drawing_file'])): ?>
            <?php $drawingUrl = getParticipantDrawingWebPath($participant['user_email'] ?? '', $participant['drawing_file']); ?>
            <div style="max-width: 520px;">
                <img
                    src="<?= htmlspecialchars($drawingUrl) ?>"
                    alt="Рисунок участника"
                    id="participantDrawingPreview"
                    style="max-width: 100%; border-radius: 12px; border:1px solid var(--color-border); cursor: zoom-in;">
            </div>
            <p class="text-secondary mt-sm">Нажмите на изображение, чтобы открыть просмотр в модальном окне.</p>
        <?php else: ?>
            <p class="text-secondary">Файл рисунка не загружен.</p>
        <?php endif; ?>
    </div>
</div>

<div class="modal" id="participantDrawingModal">
    <div class="modal__content" style="max-width: 1024px; width: 96%;">
        <div class="modal__header">
            <h3>Рисунок участника</h3>
            <button type="button" class="modal__close" id="participantDrawingModalClose">&times;</button>
        </div>
        <div class="modal__body" style="text-align: center;">
            <img src="" alt="Рисунок участника" id="participantDrawingModalImage" style="max-width: 100%; max-height: 75vh; border-radius: 10px;">
        </div>
    </div>
</div>

<?php if (!empty($_SESSION['success_message'])): ?><div class="alert alert--success mb-lg"><?= e($_SESSION['success_message']) ?></div><?php unset($_SESSION['success_message']); endif; ?>
<?php if (!empty($_SESSION['error_message'])): ?><div class="alert alert--error mb-lg"><?= e($_SESSION['error_message']) ?></div><?php unset($_SESSION['error_message']); endif; ?>

<div id="diploma-actions" class="mb-lg" style="display:flex; gap:10px; flex-wrap:wrap;">
    <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="download_diploma"><button class="btn btn--primary" type="submit"><i class="fas fa-download"></i> Скачать диплом</button></form>
    <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="send_diploma_email"><button class="btn btn--secondary" type="submit"><i class="fas fa-envelope"></i> Отправить по почте</button></form>
    <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="get_diploma_link"><button class="btn btn--ghost" type="submit"><i class="fas fa-link"></i> Получить ссылку</button></form>
</div>

<script>
(() => {
    const previewImage = document.getElementById('participantDrawingPreview');
    const modal = document.getElementById('participantDrawingModal');
    const modalImage = document.getElementById('participantDrawingModalImage');
    const closeButton = document.getElementById('participantDrawingModalClose');
    if (!previewImage || !modal || !modalImage || !closeButton) return;

    const closeModal = () => {
        modal.classList.remove('active');
        modalImage.src = '';
        document.body.style.overflow = '';
    };

    previewImage.addEventListener('click', () => {
        modalImage.src = previewImage.src;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    });

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
