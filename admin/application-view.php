<?php
// admin/application-view.php - Просмотр заявки
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

// Проверка авторизации админа
if (!isAdmin()) {
    redirect('/admin/login');
}

check_csrf();

$admin = getCurrentUser();
$application_id = $_GET['id'] ?? 0;

// Получаем заявку
$stmt = $pdo->prepare("
 SELECT a.*, c.title as contest_title, 
 u.name, u.surname, u.avatar_url, u.email, u.vk_id,
 u.organization_region, u.organization_name, u.organization_address
 FROM applications a
 JOIN contests c ON a.contest_id = c.id
 JOIN users u ON a.user_id = u.id
 WHERE a.id = ?
");
$stmt->execute([$application_id]);
$application = $stmt->fetch();

if (!$application) {
    redirect('/admin/applications');
}

// Получаем участников
$stmt = $pdo->prepare("SELECT * FROM participants WHERE application_id = ?");
$stmt->execute([$application_id]);
$participants = $stmt->fetchAll();
$participantColumns = $pdo->query("DESCRIBE participants")->fetchAll(PDO::FETCH_COLUMN);
$hasDrawingCompliantColumn = in_array('drawing_compliant', $participantColumns, true);
$hasDrawingCommentColumn = in_array('drawing_comment', $participantColumns, true);

function scaleToMinSide($image, $minSide = 1500) {
    $srcW = imagesx($image);
    $srcH = imagesy($image);
    if ($srcW >= $minSide && $srcH >= $minSide) {
        return $image;
    }

    $ratio = max($minSide / max(1, $srcW), $minSide / max(1, $srcH));
    $dstW = (int) round($srcW * $ratio);
    $dstH = (int) round($srcH * $ratio);
    $scaled = imagecreatetruecolor($dstW, $dstH);
    $white = imagecolorallocate($scaled, 255, 255, 255);
    imagefill($scaled, 0, 0, $white);
    imagecopyresampled($scaled, $image, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
    imagedestroy($image);
    return $scaled;
}

// Обработка изменения статуса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности';
    } elseif ($_POST['action'] === 'update_status') {
        $newStatus = $_POST['status'] ?? $application['status'];
        $stmt = $pdo->prepare("UPDATE applications SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $application_id]);
        $application['status'] = $newStatus;
        $_SESSION['success_message'] = 'Статус обновлён';
        redirect('/admin/applications');

    } elseif ($_POST['action'] === 'download_participant_diploma') {
        $participantId = (int)($_POST['participant_id'] ?? 0);
        $diploma = generateParticipantDiploma($participantId, false);
        $file = ROOT_PATH . '/' . $diploma['file_path'];
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="diploma_participant_' . $participantId . '.pdf"');
        readfile($file);
        exit;
    } elseif ($_POST['action'] === 'send_participant_diploma') {
        $participantId = (int)($_POST['participant_id'] ?? 0);
        $ctx = getParticipantDiplomaContext($participantId);
        $diploma = generateParticipantDiploma($participantId, false);
        sendDiplomaByEmail($ctx ?? [], $diploma);
        $_SESSION['success_message'] = 'Диплом участника отправлен';
        redirect('/admin/application/' . $application_id);
    } elseif ($_POST['action'] === 'link_participant_diploma') {
        $participantId = (int)($_POST['participant_id'] ?? 0);
        $diploma = generateParticipantDiploma($participantId, false);
        $_SESSION['success_message'] = 'Ссылка участника: ' . getPublicDiplomaUrl($diploma['public_token']);
        redirect('/admin/application/' . $application_id);
    } elseif ($_POST['action'] === 'generate_all_diplomas') {
        foreach ($participants as $participantRow) {
            generateParticipantDiploma((int)$participantRow['id'], false);
        }
        $_SESSION['success_message'] = 'Дипломы сформированы';
        redirect('/admin/application/' . $application_id);
    } elseif ($_POST['action'] === 'generate_and_send_all_diplomas') {
        foreach ($participants as $participantRow) {
            $ctx = getParticipantDiplomaContext((int)$participantRow['id']);
            $diploma = generateParticipantDiploma((int)$participantRow['id'], false);
            sendDiplomaByEmail($ctx ?? [], $diploma);
        }
        $_SESSION['success_message'] = 'Дипломы сформированы и отправлены';
        redirect('/admin/application/' . $application_id);
    } elseif ($_POST['action'] === 'collect_all_diploma_links') {
        foreach ($participants as $participantRow) {
            generateParticipantDiploma((int)$participantRow['id'], false);
        }
        $links = collectApplicationDiplomaLinks((int)$application_id);
        $lines = array_map(static fn($it) => $it['participant'] . ': ' . $it['url'], $links);
        $_SESSION['success_message'] = "Ссылки:
" . implode("
", $lines);
        redirect('/admin/application/' . $application_id);
    } elseif ($_POST['action'] === 'download_zip_diplomas') {
        foreach ($participants as $participantRow) {
            generateParticipantDiploma((int)$participantRow['id'], false);
        }
        $zipRelative = buildApplicationDiplomaZip((int)$application_id);
        $zipFile = ROOT_PATH . '/' . $zipRelative;
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zipFile) . '"');
        readfile($zipFile);
        exit;
    } elseif ($_POST['action'] === 'approve_application') {
        $stmt = $pdo->prepare("UPDATE applications SET status = 'approved', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$application_id]);
        $application['status'] = 'approved';

        $declinedSubject = getSystemSetting('application_declined_subject', 'Ваша заявка отклонена');
        $pdo->prepare("
            DELETE FROM admin_messages
            WHERE subject = ? AND message LIKE ?
        ")->execute([$declinedSubject, '%#' . $application_id . '%']);
        $pdo->prepare("
            DELETE FROM messages
            WHERE application_id = ? AND title = ?
        ")->execute([$application_id, 'Оспаривание решения по заявке #' . $application_id]);
        $pdo->prepare("UPDATE application_corrections SET is_resolved = 1, resolved_at = NOW() WHERE application_id = ?")
            ->execute([$application_id]);
        disallowApplicationEdit($application_id);

        $_SESSION['success_message'] = 'Заявка принята';
        redirect('/admin/applications');
    } elseif ($_POST['action'] === 'cancel_application') {
        $stmt = $pdo->prepare("UPDATE applications SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$application_id]);
        $application['status'] = 'cancelled';

        $subject = getSystemSetting('application_cancelled_subject', 'Ваша заявка отменена');
        $message = getSystemSetting('application_cancelled_message', 'Ваша заявка отменена администратором.') . "\n\nНомер заявки: #" . $application_id;
        $stmt = $pdo->prepare("INSERT INTO admin_messages (user_id, admin_id, subject, message, priority, created_at) VALUES (?, ?, ?, ?, 'normal', NOW())");
        $stmt->execute([$application['user_id'], $admin['id'], $subject, $message]);

        $_SESSION['success_message'] = 'Заявка отменена';
        redirect('/admin/applications');
    } elseif ($_POST['action'] === 'decline_application') {
        // В ряде БД статус отклонения хранится как `rejected` (без `declined` в ENUM),
        // поэтому сохраняем совместимое значение.
        $stmt = $pdo->prepare("UPDATE applications SET status = 'rejected', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$application_id]);
        $application['status'] = 'rejected';

        $subject = getSystemSetting('application_declined_subject', 'Ваша заявка отклонена');
        $message = getSystemSetting('application_declined_message', 'Ваша заявка отклонена администратором.') . "\n\nНомер заявки: #" . $application_id;
        $stmt = $pdo->prepare("INSERT INTO admin_messages (user_id, admin_id, subject, message, priority, created_at) VALUES (?, ?, ?, ?, 'critical', NOW())");
        $stmt->execute([$application['user_id'], $admin['id'], $subject, $message]);

        $_SESSION['success_message'] = 'Заявка отклонена';
        redirect('/admin/applications');

} elseif ($_POST['action'] === 'delete') {
 // Удаляем участников и заявку
 $pdo->prepare("DELETE FROM participants WHERE application_id = ?")->execute([$application_id]);
 $pdo->prepare("DELETE FROM applications WHERE id = ?")->execute([$application_id]);
 $_SESSION['success_message'] = 'Заявка удалена';
 redirect('/admin/applications');
 } elseif ($_POST['action'] === 'send_message') {
 $subject = trim($_POST['subject'] ?? '');
 $message = trim($_POST['message'] ?? '');
 $priority = $_POST['priority'] ?? 'normal';
 
 if (empty($subject) || empty($message)) {
 $error = 'Заполните тему и текст сообщения';
 } else {
 $stmt = $pdo->prepare("INSERT INTO admin_messages (user_id, admin_id, subject, message, priority, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
 $stmt->execute([$application['user_id'], $admin['id'], $subject, $message, $priority]);
 $_SESSION['success_message'] = 'Сообщение отправлено';
 }
 } elseif ($_POST['action'] === 'toggle_drawing_compliance') {
 $isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (string) ($_POST['ajax'] ?? '') === '1';
 $participantId = intval($_POST['participant_id'] ?? 0);
 $isCompliant = isset($_POST['drawing_compliant']) ? 1 : 0;
 $comment = trim($_POST['comment'] ?? '');

 if ($participantId <= 0) {
     if ($isAjaxRequest) {
         jsonResponse(['success' => false, 'error' => 'Некорректный участник'], 422);
     }
     $_SESSION['success_message'] = 'Не удалось сохранить проверку';
     redirect('/admin/application/' . $application_id);
 }

 if ($hasDrawingCompliantColumn && $hasDrawingCommentColumn) {
     $stmt = $pdo->prepare("
     UPDATE participants
     SET drawing_compliant = ?, drawing_comment = ?
     WHERE id = ? AND application_id = ?
     ");
     $stmt->execute([$isCompliant, $isCompliant ? null : $comment, $participantId, $application_id]);
 } elseif ($hasDrawingCompliantColumn) {
     $stmt = $pdo->prepare("
     UPDATE participants
     SET drawing_compliant = ?
     WHERE id = ? AND application_id = ?
     ");
     $stmt->execute([$isCompliant, $participantId, $application_id]);
 } elseif ($hasDrawingCommentColumn) {
     $stmt = $pdo->prepare("
     UPDATE participants
     SET drawing_comment = ?
     WHERE id = ? AND application_id = ?
     ");
     $stmt->execute([$isCompliant ? null : $comment, $participantId, $application_id]);
 }

 if ($isCompliant) {
     $pdo->prepare("
     UPDATE application_corrections
     SET is_resolved = 1, resolved_at = NOW()
     WHERE application_id = ? AND participant_id = ? AND is_resolved = 0
     ")->execute([$application_id, $participantId]);
 }

 if ($isAjaxRequest) {
     jsonResponse(['success' => true]);
 }
 $_SESSION['success_message'] = 'Проверка рисунка обновлена';
 redirect('/admin/application/' . $application_id);
 } elseif ($_POST['action'] === 'send_to_revision') {
     $participantsForRevisionStmt = $pdo->prepare("
         SELECT id, drawing_compliant, drawing_comment
         FROM participants
         WHERE application_id = ?
     ");
     $participantsForRevisionStmt->execute([$application_id]);
     $participantsForRevision = $participantsForRevisionStmt->fetchAll();

     $needRevision = array_filter($participantsForRevision, function ($row) use ($hasDrawingCompliantColumn) {
         if (!$hasDrawingCompliantColumn) {
             return false;
         }
         return (int) ($row['drawing_compliant'] ?? 1) === 0;
     });

     if (empty($needRevision)) {
         $_SESSION['success_message'] = 'Нет участников, отмеченных как несоответствующие условиям конкурса.';
         redirect('/admin/application/' . $application_id);
     }

     foreach ($needRevision as $participantRow) {
         $participantId = (int) ($participantRow['id'] ?? 0);
         if ($participantId <= 0) {
             continue;
         }
         $existsStmt = $pdo->prepare("
             SELECT COUNT(*)
             FROM application_corrections
             WHERE application_id = ? AND participant_id = ? AND field_name = ? AND is_resolved = 0
         ");
         $existsStmt->execute([$application_id, $participantId, 'Рисунок не соответствует условиям конкурса']);
         if ((int) $existsStmt->fetchColumn() === 0) {
             addCorrection(
                 $application_id,
                 'Рисунок не соответствует условиям конкурса',
                 trim((string) ($participantRow['drawing_comment'] ?? '')) ?: 'Требуется корректировка рисунка',
                 $participantId
             );
         }
     }

     allowApplicationEdit($application_id);
     $pdo->prepare("UPDATE applications SET updated_at = NOW() WHERE id = ?")->execute([$application_id]);

     $subject = getSystemSetting('application_revision_subject', 'Заявка отправлена на корректировку');
     $messageText = getSystemSetting('application_revision_message', 'Ваша заявка отправлена на корректировку. Пожалуйста, внесите исправления.') . "\n\nНомер заявки: #" . $application_id;
     $pdo->prepare("INSERT INTO admin_messages (user_id, admin_id, subject, message, priority, created_at) VALUES (?, ?, ?, ?, 'important', NOW())")
         ->execute([$application['user_id'], $admin['id'], $subject, $messageText]);

     $_SESSION['success_message'] = 'Заявка отправлена на корректировку';
     redirect('/admin/application/' . $application_id);
 } elseif ($_POST['action'] === 'save_drawing_edit') {
     header('Content-Type: application/json');
     $participantId = intval($_POST['participant_id'] ?? 0);
     $rotation = floatval($_POST['rotation'] ?? 0);
     $cropX = max(0, floatval($_POST['crop_x'] ?? 0));
     $cropY = max(0, floatval($_POST['crop_y'] ?? 0));
     $cropW = max(1, floatval($_POST['crop_w'] ?? 1));
     $cropH = max(1, floatval($_POST['crop_h'] ?? 1));

     $pStmt = $pdo->prepare("SELECT drawing_file FROM participants WHERE id = ? AND application_id = ?");
     $pStmt->execute([$participantId, $application_id]);
     $participant = $pStmt->fetch();

     if (!$participant || empty($participant['drawing_file'])) {
         echo json_encode(['success' => false, 'message' => 'Рисунок не найден']);
         exit;
     }

     $sourcePath = getParticipantDrawingFsPath($application['email'] ?? '', $participant['drawing_file']);
     if (!$sourcePath || !file_exists($sourcePath)) {
         echo json_encode(['success' => false, 'message' => 'Файл на диске не найден']);
         exit;
     }

     $source = imagecreatefromstring(file_get_contents($sourcePath));
     if (!$source) {
         echo json_encode(['success' => false, 'message' => 'Не удалось открыть рисунок']);
         exit;
     }

     if (abs($rotation) > 0.01) {
         $bg = imagecolorallocate($source, 255, 255, 255);
         $rotated = imagerotate($source, -$rotation, $bg);
         imagedestroy($source);
         $source = $rotated;
     }

     $srcW = imagesx($source);
     $srcH = imagesy($source);
     $cropX = min($cropX, $srcW - 1);
     $cropY = min($cropY, $srcH - 1);
     $cropW = min($cropW, $srcW - $cropX);
     $cropH = min($cropH, $srcH - $cropY);

     $cropped = imagecreatetruecolor((int) $cropW, (int) $cropH);
     $white = imagecolorallocate($cropped, 255, 255, 255);
     imagefill($cropped, 0, 0, $white);
     imagecopy($cropped, $source, 0, 0, (int) $cropX, (int) $cropY, (int) $cropW, (int) $cropH);
     imagedestroy($source);

     $final = scaleToMinSide($cropped, 1500);
     imagejpeg($final, $sourcePath, 92);
     imagedestroy($final);

     echo json_encode([
         'success' => true,
         'updated_url' => getParticipantDrawingWebPath($application['email'] ?? '', $participant['drawing_file']) . '?v=' . time(),
     ]);
     exit;
 }
}

generateCSRFToken();

$currentPage = 'applications';
$pageTitle = 'Заявка #' . $application_id;
$breadcrumb = 'Заявки / Просмотр';

require_once __DIR__ . '/includes/header.php';
?>

<div class="flex items-center gap-md mb-lg">
    <a href="/admin/applications" class="btn btn--ghost">
        <i class="fas fa-arrow-left"></i> Назад
    </a>
</div>

<div class="flex gap-md mb-lg" style="flex-wrap:wrap;">
    <button type="button" class="btn" style="background:#EEF2FF; color:#6366F1;" onclick="openMessageModal()">
        <i class="fas fa-envelope"></i> Сообщение
    </button>
    <form method="POST">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
        <input type="hidden" name="action" value="cancel_application">
        <button type="submit" class="btn" style="background:#FEE2E2; color:#B91C1C;">
            <i class="fas fa-ban"></i> Отмена
        </button>
    </form>
    <form method="POST" onsubmit="return confirm('Удалить заявку?');">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn" style="background:#FEE2E2; color:#DC2626;">
            <i class="fas fa-trash"></i> Удалить
        </button>
    </form>
</div>

<!-- Сообщения -->
<?php if (isset($_SESSION['success_message'])): ?>
<div class="alert alert--success alert--permanent mb-lg">
    <i class="fas fa-check-circle alert__icon"></i>
    <div class="alert__content">
        <div class="alert__message"><?= htmlspecialchars($_SESSION['success_message']) ?></div>
    </div>
    <button type="button" class="btn-close"></button>
</div>
<?php unset($_SESSION['success_message']); endif; ?>

<!-- Информация о заявке -->
<div class="card mb-lg">
<div class="card__header" style="padding:20px24px;">
<div class="flex justify-between items-center" style="flex-wrap: wrap; gap:16px;">
<div>
<h2 style="font-size:20px; margin-bottom:4px;">Заявка #<?= e($application_id) ?></h2>
<p class="text-secondary"><?= htmlspecialchars($application['contest_title']) ?></p>
</div>
<div style="flex-shrink:0;">
<?php $statusMeta = getApplicationStatusMeta($application['status']); ?>
<span class="badge <?= $statusMeta['badge_class'] ?>" style="font-size:14px; padding:8px 12px;">
    <?= htmlspecialchars($statusMeta['label']) ?>
</span>
</div>
</div>
</div>
    <div class="card__body">
<div class="form-row">
<div class="form-group">
<label class="form-label">Заявитель</label>
<div class="flex items-center gap-md">
 <?php if (!empty($application['avatar_url'])): ?>
<img src="<?= htmlspecialchars($application['avatar_url']) ?>" 
 style="width:40px; height:40px; border-radius:50%;">
 <?php else: ?>
<div style="width:40px; height:40px; border-radius:50%; background: #EEF2FF; display: flex; align-items: center; justify-content: center; color: #6366F1;">
<i class="fas fa-user"></i>
</div>
 <?php endif; ?>
<div>
<p class="font-semibold"><?= htmlspecialchars(($application['name'] ?? '') . ' ' . ($application['surname'] ?? '')) ?></p>
<p class="text-secondary" style="font-size:13px;"><?= htmlspecialchars($application['email'] ?: 'Email не указан') ?></p>
</div>
</div>
</div>
<div class="form-group">
<label class="form-label">ФИО родителя/куратора</label>
<p><?= htmlspecialchars($application['parent_fio'] ?: '—') ?></p>
</div>
<div class="form-group">
<label class="form-label">Дата подачи</label>
<p><?= date('d.m.Y H:i', strtotime($application['created_at'])) ?></p>
</div>
</div>
        
 <!-- Данные организации заявителя -->
 <?php if ($application['organization_region'] || $application['organization_name'] || $application['organization_address']): ?>
<div class="form-group mt-md">
<label class="form-label">Место обучения</label>
 <?php if ($application['organization_region']): ?>
<p><strong>Регион:</strong> <?= htmlspecialchars($application['organization_region']) ?></p>
 <?php endif; ?>
 <?php if ($application['organization_name']): ?>
<p><strong>Организация:</strong> <?= htmlspecialchars($application['organization_name']) ?></p>
 <?php endif; ?>
 <?php if ($application['organization_address']): ?>
<p><strong>Адрес:</strong> <?= htmlspecialchars($application['organization_address']) ?></p>
 <?php endif; ?>
</div>
 <?php endif; ?>
        
 <?php if ($application['source_info']): ?>
<div class="form-group mt-md">
<label class="form-label">Откуда узнал о конкурсе</label>
<p><?= htmlspecialchars($application['source_info']) ?></p>
</div>
 <?php endif; ?>

 <?php if ($application['colleagues_info']): ?>
<div class="form-group mt-md">
<label class="form-label">Информация о коллегах</label>
<p><?= htmlspecialchars($application['colleagues_info']) ?></p>
</div>
 <?php endif; ?>
        
 <?php if ($application['payment_receipt']): ?>
<div class="form-group mt-md">
<label class="form-label">Квитанция об оплате</label>
<a href="/uploads/documents/<?= htmlspecialchars($application['payment_receipt']) ?>" 
 target="_blank" class="btn btn--secondary btn--sm">
<i class="fas fa-file-image"></i> Просмотреть
</a>
</div>
 <?php endif; ?>
</div>
</div>

<!-- Участники -->
<h4 class="mb-lg">Количество участников - <?= count($participants) ?></h4>

<?php foreach ($participants as $i => $p): ?>
<div class="card mb-lg">
    <div class="card__header">
        <div class="flex justify-between items-center w-100">
            <h3>Участник #<?= $i + 1 ?></h3>
            <?php if ($p['drawing_file']): ?>
            <span class="badge badge--success">
                <i class="fas fa-image"></i> Рисунок загружен
            </span>
            <?php endif; ?>
        </div>
    </div>
<div class="card__body">
<div style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap;">
 <?php if ($p['drawing_file']): ?>
        <div style="flex:0 0 360px; max-width:100%;">
            <label class="form-label">Рисунок</label>
            <?php $drawingUrl = getParticipantDrawingWebPath($application['email'] ?? '', $p['drawing_file']); ?>
            <div style="max-width: 360px;">
                <img src="<?= htmlspecialchars($drawingUrl) ?>" 
                     data-participant-id="<?= (int) $p['id'] ?>"
                     class="js-admin-drawing"
                     alt="Рисунок участника" 
                     style="width: 100%; border-radius: var(--radius-lg); border: 2px solid var(--color-border);">
            </div>
            <div class="flex gap-sm mt-md" style="flex-wrap: wrap;">
                <button type="button" class="btn btn--secondary js-open-editor" data-participant-id="<?= (int) $p['id'] ?>" data-image-src="<?= htmlspecialchars($drawingUrl) ?>">
                    <i class="fas fa-crop-alt"></i> Редактировать рисунок
                </button>
            </div>
        </div>
        <?php endif; ?>

    <div style="flex:1; min-width:280px; max-width:680px;">
        <div class="form-row" style="gap:20px;">
            <div class="form-group">
                <label class="form-label">ФИО участника</label>
                <p class="font-semibold"><?= htmlspecialchars($p['fio']) ?></p>
            </div>
            <div class="form-group">
                <label class="form-label">Возраст</label>
                <p><?= $p['age'] ?> лет</p>
            </div>
        </div>

        <div class="form-group" style="margin-top:16px;">
            <label class="form-label">Регион</label>
            <p><?= htmlspecialchars($p['region'] ?? '—') ?></p>
        </div>
        <div class="form-group" style="margin-top:16px;">
            <label class="form-label">Организация</label>
            <p><?= htmlspecialchars($p['organization_name'] ?? '—') ?></p>
        </div>
        <div class="form-group" style="margin-top:16px;">
            <label class="form-label">Адрес организации</label>
            <p><?= htmlspecialchars($p['organization_address'] ?? '—') ?></p>
        </div>

        <div class="flex gap-sm mt-md" style="flex-wrap:wrap;">
            <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="download_participant_diploma"><input type="hidden" name="participant_id" value="<?= (int)$p['id'] ?>"><button class="btn btn--primary btn--sm" type="submit">Скачать диплом</button></form>
            <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="send_participant_diploma"><input type="hidden" name="participant_id" value="<?= (int)$p['id'] ?>"><button class="btn btn--secondary btn--sm" type="submit">Отправить по почте</button></form>
            <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="link_participant_diploma"><input type="hidden" name="participant_id" value="<?= (int)$p['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">Получить ссылку</button></form>
        </div>

        <form method="POST" class="mt-md js-drawing-compliance-form" style="border:1px solid #E5E7EB; padding:16px; border-radius:12px; background:#F8FAFC;">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="action" value="toggle_drawing_compliance">
            <input type="hidden" name="participant_id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="ajax" value="1">
            <div class="flex gap-md items-center" style="flex-wrap:wrap;">
                <label class="ios-toggle-wrap">
                    <span class="ios-toggle-label">Соответствует условиям конкурса</span>
                    <span class="ios-toggle">
                        <input type="checkbox" name="drawing_compliant" value="1" class="js-drawing-compliant-toggle" <?= isset($p['drawing_compliant']) && (int)$p['drawing_compliant'] === 1 ? 'checked' : '' ?>>
                        <span class="ios-toggle__slider"></span>
                    </span>
                </label>
            </div>
            <div class="mt-sm">
                <label class="form-label">Что исправить (если не соответствует)</label>
                <textarea class="form-textarea js-drawing-comment" name="comment" rows="2" placeholder="Укажите, что нужно исправить"><?= htmlspecialchars($p['drawing_comment'] ?? '') ?></textarea>
            </div>
        </form>
    </div>
</div>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($participants)): ?>
<div class="card">
<div class="card__body text-center" style="padding:40px;">
<p class="text-secondary">Нет участников</p>
</div>
</div>
<?php endif; ?>

<div class="card mb-lg">
    <div class="card__body">
        <div class="flex gap-md" style="flex-wrap:wrap; justify-content:flex-end;">

            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="generate_all_diplomas">
                <button type="submit" class="btn" style="background:#DBEAFE;color:#1E3A8A;">Сформировать дипломы</button>
            </form>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="generate_and_send_all_diplomas">
                <button type="submit" class="btn" style="background:#DCFCE7;color:#14532D;">Сформировать и отправить</button>
            </form>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="collect_all_diploma_links">
                <button type="submit" class="btn" style="background:#F5F3FF;color:#5B21B6;">Получить ссылки</button>
            </form>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="download_zip_diplomas">
                <button type="submit" class="btn" style="background:#FEF3C7;color:#92400E;">Скачать все дипломы (ZIP)</button>
            </form>

            <form method="POST" onsubmit="return confirm('Отправить заявку на корректировку?');">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="send_to_revision">
                <button type="submit" class="btn" style="background:#FEF3C7; color:#92400E;">
                    <i class="fas fa-edit"></i> Отправить на корректировку
                </button>
            </form>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="decline_application">
                <button type="submit" class="btn" style="background:#FECACA; color:#991B1B;">
                    <i class="fas fa-times-circle"></i> Заявка отклонена
                </button>
            </form>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="approve_application">
                <button type="submit" class="btn" style="background:#D1FAE5; color:#065F46;">
                    <i class="fas fa-check"></i> Заявка принята
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Модальное окно отправки сообщения -->
<div class="modal" id="messageModal">
<div class="modal__content" style="max-width:550px;">
<div class="modal__header">
<h3>Отправить сообщение пользователю</h3>
<button type="button" class="modal__close" onclick="closeMessageModal()">&times;</button>
</div>
<form method="POST" action="/admin/application/<?= e($application_id) ?>">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="action" value="send_message">
<div class="modal__body">
<div class="form-group">
<label class="form-label">Пользователь</label>
<p><strong><?= htmlspecialchars(($application['name'] ?? '') . ' ' . ($application['surname'] ?? '')) ?></strong> (<?= htmlspecialchars($application['email']) ?>)</p>
</div>
<div class="form-group">
<label class="form-label">Приоритет сообщения</label>
<div class="priority-buttons" style="display:flex; gap:12px;">
<label class="priority-btn priority-btn--normal" onclick="selectPriority('normal')" style="flex:1; display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px10px; border-radius:10px; border:2px solid #E5E7EB; background:white; cursor:pointer;">
<input type="radio" name="priority" value="normal" checked style="display:none;">
<span style="color:#6B7280; font-size:20px;"><i class="fas fa-circle"></i></span>
<span style="font-size:12px; font-weight:600;">Обычное</span>
</label>
<label class="priority-btn priority-btn--important" onclick="selectPriority('important')" style="flex:1; display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px10px; border-radius:10px; border:2px solid #E5E7EB; background:white; cursor:pointer;">
<input type="radio" name="priority" value="important" style="display:none;">
<span style="color:#F59E0B; font-size:20px;"><i class="fas fa-exclamation-circle"></i></span>
<span style="font-size:12px; font-weight:600;">Важное</span>
</label>
<label class="priority-btn priority-btn--critical" onclick="selectPriority('critical')" style="flex:1; display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px10px; border-radius:10px; border:2px solid #E5E7EB; background:white; cursor:pointer;">
<input type="radio" name="priority" value="critical" style="display:none;">
<span style="color:#EF4444; font-size:20px;"><i class="fas fa-exclamation-triangle"></i></span>
<span style="font-size:12px; font-weight:600;">Критическое</span>
</label>
</div>
</div>
<div class="form-group">
<label class="form-label">Тема сообщения</label>
<input type="text" name="subject" class="form-input" required placeholder="Введите тему">
</div>
<div class="form-group">
<label class="form-label">Текст сообщения</label>
<textarea name="message" class="form-textarea" rows="5" required placeholder="Введите текст сообщения"></textarea>
</div>
</div>
<div class="modal__footer flex gap-md" style="padding:20px; border-top:1px solid #E5E7EB; display:flex; justify-content:flex-end; gap:12px;">
<button type="button" class="btn btn--ghost" onclick="closeMessageModal()">Отмена</button>
<button type="submit" class="btn btn--primary"><i class="fas fa-paper-plane"></i> Отправить</button>
</div>
</form>
</div>
</div>

<div class="modal" id="drawingEditorModal">
<div class="modal__content" style="max-width: 1100px; width: 96%;">
<div class="modal__header">
<h3>Редактирование рисунка</h3>
<button type="button" class="modal__close" onclick="closeDrawingEditor()">&times;</button>
</div>
<div class="modal__body">
<input type="hidden" id="editorParticipantId">
<div class="flex gap-md mb-md" style="flex-wrap:wrap;">
<button type="button" class="btn btn--secondary" onclick="rotateBy(-45)">-45°</button>
<button type="button" class="btn btn--secondary" onclick="rotateBy(45)">+45°</button>
<button type="button" class="btn btn--secondary" onclick="rotateBy(-90)">-90°</button>
<button type="button" class="btn btn--secondary" onclick="rotateBy(90)">+90°</button>
<label style="display:flex;align-items:center;gap:8px;">Угол:
<input type="number" id="rotationInput" value="0" step="1" style="width:90px;" class="form-input">
</label>
</div>
<div style="max-height:70vh;overflow:auto;">
<img id="editorImage" src="" alt="Рисунок" style="max-width:100%; display:block;">
</div>
<div class="flex gap-md mt-lg">
<button type="button" id="saveDrawingChanges" class="btn btn--primary" style="display:none;">Сохранить изменения</button>
<button type="button" id="cancelDrawingChanges" class="btn btn--ghost" style="display:none;" onclick="resetDrawingEditor()">Отменить изменения</button>
</div>
</div>
</div>
</div>

<script>
let cropper = null;
let currentRotation = 0;
let editorDirty = false;

function markEditorDirty(dirty) {
 editorDirty = dirty;
 document.getElementById('saveDrawingChanges').style.display = dirty ? 'inline-flex' : 'none';
 document.getElementById('cancelDrawingChanges').style.display = dirty ? 'inline-flex' : 'none';
}

function openDrawingEditor(participantId, imageSrc) {
 const modal = document.getElementById('drawingEditorModal');
 const image = document.getElementById('editorImage');
 document.getElementById('editorParticipantId').value = participantId;
 image.src = imageSrc;
 modal.classList.add('active');
 document.body.style.overflow = 'hidden';
 currentRotation = 0;
 document.getElementById('rotationInput').value = '0';
 markEditorDirty(false);

 if (cropper) {
  cropper.destroy();
 }

 setTimeout(() => {
  cropper = new Cropper(image, {
   viewMode: 1,
   autoCropArea: 0.9,
   responsive: true,
   background: false,
   ready: () => markEditorDirty(false),
   crop: () => markEditorDirty(true),
  });
 }, 50);
}

function closeDrawingEditor() {
 const modal = document.getElementById('drawingEditorModal');
 modal.classList.remove('active');
 document.body.style.overflow = '';
 if (cropper) {
  cropper.destroy();
  cropper = null;
 }
}

function rotateBy(deg) {
 if (!cropper) return;
 currentRotation += deg;
 cropper.rotate(deg);
 document.getElementById('rotationInput').value = String(Math.round(currentRotation));
 markEditorDirty(true);
}

function resetDrawingEditor() {
 if (!cropper) return;
 cropper.reset();
 currentRotation = 0;
 document.getElementById('rotationInput').value = '0';
 markEditorDirty(false);
}

document.getElementById('rotationInput').addEventListener('change', function() {
 if (!cropper) return;
 const target = parseFloat(this.value || '0');
 const diff = target - currentRotation;
 cropper.rotate(diff);
 currentRotation = target;
 markEditorDirty(true);
});

document.querySelectorAll('.js-open-editor').forEach((btn) => {
 btn.addEventListener('click', () => openDrawingEditor(btn.dataset.participantId, btn.dataset.imageSrc));
});

document.getElementById('saveDrawingChanges').addEventListener('click', function() {
 if (!cropper) return;
 const cropData = cropper.getData(true);
 const participantId = document.getElementById('editorParticipantId').value;
 const formData = new FormData();
 formData.append('action', 'save_drawing_edit');
 formData.append('participant_id', participantId);
 formData.append('csrf_token', '<?= generateCSRFToken() ?>');
 formData.append('rotation', String(currentRotation));
 formData.append('crop_x', String(cropData.x));
 formData.append('crop_y', String(cropData.y));
 formData.append('crop_w', String(cropData.width));
 formData.append('crop_h', String(cropData.height));

 fetch('/admin/application/<?= e($application_id) ?>', { method: 'POST', body: formData })
  .then(r => r.json())
  .then(data => {
   if (!data.success) {
    alert(data.message || 'Ошибка сохранения');
    return;
   }
   document.querySelectorAll(`.js-admin-drawing[data-participant-id="${participantId}"]`).forEach((img) => {
    img.src = data.updated_url;
   });
   closeDrawingEditor();
  })
  .catch(() => alert('Не удалось сохранить изменения'));
});

function openMessageModal() { document.getElementById('messageModal').classList.add('active'); document.body.style.overflow = 'hidden'; }
function closeMessageModal() { document.getElementById('messageModal').classList.remove('active'); document.body.style.overflow = ''; }
function selectPriority(value) {
 document.querySelectorAll('.priority-btn').forEach(btn => btn.style.borderColor = '#E5E7EB');
 document.querySelector(`.priority-btn--${value}`).style.borderColor = value === 'normal' ? '#6B7280' : value === 'important' ? '#F59E0B' : '#EF4444';
 document.querySelector(`input[value="${value}"]`).checked = true;
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeMessageModal(); });
document.getElementById('messageModal').addEventListener('click', function(e) { if (e.target === this) closeMessageModal(); });

async function saveDrawingCompliance(form) {
 const toggle = form.querySelector('.js-drawing-compliant-toggle');
 if (!toggle) return;
 const formData = new FormData(form);
 if (!toggle.checked) {
  formData.delete('drawing_compliant');
 }
 try {
  const response = await fetch('/admin/application/<?= e($application_id) ?>', {
   method: 'POST',
   headers: { 'X-Requested-With': 'XMLHttpRequest' },
   body: formData,
  });
  const data = await response.json();
  if (!data.success) {
   alert(data.error || 'Не удалось сохранить проверку');
  }
 } catch (error) {
  alert('Не удалось сохранить проверку');
 }
}

document.querySelectorAll('.js-drawing-compliance-form').forEach((form) => {
 const toggle = form.querySelector('.js-drawing-compliant-toggle');
 const comment = form.querySelector('.js-drawing-comment');
 if (toggle) {
  toggle.addEventListener('change', () => saveDrawingCompliance(form));
 }
 if (comment) {
  let commentTimer = null;
  comment.addEventListener('input', () => {
   if (commentTimer) clearTimeout(commentTimer);
   commentTimer = setTimeout(() => saveDrawingCompliance(form), 500);
  });
 }
 form.addEventListener('submit', (event) => event.preventDefault());
});
</script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
