<?php
// my-application-view.php - Просмотр заявки пользователем
require_once dirname(__DIR__, 3) . '/includes/init.php';

// Проверка авторизации
if (!isAuthenticated()) {
 redirect('/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/contests'));
}
check_csrf();

$applicationId = intval($_GET['id'] ??0);
$userId = getCurrentUserId();
$user = getCurrentUser();

if (!function_exists('messageAttachmentInsertPayload')) {
    function messageAttachmentInsertPayload(?array $uploadResult): array
    {
        if (empty($uploadResult['uploaded'])) {
            return [null, null, null, 0];
        }

        return [
            (string) ($uploadResult['file_name'] ?? ''),
            (string) ($uploadResult['original_name'] ?? ''),
            (string) ($uploadResult['mime_type'] ?? ''),
            (int) ($uploadResult['file_size'] ?? 0),
        ];
    }
}

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

$disputeChatSubject = buildDisputeChatTitle($applicationId);
$curatorChatTitle = buildCuratorChatTitle($applicationId);
$isDisputeChatClosed = false;
$hasCuratorChat = false;
try {
    $closedStmt = $pdo->prepare("SELECT dispute_chat_closed FROM applications WHERE id = ? LIMIT 1");
    $closedStmt->execute([$applicationId]);
    $isDisputeChatClosed = (int) $closedStmt->fetchColumn() === 1;
} catch (Exception $e) {
    $isDisputeChatClosed = false;
}

