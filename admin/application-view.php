<?php
// admin/application-view.php - Просмотр заявки
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

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

// Проверка авторизации админа
if (!isAdmin()) {
    redirect('/admin/login');
}

check_csrf();

$admin = getCurrentUser();
$application_id = $_GET['id'] ?? 0;

$buildApplicationsReturnUrl = static function (array $query): string {
    $allowedKeys = [
        'status',
        'contest_id',
        'search',
        'search_application_id',
        'search_user_id',
        'participant_id',
        'participant_query',
        'queue',
        'show_archived',
        'page',
    ];

    $filtered = [];
    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $query)) {
            continue;
        }

        $value = $query[$key];
        if ($value === null || $value === '') {
            continue;
        }

        $filtered[$key] = is_scalar($value) ? (string) $value : '';
    }

    $queryString = http_build_query($filtered);
    return '/admin/applications' . ($queryString !== '' ? ('?' . $queryString) : '');
};

$applicationsReturnUrl = $buildApplicationsReturnUrl($_GET);
$rawApplicationsReturnUrl = trim((string) ($_GET['return_url'] ?? ''));
if ($rawApplicationsReturnUrl !== '' && str_starts_with($rawApplicationsReturnUrl, '/admin/')) {
    $applicationsReturnUrl = $rawApplicationsReturnUrl;
}

