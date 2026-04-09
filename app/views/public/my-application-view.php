<?php
// my-application-view.php - Просмотр заявки пользователем
require_once dirname(__DIR__, 3) . '/config.php';

// Проверка авторизации
if (!isAuthenticated()) {
 redirect('/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/contests'));
}
check_csrf();

$applicationId = intval($_GET['id'] ??0);
$userId = getCurrentUserId();
$user = getCurrentUser();

// Получаем заявку
$stmt = $pdo->prepare("
 SELECT a.*, c.title as contest_title, c.id as contest_id
 FROM applications a
 JOIN contests c ON a.contest_id = c.id
 WHERE a.id = ? AND a.user_id = ?
");
$stmt->execute([$applicationId, $userId]);
$application = $stmt->fetch();

if (!$application) {
 redirect('/my-applications');
}

$isApplicationAccepted = in_array((string)($application['status'] ?? ''), ['accepted', 'approved'], true);

$disputeChatSubject = 'Оспаривание решения по заявке #' . $applicationId;
$isDisputeChatClosed = false;
try {
    $closedStmt = $pdo->prepare("SELECT dispute_chat_closed FROM applications WHERE id = ? LIMIT 1");
    $closedStmt->execute([$applicationId]);
    $isDisputeChatClosed = (int) $closedStmt->fetchColumn() === 1;
} catch (Exception $e) {
    $isDisputeChatClosed = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dispute_reply') {
    $isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (string) ($_POST['ajax'] ?? '') === '1';
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Ошибка безопасности. Обновите страницу.';
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 403);
        }
    } else {
        $reason = trim($_POST['dispute_reason'] ?? '');
        if (!in_array($application['status'], ['declined', 'rejected'], true)) {
            $_SESSION['error_message'] = 'Оспорить можно только отклонённую заявку.';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 422);
            }
        } elseif ($isDisputeChatClosed) {
            $_SESSION['error_message'] = 'Чат завершён. Отправка новых сообщений недоступна.';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 423);
            }
        } elseif ($reason === '') {
            $_SESSION['error_message'] = 'Укажите причину оспаривания.';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 422);
            }
        } else {
            try {
                $stmt = $pdo->prepare("
                INSERT INTO messages (user_id, application_id, title, content, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $user['id'],
                    $applicationId,
                    $disputeChatSubject,
                    $reason,
                    $user['id'],
                ]);
                try {
                    $unarchiveStmt = $pdo->prepare("UPDATE applications SET dispute_chat_archived = 0 WHERE id = ?");
                    $unarchiveStmt->execute([$applicationId]);
                } catch (Exception $ignored) {
                }
                $_SESSION['success_message'] = 'Сообщение отправлено администратору.';
                if ($isAjaxRequest) {
                    $userLabel = trim(($user['surname'] ?? '') . ' ' . ($user['name'] ?? '') . ' ' . ($user['patronymic'] ?? ''));
                    if ($userLabel === '') {
                        $userLabel = 'Пользователь';
                    }
                    jsonResponse([
                        'success' => true,
                        'message' => [
                            'id' => (int) $pdo->lastInsertId(),
                            'content' => $reason,
                            'created_at' => date('d.m.Y H:i'),
                            'author_label' => $userLabel,
                            'from_admin' => false,
                            'author_name' => $userLabel,
                            'author_email' => (string) ($user['email'] ?? ''),
                        ],
                    ]);
                }
            } catch (Exception $e) {
                $_SESSION['error_message'] = 'Не удалось отправить сообщение.';
                if ($isAjaxRequest) {
                    jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 500);
                }
            }
        }
    }
    redirect('/application/' . $applicationId . '#dispute-chat');
}

