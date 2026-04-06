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

$stmt = $pdo->prepare("\n    SELECT p.*,\n           a.id AS application_id, a.status AS application_status, a.parent_fio, a.source_info, a.colleagues_info,\n           c.id AS contest_id, c.title AS contest_title,\n           u.id AS user_id, u.name AS user_name, u.surname AS user_surname, u.email AS user_email\n    FROM participants p\n    INNER JOIN applications a ON p.application_id = a.id\n    LEFT JOIN contests c ON a.contest_id = c.id\n    LEFT JOIN users u ON a.user_id = u.id\n    WHERE p.id = ?\n");
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

$participantFio = splitFioString($participant['fio'] ?? '');
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
            <div><strong>Фамилия:</strong> <?= htmlspecialchars($participantFio['surname']) ?></div>
            <div><strong>Имя:</strong> <?= htmlspecialchars($participantFio['name']) ?></div>
            <div><strong>Отчество:</strong> <?= htmlspecialchars($participantFio['patronymic']) ?></div>
            <div><strong>Возраст:</strong> <?= (int) ($participant['age'] ?? 0) ?: '—' ?></div>
            <div><strong>Регион:</strong> <?= htmlspecialchars($participant['region'] ?: '—') ?></div>
            <div><strong>Email заявки:</strong> <?= htmlspecialchars($participant['user_email'] ?: ($participant['organization_email'] ?: '—')) ?></div>
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
            <img src="<?= htmlspecialchars($drawingUrl) ?>" alt="Рисунок участника" style="max-width: 100%; border-radius: 12px; border:1px solid var(--color-border);">
        <?php else: ?>
            <p class="text-secondary">Файл рисунка не загружен.</p>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($_SESSION['success_message'])): ?><div class="alert alert--success mb-lg"><?= e($_SESSION['success_message']) ?></div><?php unset($_SESSION['success_message']); endif; ?>
<?php if (!empty($_SESSION['error_message'])): ?><div class="alert alert--error mb-lg"><?= e($_SESSION['error_message']) ?></div><?php unset($_SESSION['error_message']); endif; ?>

<div id="diploma-actions" class="mb-lg" style="display:flex; gap:10px; flex-wrap:wrap;">
    <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="download_diploma"><button class="btn btn--primary" type="submit"><i class="fas fa-download"></i> Скачать диплом</button></form>
    <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="send_diploma_email"><button class="btn btn--secondary" type="submit"><i class="fas fa-envelope"></i> Отправить по почте</button></form>
    <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="get_diploma_link"><button class="btn btn--ghost" type="submit"><i class="fas fa-link"></i> Получить ссылку</button></form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
