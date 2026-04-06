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

if (isset($_GET['action']) && $_GET['action'] === 'generate_diploma') {
    $contestTitle = (string) ($participant['contest_title'] ?? 'Конкурс');
    $fioForDiploma = trim(($participantFio['surname'] !== '—' ? $participantFio['surname'] : '') . ' ' . ($participantFio['name'] !== '—' ? $participantFio['name'] : '') . ' ' . ($participantFio['patronymic'] !== '—' ? $participantFio['patronymic'] : ''));
    $fioForDiploma = trim($fioForDiploma) ?: 'Участник';

    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <title>Диплом участника</title>
        <style>
            body { font-family: Georgia, serif; background:#f5f5f5; margin:0; padding:30px; }
            .diploma { max-width:1000px; margin:0 auto; background:#fff; border:14px solid #d6b46a; padding:52px 64px; box-sizing:border-box; }
            .diploma h1 { text-align:center; font-size:54px; margin:0 0 16px; color:#7c4a00; }
            .diploma h2 { text-align:center; font-size:24px; letter-spacing:1px; margin:0 0 30px; color:#8f6a24; text-transform:uppercase; }
            .diploma .participant { text-align:center; font-size:44px; margin:36px 0 20px; color:#222; }
            .diploma .text { text-align:center; font-size:22px; line-height:1.45; color:#444; }
            .diploma .contest { text-align:center; font-size:28px; margin-top:24px; color:#111; font-weight:700; }
            .diploma .footer { display:flex; justify-content:space-between; margin-top:56px; color:#555; font-size:18px; }
            .actions { max-width:1000px; margin:16px auto 0; display:flex; gap:10px; }
            @media print { .actions { display:none; } body { padding:0; background:#fff; } .diploma { border-width:12px; } }
        </style>
    </head>
    <body>
        <div class="diploma">
            <h1>ДИПЛОМ</h1>
            <h2>Участника конкурса</h2>
            <p class="text">Награждается</p>
            <div class="participant"><?= htmlspecialchars($fioForDiploma) ?></div>
            <p class="text">за участие в конкурсе детского творчества</p>
            <div class="contest"><?= htmlspecialchars($contestTitle) ?></div>
            <div class="footer">
                <span>Дата: <?= date('d.m.Y') ?></span>
                <span>Оргкомитет</span>
            </div>
        </div>
        <div class="actions">
            <button onclick="window.print()">Печать / Сохранить PDF</button>
            <a href="/admin/participant/<?= (int) $participantId ?>">Назад к карточке участника</a>
        </div>
    </body>
    </html>
    <?php
    exit;
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

<div class="mb-lg">
    <a href="/admin/participant/<?= (int) $participantId ?>?action=generate_diploma" class="btn btn--primary" target="_blank" rel="noopener">
        <i class="fas fa-award"></i> Сформировать диплом участника
    </a>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