if (($_GET['action'] ?? '') === 'poll_dispute_messages') {
    $pollApplicationId = intval($_GET['application_id'] ?? 0);
    $lastMessageId = max(0, intval($_GET['last_message_id'] ?? 0));
    if ($pollApplicationId <= 0 || $pollApplicationId !== $applicationId) {
        jsonResponse(['success' => false, 'error' => 'Некорректная заявка'], 422);
    }

    $pollStmt = $pdo->prepare("
        SELECT
            m.id,
            m.content,
            m.created_at,
            u.name,
            u.surname,
            u.patronymic,
            u.email,
            u.is_admin
        FROM messages m
        JOIN users u ON u.id = m.created_by
        WHERE m.user_id = ?
          AND m.application_id = ?
          AND m.title = ?
          AND m.id > ?
        ORDER BY m.id ASC
    ");
    $pollStmt->execute([$user['id'], $applicationId, $disputeChatSubject, $lastMessageId]);
    $rows = $pollStmt->fetchAll();

    $messages = [];
    foreach ($rows as $row) {
        $authorName = trim(($row['surname'] ?? '') . ' ' . ($row['name'] ?? '') . ' ' . ($row['patronymic'] ?? ''));
        $fromAdmin = (int) ($row['is_admin'] ?? 0) === 1;
        $messages[] = [
            'id' => (int) $row['id'],
            'content' => (string) ($row['content'] ?? ''),
            'created_at' => date('d.m.Y H:i', strtotime((string) $row['created_at'])),
            'author_label' => $fromAdmin ? 'Руководитель проекта — ' . ($authorName !== '' ? $authorName : 'Администратор') : ($authorName !== '' ? $authorName : 'Пользователь'),
            'from_admin' => $fromAdmin,
            'author_name' => $authorName,
            'author_email' => (string) ($row['email'] ?? ''),
        ];
    }

    jsonResponse(['success' => true, 'messages' => $messages]);
}

// Получаем работы (синхронизируются из участников)
$participants = getApplicationWorks((int)$applicationId);
$participantByWorkId = [];
foreach ($participants as $participantRow) {
    $participantByWorkId[(int)($participantRow['id'] ?? 0)] = $participantRow;
}

$ensureDiplomaActionsAllowed = static function () use ($isApplicationAccepted, $applicationId) {
    if ($isApplicationAccepted) {
        return;
    }
    $_SESSION['error_message'] = 'Действия с дипломами доступны только после статуса «Принята к участию».';
    redirect('/application/' . $applicationId);
};

if (($_GET['action'] ?? '') === 'diploma_preview_one') {
    $ensureDiplomaActionsAllowed();
    $workId = (int) ($_GET['work_id'] ?? 0);
    if (!isset($participantByWorkId[$workId])) {
        $_SESSION['error_message'] = 'Работа не найдена.';
        redirect('/application/' . $applicationId);
    }
    try {
        $diploma = generateWorkDiploma($workId, false);
        redirect(getPublicDiplomaUrl((string) ($diploma['public_token'] ?? '')));
    } catch (Throwable $e) {
        $_SESSION['error_message'] = $e->getMessage();
        redirect('/application/' . $applicationId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && str_starts_with((string)$_POST['action'], 'diploma_')) {
    $ensureDiplomaActionsAllowed();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Ошибка безопасности.';
        redirect('/application/' . $applicationId);
    }

    try {
        if ($_POST['action'] === 'diploma_download_one') {
            $workId = (int)($_POST['work_id'] ?? 0);
            if (!isset($participantByWorkId[$workId])) { throw new RuntimeException('Работа не найдена'); }
            $diploma = generateWorkDiploma($workId, false);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="diploma_work_' . $workId . '.pdf"');
            readfile(ROOT_PATH . '/' . $diploma['file_path']);
            exit;
        }
        if ($_POST['action'] === 'diploma_link_one') {
            $workId = (int)($_POST['work_id'] ?? 0);
            if (!isset($participantByWorkId[$workId])) { throw new RuntimeException('Работа не найдена'); }
            $diploma = generateWorkDiploma($workId, false);
            $_SESSION['success_message'] = 'Ссылка скопирована: ' . getPublicDiplomaUrl((string)($diploma['public_token'] ?? ''));
            redirect('/application/' . $applicationId);
        }
        if ($_POST['action'] === 'diploma_email_one') {
            $workId = (int)($_POST['work_id'] ?? 0);
            if (!isset($participantByWorkId[$workId])) { throw new RuntimeException('Работа не найдена'); }
            $diploma = generateWorkDiploma($workId, false);
            $ctx = getWorkDiplomaContext($workId);
            if (!$ctx || !sendDiplomaByEmail($ctx, $diploma)) {
                throw new RuntimeException('Не удалось отправить диплом на почту.');
            }
            $_SESSION['success_message'] = 'Диплом отправлен на почту.';
            redirect('/application/' . $applicationId);
        }
        if ($_POST['action'] === 'diploma_download_all') {
            foreach ($participants as $participantRow) {
                if (mapWorkStatusToDiplomaType((string)($participantRow['status'] ?? 'pending')) === null) {
                    continue;
                }
                generateWorkDiploma((int)$participantRow['id'], false);
            }
            $zipRelative = buildApplicationDiplomaZip($applicationId);
            $zipAbsolute = ROOT_PATH . '/' . $zipRelative;
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename($zipAbsolute) . '"');
            readfile($zipAbsolute);
            exit;
        }
        if ($_POST['action'] === 'diploma_links_all') {
            foreach ($participants as $participantRow) {
                if (mapWorkStatusToDiplomaType((string)($participantRow['status'] ?? 'pending')) === null) {
                    continue;
                }
                generateWorkDiploma((int)$participantRow['id'], false);
            }
            $links = collectApplicationDiplomaLinks($applicationId);
            $_SESSION['success_message'] = 'Ссылки скопированы: ' . implode(' | ', array_map(static fn($it) => $it['participant'] . ': ' . $it['url'], $links));
            redirect('/application/' . $applicationId);
        }
        if ($_POST['action'] === 'diploma_email_all') {
            $sent = 0;
            foreach ($participants as $participantRow) {
                $status = (string)($participantRow['status'] ?? 'pending');
                if (mapWorkStatusToDiplomaType($status) === null) {
                    continue;
                }
                $workId = (int)($participantRow['id'] ?? 0);
                $diploma = generateWorkDiploma($workId, false);
                $ctx = getWorkDiplomaContext($workId);
                if ($ctx && sendDiplomaByEmail($ctx, $diploma)) {
                    $sent++;
                }
            }
            if ($sent > 0) {
                $_SESSION['success_message'] = 'Диплом отправлен на почту.';
            } else {
                $_SESSION['error_message'] = 'Нет доступных дипломов для отправки.';
            }
            redirect('/application/' . $applicationId);
        }
    } catch (Throwable $e) {
        $_SESSION['error_message'] = $e->getMessage();
        redirect('/application/' . $applicationId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'update_participant_correction') {
    $isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (string) ($_POST['ajax'] ?? '') === '1';

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Ошибка безопасности. Обновите страницу.';
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => $message], 403);
        }
        $_SESSION['error_message'] = $message;
        redirect('/application/' . $applicationId);
    }

    $workId = (int)($_POST['work_id'] ?? 0);
    $workRow = $participantByWorkId[$workId] ?? null;
    if (!$workRow) {
        $message = 'Работа не найдена.';
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => $message], 404);
        }
        $_SESSION['error_message'] = $message;
        redirect('/application/' . $applicationId);
    }

    $participantId = (int)($workRow['participant_id'] ?? 0);
    $correctionCheckStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM application_corrections
        WHERE application_id = ? AND participant_id = ? AND is_resolved = 0
    ");
    $correctionCheckStmt->execute([$applicationId, $participantId]);
    if ((int)$correctionCheckStmt->fetchColumn() <= 0) {
        $message = 'Корректировка для этой работы не требуется.';
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => $message], 422);
        }
        $_SESSION['error_message'] = $message;
        redirect('/application/' . $applicationId);
    }

    $fio = trim((string)($_POST['fio'] ?? ''));
    $age = max(0, (int)($_POST['age'] ?? 0));
    $workTitle = trim((string)($_POST['work_title'] ?? ''));
    if ($fio === '') {
        $message = 'Укажите ФИО участника.';
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => $message], 422);
        }
        $_SESSION['error_message'] = $message;
        redirect('/application/' . $applicationId);
    }

    $drawingFile = trim((string)($workRow['drawing_file'] ?? ''));
    $oldDrawingPath = $drawingFile !== '' ? getParticipantDrawingFsPath($user['email'] ?? '', $drawingFile) : null;
    $newDrawingPath = null;

    if (isset($_FILES['drawing_file']) && (int)($_FILES['drawing_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ((int)$_FILES['drawing_file']['error'] !== UPLOAD_ERR_OK) {
            $message = 'Не удалось загрузить новый рисунок.';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $message], 422);
            }
            $_SESSION['error_message'] = $message;
            redirect('/application/' . $applicationId);
        }

        $ext = strtolower((string)pathinfo((string)($_FILES['drawing_file']['name'] ?? ''), PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'bmp'];
        if (!in_array($ext, $allowedExt, true)) {
            $message = 'Недопустимый формат файла.';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $message], 422);
            }
            $_SESSION['error_message'] = $message;
            redirect('/application/' . $applicationId);
        }

        $userUploadPath = DRAWINGS_PATH . '/' . normalizeDrawingOwner($user['email'] ?? '');
        if (!is_dir($userUploadPath) && !mkdir($userUploadPath, 0777, true) && !is_dir($userUploadPath)) {
            $message = 'Не удалось подготовить каталог для файла.';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $message], 500);
            }
            $_SESSION['error_message'] = $message;
            redirect('/application/' . $applicationId);
        }

        $tmpUpload = (string)($_FILES['drawing_file']['tmp_name'] ?? '');
        $newFilename = sanitizeFilename($fio) . '_' . $age . '_' . bin2hex(random_bytes(4)) . '.jpg';
        $saved = processAndSaveImage($tmpUpload, $userUploadPath, $newFilename);
        if (!$saved) {
            $message = 'Не удалось обработать файл рисунка.';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $message], 422);
            }
            $_SESSION['error_message'] = $message;
            redirect('/application/' . $applicationId);
        }

        $newDrawingPath = (string)$saved;
        $drawingFile = basename($newDrawingPath);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE participants
            SET fio = ?, age = ?, drawing_file = ?
            WHERE id = ? AND application_id = ?
        ")->execute([$fio, $age, $drawingFile, $participantId, $applicationId]);
        $pdo->prepare("UPDATE works SET work_title = ?, updated_at = NOW() WHERE id = ? AND application_id = ?")
            ->execute([$workTitle, $workId, $applicationId]);
        $pdo->prepare("
            UPDATE application_corrections
            SET is_resolved = 1, resolved_at = NOW()
            WHERE application_id = ? AND participant_id = ? AND is_resolved = 0
        ")->execute([$applicationId, $participantId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($newDrawingPath && file_exists($newDrawingPath)) {
            @unlink($newDrawingPath);
        }
        $message = 'Не удалось сохранить изменения участника.';
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => $message], 500);
        }
        $_SESSION['error_message'] = $message;
        redirect('/application/' . $applicationId);
    }

    if ($newDrawingPath && $oldDrawingPath && file_exists($oldDrawingPath) && realpath($oldDrawingPath) !== realpath($newDrawingPath)) {
        @unlink($oldDrawingPath);
    }

    $updatedDrawingUrl = $drawingFile !== '' ? getParticipantDrawingWebPath($user['email'] ?? '', $drawingFile) : null;
    if ($updatedDrawingUrl) {
        $updatedDrawingUrl .= '?v=' . time();
    }

    if ($isAjaxRequest) {
        jsonResponse([
            'success' => true,
            'message' => 'Изменения сохранены.',
            'participant' => [
                'fio' => $fio,
                'age' => $age > 0 ? $age : '—',
                'work_title' => $workTitle,
                'drawing_url' => $updatedDrawingUrl,
            ],
        ]);
    }

    $_SESSION['success_message'] = 'Изменения сохранены.';
    redirect('/application/' . $applicationId);
}

