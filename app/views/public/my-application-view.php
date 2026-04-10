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

$isApplicationAccepted = getApplicationCanonicalStatus($application) === 'approved';

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
        if (getApplicationCanonicalStatus($application) !== 'rejected') {
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

$workSummary = buildApplicationWorkSummary($participants);
$uiStatusMeta = getApplicationUiStatusMeta($workSummary);
$displayPermissions = getApplicationDisplayPermissions($application, $participants);
$canShowBulkDiplomaActions = (bool) ($displayPermissions['can_show_bulk_diplomas'] ?? false);

$ensureIndividualDiplomaActionsAllowed = static function (int $workId) use ($participantByWorkId, $applicationId) {
    $workRow = $participantByWorkId[$workId] ?? null;
    if ($workRow && canShowIndividualDiplomaActions($workRow)) {
        return;
    }
    $_SESSION['error_message'] = 'Для выбранной работы диплом пока недоступен.';
    redirect('/application/' . $applicationId);
};

$ensureBulkDiplomaActionsAllowed = static function () use ($canShowBulkDiplomaActions, $applicationId) {
    if ($canShowBulkDiplomaActions) {
        return;
    }
    $_SESSION['error_message'] = 'Массовые действия с дипломами доступны только после принятия заявки.';
    redirect('/application/' . $applicationId);
};

if (($_GET['action'] ?? '') === 'diploma_preview_one') {
    $workId = (int) ($_GET['work_id'] ?? 0);
    if (!isset($participantByWorkId[$workId])) {
        $_SESSION['error_message'] = 'Работа не найдена.';
        redirect('/application/' . $applicationId);
    }
    $ensureIndividualDiplomaActionsAllowed($workId);
    try {
        $diploma = generateWorkDiploma($workId, false);
        redirect(getPublicDiplomaUrl((string) ($diploma['public_token'] ?? '')));
    } catch (Throwable $e) {
        $_SESSION['error_message'] = $e->getMessage();
        redirect('/application/' . $applicationId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && str_starts_with((string)$_POST['action'], 'diploma_')) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Ошибка безопасности.';
        redirect('/application/' . $applicationId);
    }

    try {
        if ($_POST['action'] === 'diploma_download_one') {
            $workId = (int)($_POST['work_id'] ?? 0);
            if (!isset($participantByWorkId[$workId])) { throw new RuntimeException('Работа не найдена'); }
            $ensureIndividualDiplomaActionsAllowed($workId);
            $diploma = generateWorkDiploma($workId, false);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="diploma_work_' . $workId . '.pdf"');
            readfile(ROOT_PATH . '/' . $diploma['file_path']);
            exit;
        }
        if ($_POST['action'] === 'diploma_link_one') {
            $workId = (int)($_POST['work_id'] ?? 0);
            if (!isset($participantByWorkId[$workId])) { throw new RuntimeException('Работа не найдена'); }
            $ensureIndividualDiplomaActionsAllowed($workId);
            $diploma = generateWorkDiploma($workId, false);
            $_SESSION['success_message'] = 'Ссылка скопирована: ' . getPublicDiplomaUrl((string)($diploma['public_token'] ?? ''));
            redirect('/application/' . $applicationId);
        }
        if ($_POST['action'] === 'diploma_email_one') {
            $workId = (int)($_POST['work_id'] ?? 0);
            if (!isset($participantByWorkId[$workId])) { throw new RuntimeException('Работа не найдена'); }
            $ensureIndividualDiplomaActionsAllowed($workId);
            $diploma = generateWorkDiploma($workId, false);
            $ctx = getWorkDiplomaContext($workId);
            if (!$ctx || !sendDiplomaByEmail($ctx, $diploma)) {
                throw new RuntimeException('Не удалось отправить диплом на почту.');
            }
            $_SESSION['success_message'] = 'Диплом отправлен на почту.';
            redirect('/application/' . $applicationId);
        }
        if ($_POST['action'] === 'diploma_download_all') {
            $ensureBulkDiplomaActionsAllowed();
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
            $ensureBulkDiplomaActionsAllowed();
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
            $ensureBulkDiplomaActionsAllowed();
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

    if ($workTitle === '') {
        $message = 'Укажите название рисунка.';
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
    $resubmittedForReview = false;
    try {
        $pdo->prepare("
            UPDATE participants
            SET fio = ?, age = ?, drawing_file = ?
            WHERE id = ? AND application_id = ?
        ")->execute([$fio, $age, $drawingFile, $participantId, $applicationId]);
        $pdo->prepare("UPDATE works SET title = ?, updated_at = NOW() WHERE id = ? AND application_id = ?")
            ->execute([$workTitle, $workId, $applicationId]);
        $pdo->prepare("
            UPDATE application_corrections
            SET is_resolved = 1, resolved_at = NOW()
            WHERE application_id = ? AND participant_id = ? AND is_resolved = 0
        ")->execute([$applicationId, $participantId]);

        $remainingCorrectionsStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM application_corrections
            WHERE application_id = ? AND is_resolved = 0
        ");
        $remainingCorrectionsStmt->execute([$applicationId]);
        $remainingCorrections = (int) $remainingCorrectionsStmt->fetchColumn();
        if ($remainingCorrections === 0 && (int)($application['allow_edit'] ?? 0) === 1 && (string)($application['status'] ?? '') !== 'approved') {
            $pdo->prepare("
                UPDATE applications
                SET status = 'corrected', allow_edit = 0, updated_at = NOW()
                WHERE id = ? AND user_id = ?
            ")->execute([$applicationId, $userId]);
            $application['status'] = 'corrected';
            $application['allow_edit'] = 0;
            $resubmittedForReview = true;
        }

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

    $successMessage = $resubmittedForReview
        ? 'Заявка исправлена и отправлена на повторную проверку, ждите ответа.'
        : 'Изменения сохранены.';

    if ($isAjaxRequest) {
        jsonResponse([
            'success' => true,
            'message' => $successMessage,
            'participant' => [
                'work_id' => $workId,
                'fio' => $fio,
                'age' => $age > 0 ? $age : '—',
                'work_title' => $workTitle,
                'drawing_url' => $updatedDrawingUrl,
            ],
            'resubmitted_for_review' => $resubmittedForReview,
        ]);
    }

    $_SESSION['success_message'] = $successMessage;
    redirect('/application/' . $applicationId);
}

// Получаем корректировки
$corrections = getApplicationCorrections($applicationId);
$unresolvedCorrections = array_filter($corrections, function($c) {
 return $c['is_resolved'] ==0;
});

// Проверяем, разрешено ли редактирование
$canEdit = (bool) ($displayPermissions['can_edit_application'] ?? false);
$statusMeta = getApplicationDisplayMeta($application, $workSummary);
$effectiveApplicationStatus = (string) ($statusMeta['status_code'] ?? 'draft');
$statusClass = str_replace('badge--', '', $statusMeta['badge_class']);
$participantCorrections = [];
foreach ($unresolvedCorrections as $correction) {
    $participantId = (int) ($correction['participant_id'] ?? 0);
    if ($participantId > 0) {
        $participantCorrections[$participantId][] = $correction;
    }
}

$allPending = $workSummary['total'] > 0 && $workSummary['pending'] === $workSummary['total'];
$hasDiplomas = $workSummary['diplomas'] > 0;
$participantsWithDiplomas = array_values(array_filter($participants, static function (array $participantRow): bool {
    $workStatus = (string)($participantRow['status'] ?? 'pending');
    return mapWorkStatusToDiplomaType($workStatus) !== null;
}));
$hasParticipantsWithDiplomas = count($participantsWithDiplomas) > 0;
$hasVkPublished = $workSummary['vk_published'] > 0;
$vkPublicationLinks = [];
foreach ($participants as $participantRow) {
    if (!empty($participantRow['vk_post_url'])) {
        $vkPublicationLinks[] = (string)$participantRow['vk_post_url'];
    }
}
$vkPublicationLinks = array_values(array_unique($vkPublicationLinks));

$statusColorMap = [
    'draft' => ['class' => 'status-pill--draft'],
    'submitted' => ['class' => 'status-pill--submitted'],
    'corrected' => ['class' => 'status-pill--revision'],
    'pending' => ['class' => 'status-pill--pending'],
    'partial_reviewed' => ['class' => 'status-pill--pending'],
    'reviewed' => ['class' => 'status-pill--reviewed'],
    'revision' => ['class' => 'status-pill--revision'],
    'approved' => ['class' => 'status-pill--accepted'],
    'rejected' => ['class' => 'status-pill--declined'],
    'cancelled' => ['class' => 'status-pill--declined'],
];
$statusCode = $effectiveApplicationStatus;
$statusDisplay = [
    'label' => (string) ($statusMeta['label'] ?? 'На рассмотрении'),
    'class' => (string) ($statusColorMap[$statusCode]['class'] ?? 'status-pill--pending'),
];
$applicationProgressStep = $statusCode === 'approved' ? 3 : 2;
$userFullName = trim((string) (($user['surname'] ?? '') . ' ' . ($user['name'] ?? '') . ' ' . ($user['patronymic'] ?? '')));
$userRegion = (string) ($user['region'] ?? $application['region'] ?? '—');
$userOrganization = (string) ($user['organization_name'] ?? $application['organization_name'] ?? '');
$applicationDateLabel = date('d.m.Y H:i', strtotime((string) $application['created_at']));

$disputeChatMessages = [];
if (getApplicationCanonicalStatus($application) === 'rejected') {
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
.application-page {max-width:1200px; margin:0 auto; display:flex; flex-direction:column; gap:16px;}
.application-content {display:grid; grid-template-columns:1fr; gap:16px;}
.app-card {background:#fff; border-radius:14px; box-shadow:0 8px 28px rgba(15,23,42,.06); border:1px solid #EEF2F7; padding:18px;}
.app-card__title {margin:0 0 12px; font-size:20px;}
.app-header {background:linear-gradient(145deg,#ffffff,#f8fbff);}
.app-header__top {display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;}
.app-header__meta {display:flex; gap:14px; color:#64748B; font-size:14px; flex-wrap:wrap;}
.status-pill {display:inline-flex; align-items:center; border-radius:999px; padding:6px 12px; font-size:13px; font-weight:700;}
.status-pill--pending {background:#FEF3C7; color:#92400E;}
.status-pill--draft {background:#F3F4F6; color:#374151;}
.status-pill--submitted {background:#D1FAE5; color:#065F46;}
.status-pill--revision {background:#DBEAFE; color:#1E40AF;}
.status-pill--accepted {background:#16A34A; color:#FFFFFF;}
.status-pill--reviewed {background:#E2E8F0; color:#334155;}
.status-pill--declined {background:#FEE2E2; color:#991B1B;}
.application-progress {display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-top:14px;}
.application-progress__step {display:flex; align-items:center; gap:8px; color:#94A3B8; font-size:13px;}
.application-progress__dot {width:22px; height:22px; border-radius:999px; border:2px solid #CBD5E1; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700;}
.application-progress__step.is-active {color:#0F172A;}
.application-progress__step.is-active .application-progress__dot {border-color:#4CAF50; background:#ECFDF3; color:#166534;}
.app-profile {display:flex; gap:12px; align-items:center;}
.app-profile__avatar {width:52px; height:52px; border-radius:50%; background:#E2E8F0; display:flex; align-items:center; justify-content:center; color:#64748B; font-size:20px;}
.app-profile__meta {display:grid; gap:4px;}
.app-profile__name {font-weight:700;}
.app-sidebar {display:flex; flex-direction:column; gap:16px;}
.participants-grid {display:grid; grid-template-columns:1fr; gap:16px;}
.participant-modern-card {overflow:hidden; padding:0; transition:transform .2s ease, box-shadow .2s ease;}
.participant-modern-card:hover {transform:translateY(-2px); box-shadow:0 12px 32px rgba(15,23,42,.12);}
.participant-modern-card__image-wrap {background:#F1F5F9;}
.participant-modern-card__image {display:block; width:100%; height:min(48vw,360px); min-height:220px; object-fit:cover; cursor:zoom-in;}
.participant-modern-card__body {padding:16px; display:grid; gap:10px;}
.participant-modern-card__header {display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;}
.participant-modern-card__name {margin:0; font-size:20px; line-height:1.2;}
.participant-modern-card__subtitle {color:#475569; font-size:14px;}
.participant-modern-card__facts {display:grid; grid-template-columns:1fr; gap:6px; color:#334155; font-size:14px;}
.participant-modern-card__actions {display:flex; gap:8px; flex-wrap:wrap; margin-top:4px;}
.app-highlight {background:#FEF9C3; border:1px solid #FDE68A; color:#92400E; border-radius:12px; padding:12px;}
.app-empty {text-align:center; padding:30px 16px; color:#64748B;}
.app-skeleton {display:grid; gap:12px;}
.app-skeleton__item {height:96px; border-radius:14px; background:linear-gradient(90deg,#EDF2F7 25%,#E2E8F0 37%,#EDF2F7 63%); background-size:400% 100%; animation:appShimmer 1.2s ease infinite;}
@keyframes appShimmer {0% {background-position:100% 50%;} 100% {background-position:0 50%;}}
.dispute-chat-modal {max-width:760px; width:calc(100% - 32px);}
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
.participant-edit-drawing-row {display:grid; grid-template-columns:1fr; gap:12px;}
.participant-edit-drawing-box {display:flex; flex-direction:column; gap:8px;}
.participant-edit-preview {max-width:100%; max-height:260px; border-radius:12px; border:1px solid #E5E7EB; display:none; object-fit:contain; background:#F8FAFC;}
@media (min-width: 980px) {
 .application-content {grid-template-columns:minmax(0,1.55fr) minmax(320px,1fr);}
 .participant-modern-card__facts {grid-template-columns:repeat(2,minmax(0,1fr));}
 .participant-modern-card__image {height:320px;}
 .participant-edit-drawing-row {grid-template-columns:1fr 1fr; align-items:start;}
}
</style>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="container" style="padding: var(--space-xl) var(--space-lg); background:#f8f9fb;">
<div id="applicationPageSkeleton" class="application-page app-skeleton" aria-hidden="true">
    <div class="app-skeleton__item"></div>
    <div class="app-skeleton__item"></div>
    <div class="app-skeleton__item"></div>
</div>
<div class="application-page" id="applicationPageContent" style="display:none;">
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

<section class="app-card app-header">
    <div class="app-header__top">
        <div>
            <h1 class="app-card__title" style="margin-bottom:8px;"><?= htmlspecialchars($application['contest_title']) ?></h1>
            <div class="app-header__meta">
                <span><i class="fas fa-calendar"></i> Подана: <?= $applicationDateLabel ?></span>
                <span>ID заявки: #<?= (int) $applicationId ?></span>
            </div>
        </div>
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <span class="status-pill <?= e($statusDisplay['class']) ?>"><?= e($statusDisplay['label']) ?></span>
            <span class="badge <?= e($uiStatusMeta['badge_class']) ?>"><?= e($uiStatusMeta['label']) ?></span>
        </div>
    </div>
    <div class="application-progress" aria-label="Прогресс заявки">
        <div class="application-progress__step <?= $applicationProgressStep >= 1 ? 'is-active' : '' ?>"><span class="application-progress__dot">1</span>Подана</div>
        <div class="application-progress__step <?= $applicationProgressStep >= 2 ? 'is-active' : '' ?>"><span class="application-progress__dot">2</span>Проверка</div>
        <div class="application-progress__step <?= $applicationProgressStep >= 3 ? 'is-active' : '' ?>"><span class="application-progress__dot">3</span>Принята</div>
    </div>
</section>

<div class="application-content">
    <section>
        <div class="app-card">
            <h2 class="app-card__title" style="font-size:18px;">Участники заявки (<?= count($participants) ?>)</h2>
            <?php if ($workSummary['total'] === 0): ?>
                <div class="app-empty">
                    <div style="font-size:36px; margin-bottom:8px;"><i class="fas fa-palette"></i></div>
                    В заявке пока нет работ. Добавьте участника через редактирование заявки.
                </div>
            <?php else: ?>
                <div class="participants-grid">
                    <?php $galleryDisplayIndex = 0; ?>
                    <?php foreach ($participants as $index => $participant): ?>
                        <?php
                            $hasParticipantCorrection = !empty($participantCorrections[(int) ($participant['participant_id'] ?? 0)]);
                            $workStatus = (string)($participant['status'] ?? 'pending');
                            $isDiplomaAvailable = mapWorkStatusToDiplomaType($workStatus) !== null;
                            $workTitle = trim((string) (($participant['work_title'] ?? $participant['title'] ?? '')));
                            $participantVkUrl = trim((string)($participant['vk_post_url'] ?? ''));
                            $drawingSrc = !empty($participant['drawing_file']) ? getParticipantDrawingWebPath($user['email'] ?? '', $participant['drawing_file']) : '';
                            $participantGalleryIndex = null;
                            if ($drawingSrc !== '') {
                                $participantGalleryIndex = $galleryDisplayIndex++;
                            }
                        ?>
                        <article class="app-card participant-modern-card<?= $hasParticipantCorrection ? ' participant-card--needs-fix' : '' ?>" data-work-id="<?= (int)($participant['id'] ?? 0) ?>">
                            <div class="participant-modern-card__image-wrap">
                                <?php if ($drawingSrc !== ''): ?>
                                    <img src="<?= e($drawingSrc) ?>" alt="Рисунок участника <?= e((string)($participant['fio'] ?? '')) ?>" class="participant-modern-card__image js-gallery-image" data-gallery-index="<?= (int) $participantGalleryIndex ?>">
                                <?php else: ?>
                                    <div class="participant-modern-card__image" style="display:flex;align-items:center;justify-content:center;color:#94A3B8;background:#F1F5F9;"><i class="fas fa-image"></i></div>
                                <?php endif; ?>
                            </div>
                            <div class="participant-modern-card__body">
                                <div class="participant-modern-card__header">
                                    <div>
                                        <h3 class="participant-modern-card__name"><?= e((string)($participant['fio'] ?? 'Без имени')) ?></h3>
                                        <div class="participant-modern-card__subtitle js-participant-work-subtitle"><?= $workTitle !== '' ? '«' . e($workTitle) . '»' : 'Работа #' . ($index + 1) ?></div>
                                    </div>
                                    <span class="badge <?= getWorkStatusBadgeClass($workStatus) ?>"><?= e(getWorkStatusLabel($workStatus)) ?></span>
                                </div>
                                <div class="participant-modern-card__subtitle"><?= e(getWorkStatusHint($workStatus)) ?></div>
                                <div class="participant-modern-card__facts">
                                    <div><strong>Возраст:</strong> <span class="js-participant-age"><?= e((string)($participant['age'] ?? '—')) ?></span></div>
                                    <div><strong>Регион:</strong> <?= e((string)($participant['region'] ?? '—')) ?></div>
                                    <div><strong>Организация:</strong> <?= e((string)($participant['organization_name'] ?? '—')) ?></div>
                                    <div><strong>Название рисунка:</strong> <span class="js-participant-work-title"><?= e($workTitle !== '' ? $workTitle : '—') ?></span></div>
                                    <div><strong>ID участника:</strong> #<?= (int) ($participant['participant_id'] ?? 0) ?></div>
                                </div>
                                <?php if ($hasParticipantCorrection): ?>
                                    <div class="app-highlight">
                                        <strong><i class="fas fa-tools"></i> Требует исправлений</strong>
                                        <?php foreach ($participantCorrections[(int) ($participant['participant_id'] ?? 0)] as $participantCorrection): ?>
                                            <div>• <?= htmlspecialchars($participantCorrection['field_name']) ?><?= !empty($participantCorrection['comment']) ? ': ' . htmlspecialchars($participantCorrection['comment']) : '' ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="participant-modern-card__actions">
                                    <?php if ($drawingSrc !== ''): ?>
                                        <button type="button" class="btn btn--ghost btn--sm js-gallery-open" data-gallery-index="<?= (int) $participantGalleryIndex ?>"><i class="fas fa-expand"></i> Увеличить</button>
                                    <?php endif; ?>
                                    <?php if ($hasParticipantCorrection): ?>
                                        <button type="button" class="btn btn--primary btn--sm js-open-participant-edit" data-work-id="<?= (int)($participant['id'] ?? 0) ?>" data-fio="<?= htmlspecialchars((string)($participant['fio'] ?? ''), ENT_QUOTES) ?>" data-age="<?= (int)($participant['age'] ?? 0) ?>" data-work-title="<?= htmlspecialchars($workTitle, ENT_QUOTES) ?>" data-drawing-url="<?= htmlspecialchars($drawingSrc, ENT_QUOTES) ?>"><i class="fas fa-pen"></i> Исправить</button>
                                    <?php endif; ?>
                                    <?php if ($isDiplomaAvailable): ?>
                                        <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="diploma_download_one"><input type="hidden" name="work_id" value="<?= (int)$participant['id'] ?>"><button class="btn btn--primary btn--sm" type="submit"><i class="fas fa-download"></i> Скачать диплом</button></form>
                                    <?php endif; ?>
                                    <?php if ($participantVkUrl !== ''): ?>
                                        <a class="btn btn--secondary btn--sm" href="<?= e($participantVkUrl) ?>" target="_blank" rel="noopener"><i class="fab fa-vk"></i> Публикация</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <aside class="app-sidebar">
        <section class="app-card">
            <h2 class="app-card__title" style="font-size:18px;">Информация о пользователе</h2>
            <div class="app-profile">
                <div class="app-profile__avatar"><i class="fas fa-user"></i></div>
                <div class="app-profile__meta">
                    <div class="app-profile__name"><?= e($userFullName !== '' ? $userFullName : 'Пользователь') ?></div>
                    <div><?= !empty($user['email']) ? e((string) $user['email']) : 'Email не указан' ?></div>
                    <div><?= e($userRegion !== '' ? $userRegion : 'Регион не указан') ?></div>
                    <?php if ($userOrganization !== ''): ?><div><?= e($userOrganization) ?></div><?php endif; ?>
                </div>
            </div>
        </section>

        <?php if (!empty($unresolvedCorrections) || $application['status'] === 'revision'): ?>
        <section class="app-card">
            <h2 class="app-card__title" style="font-size:18px;">Статус и комментарии</h2>
            <div class="app-highlight">
                <div><strong>Требуются корректировки</strong></div>
                <?php if (!empty($unresolvedCorrections)): ?>
                    <?php foreach ($unresolvedCorrections as $corr): ?>
                        <div>• <?= htmlspecialchars($corr['field_name']) ?><?= !empty($corr['comment']) ? ': ' . htmlspecialchars($corr['comment']) : '' ?></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div>Администратор запросил внесение правок в заявку.</div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="app-card">
            <h2 class="app-card__title" style="font-size:18px;">Действия</h2>
            <div style="display:flex; flex-wrap:wrap; gap:8px;">
                <?php if ($effectiveApplicationStatus === 'revision' && $canEdit): ?>
                    <a href="/application-form?contest_id=<?= $application['contest_id'] ?>&edit=<?= $applicationId ?>" class="btn btn--primary"><i class="fas fa-pen"></i> Исправить заявку</a>
                <?php elseif ($canShowBulkDiplomaActions && $hasDiplomas): ?>
                    <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="diploma_download_all"><button class="btn btn--primary" type="submit"><i class="fas fa-award"></i> Скачать все дипломы</button></form>
                <?php elseif (getApplicationCanonicalStatus($application) === 'rejected'): ?>
                    <div class="app-highlight" style="width:100%;">
                        <strong>Заявка отклонена.</strong>
                        <div>Причина доступна в комментариях администратора и чате оспаривания.</div>
                    </div>
                <?php else: ?>
                    <div style="color:#64748B;">Заявка находится на рассмотрении. Действия станут доступны после проверки.</div>
                <?php endif; ?>
                <a href="/messages" class="btn btn--ghost"><i class="fas fa-envelope"></i> Сообщения</a>
                <a href="/my-applications" class="btn btn--secondary"><i class="fas fa-arrow-left"></i> К списку</a>
            </div>
        </section>
    </aside>
</div>

<?php if (getApplicationCanonicalStatus($application) === 'rejected'): ?>
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
</div>
</main>

<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
<?php
$galleryImages = [];
foreach ($participants as $participant) {
    if (!empty($participant['drawing_file'])) {
        $galleryWorkTitle = trim((string) ($participant['work_title'] ?? $participant['title'] ?? ''));
        $galleryImages[] = [
            'src' => getParticipantDrawingWebPath($user['email'] ?? '', $participant['drawing_file']),
            'title' => $galleryWorkTitle !== '' ? $galleryWorkTitle : ($participant['fio'] ?? 'Рисунок'),
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
    <div class="form-group"><label class="form-label">Название рисунка</label><input class="form-input" type="text" name="work_title" id="participantEditWorkTitle" required placeholder="Введите название рисунка"></div>
    <div class="participant-edit-drawing-row">
        <div class="participant-edit-drawing-box">
            <label class="form-label">Рисунок</label>
            <img id="participantEditPreview" src="" alt="Предпросмотр рисунка" class="participant-edit-preview">
        </div>
        <div class="participant-edit-drawing-box">
            <label class="form-label">Заменить рисунок</label>
            <div class="upload-area" id="participantEditUploadArea" style="border:2px dashed #D1D5DB; border-radius:12px; padding:20px; text-align:center;">
                <input class="file-upload__input" type="file" name="drawing_file" id="participantEditDrawingFile" accept="image/*">
                <div class="upload-area__icon"><i class="fas fa-cloud-upload-alt"></i></div>
                <div class="upload-area__title" id="participantEditUploadTitle">Нажмите или перетащите новый рисунок</div>
                <div class="upload-area__hint" id="participantEditUploadHint">JPG, PNG, GIF, WebP, TIF</div>
            </div>
        </div>
    </div>
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
window.addEventListener('load', () => {
 const skeleton = document.getElementById('applicationPageSkeleton');
 const content = document.getElementById('applicationPageContent');
 if (content) {
  content.style.display = '';
 }
 if (skeleton) {
  skeleton.style.display = 'none';
 }
});

function openParticipantEditModal(button) {
 const modal = document.getElementById('participantEditModal');
 const preview = document.getElementById('participantEditPreview');
 const uploadArea = document.getElementById('participantEditUploadArea');
 const uploadTitle = document.getElementById('participantEditUploadTitle');
 const uploadHint = document.getElementById('participantEditUploadHint');
 const fileInput = document.getElementById('participantEditDrawingFile');
 document.getElementById('participantEditWorkId').value = button.dataset.workId || '';
 document.getElementById('participantEditFio').value = button.dataset.fio || '';
 document.getElementById('participantEditAge').value = button.dataset.age || '';
 document.getElementById('participantEditWorkTitle').value = button.dataset.workTitle || '';
 clearParticipantDrawingObjectUrl();
 const drawingUrl = button.dataset.drawingUrl || '';
 if (drawingUrl) {
  preview.src = drawingUrl;
  preview.style.display = 'block';
 } else {
  preview.removeAttribute('src');
  preview.style.display = 'none';
 }
 if (uploadArea) {
  uploadArea.classList.remove('has-file');
 }
 if (uploadTitle) {
  uploadTitle.textContent = 'Нажмите или перетащите новый рисунок';
 }
 if (uploadHint) {
  uploadHint.textContent = 'JPG, PNG, GIF, WebP, TIF';
 }
 if (fileInput) {
  fileInput.value = '';
 }
 modal.classList.add('active');
 document.body.style.overflow = 'hidden';
}

function closeParticipantEditModal() {
 const modal = document.getElementById('participantEditModal');
 if (!modal) return;
 clearParticipantDrawingObjectUrl();
 modal.classList.remove('active');
 document.body.style.overflow = '';
 document.getElementById('participantEditForm')?.reset();
}

function previewParticipantDrawingFile(file) {
 const preview = document.getElementById('participantEditPreview');
 const uploadArea = document.getElementById('participantEditUploadArea');
 const uploadTitle = document.getElementById('participantEditUploadTitle');
 const uploadHint = document.getElementById('participantEditUploadHint');
 if (!preview || !file) return;

 const objectUrl = URL.createObjectURL(file);
 preview.dataset.objectUrl = objectUrl;
 preview.src = objectUrl;
 preview.style.display = 'block';
 uploadArea?.classList.add('has-file');
 if (uploadTitle) {
  uploadTitle.textContent = file.name || 'Файл выбран';
 }
 if (uploadHint) {
  uploadHint.textContent = 'Предпросмотр обновлён. Изменения сохранятся после отправки формы.';
 }
}

function clearParticipantDrawingObjectUrl() {
 const preview = document.getElementById('participantEditPreview');
 const objectUrl = preview?.dataset?.objectUrl || '';
 if (objectUrl) {
  URL.revokeObjectURL(objectUrl);
  delete preview.dataset.objectUrl;
 }
}

function updateParticipantCardAfterSave(participant) {
 if (!participant || !participant.work_id) return;
 const card = document.querySelector(`.participant-modern-card[data-work-id="${participant.work_id}"]`);
 if (!card) return;

 const fio = (participant.fio || '').trim();
 const age = participant.age ?? '—';
 const workTitle = (participant.work_title || '').trim();
 const workTitleLabel = workTitle !== '' ? workTitle : '—';

 const nameNode = card.querySelector('.participant-modern-card__name');
 if (nameNode && fio !== '') {
  nameNode.textContent = fio;
 }

 const subtitleNode = card.querySelector('.js-participant-work-subtitle');
 if (subtitleNode) {
  subtitleNode.textContent = workTitle !== '' ? `«${workTitle}»` : 'Без названия';
 }

 const ageNode = card.querySelector('.js-participant-age');
 if (ageNode) {
  ageNode.textContent = String(age);
 }

 const workTitleNode = card.querySelector('.js-participant-work-title');
 if (workTitleNode) {
  workTitleNode.textContent = workTitleLabel;
 }

 const editButton = card.querySelector('.js-open-participant-edit');
 if (editButton) {
  editButton.dataset.fio = fio;
  editButton.dataset.age = String(age);
  editButton.dataset.workTitle = workTitle;
 }

 if (participant.drawing_url) {
  const imageNode = card.querySelector('.participant-modern-card__image');
  if (imageNode) {
   imageNode.src = participant.drawing_url;
  }
 }
}

function applyApplicationResubmittedState() {
 document.querySelectorAll('.participant-card--needs-fix').forEach((card) => {
  card.classList.remove('participant-card--needs-fix');
  card.querySelectorAll('.app-highlight').forEach((node) => node.remove());
 });

 document.querySelectorAll('.js-open-participant-edit').forEach((button) => button.remove());

 const statusPill = document.querySelector('.status-pill');
 if (statusPill) {
  statusPill.className = 'status-pill status-pill--pending';
  statusPill.textContent = 'На рассмотрении';
 }
}

document.querySelectorAll('.js-open-participant-edit').forEach((button) => {
 button.addEventListener('click', () => openParticipantEditModal(button));
});

document.getElementById('participantEditModal')?.addEventListener('click', (event) => {
 if (event.target === event.currentTarget) {
  closeParticipantEditModal();
 }
});

const participantEditUploadArea = document.getElementById('participantEditUploadArea');
const participantEditDrawingFileInput = document.getElementById('participantEditDrawingFile');
if (participantEditUploadArea && participantEditDrawingFileInput) {
 participantEditUploadArea.addEventListener('click', (event) => {
  if (event.target !== participantEditDrawingFileInput) {
   participantEditDrawingFileInput.click();
  }
 });

 participantEditUploadArea.addEventListener('dragover', (event) => {
  event.preventDefault();
  participantEditUploadArea.classList.add('dragover');
 });

 participantEditUploadArea.addEventListener('dragleave', (event) => {
  event.preventDefault();
  participantEditUploadArea.classList.remove('dragover');
 });

 participantEditUploadArea.addEventListener('drop', (event) => {
  event.preventDefault();
  participantEditUploadArea.classList.remove('dragover');
  const files = event.dataTransfer?.files;
  if (!files || !files.length) return;
  const file = files[0];
  if (!file.type.startsWith('image/')) {
   showToast('Можно загружать только изображения.', 'error');
   return;
  }
  const transfer = new DataTransfer();
  transfer.items.add(file);
  participantEditDrawingFileInput.files = transfer.files;
  clearParticipantDrawingObjectUrl();
  previewParticipantDrawingFile(file);
 });

 participantEditDrawingFileInput.addEventListener('change', () => {
  const file = participantEditDrawingFileInput.files && participantEditDrawingFileInput.files[0];
  if (!file) return;
  if (!file.type.startsWith('image/')) {
   showToast('Можно загружать только изображения.', 'error');
   participantEditDrawingFileInput.value = '';
   return;
  }
  clearParticipantDrawingObjectUrl();
  previewParticipantDrawingFile(file);
 });
}

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

   updateParticipantCardAfterSave(data.participant || null);
   if (data.resubmitted_for_review) {
    applyApplicationResubmittedState();
   }
   showToast(data.message || 'Изменения сохранены', 'success');
   clearParticipantDrawingObjectUrl();
   closeParticipantEditModal();
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

if (['rejected'].includes(currentApplicationStatus)) {
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