// Получаем заявку
$stmt = $pdo->prepare("
 SELECT a.*, c.title as contest_title, c.requires_payment_receipt AS contest_requires_payment_receipt,
 u.name, u.surname, u.patronymic, u.avatar_url, u.email, u.vk_id,
 u.organization_region, u.organization_name, u.organization_address, u.user_type
 FROM applications a
 JOIN contests c ON a.contest_id = c.id
 JOIN users u ON a.user_id = u.id
 WHERE a.id = ?
");
$stmt->execute([$application_id]);
$application = $stmt->fetch();

if (!$application) {
    redirect($applicationsReturnUrl);
}

$applicantEmail = trim((string) ($application['email'] ?? ''));
$blacklistEntry = $applicantEmail !== '' ? mailingGetBlacklistEntry($applicantEmail) : null;
$isApplicantBlacklisted = $blacklistEntry !== null;
$applicationCurrentPageUrl = (string) ($_SERVER['REQUEST_URI'] ?? ('/admin/application/' . $application_id));

try {
    $hasOpenedByAdminColumn = (bool) $pdo->query("SHOW COLUMNS FROM applications LIKE 'opened_by_admin'")->fetch();
    if ($hasOpenedByAdminColumn) {
        $pdo->prepare("UPDATE applications SET opened_by_admin = 1 WHERE id = ?")->execute([(int) $application_id]);
    }
} catch (Exception $e) {
    // no-op: column can be missing on older installations
}

// Получаем участников
$stmt = $pdo->prepare("SELECT * FROM participants WHERE application_id = ?");
$stmt->execute([$application_id]);
$participants = $stmt->fetchAll();
	$works = getApplicationWorks((int)$application_id);
	$isApplicationApproved = (string) ($application['status'] ?? '') === 'approved';
	$isApplicationFinal = in_array((string) ($application['status'] ?? ''), ['approved', 'rejected', 'cancelled'], true);
	$isApplicationDecisionLocked = (string) ($application['status'] ?? '') === 'cancelled';
	$showVkPublishPrompt = max(0, (int) ($_SESSION['vk_publish_prompt_application_id'] ?? 0)) === (int) $application_id;
	unset($_SESSION['vk_publish_prompt_application_id']);
$participantColumns = $pdo->query("DESCRIBE participants")->fetchAll(PDO::FETCH_COLUMN);
$hasDrawingCompliantColumn = in_array('drawing_compliant', $participantColumns, true);
$hasDrawingCommentColumn = in_array('drawing_comment', $participantColumns, true);
$drawingCommentPresetsRaw = trim((string) getSystemSetting('drawing_comment_presets', ''));
if ($drawingCommentPresetsRaw === '') {
    $drawingCommentPresetsRaw = implode("\n", [
        'Пожалуйста, загрузите более качественное изображение рисунка без затемнений и бликов.',
        'Пожалуйста, проверьте соответствие работы теме конкурса и при необходимости замените рисунок.',
        'Пожалуйста, убедитесь, что на изображении нет посторонних элементов, подписей и рамок.',
    ]);
}
$drawingCommentPresets = array_values(array_filter(array_map(
    static fn($item) => trim((string) $item),
    preg_split('/\R/u', $drawingCommentPresetsRaw) ?: []
), static fn($item) => $item !== ''));
$hasNonCompliantDrawings = false;
$hasRevisionCandidate = false;
$allParticipantsDecidedForRevision = !empty($works);
if ($hasDrawingCompliantColumn) {
    foreach ($works as $workRow) {
        $isCompliantRow = (int)($workRow['drawing_compliant'] ?? 1) === 1;
        $isFinalDecision = in_array((string) ($workRow['status'] ?? 'pending'), ['accepted', 'reviewed_non_competitive'], true);
        if (!$isFinalDecision && $isCompliantRow) {
            $allParticipantsDecidedForRevision = false;
        }
        if (!$isCompliantRow) {
            $hasNonCompliantDrawings = true;
            $hasRevisionCandidate = true;
        }
    }
} else {
    $allParticipantsDecidedForRevision = false;
}
$revisionButtonDisabled = $isApplicationApproved || !$allParticipantsDecidedForRevision || !$hasRevisionCandidate;
$declineButtonDisabled = true;
if (!empty($works)) {
    $allWorksFinallyRejected = true;
    foreach ($works as $workRow) {
        $status = (string) ($workRow['status'] ?? 'pending');
        if ($status === 'accepted') {
            $allWorksFinallyRejected = false;
            break;
        }
        if ($status !== 'reviewed_non_competitive') {
            $allWorksFinallyRejected = false;
        }
    }
    $declineButtonDisabled = !$allWorksFinallyRejected;
}
if ($hasDrawingCompliantColumn) {
    foreach ($works as $workRow) {
        if ((int)($workRow['drawing_compliant'] ?? 1) === 0) {
            break;
        }
    }
}
$vkPublicationInfo = null;
$applicationVkStatus = getApplicationVkPublicationStatus((int) $application_id);
try {
    ensureVkPublicationSchema();
    $vkPublicationStmt = $pdo->prepare("
        SELECT
            i.item_status,
            i.vk_post_id,
            i.vk_post_url,
            i.error_message,
            i.published_at,
            i.vk_donut_enabled,
            i.vk_donut_paid_duration,
            i.vk_donut_can_publish_free_copy,
            i.donation_enabled,
            i.donation_goal_id,
            i.vk_donate_id,
            d.title AS donation_goal_title,
            t.id AS task_id,
            t.task_status,
            t.created_at AS task_created_at
        FROM vk_publication_task_items i
        INNER JOIN vk_publication_tasks t ON t.id = i.task_id
        LEFT JOIN vk_donates d ON d.id = i.donation_goal_id
        WHERE i.application_id = ?
        ORDER BY COALESCE(i.published_at, t.created_at) DESC, i.id DESC
        LIMIT 1
    ");
    $vkPublicationStmt->execute([(int) $application_id]);
    $vkPublicationInfo = $vkPublicationStmt->fetch() ?: null;
} catch (Throwable $e) {
    $vkPublicationInfo = null;
}

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

function findWorkParticipantId(array $works, int $workId): int {
    foreach ($works as $workRow) {
        if ((int) ($workRow['id'] ?? 0) === $workId) {
            return (int) ($workRow['participant_id'] ?? 0);
        }
    }
    return 0;
}

function findParticipantWorkId(array $works, int $participantId): int {
    foreach ($works as $workRow) {
        if ((int) ($workRow['participant_id'] ?? 0) === $participantId) {
            return (int) ($workRow['id'] ?? 0);
        }
    }
    return 0;
}

function resetApplicationDecisionStatusIfNeeded(int $applicationId, string $currentStatus): array {
    global $pdo;
    $normalizedStatus = normalizeApplicationStoredStatus($currentStatus);
    if (!in_array($normalizedStatus, ['approved', 'rejected'], true)) {
        return [
            'status' => $normalizedStatus,
            'was_reset' => false,
        ];
    }
    $pdo->prepare("UPDATE applications SET status = 'submitted', updated_at = NOW() WHERE id = ?")
        ->execute([$applicationId]);
    return [
        'status' => 'submitted',
        'was_reset' => true,
    ];
}

// Обработка изменения статуса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || (string) ($_POST['ajax'] ?? '') === '1';
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        if ($isAjaxRequest) {
            jsonResponse(['success' => false, 'error' => 'Ошибка безопасности'], 422);
        }
        $error = 'Ошибка безопасности';
    } elseif ($_POST['action'] === 'update_status') {
        $newStatus = $_POST['status'] ?? $application['status'];
        $stmt = $pdo->prepare("UPDATE applications SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $application_id]);
        $application['status'] = $newStatus;
        $_SESSION['success_message'] = 'Статус обновлён';
        redirect($applicationsReturnUrl);

    } elseif ($_POST['action'] === 'toggle_applicant_blacklist') {
        if ($applicantEmail === '') {
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => 'У заявителя не указан email.'], 422);
            }
            throw new RuntimeException('У заявителя не указан email.');
        }

        if ($isApplicantBlacklisted) {
            mailingRemoveEmailFromBlacklist($applicantEmail);
            $blacklistEntry = null;
            $isApplicantBlacklisted = false;
            $message = 'Email удалён из чёрного списка рассылки.';
        } else {
            $blacklistEntry = mailingAddEmailToBlacklist($applicantEmail, 'Добавлено из карточки заявки #' . (int) $application_id);
            $isApplicantBlacklisted = true;
            $message = 'Email добавлен в чёрный список рассылки.';
        }

        if ($isAjaxRequest) {
            jsonResponse([
                'success' => true,
                'message' => $message,
                'email' => (string) ($blacklistEntry['email'] ?? $applicantEmail),
                'is_blacklisted' => $isApplicantBlacklisted ? 1 : 0,
            ]);
        }

	        $_SESSION['success_message'] = $message;
	        redirect('/admin/application/' . $application_id . (parse_url($applicationsReturnUrl, PHP_URL_QUERY) ? ('?' . parse_url($applicationsReturnUrl, PHP_URL_QUERY)) : ''));

	    } elseif ($_POST['action'] === 'set_work_status') {
	        $workId = (int)($_POST['work_id'] ?? 0);
	        $participantId = (int)($_POST['participant_id'] ?? findWorkParticipantId($works, $workId));
	        $newStatus = (string)($_POST['work_status'] ?? 'pending');
        if ($workId <= 0 || !in_array($newStatus, ['pending', 'accepted', 'reviewed', 'reviewed_non_competitive'], true)) {
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => 'Некорректный статус работы'], 422);
            }
            $_SESSION['success_message'] = 'Некорректный статус работы';
            redirect('/admin/application/' . $application_id . (parse_url($applicationsReturnUrl, PHP_URL_QUERY) ? ('?' . parse_url($applicationsReturnUrl, PHP_URL_QUERY)) : ''));
        }
        $comment = trim((string) ($_POST['comment'] ?? ''));
        updateWorkStatus($workId, $newStatus);
        $applicationStatusReset = resetApplicationDecisionStatusIfNeeded((int) $application_id, (string) ($application['status'] ?? 'submitted'));
        $application['status'] = (string) ($applicationStatusReset['status'] ?? 'submitted');
        $isApplicationApproved = (string) ($application['status'] ?? '') === 'approved';
        $isApplicationFinal = in_array((string) ($application['status'] ?? ''), ['approved', 'rejected', 'cancelled'], true);
        $isApplicationDecisionLocked = (string) ($application['status'] ?? '') === 'cancelled';

        if ($participantId > 0 && $hasDrawingCompliantColumn) {
            if ($newStatus === 'accepted') {
                if ($hasDrawingCommentColumn) {
                    $pdo->prepare("
                        UPDATE participants
                        SET drawing_compliant = 1, drawing_comment = NULL
                        WHERE id = ? AND application_id = ?
                    ")->execute([$participantId, $application_id]);
                } else {
                    $pdo->prepare("
                        UPDATE participants
                        SET drawing_compliant = 1
                        WHERE id = ? AND application_id = ?
                    ")->execute([$participantId, $application_id]);
                }
            }
        }
        if ($isAjaxRequest) {
	            jsonResponse([
	                'success' => true,
	                'message' => 'Статус работы обновлён',
	                'work_status' => $newStatus,
	                'status_label' => getWorkStatusLabel($newStatus),
	                'status_class' => getWorkStatusBadgeClass($newStatus),
	                'drawing_compliant' => $newStatus === 'accepted' ? 1 : null,
	                'application_status' => (string) ($application['status'] ?? 'submitted'),
	                'application_status_reset' => (bool) ($applicationStatusReset['was_reset'] ?? false),
	            ]);
	        }
	        $_SESSION['success_message'] = 'Статус работы обновлён';
	        redirect('/admin/application/' . $application_id . (parse_url($applicationsReturnUrl, PHP_URL_QUERY) ? ('?' . parse_url($applicationsReturnUrl, PHP_URL_QUERY)) : ''));
    } elseif ($_POST['action'] === 'approve_application') {
        if ($hasDrawingCompliantColumn) {
            $unresolvedStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM works w
                WHERE w.application_id = ?
                  AND w.status NOT IN ('accepted', 'reviewed_non_competitive')
            ");
            $unresolvedStmt->execute([$application_id]);
            if ((int)$unresolvedStmt->fetchColumn() > 0) {
                $errorMessage = 'Нельзя принять заявку: не по всем рисункам принято решение.';
                if ($isAjaxRequest) {
                    jsonResponse(['success' => false, 'error' => $errorMessage], 422);
                }
                $_SESSION['error_message'] = $errorMessage;
                redirect('/admin/application/' . $application_id . (parse_url($applicationsReturnUrl, PHP_URL_QUERY) ? ('?' . parse_url($applicationsReturnUrl, PHP_URL_QUERY)) : ''));
            }
        }
        $stmt = $pdo->prepare("UPDATE applications SET status = 'approved', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$application_id]);
        $application['status'] = 'approved';
        $isApplicationApproved = true;
        $isApplicationFinal = true;

        $declinedSubject = getSystemSetting('application_declined_subject', 'Ваша заявка отклонена');
        $pdo->prepare("
            DELETE FROM admin_messages
            WHERE subject = ? AND message LIKE ?
        ")->execute([$declinedSubject, '%#' . $application_id . '%']);
        $pdo->prepare("
            DELETE FROM messages
            WHERE application_id = ? AND title = ?
        ")->execute([$application_id, buildDisputeChatTitle($application_id)]);
        $pdo->prepare("UPDATE application_corrections SET is_resolved = 1, resolved_at = NOW() WHERE application_id = ?")
            ->execute([$application_id]);
        disallowApplicationEdit($application_id);

        $acceptedWorksStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM works
            WHERE application_id = ?
              AND status = 'accepted'
        ");
        $acceptedWorksStmt->execute([$application_id]);
        $hasAcceptedWorksForVkPublish = (int) $acceptedWorksStmt->fetchColumn() > 0;

        if ($isAjaxRequest) {
            jsonResponse([
                'success' => true,
                'message' => 'Заявка принята',
                'open_vk_publish_prompt' => $hasAcceptedWorksForVkPublish,
            ]);
        }

        $_SESSION['success_message'] = 'Заявка принята';
        if ($hasAcceptedWorksForVkPublish) {
            $_SESSION['vk_publish_prompt_application_id'] = (int) $application_id;
        }
        redirect('/admin/application/' . $application_id . (parse_url($applicationsReturnUrl, PHP_URL_QUERY) ? ('?' . parse_url($applicationsReturnUrl, PHP_URL_QUERY)) : ''));
    } elseif ($_POST['action'] === 'cancel_application') {
        $stmt = $pdo->prepare("UPDATE applications SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$application_id]);
        $application['status'] = 'cancelled';
        $isApplicationFinal = true;

        $subject = getSystemSetting('application_cancelled_subject', 'Ваша заявка отменена');
        $message = getSystemSetting('application_cancelled_message', 'Ваша заявка отменена администратором.') . "\n\nНомер заявки: #" . $application_id;
        $stmt = $pdo->prepare("INSERT INTO admin_messages (user_id, admin_id, subject, message, priority, created_at) VALUES (?, ?, ?, ?, 'normal', NOW())");
        $stmt->execute([$application['user_id'], $admin['id'], $subject, $message]);

        $_SESSION['success_message'] = 'Заявка отменена';
        redirect($applicationsReturnUrl);
    } elseif ($_POST['action'] === 'decline_application') {
        $worksForDeclineCheck = getApplicationWorks((int) $application_id);
        $canDeclineApplication = !empty($worksForDeclineCheck);
        foreach ($worksForDeclineCheck as $workRow) {
            if ((string) ($workRow['status'] ?? 'pending') !== 'reviewed_non_competitive') {
                $canDeclineApplication = false;
                break;
            }
        }
        if (!$canDeclineApplication) {
            $_SESSION['error_message'] = 'Отклонить заявку можно только когда по всем рисункам принято решение «Рисунок отклонён».';
            redirect('/admin/application/' . $application_id . (parse_url($applicationsReturnUrl, PHP_URL_QUERY) ? ('?' . parse_url($applicationsReturnUrl, PHP_URL_QUERY)) : ''));
        }
        // В ряде БД статус отклонения хранится как `rejected` (без `declined` в ENUM),
        // поэтому сохраняем совместимое значение.
        $stmt = $pdo->prepare("UPDATE applications SET status = 'rejected', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$application_id]);
        $application['status'] = 'rejected';
        $isApplicationFinal = true;

        $subject = getSystemSetting('application_declined_subject', 'Ваша заявка отклонена');
        $message = getSystemSetting('application_declined_message', 'Ваша заявка отклонена администратором.') . "\n\nНомер заявки: #" . $application_id;
        $stmt = $pdo->prepare("INSERT INTO admin_messages (user_id, admin_id, subject, message, priority, created_at) VALUES (?, ?, ?, ?, 'critical', NOW())");
        $stmt->execute([$application['user_id'], $admin['id'], $subject, $message]);

        $_SESSION['success_message'] = 'Заявка отклонена';
        redirect($applicationsReturnUrl);

} elseif ($_POST['action'] === 'delete') {
 // Удаляем участников и заявку
 $pdo->prepare("DELETE FROM participants WHERE application_id = ?")->execute([$application_id]);
 $pdo->prepare("DELETE FROM applications WHERE id = ?")->execute([$application_id]);
 $_SESSION['success_message'] = 'Заявка удалена';
 redirect($applicationsReturnUrl);
 } elseif ($_POST['action'] === 'send_message') {
 $subject = trim($_POST['subject'] ?? '');
 $message = trim($_POST['message'] ?? '');
 $priority = $_POST['priority'] ?? 'normal';
 $attachmentUpload = uploadMessageAttachment($_FILES['attachment'] ?? []);
 
 if (empty($attachmentUpload['success'])) {
 $error = (string) ($attachmentUpload['message'] ?? 'Не удалось загрузить вложение.');
 } elseif ($subject === '' || ($message === '' && empty($attachmentUpload['uploaded']))) {
 $error = 'Заполните тему и текст сообщения или прикрепите файл';
 } else {
 [$attachmentFile, $attachmentOriginalName, $attachmentMimeType, $attachmentSize] = messageAttachmentInsertPayload($attachmentUpload);
 $stmt = $pdo->prepare("INSERT INTO admin_messages (user_id, admin_id, subject, message, priority, created_at, attachment_file, attachment_original_name, attachment_mime_type, attachment_size) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)");
 $stmt->execute([$application['user_id'], $admin['id'], $subject, $message, $priority, $attachmentFile, $attachmentOriginalName, $attachmentMimeType, $attachmentSize]);
 $_SESSION['success_message'] = 'Сообщение отправлено';
 }
 } elseif ($_POST['action'] === 'open_applicant_chat') {
 $targetChatTitle = '';
 $existingChatStmt = $pdo->prepare("
 SELECT title
 FROM messages
 WHERE user_id = ?
   AND application_id = ?
 ORDER BY created_at DESC, id DESC
 LIMIT 1
 ");
 $existingChatStmt->execute([(int) $application['user_id'], (int) $application_id]);
 $targetChatTitle = trim((string) ($existingChatStmt->fetchColumn() ?: ''));

 if ($targetChatTitle === '') {
  $targetChatTitle = buildCuratorChatTitle((int) $application_id);
  $introMessage = trim(
   "Здравствуйте!\n\n"
   . "Открыт чат с куратором по заявке #{$application_id}."
   . (!empty($application['contest_title']) ? "\nКонкурс: " . (string) $application['contest_title'] : '')
   . "\n\nЗдесь можно уточнить детали по заявке и получить помощь по ходу работы."
  );
  $insertChatStmt = $pdo->prepare("
   INSERT INTO messages (user_id, application_id, title, content, created_by, created_at, is_read)
   VALUES (?, ?, ?, ?, ?, NOW(), 0)
  ");
  $insertChatStmt->execute([
   (int) $application['user_id'],
   (int) $application_id,
   $targetChatTitle,
   $introMessage,
   (int) ($admin['id'] ?? 0),
  ]);
  $_SESSION['success_message'] = 'Чат с заявителем создан';
 }

 redirect(
  '/admin/messages/user/' . (int) $application['user_id']
  . '?chat_application_id=' . (int) $application_id
  . '&chat_title=' . urlencode($targetChatTitle)
 );
 } elseif ($_POST['action'] === 'toggle_drawing_compliance') {
 $participantId = intval($_POST['participant_id'] ?? 0);
 $isCompliant = isset($_POST['drawing_compliant']) ? 1 : 0;
 $workId = findParticipantWorkId($works, $participantId);
 $comment = trim($_POST['comment'] ?? '');

 if ($participantId <= 0) {
     if ($isAjaxRequest) {
         jsonResponse(['success' => false, 'error' => 'Некорректный участник'], 422);
     }
     $_SESSION['success_message'] = 'Не удалось сохранить проверку';
     redirect('/admin/application/' . $application_id . (parse_url($applicationsReturnUrl, PHP_URL_QUERY) ? ('?' . parse_url($applicationsReturnUrl, PHP_URL_QUERY)) : ''));
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

 if ($workId > 0) {
     updateWorkStatus($workId, $isCompliant ? 'accepted' : 'reviewed');
     $applicationStatusReset = resetApplicationDecisionStatusIfNeeded((int) $application_id, (string) ($application['status'] ?? 'submitted'));
     $application['status'] = (string) ($applicationStatusReset['status'] ?? 'submitted');
     $isApplicationApproved = (string) ($application['status'] ?? '') === 'approved';
     $isApplicationFinal = in_array((string) ($application['status'] ?? ''), ['approved', 'rejected', 'cancelled'], true);
     $isApplicationDecisionLocked = (string) ($application['status'] ?? '') === 'cancelled';
 } else {
     $applicationStatusReset = ['was_reset' => false];
 }

 if ($isAjaxRequest) {
     jsonResponse([
         'success' => true,
         'application_status' => (string) ($application['status'] ?? 'submitted'),
         'application_status_reset' => (bool) ($applicationStatusReset['was_reset'] ?? false),
     ]);
 }
 $_SESSION['success_message'] = 'Проверка рисунка обновлена';
 redirect('/admin/application/' . $application_id);
 } elseif ($_POST['action'] === 'send_to_revision') {
     $worksForRevisionCheck = getApplicationWorks((int) $application_id);
     $hasRevisionPath = false;
     $hasUnresolvedRevisionDecision = false;
     foreach ($worksForRevisionCheck as $workRow) {
         $isCompliantRow = (int) ($workRow['drawing_compliant'] ?? 1) === 1;
         $isFinalDecision = in_array((string) ($workRow['status'] ?? 'pending'), ['accepted', 'reviewed_non_competitive'], true);
         if (!$isFinalDecision && $isCompliantRow) {
             $hasUnresolvedRevisionDecision = true;
             break;
         }
         if (!$isCompliantRow) {
             $hasRevisionPath = true;
         }
     }

     if ($hasUnresolvedRevisionDecision) {
         $_SESSION['error_message'] = 'Нельзя отправить заявку на корректировку, пока по всем участникам не принято решение.';
         redirect('/admin/application/' . $application_id . (parse_url($applicationsReturnUrl, PHP_URL_QUERY) ? ('?' . parse_url($applicationsReturnUrl, PHP_URL_QUERY)) : ''));
     }

     if (!$hasRevisionPath) {
         $_SESSION['error_message'] = 'Для отправки на корректировку выключите переключатель «Соответствует условиям конкурса» хотя бы у одного участника.';
         redirect('/admin/application/' . $application_id . (parse_url($applicationsReturnUrl, PHP_URL_QUERY) ? ('?' . parse_url($applicationsReturnUrl, PHP_URL_QUERY)) : ''));
     }

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
         redirect('/admin/application/' . $application_id . (parse_url($applicationsReturnUrl, PHP_URL_QUERY) ? ('?' . parse_url($applicationsReturnUrl, PHP_URL_QUERY)) : ''));
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

    if ($isAjaxRequest) {
        $vkStatusAfterRevision = getApplicationVkPublicationStatus((int) $application_id);
        jsonResponse([
            'success' => true,
            'message' => 'Заявка отправлена на корректировку',
            'application_status' => 'revision',
            'open_vk_publish_prompt' => (int) ($vkStatusAfterRevision['remaining_count'] ?? 0) > 0,
        ]);
    }

    $_SESSION['success_message'] = 'Заявка отправлена на корректировку';
    redirect($applicationsReturnUrl);
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
$headerBackUrl = $applicationsReturnUrl;
$headerBackLabel = 'Назад';

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .message-attachment-preview {
        display: block;
    }

    .message-attachment-preview__image-button {
        display: inline-flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
        width: min(100%, 280px);
        padding: 12px;
        border: 1px solid #dbe3f0;
        border-radius: 14px;
        background: #f8fbff;
        cursor: pointer;
        text-align: left;
    }

    .message-attachment-preview__thumb {
        display: block;
        width: 100%;
        max-height: 180px;
        object-fit: contain;
        border-radius: 12px;
        background: #fff;
    }

    .message-attachment-preview__caption {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #2563eb;
        font-size: 13px;
        font-weight: 600;
    }

    .message-attachment-preview__file {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 12px 14px;
        border-radius: 14px;
        border: 1px solid #dbe3f0;
        background: #fff;
        color: #0f172a;
        text-decoration: none;
        font-weight: 600;
    }
</style>

<?php
$statusMeta = getApplicationDisplayMeta($application, buildApplicationWorkSummary($works));
$submittedAt = !empty($application['created_at']) ? date('d.m.Y H:i', strtotime($application['created_at'])) : '—';
$applicantName = trim((string) (($application['surname'] ?? '') . ' ' . ($application['name'] ?? '') . ' ' . ($application['patronymic'] ?? ''))) ?: '—';
$receiptMeta = getApplicationPaymentReceiptMeta($application);
$paymentReceipt = trim((string) ($application['payment_receipt'] ?? ''));
$paymentReceiptName = $paymentReceipt !== '' ? basename($paymentReceipt) : '—';
$paymentReceiptUrl = (string) ($receiptMeta['file_url'] ?? '');
$workStats = ['total' => count($works), 'accepted' => 0, 'reviewed' => 0, 'rejected' => 0];
$nonCompliantCount = 0;
foreach ($works as $workRow) {
    $status = (string) ($workRow['status'] ?? 'pending');
    if ($status === 'accepted') {
        $workStats['accepted']++;
    } elseif ($status === 'reviewed') {
        $workStats['reviewed']++;
    } elseif ($status === 'reviewed_non_competitive' || $status === 'rejected') {
        $workStats['rejected']++;
    }
    if ($hasDrawingCompliantColumn && (int) ($workRow['drawing_compliant'] ?? 1) === 0) {
        $nonCompliantCount++;
    }
}
$latestMessageStmt = $pdo->prepare("
    SELECT subject, priority, created_at
    FROM admin_messages
    WHERE user_id = ? AND admin_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$latestMessageStmt->execute([(int) $application['user_id'], (int) $admin['id']]);
$latestMessage = $latestMessageStmt->fetch() ?: null;
$approveButtonDisabled = $isApplicationApproved;
$approveButtonIcon = $isApplicationApproved ? 'fa-check-double' : 'fa-check';
$approveButtonText = $isApplicationApproved ? 'Заявка принята' : 'Принять заявку';
$currentApplicationUiStatus = (string) ($statusMeta['status_code'] ?? 'draft');
$isRevisionApplicationState = $currentApplicationUiStatus === 'revision';
$isRejectedApplicationState = (string) ($application['status'] ?? '') === 'rejected';
?>

<section class="application-hero card mb-lg">
    <div class="card__body">
        <div class="application-hero__head">
            <div>
                <h2 class="application-hero__title">Заявка #<?= e($application_id) ?></h2>
                <p class="application-hero__subtitle"><?= e($application['contest_title']) ?></p>
            </div>
            <div class="flex gap-sm application-actions">
                <span class="badge application-hero__status <?= $statusMeta['badge_class'] ?>"><?= e($statusMeta['label']) ?></span>
                <span class="badge application-hero__status <?= e((string) ($receiptMeta['badge_class'] ?? 'badge--secondary')) ?>"><?= e((string) ($receiptMeta['label'] ?? '—')) ?></span>
            </div>
        </div>
        <div class="application-hero__meta">
            <span class="application-meta-chip"><i class="fas fa-calendar-alt"></i>Подана: <?= e($submittedAt) ?></span>
            <span class="application-meta-chip"><i class="fas fa-user"></i><?= e($applicantName) ?></span>
            <a class="application-meta-chip" href="mailto:<?= e($application['email'] ?? '') ?>"><i class="fas fa-envelope"></i><?= e($application['email'] ?: '—') ?></a>
            <span class="application-meta-chip"><i class="fas fa-images"></i>Работ: <?= (int) $workStats['total'] ?></span>
            <span class="application-meta-chip"><i class="fas fa-receipt"></i><?= e((string) ($receiptMeta['label'] ?? '—')) ?></span>
        </div>
        <div class="application-hero__actions">
            <a href="/admin/user/<?= (int) $application['user_id'] ?>" class="btn btn--secondary"><i class="fas fa-user-circle"></i> Профиль заявителя</a>
            <a href="/admin/messages/user/<?= (int) $application['user_id'] ?>" class="btn btn--secondary"><i class="fas fa-envelope"></i> Центр сообщений</a>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="open_applicant_chat">
                <button type="submit" class="btn btn--secondary"><i class="fas fa-comments"></i> Чат с заявителем</button>
            </form>
            <button type="button" class="btn btn--primary" onclick="openMessageModal()"><i class="fas fa-paper-plane"></i> Связаться с заявителем</button>
            <a href="#application-actions" class="btn btn--ghost"><i class="fas fa-bolt"></i> К действиям</a>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="cancel_application">
                <button type="submit" class="btn application-btn application-btn--warning"><i class="fas fa-ban"></i> Отмена</button>
            </form>
            <form method="POST" onsubmit="return confirm('Удалить заявку?');">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn application-btn application-btn--danger"><i class="fas fa-trash"></i> Удалить</button>
            </form>
        </div>
        <?php if ($latestMessage): ?>
            <div class="application-message-status">
                <i class="fas fa-check-circle"></i>
                Последнее сообщение отправлено: <strong><?= e(date('d.m.Y H:i', strtotime((string) $latestMessage['created_at']))) ?></strong>,
                «<?= e($latestMessage['subject']) ?>».
            </div>
        <?php endif; ?>
    </div>
</section>

<nav class="application-anchor-nav mb-lg">
    <a href="#application-overview">Общая информация</a>
    <a href="#application-works">Работы</a>
    <a href="#application-actions">Действия</a>
</nav>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert--success mb-md"><i class="fas fa-check-circle alert__icon"></i><div class="alert__content"><div class="alert__message"><?= htmlspecialchars($_SESSION['success_message']) ?></div></div></div>
<?php unset($_SESSION['success_message']); endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert--error mb-md"><i class="fas fa-times-circle alert__icon"></i><div class="alert__content"><div class="alert__message"><?= htmlspecialchars($_SESSION['error_message']) ?></div></div></div>
<?php unset($_SESSION['error_message']); endif; ?>
<?php if ($hasNonCompliantDrawings): ?>
    <div class="alert alert--warning mb-lg">
        <i class="fas fa-exclamation-triangle alert__icon"></i>
        <div class="alert__content"><div class="alert__message">Есть работы с пометкой о несоответствии. Для таких рисунков нужен комментарий с причиной и перечнем исправлений.</div></div>
    </div>
<?php endif; ?>

<div class="application-layout">
    <div class="application-main">
        <section id="application-overview" class="application-section mb-lg">
            <div class="application-info-grid">
                <article class="card"><div class="card__body">
                    <h3 class="application-card-title">Заявитель</h3>
                    <div class="application-applicant">
                        <?php if (!empty($application['avatar_url'])): ?>
                            <img src="<?= e($application['avatar_url']) ?>" class="application-applicant__avatar" alt="Аватар заявителя">
                        <?php else: ?>
                            <div class="application-applicant__avatar application-applicant__avatar--empty"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        <div>
                            <div class="font-semibold"><?= e($applicantName) ?></div>
                            <a href="mailto:<?= e($application['email'] ?? '') ?>" class="text-secondary"><?= e($application['email'] ?: '—') ?></a>
                            <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                <span class="badge <?= $isApplicantBlacklisted ? 'badge--error' : 'badge--secondary' ?>" id="applicantBlacklistBadge">
                                    <?= $isApplicantBlacklisted ? 'В чёрном списке рассылки' : 'Не в чёрном списке' ?>
                                </span>
                                <?php if ($applicantEmail !== ''): ?>
                                    <form method="POST" class="js-applicant-blacklist-form">
                                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                        <input type="hidden" name="action" value="toggle_applicant_blacklist">
                                        <button class="btn <?= $isApplicantBlacklisted ? 'btn--secondary' : 'btn--danger' ?> btn--sm" type="submit" id="applicantBlacklistButton">
                                            <?= $isApplicantBlacklisted ? 'Убрать из чёрного списка' : 'Добавить в чёрный список' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <div class="text-secondary" style="margin-top:6px;font-size:12px;">Влияет только на модуль рассылки. Остальные действия с заявкой не ограничиваются.</div>
                        </div>
                    </div>
                    <dl class="application-kv-list"><dt>Тип профиля</dt><dd><?= e(getUserTypeLabel((string) ($application['user_type'] ?? 'parent'))) ?></dd><dt>ФИО родителя/куратора</dt><dd><?= e($application['parent_fio'] ?: '—') ?></dd></dl>
                </div></article>
                <article class="card"><div class="card__body">
                    <h3 class="application-card-title">Заявка</h3>
                    <dl class="application-kv-list">
                        <dt>Номер</dt><dd>#<?= (int) $application_id ?></dd>
                        <dt>Конкурс</dt><dd><?= e($application['contest_title'] ?: '—') ?></dd>
                        <dt>Статус</dt><dd><span class="badge <?= e($statusMeta['badge_class']) ?>"><?= e($statusMeta['label']) ?></span></dd>
                        <dt>Оплата</dt><dd><span class="badge <?= e((string) ($receiptMeta['badge_class'] ?? 'badge--secondary')) ?>"><?= e((string) ($receiptMeta['label'] ?? '—')) ?></span></dd>
                        <dt>Дата подачи</dt><dd><?= e($submittedAt) ?></dd>
                    </dl>
                </div></article>
                <article class="card"><div class="card__body">
                    <h3 class="application-card-title">Организация</h3>
                    <dl class="application-kv-list">
                        <dt>Регион</dt><dd><?= e($application['organization_region'] ?: '—') ?></dd>
                        <dt>Название и адрес образовательного учреждения</dt><dd><?= e($application['organization_name'] ?: '—') ?></dd>
                        <dt>Контактная информация организации</dt><dd><?= e($application['organization_address'] ?: '—') ?></dd>
                    </dl>
                </div></article>
                <article class="card"><div class="card__body">
                    <h3 class="application-card-title">Дополнительная информация</h3>
                    <dl class="application-kv-list">
                        <dt>Источник</dt><dd><?= e($application['source_info'] ?: '—') ?></dd>
                        <dt>Коллеги</dt><dd><?= e($application['colleagues_info'] ?: '—') ?></dd>
                        <?php if (!empty($receiptMeta['is_required'])): ?>
                            <dt>Требуется квитанция</dt><dd>Да</dd>
                        <?php endif; ?>
                    </dl>
                    <?php if (!empty($receiptMeta['is_required'])): ?>
                    <div class="application-file-block">
                        <i class="fas fa-file-invoice"></i>
                        <?php if ($paymentReceipt !== ''): ?>
                            <div>
                                <strong><?= e($paymentReceiptName) ?></strong>
                                <div class="flex gap-sm work-card__footer-actions">
                                    <a href="<?= e($paymentReceiptUrl) ?>" target="_blank" class="application-file-block__link">Открыть квитанцию</a>
                                    <a href="<?= e($paymentReceiptUrl) ?>" target="_blank" class="application-file-block__link" download>Скачать</a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div>
                                <strong><?= !empty($receiptMeta['is_required']) ? 'Квитанция пока не приложена' : 'Квитанция не приложена' ?></strong>
                                <?php if (!empty($receiptMeta['is_required'])): ?>
                                    <div class="text-secondary application-payment-note">Для этого конкурса квитанция обязательна и должна быть видна администратору.</div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div></article>
            </div>
        </section>

        <section id="application-works" class="application-section mb-lg">
            <div class="card mb-lg"><div class="card__body">
                <h3 class="application-card-title">Сводка по работам</h3>
                <div class="application-work-summary">
                    <div class="application-summary-item"><span>Всего</span><strong><?= (int) $workStats['total'] ?></strong></div>
                    <div class="application-summary-item"><span>Принято</span><strong><?= (int) $workStats['accepted'] ?></strong></div>
                    <div class="application-summary-item"><span>На корректировке</span><strong><?= (int) $workStats['reviewed'] ?></strong></div>
                    <div class="application-summary-item"><span>Отклонено/не соответствует</span><strong><?= (int) ($workStats['rejected'] + $nonCompliantCount) ?></strong></div>
                </div>
                <?php if ($hasNonCompliantDrawings): ?><p class="application-summary-warning">Проверьте, что по всем несоответствующим рисункам заполнен комментарий.</p><?php endif; ?>
            </div></div>

            <?php foreach ($works as $i => $p): ?>
                <article class="card mb-lg work-card">
                    <div class="card__header work-card__header">
                        <div><h3>Работа #<?= $i + 1 ?></h3><p class="text-secondary"><?= e($p['fio'] ?: 'Участник не указан') ?></p></div>
                        <div class="work-card__header-actions">
                            <span class="badge <?= getWorkStatusBadgeClass((string)($p['status'] ?? 'pending')) ?>" data-work-status-badge><?= e(getWorkStatusLabel((string)($p['status'] ?? 'pending'))) ?></span>
                            <?php if (!empty($p['participant_id'])): ?><a href="/admin/participant/<?= (int) $p['participant_id'] ?>" class="btn btn--ghost btn--sm"><i class="fas fa-user"></i> Профиль</a><?php endif; ?>
                        </div>
                    </div>
                    <div class="card__body">
                        <div class="work-card__layout">
                            <div class="work-card__preview">
                                <?php if ($p['drawing_file']): ?>
                                    <?php $drawingUrl = getParticipantDrawingWebPath($application['email'] ?? '', $p['drawing_file']); ?>
                                    <?php $drawingPreviewUrl = getParticipantDrawingPreviewWebPath($application['email'] ?? '', $p['drawing_file']); ?>
                                    <button
                                        type="button"
                                        class="work-card__image-button js-open-drawing-viewer"
                                        data-image-src="<?= e($drawingUrl) ?>"
                                        data-image-alt="Рисунок участника <?= e($p['fio'] ?: '') ?>"
                                        aria-label="Открыть рисунок участника"
                                    >
                                        <img src="<?= e($drawingPreviewUrl) ?>" data-participant-id="<?= (int) ($p['participant_id'] ?? 0) ?>" class="js-admin-drawing work-card__image" alt="Рисунок участника">
                                        <span class="work-card__image-hint"><i class="fas fa-search-plus"></i> Нажмите для просмотра</span>
                                    </button>
                                    <button type="button" class="btn btn--secondary js-open-editor mt-sm" data-participant-id="<?= (int) ($p['participant_id'] ?? 0) ?>" data-image-src="<?= e($drawingUrl) ?>"><i class="fas fa-crop-alt"></i> Редактировать</button>
                                <?php else: ?>
                                    <div class="drawing-empty-state"><i class="fas fa-image"></i><strong>Рисунок отсутствует</strong><span>Участник ещё не загрузил файл.</span></div>
                                <?php endif; ?>
                            </div>
                            <div class="work-card__details">
                                <section class="work-section"><h4>Участник</h4><dl class="application-kv-list"><dt>ФИО</dt><dd><?= e($p['fio'] ?: '—') ?></dd><dt>Номер участника</dt><dd><?php if (!empty($p['participant_id'])): ?><a href="/admin/participant/<?= (int) $p['participant_id'] ?>" style="color:#7C3AED;text-decoration:none;"><?= e(getParticipantDisplayNumber((array) $p)) ?></a><?php else: ?>—<?php endif; ?></dd><dt>Возраст</dt><dd><?= (int) ($p['age'] ?? 0) ?> лет</dd><dt>Регион</dt><dd><?= e($p['region'] ?? '—') ?></dd></dl></section>
                                <section class="work-section"><h4>Организация</h4><dl class="application-kv-list"><dt>Название и адрес образовательного учреждения</dt><dd><?= e($p['organization_name'] ?? '—') ?></dd><dt>Контактная информация организации</dt><dd><?= e($p['organization_address'] ?? '—') ?></dd></dl></section>
                                <?php
                                    $workStatus = (string) ($p['status'] ?? 'pending');
                                    $isDecisionFinal = in_array($workStatus, ['accepted', 'reviewed_non_competitive'], true);
                                    $isComplianceLocked = $isDecisionFinal || $isApplicationDecisionLocked;
                                ?>
                                <section class="work-section"><h4>Проверка работы</h4>
                                    <form method="POST" class="js-drawing-compliance-form work-compliance-form<?= $isComplianceLocked ? ' is-disabled' : '' ?>" data-compliance-form data-locked="<?= $isComplianceLocked ? '1' : '0' ?>">
                                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                        <input type="hidden" name="action" value="toggle_drawing_compliance">
                                        <input type="hidden" name="participant_id" value="<?= (int) ($p['participant_id'] ?? 0) ?>">
                                        <input type="hidden" name="ajax" value="1">
                                        <label class="ios-toggle-wrap"><span class="ios-toggle-label">Соответствует условиям конкурса</span><span class="ios-toggle"><input type="checkbox" name="drawing_compliant" value="1" class="js-drawing-compliant-toggle" <?= isset($p['drawing_compliant']) && (int)$p['drawing_compliant'] === 1 ? 'checked' : '' ?> <?= $isComplianceLocked ? 'disabled aria-disabled="true"' : '' ?>><span class="ios-toggle__slider"></span></span></label>
                                        <div class="js-drawing-comment-template-wrap mt-sm" <?= isset($p['drawing_compliant']) && (int)$p['drawing_compliant'] === 1 ? 'style="display:none;"' : '' ?>>
                                            <label class="form-label">Вариант сообщения</label>
                                            <select class="form-select js-drawing-comment-template" <?= $isComplianceLocked ? 'disabled aria-disabled="true"' : '' ?>>
                                                <option value="__custom__" selected>Написать свой вариант</option>
                                                <?php foreach ($drawingCommentPresets as $presetText): ?>
                                                    <option value="<?= e($presetText) ?>"><?= e(mb_strimwidth($presetText, 0, 140, '...')) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <label class="form-label mt-sm">Что исправить</label>
                                        <textarea class="form-textarea js-drawing-comment" name="comment" rows="2" placeholder="Укажите, что нужно исправить" <?= $isComplianceLocked ? 'disabled aria-disabled="true"' : '' ?>><?= e($p['drawing_comment'] ?? '') ?></textarea>
                                    </form>
                                </section>
	                                <section class="work-section"><h4>Действия по работе</h4>
	                                    <div class="work-actions" data-work-controls data-work-id="<?= (int) $p['id'] ?>" data-work-status="<?= e($workStatus) ?>">
	                                        <form method="POST" class="js-work-async-form" data-accept-work-form><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="set_work_status"><input type="hidden" name="work_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="participant_id" value="<?= (int) ($p['participant_id'] ?? 0) ?>"><input type="hidden" name="work_status" value="accepted"><button class="btn btn--sm work-decision-btn <?= ((string) ($p['status'] ?? 'pending')) === 'accepted' ? 'work-decision-btn--accepted is-active' : '' ?>" type="submit" data-decision-button="accepted" <?= ((string) ($p['status'] ?? 'pending')) === 'reviewed_non_competitive' ? 'disabled aria-disabled="true"' : '' ?>>Рисунок принят</button></form>
	                                        <form method="POST" class="js-work-async-form" data-reject-work-form><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="set_work_status"><input type="hidden" name="work_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="participant_id" value="<?= (int) ($p['participant_id'] ?? 0) ?>"><input type="hidden" name="work_status" value="reviewed_non_competitive"><input type="hidden" name="comment" value="<?= e((string) ($p['drawing_comment'] ?? '')) ?>" data-reject-comment-input><button class="btn btn--sm work-decision-btn <?= ((string) ($p['status'] ?? 'pending')) === 'reviewed_non_competitive' ? 'work-decision-btn--rejected is-active' : '' ?>" type="submit" data-decision-button="reviewed_non_competitive" <?= ((string) ($p['status'] ?? 'pending')) === 'accepted' ? 'disabled aria-disabled="true"' : '' ?>>Рисунок отклонён</button></form>
	                                    </div>
	                                </section>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (empty($works)): ?><div class="card"><div class="card__body text-center"><div class="drawing-empty-state"><i class="fas fa-users-slash"></i><strong>Работ пока нет</strong><span>В заявке отсутствуют участники.</span></div></div></div><?php endif; ?>
        </section>
    </div>

    <aside id="application-actions" class="application-sidebar">
        <div class="card application-sticky-panel">
            <div class="card__body">
	                <h3 class="application-card-title">Действия с заявкой</h3>
	                <div class="card vk-publication-card">
                    <div class="card__body">
                        <div class="vk-publication-card__title">Публикация в VK</div>
                        <div class="flex items-center gap-sm vk-publication-card__meta">
                            <span class="badge <?= e((string) ($applicationVkStatus['badge_class'] ?? 'badge--secondary')) ?>">
                                <?= e((string) ($applicationVkStatus['status_label'] ?? 'Не опубликована')) ?>
                            </span>
                            <span class="text-secondary vk-publication-card__caption">
                                Опубликовано <?= (int) ($applicationVkStatus['published_count'] ?? 0) ?> из <?= (int) ($applicationVkStatus['total_count'] ?? 0) ?>
                            </span>
                        </div>
                        <?php if (!empty($applicationVkStatus['last_attempt_at'])): ?>
                            <div class="text-secondary vk-publication-card__text">
                                Последняя попытка: <?= e(date('d.m.Y H:i', strtotime((string) $applicationVkStatus['last_attempt_at']))) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($applicationVkStatus['last_error'])): ?>
                            <div class="text-secondary vk-publication-card__text">
                                Ошибка: <?= e((string) $applicationVkStatus['last_error']) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($applicationVkStatus['last_post_url'])): ?>
                            <a class="btn btn--ghost btn--sm vk-publication-card__link" href="<?= e((string) $applicationVkStatus['last_post_url']) ?>" target="_blank">
                                <i class="fas fa-up-right-from-square"></i> Открыть пост
                            </a>
                        <?php endif; ?>
                        <button
                            type="button"
                            class="btn btn--secondary btn--sm"
                            id="openVkPublishModalBtn"
                            data-application-id="<?= (int) $application_id ?>"
                        >
                            <?php if (($applicationVkStatus['status_code'] ?? '') === 'published'): ?>
                                Опубликовать повторно
                            <?php elseif (($applicationVkStatus['status_code'] ?? '') === 'partial'): ?>
                                Опубликовать оставшиеся
                            <?php else: ?>
                                Опубликовать в VK
                            <?php endif; ?>
                        </button>
                    </div>
                </div>
                <div class="application-sidebar-actions">
                    <form method="POST" class="js-application-secondary-action" id="sendToRevisionForm"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="send_to_revision"><button type="submit" class="btn application-btn application-btn--warning <?= $isRevisionApplicationState ? 'is-current' : '' ?>" id="sendToRevisionButton" <?= $revisionButtonDisabled ? 'disabled aria-disabled="true" tabindex="-1"' : '' ?>><i class="fas fa-edit"></i> На корректировку</button></form>
                    <form method="POST" class="js-application-secondary-action"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="decline_application"><button type="submit" class="btn application-btn application-btn--danger <?= $isRejectedApplicationState ? 'is-current' : '' ?>" id="declineApplicationButton" <?= $declineButtonDisabled ? 'disabled aria-disabled="true" tabindex="-1"' : '' ?>><i class="fas fa-times-circle"></i> Отклонить</button></form>
                    <form method="POST" id="approveApplicationForm">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="approve_application">
                    </form>
                    <button
                        type="button"
                        class="btn application-btn application-btn--success <?= $isApplicationApproved ? 'is-current' : '' ?>"
                        id="approveApplicationButton"
                        data-id="<?= (int) $application_id ?>"
                        data-approved="<?= $isApplicationApproved ? '1' : '0' ?>"
                        data-csrf="<?= e(csrf_token()) ?>"
                        <?= $approveButtonDisabled ? 'disabled aria-disabled="true" tabindex="-1"' : '' ?>
                    >
                        <i class="fas <?= e($approveButtonIcon) ?>"></i> <?= e($approveButtonText) ?>
                    </button>
                    <?php if ($isApplicationApproved): ?>
                        <a href="<?= e($applicationsReturnUrl) ?>" class="btn btn--ghost"><i class="fas fa-list"></i> Закрыть</a>
                    <?php endif; ?>
                </div>
                <p class="application-sidebar-hint" style="<?= $isApplicationFinal || $approveButtonDisabled ? 'display:none;' : '' ?>">Кнопка станет активной, когда по каждой работе будет принято решение кнопкой «Рисунок принят» или «Рисунок отклонён».</p>
                <p class="application-sidebar-hint" id="revisionApplicationHint" style="<?= $isApplicationFinal || !$revisionButtonDisabled ? 'display:none;' : '' ?>">Кнопка «На корректировку» станет активной, когда по всем участникам будет принято решение и хотя бы у одного участника будет выключен переключатель «Соответствует условиям конкурса».</p>
            </div>
        </div>
    </aside>
</div>

<div class="modal" id="vkPublishPromptModal">
    <div class="modal__content vk-publish-modal__content">
        <div class="modal__header">
            <h3 class="modal__title">Публикация в VK</h3>
            <button type="button" class="modal__close vk-publish-modal__close" aria-label="Закрыть" id="vkPublishPromptModalClose">&times;</button>
        </div>
        <div class="modal__body">
            <div id="vkPublishModalSummary" class="text-secondary vk-publish-modal__summary"></div>
            <div id="vkPublishPreview" class="vk-publish-modal__preview"></div>
            <div class="vk-publish-modal__section">
                <div class="vk-publish-modal__section-title">Будут опубликованы только принятые и ещё не опубликованные рисунки</div>
            </div>
            <div id="vkPublishPromptStatus" class="alert vk-publish-modal__status"></div>
        </div>
        <div class="modal__footer vk-publish-modal__footer">
            <button type="button" class="btn btn--primary" id="vkPublishPromptRun">Опубликовать</button>
            <button type="button" class="btn btn--secondary" id="vkPublishPromptSkip">Отмена</button>
            <button type="button" class="btn btn--secondary is-hidden" id="vkPublishPromptClose">Закрыть</button>
        </div>
    </div>
</div>

<!-- Модальное окно отправки сообщения -->
<div class="modal" id="messageModal">
<div class="modal__content application-message-modal message-compose-modal">
<div class="modal__header">
<h3>Отправить сообщение пользователю</h3>
<button type="button" class="modal__close" onclick="closeMessageModal()">&times;</button>
</div>
<form method="POST" action="/admin/application/<?= e($application_id) ?>" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="action" value="send_message">
<div class="modal__body">
<div class="message-compose">
<div class="message-compose__top">
<div class="message-compose__intro message-compose__intro--compact">
<div class="message-compose__intro-icon"><i class="fas fa-comments"></i></div>
<div>
<div class="message-compose__intro-title">Сообщение по заявке #<?= (int) $application_id ?></div>
</div>
</div>
<div class="message-compose__recipient">
<div class="message-compose__recipient-icon"><i class="fas fa-user-circle"></i></div>
<div>
<div class="message-compose__recipient-title"><?= e($applicantName) ?></div>
<div class="message-compose__recipient-note"><?= e($application['email'] ?? 'Email не указан') ?></div>
</div>
</div>
</div>
<div class="message-compose__section">
<label class="form-label">Приоритет сообщения</label>
<div class="message-compose__priority-grid">
<label class="priority-btn priority-btn--normal selected" onclick="selectPriority('normal')">
<input type="radio" name="priority" value="normal" checked>
<span class="priority-icon"><i class="fas fa-circle"></i></span>
<span class="priority-text">Обычное</span>
</label>
<label class="priority-btn priority-btn--important" onclick="selectPriority('important')">
<input type="radio" name="priority" value="important">
<span class="priority-icon"><i class="fas fa-exclamation-circle"></i></span>
<span class="priority-text">Важное</span>
</label>
<label class="priority-btn priority-btn--critical" onclick="selectPriority('critical')">
<input type="radio" name="priority" value="critical">
<span class="priority-icon"><i class="fas fa-exclamation-triangle"></i></span>
<span class="priority-text">Критическое</span>
</label>
</div>
</div>
<div class="message-compose__section">
<label class="form-label">Тема сообщения</label>
<input type="text" name="subject" class="form-input" id="messageSubjectInput" required placeholder="Введите тему">
</div>
<div class="message-compose__section">
<label class="form-label">Текст сообщения</label>
<textarea name="message" class="form-textarea" rows="5" placeholder="Введите текст сообщения"></textarea>
</div>
<div class="message-compose__section">
<label class="form-label">Вложение</label>
<input type="file" name="attachment" class="form-input js-message-attachment-input" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.doc,.docx,.rtf,.xls,.xlsx,.csv,.zip,image/*,application/pdf,text/plain,text/csv">
<div class="form-help">Изображение можно сразу проверить в предпросмотре, файл будет отправлен как вложение. До 10 МБ.</div>
<div class="message-attachment-preview js-message-attachment-preview" hidden></div>
</div>
</div>
<div class="modal__footer flex gap-md application-message-modal__footer">
<div class="message-compose__footer">
<div class="flex gap-md">
<button type="button" class="btn btn--ghost" onclick="closeMessageModal()">Отмена</button>
<button type="submit" class="btn btn--primary"><i class="fas fa-paper-plane"></i> Отправить</button>
</div>
</div>
</div>
</form>
</div>
</div>

<div class="modal" id="messageImagePreviewModal">
<div class="modal__content" style="max-width:min(1100px,96vw); width:96vw;">
<div class="modal__header">
<h3 id="messageImagePreviewTitle">Предпросмотр изображения</h3>
<button type="button" class="modal__close" onclick="closeMessageImagePreview()">&times;</button>
</div>
<div class="modal__body" style="display:flex; justify-content:center; align-items:center; max-height:80vh;">
<img id="messageImagePreviewImage" src="" alt="" style="display:block; max-width:100%; max-height:70vh; border-radius:16px; object-fit:contain;">
</div>
</div>
</div>

<div class="modal" id="drawingEditorModal">
<div class="modal__content">
<div class="modal__header">
<h3>Редактирование рисунка</h3>
<button type="button" class="modal__close" onclick="closeDrawingEditor()">&times;</button>
</div>
<div class="modal__body">
<input type="hidden" id="editorParticipantId">
<div class="drawing-editor-toolbar">
<button type="button" class="btn btn--secondary" onclick="rotateBy(-45)">-45°</button>
<button type="button" class="btn btn--secondary" onclick="rotateBy(45)">+45°</button>
<button type="button" class="btn btn--secondary" onclick="rotateBy(-90)">-90°</button>
<button type="button" class="btn btn--secondary" onclick="rotateBy(90)">+90°</button>
<label class="drawing-editor-angle">Угол:
<input type="number" id="rotationInput" value="0" step="1" class="form-input drawing-editor-angle__input">
</label>
</div>
<div class="drawing-editor-stage">
<img id="editorImage" src="" alt="Рисунок">
</div>
</div>
<div class="modal__footer">
<button type="button" id="saveDrawingChanges" class="btn btn--primary is-hidden">Сохранить изменения</button>
<button type="button" id="cancelDrawingChanges" class="btn btn--ghost is-hidden" onclick="resetDrawingEditor()">Отмена изменений</button>
<button type="button" class="btn btn--secondary" onclick="closeDrawingEditor()">Закрыть</button>
</div>
</div>
</div>

<div class="modal" id="drawingViewerModal">
<div class="modal__content">
<div class="modal__header">
<h3>Просмотр рисунка</h3>
<button type="button" class="modal__close" onclick="closeDrawingViewer()">&times;</button>
</div>
<div class="modal__body">
<div class="drawing-viewer-stage">
<img id="drawingViewerImage" src="" alt="Рисунок участника">
</div>
</div>
<div class="modal__footer">
<button type="button" class="btn btn--secondary" onclick="closeDrawingViewer()">Закрыть</button>
</div>
</div>
</div>

	<script>
	let cropper = null;
let currentRotation = 0;
let editorDirty = false;
const drawingEditorModal = document.getElementById('drawingEditorModal');
const drawingViewerModal = document.getElementById('drawingViewerModal');
const drawingViewerImage = document.getElementById('drawingViewerImage');

function setPageModalScrollLocked(locked) {
 document.body.style.overflow = locked ? 'hidden' : '';
}

function markEditorDirty(dirty) {
 editorDirty = dirty;
 document.getElementById('saveDrawingChanges').style.display = dirty ? 'inline-flex' : 'none';
 document.getElementById('cancelDrawingChanges').style.display = dirty ? 'inline-flex' : 'none';
}

function openDrawingEditor(participantId, imageSrc) {
 const image = document.getElementById('editorImage');
 document.getElementById('editorParticipantId').value = participantId;
 image.src = imageSrc;
 drawingEditorModal.classList.add('active');
 setPageModalScrollLocked(true);
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
 drawingEditorModal.classList.remove('active');
 setPageModalScrollLocked(false);
 if (cropper) {
  cropper.destroy();
  cropper = null;
 }
}

function openDrawingViewer(imageSrc, imageAlt) {
 if (!drawingViewerModal || !drawingViewerImage) return;
 drawingViewerImage.src = imageSrc;
 drawingViewerImage.alt = imageAlt || 'Рисунок участника';
 drawingViewerModal.classList.add('active');
 setPageModalScrollLocked(true);
}

function closeDrawingViewer() {
 if (!drawingViewerModal || !drawingViewerImage) return;
 drawingViewerModal.classList.remove('active');
 drawingViewerImage.src = '';
 setPageModalScrollLocked(false);
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

document.querySelectorAll('.js-open-drawing-viewer').forEach((button) => {
 button.addEventListener('click', () => openDrawingViewer(button.dataset.imageSrc, button.dataset.imageAlt));
});

[drawingEditorModal, drawingViewerModal].forEach((modal) => {
 if (!modal) return;
 modal.addEventListener('click', (event) => {
  if (event.target === modal) {
   if (modal === drawingEditorModal) {
    closeDrawingEditor();
   } else if (modal === drawingViewerModal) {
    closeDrawingViewer();
   }
  }
 });
});

document.addEventListener('keydown', (event) => {
 if (event.key !== 'Escape') return;
 if (drawingEditorModal?.classList.contains('active')) {
  closeDrawingEditor();
  return;
 }
 if (drawingViewerModal?.classList.contains('active')) {
  closeDrawingViewer();
 }
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
   document.querySelectorAll(`.js-open-editor[data-participant-id="${participantId}"]`).forEach((button) => {
    button.dataset.imageSrc = data.updated_url;
   });
   document.querySelectorAll(`.js-admin-drawing[data-participant-id="${participantId}"]`).forEach((img) => {
    const viewerButton = img.closest('.js-open-drawing-viewer');
    if (viewerButton) {
     viewerButton.dataset.imageSrc = data.updated_url;
    }
   });
   closeDrawingEditor();
  })
  .catch(() => alert('Не удалось сохранить изменения'));
});

function openMessageModal() {
 const modal = document.getElementById('messageModal');
 if (!modal) return;
 modal.classList.add('active');
 document.body.style.overflow = 'hidden';
 const subjectInput = modal.querySelector('input[name="subject"]');
 const messageInput = modal.querySelector('textarea[name="message"]');
 const attachmentInput = modal.querySelector('input[name="attachment"]');
 const attachmentPreview = modal.querySelector('.js-message-attachment-preview');
 if (subjectInput) subjectInput.focus();
 if (messageInput && !messageInput.value) messageInput.value = '';
 if (attachmentInput) attachmentInput.value = '';
 if (attachmentPreview) {
  attachmentPreview.innerHTML = '';
  attachmentPreview.hidden = true;
 }
}
function closeMessageModal() { document.getElementById('messageModal').classList.remove('active'); document.body.style.overflow = ''; }
function openMessageImagePreview(encodedUrl, encodedTitle) {
 const imageUrl = decodeURIComponent(encodedUrl || '');
 const imageTitle = decodeURIComponent(encodedTitle || '');
 const modal = document.getElementById('messageImagePreviewModal');
 const image = document.getElementById('messageImagePreviewImage');
 const title = document.getElementById('messageImagePreviewTitle');
 if (!modal || !image || !title || !imageUrl) return;
 image.src = imageUrl;
 image.alt = imageTitle;
 title.textContent = imageTitle || 'Предпросмотр изображения';
 modal.classList.add('active');
 document.body.style.overflow = 'hidden';
}
function closeMessageImagePreview() {
 const modal = document.getElementById('messageImagePreviewModal');
 const image = document.getElementById('messageImagePreviewImage');
 if (!modal || !image) return;
 modal.classList.remove('active');
 image.src = '';
 image.alt = '';
 const otherModal = document.querySelector('.modal.active:not(#messageImagePreviewModal)');
 document.body.style.overflow = otherModal ? 'hidden' : '';
}
function escapeAttachmentHtml(value) {
 return String(value || '')
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&#039;');
}
function buildMessageAttachmentPreviewMarkup(file) {
 if (!file) return '';
 const fileName = file.name || 'Файл';
 const safeFileName = escapeAttachmentHtml(fileName);
 if (String(file.type || '').startsWith('image/')) {
  const objectUrl = URL.createObjectURL(file);
  return `
   <button type="button" class="message-attachment-preview__image-button js-local-image-preview" data-image-src="${objectUrl}" data-image-title="${safeFileName}">
    <img src="${objectUrl}" alt="${safeFileName}" class="message-attachment-preview__thumb">
    <span class="message-attachment-preview__caption"><i class="fas fa-search-plus"></i> Предпросмотр</span>
   </button>
  `;
 }
 return `<div class="message-attachment-preview__file"><i class="fas fa-paperclip"></i><span>${safeFileName}</span></div>`;
}
function initMessageAttachmentInput(input) {
 if (!input) return;
 const preview = input.parentElement?.querySelector('.js-message-attachment-preview');
 if (!preview) return;
 input.addEventListener('change', () => {
  const file = input.files && input.files[0] ? input.files[0] : null;
  preview.innerHTML = '';
  preview.hidden = !file;
  if (!file) return;
  preview.innerHTML = buildMessageAttachmentPreviewMarkup(file);
  preview.querySelectorAll('.js-local-image-preview').forEach((button) => {
   button.addEventListener('click', () => {
    openMessageImagePreview(
     encodeURIComponent(button.dataset.imageSrc || ''),
     encodeURIComponent(button.dataset.imageTitle || 'Предпросмотр изображения')
    );
   });
  });
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

document.querySelectorAll('.js-applicant-blacklist-form').forEach((form) => {
 form.addEventListener('submit', async (event) => {
  event.preventDefault();
  const button = form.querySelector('button[type="submit"]');
  const badge = document.getElementById('applicantBlacklistBadge');
  const defaultHtml = button ? button.innerHTML : '';
  if (button) {
   button.disabled = true;
   button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  }

  try {
   const formData = new FormData(form);
   formData.append('ajax', '1');
   const response = await fetch('/admin/application/<?= e($application_id) ?>', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: formData,
   });
   const data = await parseJsonResponse(response);
   if (!response.ok || !data.success) {
    throw new Error(data.error || 'Не удалось обновить чёрный список');
   }

   if (badge) {
    badge.className = 'badge ' + (Number(data.is_blacklisted) === 1 ? 'badge--error' : 'badge--secondary');
    badge.textContent = Number(data.is_blacklisted) === 1 ? 'В чёрном списке рассылки' : 'Не в чёрном списке';
   }
   if (button) {
    button.disabled = false;
    button.removeAttribute('aria-disabled');
    button.className = 'btn ' + (Number(data.is_blacklisted) === 1 ? 'btn--secondary' : 'btn--danger') + ' btn--sm';
    button.innerHTML = Number(data.is_blacklisted) === 1 ? 'Убрать из чёрного списка' : 'Добавить в чёрный список';
   }
   showToast(data.message || 'Статус чёрного списка обновлён', 'success');
  } catch (error) {
   if (button) {
    button.disabled = false;
    button.innerHTML = defaultHtml;
   }
   showToast(error.message || 'Не удалось обновить чёрный список', 'error');
  }
 });
});

const applicationDecisionLocked = <?= $isApplicationDecisionLocked ? 'true' : 'false' ?>;

function updateDecisionButtons(controls, status) {
 if (!controls) return;
 const acceptButton = controls.querySelector('[data-decision-button="accepted"]');
 const rejectButton = controls.querySelector('[data-decision-button="reviewed_non_competitive"]');
 if (!acceptButton || !rejectButton) return;

 acceptButton.classList.toggle('is-active', status === 'accepted');
 acceptButton.classList.toggle('work-decision-btn--accepted', status === 'accepted');
 rejectButton.classList.toggle('is-active', status === 'reviewed_non_competitive');
 rejectButton.classList.toggle('work-decision-btn--rejected', status === 'reviewed_non_competitive');

 const acceptedFinal = status === 'accepted';
 const rejectedFinal = status === 'reviewed_non_competitive';
 const acceptDisabled = rejectedFinal;
 const rejectDisabled = acceptedFinal;

 acceptButton.disabled = acceptDisabled;
 acceptButton.setAttribute('aria-disabled', acceptDisabled ? 'true' : 'false');
 acceptButton.classList.toggle('is-disabled', acceptDisabled);

 rejectButton.disabled = rejectDisabled;
	rejectButton.setAttribute('aria-disabled', rejectDisabled ? 'true' : 'false');
	rejectButton.classList.toggle('is-disabled', rejectDisabled);
	}

	async function parseJsonResponse(response) {
 const rawBody = await response.text();
 if (!rawBody) {
  throw new Error('Сервер вернул пустой ответ. Проверьте логи PHP и повторите попытку.');
 }
 try {
  return JSON.parse(rawBody);
 } catch (error) {
  throw new Error(`Сервер вернул некорректный ответ (HTTP ${response.status}).`);
 }
}

document.querySelectorAll('.js-work-async-form').forEach((form) => {
 form.addEventListener('submit', async (event) => {
  event.preventDefault();
  const button = event.submitter || form.querySelector('button[type="submit"]');
  const defaultHtml = button ? button.innerHTML : '';
  if (button) {
   button.disabled = true;
   button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  }

  const formData = new FormData(form);
  const controls = form.closest('[data-work-controls]');
  if (formData.get('action') === 'set_work_status' && controls && button?.dataset?.decisionButton) {
   const requestedStatus = String(formData.get('work_status') || '');
   const currentStatus = String(controls.dataset.workStatus || 'pending');
   if (requestedStatus !== '' && requestedStatus === currentStatus) {
    formData.set('work_status', 'pending');
   }
  }
  formData.append('ajax', '1');
  const action = formData.get('action');

  try {
   const response = await fetch('/admin/application/<?= e($application_id) ?>', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: formData,
   });
   const data = await parseJsonResponse(response);
   if (!response.ok || !data.success) {
    throw new Error(data.error || 'Операция не выполнена');
   }

   if (action === 'set_work_status' && controls) {
    controls.dataset.workStatus = data.work_status || formData.get('work_status') || controls.dataset.workStatus || 'pending';
    const card = controls.closest('.card');
    const badge = card?.querySelector('[data-work-status-badge]');
    if (badge) {
     badge.className = 'badge ' + (data.status_class || '');
     badge.textContent = data.status_label || badge.textContent;
	    }
	    const workStatus = data.work_status || formData.get('work_status') || 'pending';
	    const compliantToggle = card?.querySelector('.js-drawing-compliant-toggle');
	    const commentField = card?.querySelector('.js-drawing-comment');
	    if (compliantToggle && data.drawing_compliant !== null) {
	      compliantToggle.checked = String(data.drawing_compliant) === '1';
    }
    if (workStatus === 'accepted' && commentField) {
      commentField.value = '';
    }
    updateDecisionButtons(controls, workStatus);
    syncComplianceFormState(card, workStatus);
    syncApplicationActionState(data.application_status || 'submitted');
	    syncRevisionApplicationButtonState();
	    syncDeclineApplicationButtonState();
	    syncApproveApplicationButtonState();
	   }
	   showToast(data.message || 'Готово', 'success');
	  } catch (error) {
	   showToast(error.message || 'Не удалось выполнить действие', 'error');
	  } finally {
   if (button) {
    button.disabled = false;
    button.innerHTML = defaultHtml;
   }
  }
 });
});

function selectPriority(value) {
 document.querySelectorAll('.priority-btn').forEach((btn) => btn.classList.remove('selected'));
 const selectedBtn = document.querySelector(`.priority-btn--${value}`);
 if (selectedBtn) {
  selectedBtn.classList.add('selected');
 }
 const input = document.querySelector(`input[name="priority"][value="${value}"]`);
 if (input) {
  input.checked = true;
 }
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeMessageModal(); });
document.getElementById('messageModal').addEventListener('click', function(e) { if (e.target === this) closeMessageModal(); });
document.getElementById('messageImagePreviewModal')?.addEventListener('click', function(e) { if (e.target === this) closeMessageImagePreview(); });
document.querySelectorAll('.js-message-attachment-input').forEach(initMessageAttachmentInput);
document.querySelectorAll('.js-subject-template').forEach((button) => {
 button.addEventListener('click', () => {
  const subjectInput = document.getElementById('messageSubjectInput');
  if (subjectInput) {
   subjectInput.value = button.dataset.subject || '';
   subjectInput.focus();
  }
 });
});

async function saveDrawingCompliance(form) {
 const toggle = form.querySelector('.js-drawing-compliant-toggle');
 const comment = form.querySelector('.js-drawing-comment');
 if (!toggle) return;
 if (!toggle.checked && !String(comment?.value || '').trim()) {
  syncApproveApplicationButtonState();
  return;
 }
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
  const data = await parseJsonResponse(response);
  if (!data.success) {
   alert(data.error || 'Не удалось сохранить проверку');
  } else {
   syncApplicationActionState(data.application_status || 'submitted');
  }
 } catch (error) {
  alert('Не удалось сохранить проверку');
 }
}

function syncDrawingCommentTemplateVisibility(form) {
 const toggle = form.querySelector('.js-drawing-compliant-toggle');
 const templateWrap = form.querySelector('.js-drawing-comment-template-wrap');
 if (!toggle || !templateWrap) return;
 templateWrap.style.display = toggle.checked ? 'none' : '';
}

document.querySelectorAll('.js-drawing-compliance-form').forEach((form) => {
 const toggle = form.querySelector('.js-drawing-compliant-toggle');
 const comment = form.querySelector('.js-drawing-comment');
 const templateSelect = form.querySelector('.js-drawing-comment-template');
 const controls = form.closest('.card')?.querySelector('[data-work-controls]');
 if (toggle) {
  toggle.addEventListener('change', () => {
   if (form.dataset.locked === '1') {
    return;
   }
   syncDrawingCommentTemplateVisibility(form);
	   if (controls) {
	    controls.dataset.workStatus = toggle.checked ? 'accepted' : (String(comment?.value || '').trim() ? 'reviewed' : 'pending');
	    updateDecisionButtons(controls, controls.dataset.workStatus);
	   }
   saveDrawingCompliance(form);
   syncRevisionApplicationButtonState();
   syncDeclineApplicationButtonState();
   syncApproveApplicationButtonState();
  });
 }
 if (comment) {
  let commentTimer = null;
  comment.addEventListener('input', () => {
   if (form.dataset.locked === '1') {
    return;
   }
   const rejectCommentInput = form.closest('.card')?.querySelector('[data-reject-comment-input]');
   if (rejectCommentInput) {
    rejectCommentInput.value = comment.value;
   }
	   if (controls && toggle && !toggle.checked) {
	    controls.dataset.workStatus = comment.value.trim() ? 'reviewed' : 'pending';
	    updateDecisionButtons(controls, controls.dataset.workStatus);
	   }
   if (commentTimer) clearTimeout(commentTimer);
   commentTimer = setTimeout(() => saveDrawingCompliance(form), 500);
   syncRevisionApplicationButtonState();
   syncDeclineApplicationButtonState();
   syncApproveApplicationButtonState();
  });
 }
 if (templateSelect && comment) {
  templateSelect.addEventListener('change', () => {
   if (form.dataset.locked === '1') {
    return;
   }
   const selectedValue = String(templateSelect.value || '');
   if (selectedValue !== '__custom__') {
    comment.value = selectedValue;
    comment.dispatchEvent(new Event('input', { bubbles: true }));
   } else {
    comment.focus();
   }
   syncDrawingCommentTemplateVisibility(form);
  });
 }
 form.addEventListener('submit', (event) => event.preventDefault());
 syncDrawingCommentTemplateVisibility(form);
});


function ensureComplianceFieldsAvailable() {
 document.querySelectorAll('.js-drawing-compliance-form').forEach((form) => {
  const section = form.closest('.work-section');
  if (section) {
   section.hidden = false;
   section.style.removeProperty('display');
   section.style.removeProperty('visibility');
  }
 });
}

function syncComplianceFormState(card, workStatus) {
 const form = card?.querySelector('[data-compliance-form]');
 if (!form) return;
 const shouldLock = applicationDecisionLocked || workStatus === 'accepted' || workStatus === 'reviewed_non_competitive';
 form.dataset.locked = shouldLock ? '1' : '0';
 form.classList.toggle('is-disabled', shouldLock);
 const toggle = form.querySelector('.js-drawing-compliant-toggle');
 const comment = form.querySelector('.js-drawing-comment');
 const templateSelect = form.querySelector('.js-drawing-comment-template');
 [toggle, comment].forEach((field) => {
  if (!field) return;
  field.disabled = shouldLock;
  if (shouldLock) {
   field.setAttribute('aria-disabled', 'true');
  } else {
   field.removeAttribute('aria-disabled');
  }
 });
 if (templateSelect) {
  templateSelect.disabled = shouldLock;
  if (shouldLock) {
   templateSelect.setAttribute('aria-disabled', 'true');
  } else {
   templateSelect.removeAttribute('aria-disabled');
  }
 }
 syncDrawingCommentTemplateVisibility(form);
}

function syncApplicationActionState(applicationStatus, options = {}) {
 const normalizedStatus = String(applicationStatus || 'submitted');
 const approveButton = document.getElementById('approveApplicationButton');
 const revisionButton = document.getElementById('sendToRevisionButton');
 const declineButton = document.getElementById('declineApplicationButton');
 const approveHint = document.querySelector('.application-sidebar-hint');
 const revisionHint = document.getElementById('revisionApplicationHint');
 const shouldShowSecondaryActions = options.showSecondaryActions !== false;

 if (approveButton) {
  const isApproved = normalizedStatus === 'approved';
  approveButton.dataset.approved = isApproved ? '1' : '0';
  approveButton.dataset.applicationStatus = normalizedStatus;
  approveButton.classList.toggle('is-current', isApproved);
  approveButton.innerHTML = isApproved
   ? '<i class="fas fa-check-double"></i> Заявка принята'
   : '<i class="fas fa-check"></i> Принять заявку';
 }

 if (revisionButton) {
  revisionButton.classList.toggle('is-current', normalizedStatus === 'revision');
 }

 if (declineButton) {
  declineButton.classList.toggle('is-current', normalizedStatus === 'rejected');
 }

 if (shouldShowSecondaryActions) {
  document.querySelectorAll('.js-application-secondary-action').forEach((secondaryAction) => {
   secondaryAction.style.display = '';
  });
 }

 if (['approved', 'rejected', 'cancelled'].includes(normalizedStatus)) {
  if (approveHint) {
   approveHint.style.display = 'none';
  }
  if (revisionHint) {
   revisionHint.style.display = 'none';
  }
 }
}

function syncApproveApplicationButtonState() {
 const approveButton = document.getElementById('approveApplicationButton');
 if (!approveButton) return;
 const hint = document.querySelector('.application-sidebar-hint');
 const isApproved = approveButton.dataset.approved === '1';
 if (applicationDecisionLocked || isApproved) {
  approveButton.disabled = true;
  approveButton.setAttribute('aria-disabled', 'true');
  approveButton.setAttribute('tabindex', '-1');
  if (hint) {
   hint.style.display = 'none';
  }
  return;
 }
 const hasInvalid = Array.from(document.querySelectorAll('[data-work-controls]')).some((controls) => {
  const status = controls.dataset.workStatus || 'pending';
  return status !== 'accepted' && status !== 'reviewed_non_competitive';
 });
 approveButton.disabled = hasInvalid;
 approveButton.setAttribute('aria-disabled', hasInvalid ? 'true' : 'false');
 if (hint) {
  hint.style.display = hasInvalid ? 'block' : 'none';
 }
 if (hasInvalid) {
  approveButton.setAttribute('tabindex', '-1');
 } else {
  approveButton.removeAttribute('tabindex');
 }
}

function syncRevisionApplicationButtonState() {
 const revisionButton = document.getElementById('sendToRevisionButton');
 if (!revisionButton) return;
 const revisionHint = document.getElementById('revisionApplicationHint');
 const applicationStatus = String(document.getElementById('approveApplicationButton')?.dataset?.applicationStatus || 'submitted');
 if (applicationDecisionLocked) {
  revisionButton.disabled = true;
  revisionButton.setAttribute('aria-disabled', 'true');
  revisionButton.setAttribute('tabindex', '-1');
  if (revisionHint) {
   revisionHint.style.display = 'none';
  }
  return;
 }
 const cards = Array.from(document.querySelectorAll('[data-work-controls]'));
 const hasUndecided = cards.some((controls) => {
  const status = String(controls.dataset.workStatus || 'pending');
  const card = controls.closest('.card');
  const toggle = card?.querySelector('.js-drawing-compliant-toggle');
  const isCompliant = toggle ? toggle.checked : true;
  return status !== 'accepted' && status !== 'reviewed_non_competitive' && isCompliant;
 });
 const hasNonCompliant = cards.some((controls) => {
  const card = controls.closest('.card');
  const toggle = card?.querySelector('.js-drawing-compliant-toggle');
  return toggle ? !toggle.checked : false;
 });
 const disabled = hasUndecided || !hasNonCompliant;
 revisionButton.disabled = disabled;
 revisionButton.setAttribute('aria-disabled', disabled ? 'true' : 'false');
 if (disabled) {
  revisionButton.setAttribute('tabindex', '-1');
 } else {
  revisionButton.removeAttribute('tabindex');
 }
 if (revisionHint) {
  revisionHint.style.display = ['approved', 'rejected', 'cancelled'].includes(applicationStatus) ? 'none' : (disabled ? 'block' : 'none');
 }
}

function syncDeclineApplicationButtonState() {
 const declineButton = document.getElementById('declineApplicationButton');
 if (!declineButton) return;
 if (applicationDecisionLocked) {
  declineButton.disabled = true;
  declineButton.setAttribute('aria-disabled', 'true');
  declineButton.setAttribute('tabindex', '-1');
  return;
 }
 const cards = Array.from(document.querySelectorAll('[data-work-controls]'));
 const disabled = cards.length === 0 || cards.some((controls) => String(controls.dataset.workStatus || 'pending') !== 'reviewed_non_competitive');
 declineButton.disabled = disabled;
 declineButton.setAttribute('aria-disabled', disabled ? 'true' : 'false');
 if (disabled) {
  declineButton.setAttribute('tabindex', '-1');
 } else {
  declineButton.removeAttribute('tabindex');
 }
}

syncApplicationActionState('<?= e((string) ($application['status'] ?? 'submitted')) ?>', { showSecondaryActions: false });
syncApproveApplicationButtonState();
syncRevisionApplicationButtonState();
syncDeclineApplicationButtonState();
ensureComplianceFieldsAvailable();
	document.querySelectorAll('[data-work-controls]').forEach((controls) => {
	 updateDecisionButtons(controls, controls.dataset.workStatus || 'pending');
	 syncComplianceFormState(controls.closest('.card'), controls.dataset.workStatus || 'pending');
	});

(() => {
    const modal = document.getElementById('vkPublishPromptModal');
    const approveButton = document.getElementById('approveApplicationButton');
    if (!modal || !approveButton) return;

    const publishButton = document.getElementById('vkPublishPromptRun');
    const skipButton = document.getElementById('vkPublishPromptSkip');
    const closeButton = document.getElementById('vkPublishPromptClose');
    const closeIconButton = document.getElementById('vkPublishPromptModalClose');
    const statusBox = document.getElementById('vkPublishPromptStatus');
    const previewBox = document.getElementById('vkPublishPreview');
    const summaryBox = document.getElementById('vkPublishModalSummary');
    const openModalButton = document.getElementById('openVkPublishModalBtn');
    const applicationId = Number(approveButton.dataset.id || 0);
    const csrfToken = approveButton.dataset.csrf || '';
    let publishInProgress = false;

    const showStatus = (message, type = 'success') => {
        if (!statusBox) return;
        statusBox.className = `alert ${type === 'error' ? 'alert--error' : 'alert--success'}`;
        statusBox.style.display = 'block';
        statusBox.textContent = message;
    };

    const closeModal = () => {
        if (publishInProgress) {
            return;
        }
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };

    const setErrorState = (isErrorState) => {
        if (!publishButton || !skipButton || !closeButton) {
            return;
        }
        publishButton.classList.toggle('is-hidden', isErrorState);
        skipButton.classList.toggle('is-hidden', isErrorState);
        closeButton.classList.toggle('is-hidden', !isErrorState);
        publishButton.disabled = false;
        skipButton.disabled = false;
        closeButton.disabled = false;
    };

    if (skipButton) {
        skipButton.addEventListener('click', closeModal);
    }
    if (closeButton) {
        closeButton.addEventListener('click', closeModal);
    }
    if (closeIconButton) {
        closeIconButton.addEventListener('click', closeModal);
    }
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

    const openPublishModal = async () => {
        if (!applicationId) {
            return;
        }
        setErrorState(false);
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (previewBox) {
            previewBox.innerHTML = '<div class="text-secondary">Загрузка списка участников...</div>';
        }
        if (statusBox) {
            statusBox.style.display = 'none';
        }

        try {
            const response = await fetch(`/admin/api/get-publish-data.php?id=${applicationId}`);
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Не удалось получить данные для публикации.');
            }

            const participants = Array.isArray(data.participants) ? data.participants : [];
            const summary = data.summary || {};
            const vkStatus = data.application_vk_status || {};
            if (summaryBox) {
                let summaryText = `Всего участников: ${summary.total_items || 0} · Готово к публикации: ${summary.ready_items || 0} · Не готово: ${summary.skipped_items || 0}`;
                if (vkStatus.status_code && vkStatus.status_code !== 'not_published') {
                    summaryText += `. Ранее опубликовано: ${vkStatus.published_count || 0}, осталось: ${vkStatus.remaining_count || 0}.`;
                }
                summaryBox.textContent = summaryText;
            }
            if (previewBox) {
                if (!participants.length) {
                    previewBox.innerHTML = '<div class="text-secondary">Нет работ для публикации.</div>';
                } else {
                    previewBox.innerHTML = participants.map((item) => `
                        <div class="vk-preview-item">
                            ${item.preview_image ? `<img src="${item.preview_image}" class="vk-preview-item__thumb">` : '<div class="vk-preview-item__thumb-placeholder"><i class="fas fa-image"></i></div>'}
                            <div class="vk-preview-item__content">
                                <strong>${item.fio || 'Без имени'}</strong>
                                <span class="badge ${item.is_ready_for_publish ? 'badge--success' : 'badge--warning'}">${item.is_ready_for_publish ? 'Готово к публикации' : 'Не готово'}</span>
                                ${item.skip_reason ? `<div class="text-secondary vk-preview-item__reason">${item.skip_reason}</div>` : ''}
                            </div>
                        </div>
                    `).join('');
                }
            }
        } catch (error) {
            if (previewBox) {
                previewBox.innerHTML = '<div class="text-secondary">Данные недоступны.</div>';
            }
            showStatus(error.message || 'Ошибка загрузки данных публикации.', 'error');
        }
    };
    window.openVkPublishPromptModal = openPublishModal;

    approveButton.addEventListener('click', async () => {
        if (approveButton.disabled || !applicationId) {
            return;
        }
        if (approveButton.dataset.approved !== '1') {
            approveButton.disabled = true;
            approveButton.setAttribute('aria-disabled', 'true');
            approveButton.setAttribute('tabindex', '-1');
            try {
                const formData = new FormData();
                formData.append('action', 'approve_application');
                formData.append('csrf_token', csrfToken);
                formData.append('ajax', '1');
                const response = await fetch('/admin/application/<?= e($application_id) ?>', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                });
                const data = await parseJsonResponse(response);
                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'Не удалось принять заявку.');
                }
                syncApplicationActionState('approved', { showSecondaryActions: false });
                document.querySelectorAll('[data-work-controls]').forEach((controls) => {
                    syncComplianceFormState(controls.closest('.card'), controls.dataset.workStatus || 'pending');
                });
                syncApproveApplicationButtonState();
                syncRevisionApplicationButtonState();
                syncDeclineApplicationButtonState();
                if (!data.open_vk_publish_prompt) {
                    showToast(data.message || 'Заявка принята', 'success');
                    window.location.assign('/admin/applications');
                    return;
                }
            } catch (error) {
                approveButton.disabled = false;
                approveButton.setAttribute('aria-disabled', 'false');
                approveButton.removeAttribute('tabindex');
                showToast(error.message || 'Не удалось принять заявку', 'error');
                return;
            }
        }
        await openPublishModal();
    });
    openModalButton?.addEventListener('click', openPublishModal);

    if (publishButton) {
        publishButton.addEventListener('click', async () => {
            if (publishInProgress) {
                return;
            }
            publishInProgress = true;
            publishButton.disabled = true;
            if (skipButton) {
                skipButton.disabled = true;
            }
            showStatus('Запуск публикации и верификации через readback VK...', 'success');

            try {
                const response = await fetch('/admin/api/publish-vk.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        application_id: applicationId,
                        publication_type: 'standard',
                        csrf_token: csrfToken,
                    }),
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    showStatus(data.error || 'Не удалось выполнить публикацию.', 'error');
                    setErrorState(true);
                    return;
                }
                showStatus(`Итог: опубликовано ${data.published || 0} из ${data.total || 0}.`, 'success');
                window.location.assign('/admin/applications');
            } catch (e) {
                showStatus('Ошибка сети при публикации. Попробуйте ещё раз.', 'error');
                setErrorState(true);
            } finally {
                publishInProgress = false;
                if (!publishButton.classList.contains('is-hidden')) {
                    publishButton.disabled = false;
                }
                if (skipButton && !skipButton.classList.contains('is-hidden')) {
                    skipButton.disabled = false;
                }
            }
        });
    }

    if (<?= $showVkPublishPrompt ? 'true' : 'false' ?>) {
        openPublishModal();
    }
})();

const sendToRevisionForm = document.getElementById('sendToRevisionForm');
const sendToRevisionButton = document.getElementById('sendToRevisionButton');
if (sendToRevisionForm && sendToRevisionButton) {
 sendToRevisionForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (sendToRevisionButton.disabled) {
   return;
  }
  if (!window.confirm('Отправить заявку на корректировку?')) {
   return;
  }

  const defaultHtml = sendToRevisionButton.innerHTML;
  sendToRevisionButton.disabled = true;
  sendToRevisionButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

  try {
   const formData = new FormData(sendToRevisionForm);
   formData.append('ajax', '1');
   const response = await fetch('/admin/application/<?= e($application_id) ?>', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: formData,
   });
   const data = await parseJsonResponse(response);
   if (!response.ok || !data.success) {
    throw new Error(data.error || 'Не удалось отправить заявку на корректировку.');
   }

   sendToRevisionButton.innerHTML = defaultHtml;
   syncApplicationActionState(data.application_status || 'revision');
   syncApproveApplicationButtonState();
   syncRevisionApplicationButtonState();
   syncDeclineApplicationButtonState();
   showToast(data.message || 'Заявка отправлена на корректировку', 'success');

   if (data.open_vk_publish_prompt && typeof window.openVkPublishPromptModal === 'function') {
    await window.openVkPublishPromptModal();
   } else {
    window.location.assign('/admin/applications');
   }
  } catch (error) {
   sendToRevisionButton.disabled = false;
   sendToRevisionButton.setAttribute('aria-disabled', 'false');
   sendToRevisionButton.innerHTML = defaultHtml;
   showToast(error.message || 'Не удалось отправить заявку на корректировку', 'error');
  }
 });
}
</script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