// Получаем корректировки
$corrections = getApplicationCorrections($applicationId);
$unresolvedCorrections = array_filter($corrections, function($c) {
 return $c['is_resolved'] ==0;
});

// Проверяем, разрешено ли редактирование
$canEdit = $application['status'] === 'draft'
    || ($application['allow_edit'] ==1 && $application['status'] !== 'approved');
$statusMeta = getApplicationStatusMeta($application['status']);
$statusClass = str_replace('badge--', '', $statusMeta['badge_class']);
$participantCorrections = [];
foreach ($unresolvedCorrections as $correction) {
    $participantId = (int) ($correction['participant_id'] ?? 0);
    if ($participantId > 0) {
        $participantCorrections[$participantId][] = $correction;
    }
}

$workSummary = buildApplicationWorkSummary($participants);
$allPending = $workSummary['total'] > 0 && $workSummary['pending'] === $workSummary['total'];
$hasDiplomas = $workSummary['diplomas'] > 0;
$hasVkPublished = $workSummary['vk_published'] > 0;
$uiStatusMeta = getApplicationUiStatusMeta($workSummary);
$vkPublicationLinks = [];
foreach ($participants as $participantRow) {
    if (!empty($participantRow['vk_post_url'])) {
        $vkPublicationLinks[] = (string)$participantRow['vk_post_url'];
    }
}
$vkPublicationLinks = array_values(array_unique($vkPublicationLinks));