try {
    $curatorChatStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM messages
        WHERE user_id = ?
          AND application_id = ?
          AND title = ?
    ");
    $curatorChatStmt->execute([$userId, $applicationId, $curatorChatTitle]);
    $hasCuratorChat = (int) $curatorChatStmt->fetchColumn() > 0;
} catch (Exception $e) {
    $hasCuratorChat = false;
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
        $attachmentUpload = uploadMessageAttachment($_FILES['attachment'] ?? []);
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
        } elseif (empty($attachmentUpload['success'])) {
            $_SESSION['error_message'] = (string) ($attachmentUpload['message'] ?? 'Не удалось загрузить вложение.');
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 422);
            }
        } elseif ($reason === '' && empty($attachmentUpload['uploaded'])) {
            $_SESSION['error_message'] = 'Укажите причину оспаривания или прикрепите файл.';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $_SESSION['error_message']], 422);
            }
        } else {
            try {
                [$attachmentFile, $attachmentOriginalName, $attachmentMimeType, $attachmentSize] = messageAttachmentInsertPayload($attachmentUpload);
                $stmt = $pdo->prepare("
                INSERT INTO messages (
                    user_id,
                    application_id,
                    title,
                    content,
                    created_by,
                    created_at,
                    attachment_file,
                    attachment_original_name,
                    attachment_mime_type,
                    attachment_size
                )
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $user['id'],
                    $applicationId,
                    $disputeChatSubject,
                    $reason,
                    $user['id'],
                    $attachmentFile,
                    $attachmentOriginalName,
                    $attachmentMimeType,
                    $attachmentSize,
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
                            'attachment' => !empty($attachmentUpload['uploaded']) ? [
                                'url' => (string) ($attachmentUpload['url'] ?? ''),
                                'name' => (string) ($attachmentUpload['original_name'] ?? ''),
                                'mime_type' => (string) ($attachmentUpload['mime_type'] ?? ''),
                                'is_image' => !empty($attachmentUpload['is_image']),
                            ] : null,
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
            m.attachment_file,
            m.attachment_original_name,
            m.attachment_mime_type,
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
        $attachmentFile = (string) ($row['attachment_file'] ?? '');
        $attachmentName = (string) ($row['attachment_original_name'] ?? basename($attachmentFile));
        $messages[] = [
            'id' => (int) $row['id'],
            'content' => (string) ($row['content'] ?? ''),
            'created_at' => date('d.m.Y H:i', strtotime((string) $row['created_at'])),
            'author_label' => $fromAdmin ? 'Руководитель проекта — ' . ($authorName !== '' ? $authorName : 'Администратор') : ($authorName !== '' ? $authorName : 'Пользователь'),
            'from_admin' => $fromAdmin,
            'author_name' => $authorName,
            'author_email' => (string) ($row['email'] ?? ''),
            'attachment' => $attachmentFile !== '' ? [
                'url' => buildMessageAttachmentPublicUrl($attachmentFile),
                'name' => $attachmentName,
                'mime_type' => (string) ($row['attachment_mime_type'] ?? ''),
                'is_image' => isImageMessageAttachment((string) ($row['attachment_mime_type'] ?? ''), $attachmentName),
            ] : null,
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

	$workDiplomasByWorkId = [];
	try {
	    $diplomaStmt = $pdo->prepare('SELECT * FROM participant_diplomas WHERE application_id = ?');
	    $diplomaStmt->execute([$applicationId]);
	    $diplomaRows = $diplomaStmt->fetchAll();
	    foreach ($diplomaRows as $row) {
	        $workId = (int) ($row['work_id'] ?? 0);
	        if ($workId <= 0 || !isset($participantByWorkId[$workId])) {
	            continue;
	        }
	        $filePath = trim((string) ($row['file_path'] ?? ''));
	        if ($filePath === '') {
	            continue;
	        }
	        $absolutePath = ROOT_PATH . '/' . ltrim($filePath, '/');
	        if (!is_file($absolutePath)) {
	            continue;
	        }
	        $workDiplomasByWorkId[$workId] = (array) $row;
	    }
	} catch (Throwable $ignored) {
	    $workDiplomasByWorkId = [];
	}
	$diplomasCount = count($workDiplomasByWorkId);

	$ensureIndividualDiplomaActionsAllowed = static function (int $workId) use ($workDiplomasByWorkId, $applicationId) {
	    if (isset($workDiplomasByWorkId[$workId])) {
	        return;
	    }
	    $_SESSION['error_message'] = 'Для выбранной работы диплом пока недоступен.';
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
	        $diploma = $workDiplomasByWorkId[$workId] ?? null;
	        if (!$diploma) {
	            throw new RuntimeException('Диплом ещё не сформирован администратором.');
	        }
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
	            $diploma = $workDiplomasByWorkId[$workId] ?? null;
	            if (!$diploma) { throw new RuntimeException('Диплом ещё не сформирован администратором'); }
	            $absolutePath = ROOT_PATH . '/' . ltrim((string) ($diploma['file_path'] ?? ''), '/');
	            if (!is_file($absolutePath)) { throw new RuntimeException('Файл диплома не найден'); }
	            header('Content-Type: application/pdf');
	            header('Content-Disposition: attachment; filename="diploma_work_' . $workId . '.pdf"');
	            readfile($absolutePath);
	            exit;
	        }
	        if ($_POST['action'] === 'diploma_link_one') {
	            $workId = (int)($_POST['work_id'] ?? 0);
	            if (!isset($participantByWorkId[$workId])) { throw new RuntimeException('Работа не найдена'); }
	            $ensureIndividualDiplomaActionsAllowed($workId);
	            $diploma = $workDiplomasByWorkId[$workId] ?? null;
	            if (!$diploma) { throw new RuntimeException('Диплом ещё не сформирован администратором'); }
	            $_SESSION['success_message'] = 'Ссылка скопирована: ' . getPublicDiplomaUrl((string)($diploma['public_token'] ?? ''));
	            redirect('/application/' . $applicationId);
	        }
	        if ($_POST['action'] === 'diploma_email_one') {
	            $workId = (int)($_POST['work_id'] ?? 0);
	            if (!isset($participantByWorkId[$workId])) { throw new RuntimeException('Работа не найдена'); }
	            $ensureIndividualDiplomaActionsAllowed($workId);
	            $diploma = $workDiplomasByWorkId[$workId] ?? null;
	            if (!$diploma) { throw new RuntimeException('Диплом ещё не сформирован администратором'); }
	            $ctx = getWorkDiplomaContext($workId);
	            if (!$ctx || !sendDiplomaByEmail($ctx, $diploma)) {
	                throw new RuntimeException('Не удалось отправить диплом на почту.');
	            }
            $_SESSION['success_message'] = 'Диплом отправлен на почту.';
            redirect('/application/' . $applicationId);
        }
	        if ($_POST['action'] === 'diploma_download_all') {
	            $attachedWorkIds = [];
	            foreach ($participants as $participantRow) {
	                $workId = (int)$participantRow['id'];
	                if (isset($workDiplomasByWorkId[$workId])) {
	                    $attachedWorkIds[] = $workId;
	                }
	            }
	            if (!$attachedWorkIds) {
	                throw new RuntimeException('Нет сформированных дипломов для скачивания.');
	            }
	            $zipRelative = buildApplicationDiplomaZip($applicationId, $attachedWorkIds);
	            $zipAbsolute = ROOT_PATH . '/' . $zipRelative;
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename($zipAbsolute) . '"');
            readfile($zipAbsolute);
            exit;
        }
	        if ($_POST['action'] === 'diploma_links_all') {
	            $attachedWorkIds = [];
	            foreach ($participants as $participantRow) {
	                $workId = (int)$participantRow['id'];
	                if (isset($workDiplomasByWorkId[$workId])) {
	                    $attachedWorkIds[] = $workId;
	                }
	            }
	            if (!$attachedWorkIds) {
	                throw new RuntimeException('Нет сформированных дипломов для получения ссылок.');
	            }
	            $links = collectApplicationDiplomaLinks($applicationId, $attachedWorkIds);
	            $_SESSION['success_message'] = 'Ссылки скопированы: ' . implode(' | ', array_map(static fn($it) => $it['participant'] . ': ' . $it['url'], $links));
            redirect('/application/' . $applicationId);
        }
	        if ($_POST['action'] === 'diploma_email_all') {
	            $sent = 0;
	            foreach ($participants as $participantRow) {
	                $workId = (int)($participantRow['id'] ?? 0);
	                $diploma = $workDiplomasByWorkId[$workId] ?? null;
	                if (!$diploma) { continue; }
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
    $oldThumbPath = $drawingFile !== '' ? getParticipantDrawingThumbFsPath($user['email'] ?? '', $drawingFile) : null;
    $newDrawingPath = null;
    $newThumbPath = null;

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
        $saved = saveParticipantDrawingWithThumbnail($tmpUpload, $userUploadPath, $newFilename);
        if (empty($saved['success'])) {
            $message = 'Не удалось обработать файл рисунка.';
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => $message], 422);
            }
            $_SESSION['error_message'] = $message;
            redirect('/application/' . $applicationId);
        }

        $newDrawingPath = (string)($saved['original_path'] ?? '');
        $newThumbPath = (string)($saved['thumb_path'] ?? '');
        $drawingFile = (string)($saved['filename'] ?? '');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE participants
            SET fio = ?, age = ?, drawing_file = ?
            WHERE id = ? AND application_id = ?
        ")->execute([$fio, $age, $drawingFile, $participantId, $applicationId]);
        $pdo->prepare("UPDATE works SET updated_at = NOW() WHERE id = ? AND application_id = ?")
            ->execute([$workId, $applicationId]);
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

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($newDrawingPath && file_exists($newDrawingPath)) {
            @unlink($newDrawingPath);
        }
        if ($newThumbPath && file_exists($newThumbPath)) {
            @unlink($newThumbPath);
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
    if ($newThumbPath && $oldThumbPath && file_exists($oldThumbPath) && realpath($oldThumbPath) !== realpath($newThumbPath)) {
        @unlink($oldThumbPath);
    }

    $updatedDrawingUrl = $drawingFile !== '' ? getParticipantDrawingWebPath($user['email'] ?? '', $drawingFile) : null;
    $updatedDrawingPreviewUrl = $drawingFile !== '' ? getParticipantDrawingPreviewWebPath($user['email'] ?? '', $drawingFile) : null;
    if ($updatedDrawingUrl) {
        $updatedDrawingUrl .= '?v=' . time();
    }
    if ($updatedDrawingPreviewUrl) {
        $updatedDrawingPreviewUrl .= '?v=' . time();
    }

    $successMessage = 'Изменения сохранены.';

    if ($isAjaxRequest) {
        jsonResponse([
            'success' => true,
            'message' => $successMessage,
            'participant' => [
                'participant_id' => $participantId,
                'work_id' => $workId,
                'fio' => $fio,
                'age' => $age > 0 ? $age : '—',
                'drawing_url' => $updatedDrawingUrl,
                'drawing_preview_url' => $updatedDrawingPreviewUrl,
            ],
            'remaining_corrections' => $remainingCorrections,
        ]);
    }

    $_SESSION['success_message'] = $successMessage;
    redirect('/application/' . $applicationId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'resubmit_corrected_application') {
    $isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (string) ($_POST['ajax'] ?? '') === '1';

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Ошибка безопасности. Обновите страницу.';
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => $message], 403);
        }
        $_SESSION['error_message'] = $message;
        redirect('/application/' . $applicationId);
    }

    $remainingCorrectionsStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM application_corrections
        WHERE application_id = ? AND is_resolved = 0
    ");
    $remainingCorrectionsStmt->execute([$applicationId]);
    $remainingCorrections = (int) $remainingCorrectionsStmt->fetchColumn();

    if ($remainingCorrections > 0) {
        $message = 'Сначала исправьте всех участников из списка корректировок.';
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => $message], 422);
        }
        $_SESSION['error_message'] = $message;
        redirect('/application/' . $applicationId);
    }

    if ((int) ($application['allow_edit'] ?? 0) !== 1 || in_array((string) ($application['status'] ?? ''), ['approved', 'rejected', 'cancelled'], true)) {
        $message = 'Повторная отправка сейчас недоступна.';
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => $message], 422);
        }
        $_SESSION['error_message'] = $message;
        redirect('/application/' . $applicationId);
    }

    $pdo->prepare("
        UPDATE applications
        SET status = 'corrected', allow_edit = 0, updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ")->execute([$applicationId, $userId]);

    $message = 'Заявка исправлена и отправлена на повторную проверку.';
    if ($isAjaxRequest) {
        jsonResponse([
            'success' => true,
            'message' => $message,
            'application_status' => 'corrected',
        ]);
    }

    $_SESSION['success_message'] = $message;
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
$participantCorrectionCards = [];
foreach ($participants as $participantRow) {
    $participantId = (int) ($participantRow['participant_id'] ?? 0);
    if ($participantId <= 0 || empty($participantCorrections[$participantId])) {
        continue;
    }

    $participantCorrectionCards[] = [
        'participant_id' => $participantId,
        'work_id' => (int) ($participantRow['id'] ?? 0),
        'fio' => trim((string) ($participantRow['fio'] ?? '')) ?: 'Участник без имени',
        'comments' => array_values(array_map(
            static function (array $correction): string {
                $field = trim((string) ($correction['field_name'] ?? ''));
                $comment = trim((string) ($correction['comment'] ?? ''));
                return trim($field . ($comment !== '' ? ': ' . $comment : ''));
            },
            $participantCorrections[$participantId]
        )),
    ];
}
$hasPendingParticipantCorrections = !empty($participantCorrectionCards);
$canResubmitCorrectedApplication = $effectiveApplicationStatus === 'revision' && $canEdit && !$hasPendingParticipantCorrections;

	$allPending = $workSummary['total'] > 0 && $workSummary['pending'] === $workSummary['total'];
	$participantsTotalCount = count($participants);
	$participantsDiplomaCount = $diplomasCount;
	$hasDiplomas = $participantsDiplomaCount > 0;
	$hasVkPublished = $workSummary['vk_published'] > 0;
	$diplomaLabels = diplomaTemplateTypes();
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
    'corrected' => ['class' => 'status-pill--corrected'],
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
$showWorkSummaryBadge = $statusCode !== 'draft' && (int) ($workSummary['total'] ?? 0) > 0;
$applicationProgressStep = match ($statusCode) {
    'draft' => 1,
    'approved' => 3,
    default => 2,
};
$applicationProgressLabels = $statusCode === 'draft'
    ? ['Черновик', 'Отправка', 'Проверка']
    : ['Подана', 'Проверка', 'Принята'];
$applicationDateCaption = $statusCode === 'draft' ? 'Создана' : 'Подана';
$userFullName = trim((string) (($user['surname'] ?? '') . ' ' . ($user['name'] ?? '') . ' ' . ($user['patronymic'] ?? '')));
$firstParticipantRegion = '';
foreach ($participants as $participantRow) {
    $candidateRegion = trim((string) ($participantRow['region'] ?? ''));
    if ($candidateRegion !== '') {
        $firstParticipantRegion = $candidateRegion;
        break;
    }
}
$userRegion = (string) ($user['organization_region'] ?? $application['organization_region'] ?? $firstParticipantRegion ?? '—');
$userOrganization = (string) ($user['organization_name'] ?? $application['organization_name'] ?? '');
$applicationDateLabel = date('d.m.Y H:i', strtotime((string) $application['created_at']));

$disputeChatMessages = [];
try {
    foreach (getDisputeChatTitleVariants($applicationId) as $candidateTitle) {
        $stmt = $pdo->prepare("
        SELECT m.*, u.name, u.surname, u.patronymic, u.is_admin
        FROM messages m
        JOIN users u ON u.id = m.created_by
        WHERE m.user_id = ? AND m.application_id = ? AND m.title = ?
        ORDER BY m.created_at ASC
        ");
        $stmt->execute([$user['id'], $applicationId, $candidateTitle]);
        $disputeChatMessages = $stmt->fetchAll();
        if (!empty($disputeChatMessages)) {
            $disputeChatSubject = $candidateTitle;
            break;
        }
    }

    if (!empty($disputeChatMessages)) {
        // Помечаем ответы администратора прочитанными, если чат уже существует.
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
    }
} catch (Exception $e) {
    $disputeChatMessages = [];
}

$hasDisputeChat = !empty($disputeChatMessages);
$canStartDisputeChat = getApplicationCanonicalStatus($application) === 'rejected';

$currentPage = 'applications';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(sitePageTitle('Заявка #' . $applicationId), ENT_QUOTES, 'UTF-8') ?></title>
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
.status-pill--submitted {background:#DBEAFE; color:#1D4ED8; border:1px solid rgba(59,130,246,.2);}
.status-pill--revision {background:#DBEAFE; color:#1E40AF;}
.status-pill--corrected {background:#E0F2FE; color:#0C4A6E;}
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
.app-user-card {display:grid; gap:14px;}
.app-user-card__head {display:flex; gap:14px; align-items:center; padding:14px; border-radius:14px; background:linear-gradient(145deg,#f8fbff,#ffffff); border:1px solid #E2E8F0;}
.app-user-card__avatar {width:56px; height:56px; border-radius:18px; background:linear-gradient(145deg,#ECFDF3,#DCFCE7); display:flex; align-items:center; justify-content:center; color:#166534; font-size:22px; flex-shrink:0;}
.app-user-card__meta {display:grid; gap:4px; min-width:0;}
.app-user-card__name {font-size:18px; font-weight:800; line-height:1.2; color:#0F172A;}
.app-user-card__subtitle {font-size:13px; color:#64748B;}
.app-user-card__grid {display:grid; grid-template-columns:1fr; gap:10px;}
.app-user-card__item {padding:12px 14px; border-radius:12px; border:1px solid #E2E8F0; background:#F8FAFC;}
.app-user-card__label {display:block; margin-bottom:4px; font-size:12px; font-weight:700; letter-spacing:.02em; text-transform:uppercase; color:#64748B;}
.app-user-card__value {font-size:14px; line-height:1.45; color:#0F172A; word-break:break-word;}
.app-sidebar {display:flex; flex-direction:column; gap:16px;}
.app-sidebar__panel {display:flex; flex-direction:column; gap:16px;}
.participants-grid {display:grid; grid-template-columns:1fr; gap:16px;}
.participant-modern-card {overflow:hidden; padding:0; transition:transform .2s ease, box-shadow .2s ease;}
.participant-modern-card:hover {transform:translateY(-2px); box-shadow:0 12px 32px rgba(15,23,42,.12);}
.participant-modern-card__image-wrap {display:flex; align-items:center; justify-content:center; padding:16px; background:#F1F5F9;}
.participant-modern-card__image {display:block; width:100%; max-height:min(72vh,560px); min-height:220px; height:auto; object-fit:contain; cursor:zoom-in;}
.participant-modern-card__body {padding:16px; display:grid; gap:10px;}
.participant-modern-card__header {display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;}
.participant-modern-card__name {margin:0; font-size:20px; line-height:1.2;}
.participant-modern-card__subtitle {color:#475569; font-size:14px;}
.participant-modern-card__facts {display:grid; grid-template-columns:1fr; gap:6px; color:#334155; font-size:14px;}
.participant-modern-card__actions {display:flex; gap:8px; flex-wrap:wrap; margin-top:4px; align-items: center;}
.app-highlight {background:#FEF9C3; border:1px solid #FDE68A; color:#92400E; border-radius:12px; padding:12px;}
.app-highlight--danger-soft {background:#FEEDEE; border-color:#FCA5A5; color:#991B1B;}
.app-empty {text-align:center; padding:30px 16px; color:#64748B;}
.app-skeleton {display:grid; gap:12px;}
.app-skeleton__item {height:96px; border-radius:14px; background:linear-gradient(90deg,#EDF2F7 25%,#E2E8F0 37%,#EDF2F7 63%); background-size:400% 100%; animation:appShimmer 1.2s ease infinite;}
.app-actions {display:flex; flex-wrap:wrap; gap:10px; align-items:flex-start;}
.app-actions__primary {padding:14px 20px; font-size:15px; font-weight:800; box-shadow:0 14px 28px rgba(76,175,80,.18);}
.app-actions__primary:hover:not(:disabled) {box-shadow:0 18px 34px rgba(76,175,80,.24);}
.app-review-card {display:grid; gap:14px;}
.app-review-card__lead {margin:0; color:#475569; font-size:14px; line-height:1.45;}
.app-review-list {display:grid; gap:10px;}
.app-review-item {display:grid; gap:8px; width:100%; padding:14px 16px; border:1px solid #DBEAFE; border-radius:14px; background:linear-gradient(145deg,#f8fbff,#ffffff); text-align:left; cursor:pointer; transition:transform .2s ease, border-color .2s ease, box-shadow .2s ease;}
.app-review-item:hover {transform:translateY(-1px); border-color:#93C5FD; box-shadow:0 14px 28px rgba(37,99,235,.08);}
.app-review-item__head {display:flex; align-items:center; justify-content:space-between; gap:12px;}
.app-review-item__name {font-size:15px; font-weight:800; color:#0F172A;}
.app-review-item__hint {font-size:12px; font-weight:700; color:#2563EB;}
.app-review-item__state {display:inline-flex; align-items:center; justify-content:center; padding:5px 10px; border-radius:999px; font-size:11px; font-weight:800; letter-spacing:.02em; text-transform:uppercase; background:#FEF3C7; color:#92400E;}
.app-review-item--fixed {border-color:#BBF7D0; background:linear-gradient(145deg,#f0fdf4,#ffffff);}
.app-review-item--fixed .app-review-item__state {background:#DCFCE7; color:#166534;}
.app-review-item__comments {display:grid; gap:6px;}
.app-review-item__comment {font-size:13px; line-height:1.45; color:#475569;}
.app-review-empty {padding:14px 16px; border-radius:14px; border:1px dashed #BFDBFE; background:#EFF6FF; color:#1E3A8A; font-size:14px; line-height:1.5;}
.app-review-empty[hidden] {display:none;}
.app-actions-card {display:grid; gap:12px;}
.app-actions-card__note {color:#64748B; font-size:14px; line-height:1.45;}
.app-actions-card__cta {width:100%;}
.app-responsibility-card {padding:14px 16px; border-radius:14px; border:1px solid #FDE68A; background:linear-gradient(145deg,#fffdf5,#ffffff); color:#92400E; display:grid; gap:8px;}
.app-responsibility-card__title {display:flex; align-items:center; gap:8px; font-size:14px; font-weight:800; color:#92400E;}
.app-responsibility-card__text {margin:0; font-size:13px; line-height:1.5; color:#7C5A10;}
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
 .participant-modern-card__image {max-height:560px;}
 .participant-edit-drawing-row {grid-template-columns:1fr 1fr; align-items:start;}
 .app-user-card__grid {grid-template-columns:repeat(2,minmax(0,1fr));}
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
                <span><i class="fas fa-calendar"></i> <?= e($applicationDateCaption) ?>: <?= $applicationDateLabel ?></span>
                <span>ID заявки: #<?= (int) $applicationId ?></span>
            </div>
        </div>
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <span class="status-pill <?= e($statusDisplay['class']) ?>"><?= e($statusDisplay['label']) ?></span>
            <?php if ($showWorkSummaryBadge): ?>
                <span class="badge <?= e($uiStatusMeta['badge_class']) ?>"><?= e($uiStatusMeta['label']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="application-progress" aria-label="Прогресс заявки">
        <div class="application-progress__step <?= $applicationProgressStep >= 1 ? 'is-active' : '' ?>"><span class="application-progress__dot">1</span><?= e($applicationProgressLabels[0]) ?></div>
        <div class="application-progress__step <?= $applicationProgressStep >= 2 ? 'is-active' : '' ?>"><span class="application-progress__dot">2</span><?= e($applicationProgressLabels[1]) ?></div>
        <div class="application-progress__step <?= $applicationProgressStep >= 3 ? 'is-active' : '' ?>"><span class="application-progress__dot">3</span><?= e($applicationProgressLabels[2]) ?></div>
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
	                            $workId = (int) ($participant['id'] ?? 0);
	                            $workDiploma = $workId > 0 ? ($workDiplomasByWorkId[$workId] ?? null) : null;
	                            $isDiplomaAvailable = $workDiploma !== null;
	                            $diplomaLabel = $isDiplomaAvailable
	                                ? (string) ($diplomaLabels[(string) ($workDiploma['diploma_type'] ?? '')] ?? ($workDiploma['diploma_type'] ?? ''))
	                                : '';
	                            $participantVkUrl = trim((string)($participant['vk_post_url'] ?? ''));
	                            $drawingSrc = !empty($participant['drawing_file']) ? getParticipantDrawingWebPath($user['email'] ?? '', $participant['drawing_file']) : '';
	                            $drawingPreviewSrc = !empty($participant['drawing_file']) ? getParticipantDrawingPreviewWebPath($user['email'] ?? '', $participant['drawing_file']) : '';
                            $participantGalleryIndex = null;
                            if ($drawingSrc !== '') {
                                $participantGalleryIndex = $galleryDisplayIndex++;
                            }
                        ?>
                        <article class="app-card participant-modern-card<?= $hasParticipantCorrection ? ' participant-card--needs-fix' : '' ?>" id="participant-card-<?= (int) ($participant['participant_id'] ?? 0) ?>" data-work-id="<?= (int)($participant['id'] ?? 0) ?>" data-participant-id="<?= (int) ($participant['participant_id'] ?? 0) ?>">
                            <div class="participant-modern-card__image-wrap">
                                <?php if ($drawingSrc !== ''): ?>
                                    <img src="<?= e($drawingPreviewSrc) ?>" alt="Рисунок участника <?= e((string)($participant['fio'] ?? '')) ?>" class="participant-modern-card__image js-gallery-image" data-gallery-index="<?= (int) $participantGalleryIndex ?>">
                                <?php else: ?>
                                    <div class="participant-modern-card__image" style="display:flex;align-items:center;justify-content:center;color:#94A3B8;background:#F1F5F9;"><i class="fas fa-image"></i></div>
                                <?php endif; ?>
                            </div>
                            <div class="participant-modern-card__body">
                                <div class="participant-modern-card__header">
                                    <div>
                                        <h3 class="participant-modern-card__name"><?= e((string)($participant['fio'] ?? 'Без имени')) ?></h3>
                                        <div class="participant-modern-card__subtitle js-participant-work-subtitle">Работа #<?= (int) ($index + 1) ?></div>
                                    </div>
                                    <?php if ($effectiveApplicationStatus !== 'draft'): ?>
                                        <span class="badge <?= getWorkStatusBadgeClass($workStatus) ?>"><?= e(getWorkStatusLabel($workStatus)) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="participant-modern-card__subtitle"><?= e(getWorkStatusHint($workStatus)) ?></div>
	                                <div class="participant-modern-card__facts">
	                                    <div><strong>Возраст:</strong> <span class="js-participant-age"><?= e((string)($participant['age'] ?? '—')) ?></span></div>
	                                    <div><strong>Регион:</strong> <?= e((string)($participant['region'] ?? '—')) ?></div>
	                                    <div><strong>Организация:</strong> <?= e((string)($participant['organization_name'] ?? '—')) ?></div>
	                                    <div><strong>Номер участника:</strong> #<?= e(getParticipantDisplayNumber($participant)) ?></div>
	                                    <?php if ($isDiplomaAvailable && $diplomaLabel !== ''): ?>
	                                        <div><strong>Диплом:</strong> <?= e($diplomaLabel) ?></div>
	                                    <?php endif; ?>
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
                                        <button type="button" class="btn btn--primary btn--sm js-open-participant-edit" data-work-id="<?= (int)($participant['id'] ?? 0) ?>" data-fio="<?= htmlspecialchars((string)($participant['fio'] ?? ''), ENT_QUOTES) ?>" data-age="<?= (int)($participant['age'] ?? 0) ?>" data-drawing-url="<?= htmlspecialchars($drawingSrc, ENT_QUOTES) ?>" data-drawing-preview-url="<?= htmlspecialchars($drawingPreviewSrc, ENT_QUOTES) ?>"><i class="fas fa-pen"></i> Исправить</button>
                                    <?php endif; ?>
	                                    <?php if ($isDiplomaAvailable): ?>
	                                        <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="diploma_download_one"><input type="hidden" name="work_id" value="<?= (int) ($participant['id'] ?? 0) ?>"><button class="btn btn--primary btn--sm" type="submit"><i class="fas fa-download"></i> Скачать диплом</button></form>
	                                    <?php endif; ?>
                                    <?php if ($participantVkUrl !== ''): ?>
                                        <div class="participant-vk-card">
                                            <div class="participant-vk-card__label"><i class="fab fa-vk"></i> Публикация</div>
                                            <div class="participant-vk-card__actions">
                                                <a class="btn btn--secondary btn--sm" href="<?= e($participantVkUrl) ?>" target="_blank" rel="noopener">Перейти</a>
                                                <button type="button" class="btn btn--ghost btn--sm js-copy-vk-link" data-vk-url="<?= e($participantVkUrl) ?>">Скопировать ссылку</button>
                                            </div>
                                            <div class="participant-vk-card__copied" aria-live="polite" hidden>Ссылка скопирована</div>
                                        </div>
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
        <div class="app-sidebar__panel">
        <?php if (!empty($unresolvedCorrections) || $application['status'] === 'revision'): ?>
        <section class="app-card app-review-card" id="applicationCorrectionCard">
            <h2 class="app-card__title" style="font-size:18px;" id="applicationCorrectionTitle">Статус и комментарии</h2>
            <p class="app-review-card__lead" id="applicationCorrectionLead">
                <?= $hasPendingParticipantCorrections
                    ? 'Выберите участника из списка, чтобы сразу перейти к нужной карточке и внести исправления.'
                    : 'Все отмеченные участники уже исправлены. Можно отправить заявку на повторную проверку.' ?>
            </p>
            <div class="app-review-list" id="applicationCorrectionList"<?= $hasPendingParticipantCorrections ? '' : ' hidden' ?>>
                <?php foreach ($participantCorrectionCards as $correctionCard): ?>
                    <button type="button" class="app-review-item" data-correction-participant-id="<?= (int) $correctionCard['participant_id'] ?>" data-correction-status="pending">
                        <span class="app-review-item__head">
                            <span class="app-review-item__name"><?= e($correctionCard['fio']) ?></span>
                            <span class="app-review-item__hint">Перейти к участнику</span>
                        </span>
                        <span class="app-review-item__comments">
                            <?php foreach ($correctionCard['comments'] as $commentText): ?>
                                <span class="app-review-item__comment">• <?= e($commentText) ?></span>
                            <?php endforeach; ?>
                        </span>
                        <span class="app-review-item__state">Нужно исправить</span>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="app-review-empty" id="applicationCorrectionEmpty"<?= $hasPendingParticipantCorrections ? ' hidden' : '' ?>>
                Список корректировок пуст. Всё готово для повторной отправки заявки на проверку.
            </div>
        </section>
        <?php endif; ?>

        <section class="app-card app-actions-card" id="applicationActionsCard">
            <div class="app-actions" id="applicationActionsContent">
                <?php if ($effectiveApplicationStatus === 'draft'): ?>
                    <?php if ($canEdit): ?>
                        <a href="/application-form?contest_id=<?= $application['contest_id'] ?>&edit=<?= $applicationId ?>" class="btn btn--primary app-actions__primary"><i class="fas fa-pen"></i> Продолжить заполнение</a>
                    <?php endif; ?>
                    <div class="app-highlight" style="width:100%;">
                        <strong>Заявка сохранена как черновик.</strong>
                        <div>Она ещё не отправлена на проверку. Проверьте данные и отправьте её после завершения заполнения.</div>
                    </div>
                <?php elseif ($effectiveApplicationStatus === 'revision' && $canEdit): ?>
                    <?php if ($canResubmitCorrectedApplication): ?>
                        <form id="resubmitApplicationForm" method="POST" style="width:100%;">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                            <input type="hidden" name="action" value="resubmit_corrected_application">
                            <input type="hidden" name="ajax" value="1">
                            <button type="submit" class="btn btn--primary app-actions__primary app-actions-card__cta" id="resubmitApplicationButton">
                                <i class="fas fa-paper-plane"></i> Отправить заявку на повторную проверку
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="app-actions-card__note" id="resubmitApplicationHint">Сначала исправьте всех участников из списка корректировок. После этого здесь появится кнопка повторной отправки.</div>
                    <?php endif; ?>
                    <div class="app-highlight app-highlight--danger-soft" style="width:100%;">
                        <strong><i class="fas fa-triangle-exclamation"></i> Пользовательское соглашение не подписано.</strong>
                        <div>Перейдите в раздел с пользовательским соглашением и дайте согласие, либо внесите изменения, которые не противоречат условиям конкурса, после чего снова нажмите «Подписать».</div>
                    </div>
	                <?php elseif ($hasDiplomas): ?>
	                    <div class="app-highlight" style="width:100%;">
	                        <strong>Дипломы</strong>
	                        <div>Участников: <?= (int) $participantsTotalCount ?>, дипломы сформированы: <?= (int) $participantsDiplomaCount ?></div>
	                    </div>
	                    <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="diploma_download_all"><button class="btn btn--primary" type="submit"><i class="fas fa-award"></i> Скачать все дипломы</button></form>
	                    <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="diploma_email_all"><button class="btn btn--secondary" type="submit"><i class="fas fa-envelope"></i> Отправить дипломы себе на почту</button></form>
                <?php elseif (getApplicationCanonicalStatus($application) === 'rejected'): ?>
                    <div class="app-highlight" style="width:100%;">
                        <strong>Заявка отклонена.</strong>
                        <div>Причина доступна в комментариях администратора и чате оспаривания.</div>
                    </div>
                <?php else: ?>
                    <div style="color:#64748B;"><?= $effectiveApplicationStatus === 'corrected' ? 'Заявка повторно отправлена на проверку после исправлений.' : 'Заявка находится на рассмотрении.' ?></div>
                <?php endif; ?>
                <?php if ($hasCuratorChat): ?>
                    <a href="/messages?chat_application_id=<?= (int) $applicationId ?>&chat_title=<?= urlencode($curatorChatTitle) ?>" class="btn btn--primary">
                        <i class="fas fa-comments"></i> Открыть чат с куратором
                    </a>
                <?php endif; ?>
                <a href="/my-applications" class="btn btn--secondary"><i class="fas fa-arrow-left"></i> К списку заяввок</a>
            </div>
        </section>
        <?php if ($effectiveApplicationStatus === 'revision' && $canEdit): ?>
        <section class="app-responsibility-card" id="applicationResponsibilityCard" aria-label="Ответственность за исправления">
            <div class="app-responsibility-card__title">
                <i class="fas fa-circle-exclamation"></i>
                <span>Ответственность за исправления</span>
            </div>
            <p class="app-responsibility-card__text">
                Пользователь самостоятельно несёт ответственность за все внесённые изменения перед повторной отправкой заявки на проверку.
            </p>
        </section>
        <?php endif; ?>
        </div>
    </aside>
</div>

<?php if ($canStartDisputeChat || $hasDisputeChat): ?>
<div class="card mb-lg" id="dispute-chat">
    <div class="card__header flex justify-between items-center">
        <h3>Оспаривание решения по заявке</h3>
        <button type="button" class="btn btn--ghost btn--sm" onclick="openDisputeChatModal()">
            <i class="fas fa-comments"></i> Открыть чат
        </button>
    </div>
    <div class="card__body">
        <p class="text-secondary" style="margin:0;">
            <?= $canStartDisputeChat ? 'Просматривайте переписку с администратором и отправляйте ответы во всплывающем окне чата.' : 'Чат сохранён для просмотра истории переписки по заявке.' ?>
        </p>
        <div style="margin-top:12px;">
            <span class="badge <?= $isDisputeChatClosed ? 'badge--secondary' : 'badge--success' ?>">
                <?= $isDisputeChatClosed ? 'Чат закрыт' : 'Чат открыт' ?>
            </span>
        </div>
        <?php if ($isDisputeChatClosed): ?>
            <div class="alert alert--warning" style="margin-top:12px;">
                <i class="fas fa-lock"></i> Чат завершён администратором. Доступен только просмотр сообщений.
            </div>
        <?php elseif (!$canStartDisputeChat): ?>
            <div class="alert alert--secondary" style="margin-top:12px;">
                <i class="fas fa-info-circle"></i> Статус заявки изменился. История чата сохранена, но отправка новых сообщений недоступна.
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
                        <?php
                            $isAdminMessage = (int) ($chatMessage['is_admin'] ?? 0) === 1;
                            $attachmentFile = (string) ($chatMessage['attachment_file'] ?? '');
                            $attachmentUrl = $attachmentFile !== '' ? buildMessageAttachmentPublicUrl($attachmentFile) : '';
                            $attachmentName = (string) ($chatMessage['attachment_original_name'] ?? basename($attachmentFile));
                            $attachmentIsImage = $attachmentUrl !== '' && isImageMessageAttachment((string) ($chatMessage['attachment_mime_type'] ?? ''), $attachmentName);
                        ?>
                        <div class="dispute-chat-message <?= $isAdminMessage ? 'dispute-chat-message--user' : 'dispute-chat-message--admin' ?>" data-message-id="<?= (int) $chatMessage['id'] ?>">
                            <div class="dispute-chat-message__bubble">
                                <div class="dispute-chat-message__meta">
                                    <?php if ($isAdminMessage): ?>
                                        <?php $chatAuthorName = trim(($chatMessage['surname'] ?? '') . ' ' . ($chatMessage['name'] ?? '') . ' ' . ($chatMessage['patronymic'] ?? '')); ?>
                                        <?= htmlspecialchars('Руководитель проекта — ' . trim($chatAuthorName)) ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars(trim(($user['surname'] ?? '') . ' ' . ($user['name'] ?? '') . ' ' . ($user['patronymic'] ?? ''))) ?>
                                    <?php endif; ?>
                                    <span>• <?= date('d.m.Y H:i', strtotime($chatMessage['created_at'])) ?></span>
                                </div>
                                <div class="dispute-chat-message__text"><?= htmlspecialchars($chatMessage['content']) ?></div>
                                <?php if ($attachmentUrl !== ''): ?>
                                    <div class="message-attachment" style="margin-top:10px;">
                                        <?php if ($attachmentIsImage): ?>
                                            <button type="button" class="message-attachment__image-button" onclick="openMessageImageModal('<?= rawurlencode($attachmentUrl) ?>','<?= rawurlencode($attachmentName) ?>')">
                                                <img src="<?= htmlspecialchars($attachmentUrl) ?>" alt="<?= htmlspecialchars($attachmentName) ?>" class="message-attachment__thumb">
                                                <span class="message-attachment__caption"><i class="fas fa-search-plus"></i> Посмотреть изображение</span>
                                            </button>
                                        <?php else: ?>
                                            <a href="<?= htmlspecialchars($attachmentUrl) ?>" class="message-attachment__file" target="_blank" rel="noopener" download="<?= htmlspecialchars($attachmentName) ?>">
                                                <i class="fas fa-download"></i>
                                                <span><?= htmlspecialchars($attachmentName) ?></span>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-secondary">Сообщений пока нет.</p>
                <?php endif; ?>
            </div>

            <?php if (!$isDisputeChatClosed && $canStartDisputeChat): ?>
                <form method="POST" class="dispute-chat-modal__composer" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="dispute_reply">
                    <input type="file" id="applicationDisputeChatAttachment" name="attachment" class="chat-composer__attachment-input js-message-attachment-input" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.doc,.docx,.rtf,.xls,.xlsx,.csv,.zip,image/*,application/pdf,text/plain,text/csv">
                    <div class="message-attachment-preview chat-composer__attachment-preview js-message-attachment-preview" hidden></div>
                    <div class="form-group">
                        <label class="form-label">Ваш ответ в чате</label>
                        <textarea name="dispute_reason" class="form-textarea js-chat-hotkey" rows="4" placeholder="Напишите сообщение администратору..."></textarea>
                    </div>
                    <div class="chat-composer__actions">
                        <label class="chat-composer__attachment-trigger" for="applicationDisputeChatAttachment" title="Прикрепить файл">
                            <i class="fas fa-paperclip"></i>
                            <span>Файл</span>
                        </label>
                        <div class="chat-composer__attachment-help">Изображение покажем миниатюрой, для остальных файлов сохраним название. До 10 МБ.</div>
                        <button type="submit" class="btn btn--primary">
                            <i class="fas fa-paper-plane"></i> Отправить
                        </button>
                    </div>
                </form>
            <?php elseif ($isDisputeChatClosed): ?>
                <div class="alert alert--warning" style="margin-top:12px;">
                    <i class="fas fa-lock"></i> Чат завершён администратором. Можно только просматривать сообщения.
                </div>
            <?php else: ?>
                <div class="alert alert--secondary" style="margin-top:12px;">
                    <i class="fas fa-info-circle"></i> Отправка новых сообщений недоступна, но история чата сохранена.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
</div>
</main>

<div class="modal" id="messageImageModal">
<div class="modal__content" style="max-width:min(1100px,96vw); width:96vw;">
<div class="modal__header">
<h3 id="messageImageModalTitle">Просмотр изображения</h3>
<button type="button" class="modal__close" onclick="closeMessageImageModal()">&times;</button>
</div>
<div class="modal__body" style="display:flex; justify-content:center; align-items:center; max-height:80vh;">
<img id="messageImageModalImage" src="" alt="" style="display:block; max-width:100%; max-height:70vh; border-radius:16px; object-fit:contain;">
</div>
</div>
</div>

<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
<?php include dirname(__DIR__) . '/partials/frontend-chat-helpers.php'; ?>
<?php
$galleryImages = [];
foreach ($participants as $participant) {
    if (!empty($participant['drawing_file'])) {
        $galleryImages[] = [
            'src' => getParticipantDrawingWebPath($user['email'] ?? '', $participant['drawing_file']),
            'preview' => getParticipantDrawingPreviewWebPath($user['email'] ?? '', $participant['drawing_file']),
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
  thumb.src = item.preview || item.src;
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

async function copyTextToClipboard(text) {
 if (navigator.clipboard?.writeText) {
  await navigator.clipboard.writeText(text);
  return;
 }

 const tempInput = document.createElement('textarea');
 tempInput.value = text;
 tempInput.setAttribute('readonly', 'readonly');
 tempInput.style.position = 'absolute';
 tempInput.style.left = '-9999px';
 document.body.appendChild(tempInput);
 tempInput.select();
 document.execCommand('copy');
 tempInput.remove();
}

document.querySelectorAll('.js-copy-vk-link').forEach((button) => {
 let copiedTimer = null;
 button.addEventListener('click', async () => {
  const url = String(button.dataset.vkUrl || '').trim();
  if (!url) return;

  try {
   await copyTextToClipboard(url);
   const card = button.closest('.participant-vk-card');
   const copiedNote = card?.querySelector('.participant-vk-card__copied');
   if (!copiedNote) return;
   copiedNote.hidden = false;
   if (copiedTimer) {
    clearTimeout(copiedTimer);
   }
   copiedTimer = setTimeout(() => {
    copiedNote.hidden = true;
   }, 1800);
  } catch (error) {
   // no-op: keep the UI quiet if copying is blocked
  }
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

function scrollToParticipantCard(participantId) {
 const numericParticipantId = Number(participantId || 0);
 if (!numericParticipantId) return;
 const card = document.getElementById(`participant-card-${numericParticipantId}`);
 if (!card) return;
 card.scrollIntoView({ behavior: 'smooth', block: 'center' });
 window.setTimeout(() => {
  card.classList.add('participant-card--needs-fix');
  window.setTimeout(() => card.classList.remove('participant-card--needs-fix'), 1600);
 }, 120);
}

function updateCorrectionProgressUi() {
 const correctionList = document.getElementById('applicationCorrectionList');
 const correctionEmpty = document.getElementById('applicationCorrectionEmpty');
 const correctionLead = document.getElementById('applicationCorrectionLead');
 const resubmitHint = document.getElementById('resubmitApplicationHint');
 const items = [...(correctionList?.querySelectorAll('[data-correction-participant-id]') || [])];
 const allFixed = items.length > 0 && items.every((item) => item.dataset.correctionStatus === 'fixed');

 if (items.length === 0) {
  correctionList?.setAttribute('hidden', 'hidden');
  correctionEmpty?.removeAttribute('hidden');
  if (correctionLead) {
   correctionLead.textContent = 'Список корректировок пуст. Всё готово для повторной отправки заявки на проверку.';
  }
  return;
 }

 correctionList?.removeAttribute('hidden');
 correctionEmpty?.setAttribute('hidden', 'hidden');
 if (correctionLead) {
  correctionLead.textContent = allFixed
   ? 'Все участники отмечены как исправленные. Теперь можно отправить заявку на повторную проверку.'
   : 'Выберите участника из списка, чтобы сразу перейти к нужной карточке и внести исправления.';
 }

 if (allFixed && resubmitHint) {
  resubmitHint.outerHTML = `
   <form id="resubmitApplicationForm" method="POST" style="width:100%;">
     <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
     <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
     <input type="hidden" name="action" value="resubmit_corrected_application">
     <input type="hidden" name="ajax" value="1">
     <button type="submit" class="btn btn--primary app-actions__primary app-actions-card__cta" id="resubmitApplicationButton">
       <i class="fas fa-paper-plane"></i> Отправить заявку на повторную проверку
     </button>
   </form>
  `;
  bindResubmitApplicationForm();
 }
}

function markParticipantCorrectionAsFixed(participantId) {
 const numericParticipantId = Number(participantId || 0);
 if (!numericParticipantId) return;

 const correctionButton = document.querySelector(`[data-correction-participant-id="${numericParticipantId}"]`);
 if (!correctionButton) return;

 correctionButton.dataset.correctionStatus = 'fixed';
 correctionButton.classList.add('app-review-item--fixed');
 const state = correctionButton.querySelector('.app-review-item__state');
 if (state) {
  state.textContent = 'Исправлено';
 }

 updateCorrectionProgressUi();
}

function updateParticipantCardAfterSave(participant) {
 if (!participant || !participant.work_id) return;
 const card = document.querySelector(`.participant-modern-card[data-work-id="${participant.work_id}"]`);
 if (!card) return;

 const fio = (participant.fio || '').trim();
 const age = participant.age ?? '—';

 const nameNode = card.querySelector('.participant-modern-card__name');
 if (nameNode && fio !== '') {
  nameNode.textContent = fio;
 }

 const ageNode = card.querySelector('.js-participant-age');
 if (ageNode) {
  ageNode.textContent = String(age);
 }

 const editButton = card.querySelector('.js-open-participant-edit');
 if (editButton) {
  editButton.dataset.fio = fio;
  editButton.dataset.age = String(age);
  if (participant.drawing_url) {
   editButton.dataset.drawingUrl = participant.drawing_url;
  }
  if (participant.drawing_preview_url) {
   editButton.dataset.drawingPreviewUrl = participant.drawing_preview_url;
  }
 }

 if (participant.drawing_url) {
  const imageNode = card.querySelector('.participant-modern-card__image');
  if (imageNode) {
   imageNode.src = participant.drawing_preview_url || participant.drawing_url;
   if (participant.drawing_url) {
    imageNode.dataset.fullSrc = participant.drawing_url;
   }
  }
 }

 card.classList.remove('participant-card--needs-fix');
 card.querySelectorAll('.app-highlight').forEach((node) => node.remove());
}

function applyApplicationResubmittedState() {
 document.querySelectorAll('.participant-card--needs-fix').forEach((card) => {
  card.classList.remove('participant-card--needs-fix');
  card.querySelectorAll('.app-highlight').forEach((node) => node.remove());
 });

 document.querySelectorAll('.js-open-participant-edit').forEach((button) => button.remove());

 const statusPill = document.querySelector('.status-pill');
 if (statusPill) {
  statusPill.className = 'status-pill status-pill--corrected';
  statusPill.textContent = 'Исправлена, и отправлена на проверку';
 }

 const correctionLead = document.getElementById('applicationCorrectionLead');
 if (correctionLead) {
  correctionLead.textContent = 'Заявка повторно отправлена на проверку. Ожидайте решение организатора.';
 }

 const correctionEmpty = document.getElementById('applicationCorrectionEmpty');
 if (correctionEmpty) {
  correctionEmpty.textContent = 'Все исправления отправлены. Заявка ожидает повторную проверку.';
  correctionEmpty.removeAttribute('hidden');
 }

 document.getElementById('applicationCorrectionList')?.setAttribute('hidden', 'hidden');
 document.getElementById('resubmitApplicationForm')?.remove();
 document.getElementById('resubmitApplicationHint')?.remove();
 document.getElementById('applicationResponsibilityCard')?.remove();
}

document.querySelectorAll('.js-open-participant-edit').forEach((button) => {
 button.addEventListener('click', () => openParticipantEditModal(button));
});

document.querySelectorAll('[data-correction-participant-id]').forEach((button) => {
 button.addEventListener('click', () => {
  scrollToParticipantCard(button.dataset.correctionParticipantId);
 });
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
   markParticipantCorrectionAsFixed(data.participant?.participant_id || 0);
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

function bindResubmitApplicationForm() {
 const form = document.getElementById('resubmitApplicationForm');
 if (!form || form.dataset.bound === '1') return;
 form.dataset.bound = '1';

 form.addEventListener('submit', async (event) => {
  event.preventDefault();
  const button = document.getElementById('resubmitApplicationButton');
  const defaultHtml = button ? button.innerHTML : '';
  if (button) {
   button.disabled = true;
   button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправляем...';
  }

  try {
   const response = await fetch(window.location.href, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: new FormData(form),
   });
   const data = await response.json();
   if (!response.ok || !data.success) {
    throw new Error(data.error || 'Не удалось отправить заявку на повторную проверку.');
   }

   applyApplicationResubmittedState();
   showToast(data.message || 'Заявка отправлена на повторную проверку', 'success');
  } catch (error) {
   showToast(error.message || 'Ошибка повторной отправки заявки', 'error');
   if (button) {
    button.disabled = false;
    button.innerHTML = defaultHtml;
   }
  }
 });
}

bindResubmitApplicationForm();
updateCorrectionProgressUi();

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

 if (messageData.attachment && messageData.attachment.url) {
  const attachmentWrap = document.createElement('div');
  attachmentWrap.className = 'message-attachment';
  attachmentWrap.style.marginTop = '10px';
  if (messageData.attachment.is_image) {
   attachmentWrap.innerHTML =
    `<button type="button" class="message-attachment__image-button" onclick="openMessageImageModal('${encodeURIComponent(messageData.attachment.url || '')}','${encodeURIComponent(messageData.attachment.name || 'Изображение')}')">` +
    `<img src="${escapeHtml(messageData.attachment.url || '')}" alt="${escapeHtml(messageData.attachment.name || 'Изображение')}" class="message-attachment__thumb">` +
    '<span class="message-attachment__caption"><i class="fas fa-search-plus"></i> Посмотреть изображение</span>' +
    '</button>';
  } else {
   attachmentWrap.innerHTML =
    `<a href="${escapeHtml(messageData.attachment.url || '#')}" class="message-attachment__file" target="_blank" rel="noopener" download="${escapeHtml(messageData.attachment.name || 'attachment')}">` +
    '<i class="fas fa-download"></i><span>' + escapeHtml(messageData.attachment.name || 'Файл') + '</span></a>';
  }
  bubble.appendChild(attachmentWrap);
 }

 messageWrap.appendChild(bubble);
 container.appendChild(messageWrap);
 container.scrollTop = container.scrollHeight;
 if (numericId > latestUserDisputeMessageId) {
  latestUserDisputeMessageId = numericId;
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
   if (typeof window.resetFrontendAttachmentPreview === 'function') {
    window.resetFrontendAttachmentPreview(disputeReplyForm);
   }
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