$disputeChatMessages = [];
if (in_array($application['status'], ['declined', 'rejected'], true)) {
    try {
        $stmt = $pdo->prepare("
        SELECT m.*, u.name, u.surname, u.patronymic, u.is_admin
        FROM messages m
        JOIN users u ON u.id = m.created_by
        WHERE m.user_id = ? AND m.application_id = ? AND m.title = ?
        ORDER BY m.created_at ASC
        ");
        $stmt->execute([$user['id'], $applicationId, $disputeChatSubject]);
        $disputeChatMessages = $stmt->fetchAll();

        // Помечаем ответы администратора прочитанными
        $stmt = $pdo->prepare("
        UPDATE messages m
        JOIN users u ON u.id = m.created_by
        SET m.is_read = 1
        WHERE m.user_id = ?
          AND m.application_id = ?
          AND m.title = ?
          AND m.is_read = 0
          AND u.is_admin = 1
        ");
        $stmt->execute([$user['id'], $applicationId, $disputeChatSubject]);
    } catch (Exception $e) {
        $disputeChatMessages = [];
    }
}

$currentPage = 'applications';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Заявка #<?= $applicationId ?> - ДетскиеКонкурсы.рф</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
<style>
.dispute-chat-modal {max-width: 760px; width: calc(100% - 32px);}
.dispute-chat-modal__body {display:flex; flex-direction:column; gap:16px;}
.dispute-chat-modal__messages {max-height:420px; overflow:auto; display:flex; flex-direction:column; gap:12px;}
.dispute-chat-message {display:flex;}
.dispute-chat-message--admin {justify-content:flex-end;}
.dispute-chat-message--user {justify-content:flex-start;}
.dispute-chat-message__bubble {max-width:80%; padding:12px 14px; border-radius:12px;}
.dispute-chat-message--admin .dispute-chat-message__bubble {background:#DCFCE7;}
.dispute-chat-message--user .dispute-chat-message__bubble {background:#EEF2FF;}
.dispute-chat-message__meta {font-size:12px; color:#6B7280; margin-bottom:6px; display:flex; gap:8px; flex-wrap:wrap;}
.dispute-chat-message__text {white-space:pre-wrap; line-height:1.5;}
</style>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="container" style="padding: var(--space-xl) var(--space-lg);">
<div class="application-detail">
<?php if (!empty($_SESSION['success_message'])): ?>
<div class="alert alert--success mb-lg js-toast-alert">
<i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
</div>
<?php unset($_SESSION['success_message']); endif; ?>

<?php if (!empty($_SESSION['error_message'])): ?>
<div class="alert alert--error mb-lg js-toast-alert">
<i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
</div>
<?php unset($_SESSION['error_message']); endif; ?>

<?php if ($application['status'] === 'revision'): ?>
<div class="alert mb-lg" style="background:#FEF9C3; border:1px solid #FDE68A; color:#92400E;">
<i class="fas fa-tools"></i> Заявка отправлена на корректировку. Исправьте замечания и отправьте повторно.
</div>
<?php elseif ($application['status'] === 'cancelled'): ?>
<div class="alert mb-lg" style="background:#FEE2E2; border:1px solid #FCA5A5; color:#991B1B;">
<i class="fas fa-ban"></i> Заявка отменена.
</div>
<?php elseif (in_array($application['status'], ['declined', 'rejected'], true)): ?>
<div class="alert mb-lg" style="background:#FEE2E2; border:1px solid #FCA5A5; color:#991B1B;">
<i class="fas fa-times-circle"></i> Заявка отклонена.
</div>
<?php endif; ?>

 <!-- Корректировки -->
 <?php if (!empty($unresolvedCorrections)): ?>
<div class="corrections-alert">
<div class="corrections-alert__title">
<i class="fas fa-exclamation-triangle"></i>
 Требуются исправления (<?= count($unresolvedCorrections) ?>)
</div>
<ul class="corrections-alert__list">
 <?php foreach ($unresolvedCorrections as $corr): ?>
<li class="corrections-alert__item">
<span class="corrections-alert__field"><?= htmlspecialchars($corr['field_name']) ?></span>
 <?php if (!empty($corr['comment'])): ?>
<div class="corrections-alert__comment"><?= htmlspecialchars($corr['comment']) ?></div>
 <?php endif; ?>
</li>
 <?php endforeach; ?>
</ul>
</div>
<?php if ($canEdit): ?>
<div class="mt-md">
<a href="/application-form?contest_id=<?= $application['contest_id'] ?>&edit=<?= $applicationId ?>" class="btn btn--primary">
<i class="fas fa-wrench"></i> Исправить замечания
</a>
</div>
<?php endif; ?>
 <?php endif; ?>
        
<div class="application-detail__header">
<div class="flex justify-between items-center">
<div>
<h1 class="application-detail__title">Заявка #<?= $applicationId ?></h1>
<div class="application-detail__meta">
<span><i class="fas fa-trophy"></i> <?= htmlspecialchars($application['contest_title']) ?></span>
<span><i class="fas fa-calendar"></i> <?= date('d.m.Y H:i', strtotime($application['created_at'])) ?></span>
<span class="badge badge--<?= $statusClass ?>">
 <?= htmlspecialchars($statusMeta['label']) ?>
</span>
<span class="badge <?= e($uiStatusMeta['badge_class']) ?>"><?= e($uiStatusMeta['label']) ?></span>
</div>
</div>
<div class="participant-work-card__actions">
    <?php if ($canEdit): ?>
        <a href="/application-form?contest_id=<?= $application['contest_id'] ?>&edit=<?= $applicationId ?>" class="btn btn--primary btn--sm">
            <i class="fas fa-edit"></i> Редактировать
        </a>
    <?php endif; ?>
    <a href="/messages" class="btn btn--ghost btn--sm"><i class="fas fa-envelope"></i> Сообщения</a>
</div>
</div>
</div>

<?php if (in_array($application['status'], ['declined', 'rejected'], true)): ?>
<div class="card mb-lg" id="dispute-chat">
    <div class="card__header flex justify-between items-center">
        <h3>Оспаривание решения по заявке</h3>
        <button type="button" class="btn btn--ghost btn--sm" onclick="openDisputeChatModal()">
            <i class="fas fa-comments"></i> Открыть чат
        </button>
    </div>
    <div class="card__body">
        <p class="text-secondary" style="margin:0;">
            Просматривайте переписку с администратором и отправляйте ответы во всплывающем окне чата.
        </p>
        <?php if ($isDisputeChatClosed): ?>
            <div class="alert alert--warning" style="margin-top:12px;">
                <i class="fas fa-lock"></i> Чат завершён администратором. Доступен только просмотр сообщений.
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal" id="disputeChatModal">
    <div class="modal__content message-modal dispute-chat-modal">
        <div class="modal__header">
            <h3>Оспаривание заявки #<?= (int) $applicationId ?></h3>
            <button type="button" class="modal__close" onclick="closeDisputeChatModal()">&times;</button>
        </div>
        <div class="modal__body dispute-chat-modal__body">
            <div class="dispute-chat-modal__messages" id="disputeChatMessages">
                <?php if (!empty($disputeChatMessages)): ?>
                    <?php foreach ($disputeChatMessages as $chatMessage): ?>
                        <?php $isAdminMessage = (int) ($chatMessage['is_admin'] ?? 0) === 1; ?>
                        <div class="dispute-chat-message <?= $isAdminMessage ? 'dispute-chat-message--user' : 'dispute-chat-message--admin' ?>" data-message-id="<?= (int) $chatMessage['id'] ?>">
                            <div class="dispute-chat-message__bubble">
                                <div class="dispute-chat-message__meta">
                                    <?php if ($isAdminMessage): ?>
                                        <?php
                                            $chatAuthorName = trim(($chatMessage['surname'] ?? '') . ' ' . ($chatMessage['name'] ?? '') . ' ' . ($chatMessage['patronymic'] ?? ''));
                                        ?>
                                        <?= htmlspecialchars('Руководитель проекта — ' . trim($chatAuthorName)) ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars(trim(($user['surname'] ?? '') . ' ' . ($user['name'] ?? '') . ' ' . ($user['patronymic'] ?? ''))) ?>
                                    <?php endif; ?>
                                    <span>• <?= date('d.m.Y H:i', strtotime($chatMessage['created_at'])) ?></span>
                                </div>
                                <div class="dispute-chat-message__text"><?= htmlspecialchars($chatMessage['content']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-secondary">Сообщений пока нет.</p>
                <?php endif; ?>
            </div>

            <?php if (!$isDisputeChatClosed): ?>
                <form method="POST" class="dispute-chat-modal__composer">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="dispute_reply">
                    <div class="form-group">
                        <label class="form-label">Ваш ответ в чате</label>
                        <textarea name="dispute_reason" class="form-textarea js-chat-hotkey" rows="4" required placeholder="Напишите сообщение администратору..."></textarea>
                    </div>
                    <button type="submit" class="btn btn--primary">
                        <i class="fas fa-paper-plane"></i> Отправить
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
        
 <!-- Данные родителя -->
<div class="card mb-lg">
<div class="card__header"><h3>Данные родителя/куратора</h3></div>
<div class="card__body">
<div class="form-row">
<div class="form-group">
<div class="form-label">ФИО</div>
<div class="form-value"><?= htmlspecialchars($application['parent_fio'] ?? '—') ?></div>
</div>
</div>
</div>
</div>
        
<div class="application-works-summary card mb-lg">
    <div class="card__body">
        <div class="application-works-summary__grid">
            <div class="application-works-summary__item">
                <span class="application-works-summary__dot"></span>
                <span>Всего работ: <strong><?= (int) $workSummary['total'] ?></strong></span>
            </div>
            <div class="application-works-summary__item">
                <span class="application-works-summary__dot application-works-summary__dot--accepted"></span>
                <span>Принято: <strong><?= (int) $workSummary['accepted'] ?></strong></span>
            </div>
            <div class="application-works-summary__item">
                <span class="application-works-summary__dot application-works-summary__dot--reviewed"></span>
                <span>Рассмотрено: <strong><?= (int) $workSummary['reviewed'] ?></strong></span>
            </div>
            <div class="application-works-summary__item">
                <span class="application-works-summary__dot application-works-summary__dot--pending"></span>
                <span>На рассмотрении: <strong><?= (int) $workSummary['pending'] ?></strong></span>
            </div>
            <div class="application-works-summary__item">
                <span class="application-works-summary__dot application-works-summary__dot--accepted"></span>
                <span>Дипломов доступно: <strong><?= (int) $workSummary['diplomas'] ?></strong></span>
            </div>
            <div class="application-works-summary__item">
                <span class="application-works-summary__dot application-works-summary__dot--reviewed"></span>
                <span>Опубликовано в VK: <strong><?= (int) $workSummary['vk_published'] ?></strong></span>
            </div>
        </div>
    </div>
</div>

<?php if ($workSummary['total'] === 0): ?>
<div class="empty-state mb-lg">
    <div class="empty-state__icon"><i class="fas fa-palette"></i></div>
    <h3 class="empty-state__title">В заявке пока нет работ</h3>
    <p class="empty-state__text">Добавьте работы через редактирование заявки, чтобы мы смогли их рассмотреть.</p>
</div>
<?php elseif ($allPending): ?>
<div class="application-note mb-lg">
    <strong><i class="fas fa-hourglass-half"></i> Ваши работы находятся на рассмотрении</strong>
    <span>Дипломы появятся после рассмотрения.</span>
</div>
<?php elseif ($hasDiplomas): ?>
<div class="application-note mb-lg" style="background:#ECFDF3; border-left-color:#10B981; color:#065F46;">
    <strong><i class="fas fa-award"></i> Дипломы доступны</strong>
    <span>Спасибо за участие! Вы можете посмотреть, скачать, отправить на почту или получить ссылку.</span>
</div>
<?php endif; ?>

<h2 class="mb-lg">Работы (<?= count($participants) ?>)</h2>

<?php $galleryDisplayIndex = 0; ?>
<?php foreach ($participants as $index => $participant): ?>
<?php
    $hasParticipantCorrection = !empty($participantCorrections[(int) ($participant['participant_id'] ?? 0)]);
    $workStatus = (string)($participant['status'] ?? 'pending');
    $isDiplomaAvailable = mapWorkStatusToDiplomaType($workStatus) !== null;
    $workTitle = trim((string) ($participant['work_title'] ?? ''));
    $participantVkUrl = trim((string)($participant['vk_post_url'] ?? ''));
    $participantGalleryIndex = null;
    if (!empty($participant['drawing_file'])) {
        $participantGalleryIndex = $galleryDisplayIndex;
        $galleryDisplayIndex++;
    }
?>
<div class="participant-card participant-work-card<?= $hasParticipantCorrection ? ' participant-card--needs-fix' : '' ?>">
    <div class="participant-work-card__media">
        <?php if (!empty($participant['drawing_file'])): ?>
            <?php $drawingSrc = getParticipantDrawingWebPath($user['email'] ?? '', $participant['drawing_file']); ?>
            <img src="<?= htmlspecialchars($drawingSrc) ?>"
                alt="Рисунок участника <?= htmlspecialchars((string)($participant['fio'] ?? '')) ?>"
                class="participant-work-card__thumb js-gallery-image"
                data-gallery-index="<?= (int) $participantGalleryIndex ?>">
        <?php else: ?>
            <div class="participant-work-card__thumb participant-work-card__thumb--placeholder">
                <i class="fas fa-image"></i>
            </div>
        <?php endif; ?>
    </div>
    <div class="participant-work-card__content">
        <div class="participant-work-card__head">
            <div>
                <div class="participant-work-card__title"><?= htmlspecialchars($participant['fio'] ?? 'Без имени') ?></div>
                <div class="participant-work-card__subtitle"><?= $workTitle !== '' ? '«' . htmlspecialchars($workTitle) . '»' : 'Работа #' . ($index + 1) ?></div>
            </div>
            <span class="badge <?= getWorkStatusBadgeClass($workStatus) ?>"><?= e(getWorkStatusLabel($workStatus)) ?></span>
        </div>

        <?php if ($hasParticipantCorrection): ?>
            <div class="application-note" style="margin-bottom:12px;">
                <strong><i class="fas fa-tools"></i> Требует исправлений</strong>
                <?php foreach ($participantCorrections[(int) ($participant['participant_id'] ?? 0)] as $participantCorrection): ?>
                    <div>• <?= htmlspecialchars($participantCorrection['field_name']) ?><?= !empty($participantCorrection['comment']) ? ': ' . htmlspecialchars($participantCorrection['comment']) : '' ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p class="participant-work-card__hint"><?= e(getWorkStatusHint($workStatus)) ?></p>

        <div class="participant-work-card__meta">
            <span><strong>ID участника:</strong> #<?= (int) ($participant['participant_id'] ?? 0) ?></span>
            <span><strong>Возраст:</strong> <?= htmlspecialchars($participant['age'] ?? '—') ?></span>
            <span><strong>Регион:</strong> <?= htmlspecialchars($participant['region'] ?? '—') ?></span>
            <span><strong>Организация:</strong> <?= htmlspecialchars($participant['organization_name'] ?? '—') ?></span>
            <?php if ($participantVkUrl !== ''): ?>
                <span><strong>VK:</strong> Работа опубликована</span>
            <?php endif; ?>
        </div>

        <div class="participant-work-card__actions">
            <?php if (!empty($participant['drawing_file'])): ?>
                <button
                    type="button"
                    class="btn btn--ghost btn--sm participant-work-card__action-btn js-gallery-open"
                    data-gallery-index="<?= (int) $participantGalleryIndex ?>">
                    <i class="fas fa-image"></i> Посмотреть рисунок
                </button>
            <?php endif; ?>
            <?php if ($hasParticipantCorrection): ?>
                <button
                    type="button"
                    class="btn btn--primary btn--sm participant-work-card__action-btn js-open-participant-edit"
                    data-work-id="<?= (int)($participant['id'] ?? 0) ?>"
                    data-fio="<?= htmlspecialchars((string)($participant['fio'] ?? ''), ENT_QUOTES) ?>"
                    data-age="<?= (int)($participant['age'] ?? 0) ?>"
                    data-work-title="<?= htmlspecialchars($workTitle, ENT_QUOTES) ?>"
                    data-drawing-url="<?= !empty($participant['drawing_file']) ? htmlspecialchars($drawingSrc, ENT_QUOTES) : '' ?>">
                    <i class="fas fa-pen"></i> Исправить данные
                </button>
            <?php endif; ?>
            <?php if ($isApplicationAccepted && $isDiplomaAvailable): ?>
            <a class="btn btn--ghost btn--sm participant-work-card__action-btn" href="/application/<?= $applicationId ?>?action=diploma_preview_one&work_id=<?= (int)$participant['id'] ?>" target="_blank" rel="noopener">
                <i class="fas fa-eye"></i> Посмотреть диплом
            </a>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="diploma_download_one">
                <input type="hidden" name="work_id" value="<?= (int)$participant['id'] ?>">
                <button class="btn btn--primary btn--sm participant-work-card__action-btn" type="submit"><i class="fas fa-download"></i> Скачать диплом</button>
            </form>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="diploma_link_one">
                <input type="hidden" name="work_id" value="<?= (int)$participant['id'] ?>">
                <button class="btn btn--ghost btn--sm participant-work-card__action-btn" type="submit"><i class="fas fa-link"></i> Получить ссылку</button>
            </form>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="diploma_email_one">
                <input type="hidden" name="work_id" value="<?= (int)$participant['id'] ?>">
                <button class="btn btn--ghost btn--sm participant-work-card__action-btn" type="submit"><i class="fas fa-paper-plane"></i> Отправить на почту</button>
            </form>
            <?php else: ?>
                <button class="btn btn--ghost btn--sm participant-work-card__action-btn" type="button" disabled title="Дипломы доступны только после статуса «Принята к участию».">
                    <i class="fas fa-award"></i> Диплом пока недоступен
                </button>
            <?php endif; ?>
            <?php if ($participantVkUrl !== ''): ?>
                <a class="btn btn--secondary btn--sm participant-work-card__action-btn" href="<?= e($participantVkUrl) ?>" target="_blank" rel="noopener">
                    <i class="fab fa-vk"></i> Открыть публикацию
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
        
 <!-- Дополнительная информация -->
 <?php if (!empty($application['source_info']) || !empty($application['colleagues_info']) || !empty($application['recommendations_wishes'])): ?>
<div class="card mb-lg">
<div class="card__header"><h3>Дополнительная информация</h3></div>
<div class="card__body">
 <?php if (!empty($application['source_info'])): ?>
<div class="form-group">
<div class="form-label">Откуда узнали о конкурсе</div>
<div><?= htmlspecialchars($application['source_info']) ?></div>
</div>
 <?php endif; ?>
            
 <?php if (!empty($application['colleagues_info'])): ?>
<div class="form-group">
<div class="form-label">Проинформировали коллег</div>
<div><?= htmlspecialchars($application['colleagues_info']) ?></div>
</div>
 <?php endif; ?>

 <?php if (!empty($application['recommendations_wishes'])): ?>
<div class="form-group">
<div class="form-label">Рекомендации и пожелания</div>
<div><?= nl2br(htmlspecialchars($application['recommendations_wishes'])) ?></div>
</div>
 <?php endif; ?>
</div>
</div>
 <?php endif; ?>
        
<div class="card mb-lg">
    <div class="card__header">
        <h3>Массовые действия</h3>
    </div>
    <div class="card__body">
        <?php if ($isApplicationAccepted && $hasDiplomas): ?>
            <p class="text-secondary" style="margin-top:0;">Будут включены только доступные дипломы.</p>
            <div class="participant-work-card__actions">
                <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="diploma_download_all"><button class="btn btn--primary btn--sm participant-work-card__action-btn" type="submit"><i class="fas fa-file-archive"></i> Скачать все дипломы</button></form>
                <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="diploma_links_all"><button class="btn btn--ghost btn--sm participant-work-card__action-btn" type="submit"><i class="fas fa-link"></i> Получить все ссылки</button></form>
                <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="diploma_email_all"><button class="btn btn--ghost btn--sm participant-work-card__action-btn" type="submit"><i class="fas fa-paper-plane"></i> Отправить все дипломы</button></form>
                <?php if (!empty($vkPublicationLinks)): ?>
                    <a class="btn btn--secondary btn--sm participant-work-card__action-btn" href="<?= e($vkPublicationLinks[0]) ?>" target="_blank" rel="noopener">
                        <i class="fab fa-vk"></i> Открыть публикации
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="empty-state" style="padding:20px;">
                <div class="empty-state__icon"><i class="fas fa-award"></i></div>
                <h3 class="empty-state__title" style="font-size:18px;">Нет доступных дипломов</h3>
                <p class="empty-state__text">Дипломы станут доступны после статуса «Принята к участию».</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="mt-xl">
<a href="/my-applications" class="btn btn--secondary">
<i class="fas fa-arrow-left"></i> К списку заявок
</a>
</div>
</div>
</main>

<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
<?php
$galleryImages = [];
foreach ($participants as $participant) {
    if (!empty($participant['drawing_file'])) {
        $galleryImages[] = [
            'src' => getParticipantDrawingWebPath($user['email'] ?? '', $participant['drawing_file']),
            'title' => $participant['fio'] ?? 'Рисунок',
        ];
    }
}
?>
<div class="modal" id="participantEditModal">
<div class="modal__content" style="max-width:720px; width:96%;">
<div class="modal__header">
<h3>Исправление данных участника</h3>
<button type="button" class="modal__close" onclick="closeParticipantEditModal()">&times;</button>
</div>
<form id="participantEditForm" method="POST" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="action" value="update_participant_correction">
<input type="hidden" name="ajax" value="1">
<input type="hidden" name="work_id" id="participantEditWorkId" value="">
<div class="modal__body">
    <div class="form-group"><label class="form-label">ФИО участника</label><input class="form-input" type="text" name="fio" id="participantEditFio" required></div>
    <div class="form-group"><label class="form-label">Возраст</label><input class="form-input" type="number" min="0" name="age" id="participantEditAge" required></div>
    <div class="form-group"><label class="form-label">Название работы</label><input class="form-input" type="text" name="work_title" id="participantEditWorkTitle"></div>
    <div class="form-group"><label class="form-label">Текущий рисунок</label><img id="participantEditPreview" src="" alt="Предпросмотр рисунка" style="max-width:100%; max-height:260px; border-radius:12px; border:1px solid #E5E7EB; display:none;"></div>
    <div class="form-group"><label class="form-label">Заменить рисунок</label><input class="form-input" type="file" name="drawing_file" accept="image/*"></div>
</div>
<div class="modal__footer" style="display:flex; justify-content:flex-end; gap:12px;">
    <button type="button" class="btn btn--ghost" onclick="closeParticipantEditModal()">Отмена</button>
    <button type="submit" class="btn btn--primary" id="participantEditSubmit"><i class="fas fa-save"></i> Сохранить</button>
</div>
</form>
</div>
</div>
<?php if (!empty($galleryImages)): ?>
<div class="modal" id="galleryModal">
<div class="modal__content" style="max-width: 1100px; width: 96%;">
<div class="modal__header">
<h3 id="galleryTitle">Просмотр рисунка</h3>
<button type="button" class="modal__close" onclick="closeGallery()">&times;</button>
</div>
<div class="modal__body">
<img id="galleryImage" src="" alt="Рисунок" style="width:100%; max-height:min(62vh, calc(100vh - 280px)); object-fit:contain; border-radius:12px; background:#111;">
<div class="flex gap-sm mt-md" id="galleryThumbs" style="overflow:auto; max-height:96px;"></div>
</div>
</div>
</div>
<script>
const galleryItems = <?= json_encode($galleryImages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let galleryCurrent = 0;

function renderGalleryThumbs() {
 const container = document.getElementById('galleryThumbs');
 container.innerHTML = '';
 galleryItems.forEach((item, i) => {
  const thumb = document.createElement('img');
  thumb.src = item.src;
  thumb.alt = item.title;
  thumb.style.cssText = `width:72px;height:72px;object-fit:cover;border-radius:8px;cursor:pointer;border:${i === galleryCurrent ? '3px solid #6366F1' : '2px solid #E5E7EB'}`;
  thumb.addEventListener('click', () => openGallery(i));
  container.appendChild(thumb);
 });
}

function openGallery(index) {
 if (!galleryItems[index]) return;
 galleryCurrent = index;
 const item = galleryItems[index];
 document.getElementById('galleryImage').src = item.src;
 document.getElementById('galleryTitle').textContent = item.title;
 document.getElementById('galleryModal').classList.add('active');
 document.body.style.overflow = 'hidden';
 renderGalleryThumbs();
}

function closeGallery() {
 document.getElementById('galleryModal').classList.remove('active');
 document.body.style.overflow = '';
}

document.querySelectorAll('.js-gallery-image').forEach((img) => {
 img.style.cursor = 'zoom-in';
 img.addEventListener('click', () => {
  const galleryIndex = Number(img.dataset.galleryIndex || 0);
  openGallery(galleryIndex);
 });
});

document.querySelectorAll('.js-gallery-open').forEach((button) => {
 button.addEventListener('click', () => {
  const galleryIndex = Number(button.dataset.galleryIndex || 0);
  openGallery(galleryIndex);
 });
});

document.addEventListener('keydown', (e) => {
 if (!document.getElementById('galleryModal').classList.contains('active')) return;
 if (e.key === 'Escape') closeGallery();
 if (e.key === 'ArrowRight') openGallery((galleryCurrent + 1) % galleryItems.length);
 if (e.key === 'ArrowLeft') openGallery((galleryCurrent - 1 + galleryItems.length) % galleryItems.length);
});

document.getElementById('galleryModal').addEventListener('click', (event) => {
 if (event.target === event.currentTarget) {
  closeGallery();
 }
});
</script>
<?php endif; ?>
<script>
function openParticipantEditModal(button) {
 const modal = document.getElementById('participantEditModal');
 const preview = document.getElementById('participantEditPreview');
 document.getElementById('participantEditWorkId').value = button.dataset.workId || '';
 document.getElementById('participantEditFio').value = button.dataset.fio || '';
 document.getElementById('participantEditAge').value = button.dataset.age || '';
 document.getElementById('participantEditWorkTitle').value = button.dataset.workTitle || '';
 const drawingUrl = button.dataset.drawingUrl || '';
 if (drawingUrl) {
  preview.src = drawingUrl;
  preview.style.display = 'block';
 } else {
  preview.removeAttribute('src');
  preview.style.display = 'none';
 }
 modal.classList.add('active');
 document.body.style.overflow = 'hidden';
}

function closeParticipantEditModal() {
 const modal = document.getElementById('participantEditModal');
 if (!modal) return;
 modal.classList.remove('active');
 document.body.style.overflow = '';
 document.getElementById('participantEditForm')?.reset();
}

document.querySelectorAll('.js-open-participant-edit').forEach((button) => {
 button.addEventListener('click', () => openParticipantEditModal(button));
});

document.getElementById('participantEditModal')?.addEventListener('click', (event) => {
 if (event.target === event.currentTarget) {
  closeParticipantEditModal();
 }
});

const participantEditForm = document.getElementById('participantEditForm');
if (participantEditForm) {
 participantEditForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!participantEditForm.reportValidity()) return;

  const submitButton = document.getElementById('participantEditSubmit');
  const defaultHtml = submitButton ? submitButton.innerHTML : '';
  if (submitButton) {
   submitButton.disabled = true;
   submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Сохранение...';
  }

  try {
   const response = await fetch(window.location.href, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: new FormData(participantEditForm),
   });
   const data = await response.json();
   if (!response.ok || !data.success) {
    throw new Error(data.error || 'Не удалось сохранить изменения');
   }

   showToast(data.message || 'Изменения сохранены', 'success');
   closeParticipantEditModal();
   window.location.reload();
  } catch (error) {
   showToast(error.message || 'Ошибка сохранения', 'error');
  } finally {
   if (submitButton) {
    submitButton.disabled = false;
    submitButton.innerHTML = defaultHtml;
   }
  }
 });
}

const currentApplicationId = Number(<?= (int) $applicationId ?>);
const currentApplicationStatus = <?= json_encode((string) ($application['status'] ?? '')) ?>;
let isDisputeChatOpen = false;
let userPollTimerId = null;
let latestUserDisputeMessageId = Math.max(
 0,
 ...Array.from(document.querySelectorAll('#disputeChatMessages .dispute-chat-message'))
  .map((node) => Number(node.dataset.messageId || 0))
  .filter((value) => Number.isFinite(value))
);

function openDisputeChatModal() {
 const modal = document.getElementById('disputeChatModal');
 if (!modal) return;
 modal.classList.add('active');
 isDisputeChatOpen = true;
 document.body.style.overflow = 'hidden';
 const messagesContainer = document.getElementById('disputeChatMessages');
 if (messagesContainer) {
  messagesContainer.scrollTop = messagesContainer.scrollHeight;
 }
 scheduleUserDisputePolling();
}

function closeDisputeChatModal() {
 const modal = document.getElementById('disputeChatModal');
 if (!modal) return;
 modal.classList.remove('active');
 isDisputeChatOpen = false;
 document.body.style.overflow = '';
 if (window.location.hash === '#dispute-chat') {
  history.replaceState(null, '', window.location.pathname + window.location.search);
 }
 scheduleUserDisputePolling();
}

function appendDisputeMessage(container, messageData) {
 if (!container || !messageData) return;
 const numericId = Number(messageData.id || 0);
 if (numericId > 0 && container.querySelector(`.dispute-chat-message[data-message-id="${numericId}"]`)) {
  return;
 }
 const messageWrap = document.createElement('div');
 messageWrap.className = 'dispute-chat-message ' + (messageData.from_admin ? 'dispute-chat-message--user' : 'dispute-chat-message--admin');
 messageWrap.dataset.messageId = String(numericId || 0);

 const bubble = document.createElement('div');
 bubble.className = 'dispute-chat-message__bubble';

 const meta = document.createElement('div');
 meta.className = 'dispute-chat-message__meta';
 meta.textContent = (messageData.author_label || 'Пользователь') + ' • ' + (messageData.created_at || '');

 const text = document.createElement('div');
 text.className = 'dispute-chat-message__text';
 text.textContent = messageData.content || '';

 bubble.appendChild(meta);
 bubble.appendChild(text);
 messageWrap.appendChild(bubble);
 container.appendChild(messageWrap);
 container.scrollTop = container.scrollHeight;
 if (numericId > latestUserDisputeMessageId) {
  latestUserDisputeMessageId = numericId;
 }
}

function showUserNewMessageAlert(messageData) {
 if (!messageData || !messageData.content) return;
 const toast = document.createElement('div');
 toast.className = 'alert alert--success';
 toast.style.cssText = 'position:fixed; top:20px; right:20px; z-index:3200; min-width:280px; max-width:420px; box-shadow:0 12px 30px rgba(0,0,0,.12); cursor:pointer;';
 const preview = messageData.content.slice(0, 50);
 const authorName = (messageData.author_name || 'Пользователь').trim();
 toast.innerHTML =
  '<div style="font-size:11px; opacity:.8; margin-bottom:4px;">новое сообщение</div>' +
  '<div style="font-weight:600;">' + escapeHtml(authorName) + '</div>' +
  '<div style="margin-top:4px; opacity:.9;">' + escapeHtml(preview) + (messageData.content.length > 50 ? '...' : '') + '</div>';
 toast.addEventListener('click', () => {
  openDisputeChatModal();
  toast.remove();
 });
 document.body.appendChild(toast);
 setTimeout(() => toast.remove(), 6000);
}

async function pollUserDisputeMessages() {
 if (!currentApplicationId) return;

 try {
  const url = new URL(window.location.href);
  url.searchParams.set('action', 'poll_dispute_messages');
  url.searchParams.set('application_id', String(currentApplicationId));
  url.searchParams.set('last_message_id', String(latestUserDisputeMessageId));
  const response = await fetch(url.toString(), {
   headers: { 'X-Requested-With': 'XMLHttpRequest' }
  });
  const data = await response.json();
  if (!response.ok || !data.success || !Array.isArray(data.messages)) return;

  data.messages.forEach((messageData) => {
   appendDisputeMessage(document.getElementById('disputeChatMessages'), messageData);
   if (!isDisputeChatOpen) {
    showUserNewMessageAlert(messageData);
   }
  });
 } catch (error) {
  console.error('Ошибка polling пользовательского чата:', error);
 }
}

function scheduleUserDisputePolling() {
 if (userPollTimerId) {
  clearTimeout(userPollTimerId);
  userPollTimerId = null;
 }

 if (!currentApplicationId || !document.getElementById('disputeChatModal')) return;

 const delay = isDisputeChatOpen ? 5000 : 30000;
 userPollTimerId = setTimeout(async () => {
  await pollUserDisputeMessages();
  scheduleUserDisputePolling();
 }, delay);
}

document.getElementById('disputeChatModal')?.addEventListener('click', (event) => {
 if (event.target === event.currentTarget) {
  closeDisputeChatModal();
 }
});

if (window.location.hash === '#dispute-chat') {
 openDisputeChatModal();
}
scheduleUserDisputePolling();

const disputeReplyForm = document.querySelector('#disputeChatModal form.dispute-chat-modal__composer');
if (disputeReplyForm) {
 disputeReplyForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  const textarea = disputeReplyForm.querySelector('textarea[name="dispute_reason"]');
  if (!textarea || !disputeReplyForm.reportValidity()) return;

  const submitButton = disputeReplyForm.querySelector('button[type="submit"]');
  const originalButtonHtml = submitButton ? submitButton.innerHTML : '';
  if (submitButton) {
   submitButton.disabled = true;
   submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';
  }

  const formData = new FormData(disputeReplyForm);
  formData.append('ajax', '1');

  try {
   const response = await fetch(window.location.href, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: formData
   });
   const data = await response.json();
   if (!response.ok || !data.success) {
    throw new Error(data.error || 'Не удалось отправить сообщение');
   }

   appendDisputeMessage(document.getElementById('disputeChatMessages'), data.message);
   textarea.value = '';
   showToast('Сообщение отправлено', 'success');
  } catch (error) {
   showToast(error.message || 'Ошибка отправки сообщения', 'error');
  } finally {
   if (submitButton) {
    submitButton.disabled = false;
    submitButton.innerHTML = originalButtonHtml;
   }
  }
 });
}

document.querySelectorAll('.js-chat-hotkey').forEach((textarea) => {
 textarea.addEventListener('keydown', (event) => {
  if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
   event.preventDefault();
   const form = textarea.closest('form');
   if (form && form.reportValidity()) form.requestSubmit();
  }
 });
});

function showToast(message, type = 'success') {
 const toast = document.createElement('div');
 toast.className = 'alert ' + (type === 'success' ? 'alert--success' : 'alert--error');
 toast.style.cssText = 'position:fixed; top:20px; right:20px; z-index:3000; min-width:260px; max-width:420px; box-shadow:0 12px 30px rgba(0,0,0,.12); opacity:0; transform:translateY(-8px); transition:opacity .25s ease, transform .25s ease;';
 toast.textContent = message;
 document.body.appendChild(toast);
 requestAnimationFrame(() => {
  toast.style.opacity = '1';
  toast.style.transform = 'translateY(0)';
 });
 setTimeout(() => {
  toast.style.opacity = '0';
  toast.style.transform = 'translateY(-8px)';
  setTimeout(() => toast.remove(), 260);
 }, 2600);
}

document.querySelectorAll('.js-toast-alert').forEach((alertEl) => {
 const type = alertEl.classList.contains('alert--error') ? 'error' : 'success';
 showToast(alertEl.textContent.trim(), type);
 alertEl.remove();
});

if (['declined', 'rejected'].includes(currentApplicationStatus)) {
 const declineToastStorageKey = 'decline_toast_seen_' + currentApplicationId;
 if (!localStorage.getItem(declineToastStorageKey)) {
  showToast('Заявка отклонена.', 'error');
  localStorage.setItem(declineToastStorageKey, '1');
 }
}

document.addEventListener('keydown', (event) => {
 if (event.key === 'Escape') {
  closeParticipantEditModal();
  closeDisputeChatModal();
 }
});
</script>
</body>
</html>
