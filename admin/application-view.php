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
$displayPermissions = getApplicationDisplayPermissions($application, $works);
$canShowBulkDiplomaActions = (bool) ($displayPermissions['can_show_bulk_diplomas'] ?? false);
$showVkPublishPrompt = max(0, (int) ($_SESSION['vk_publish_prompt_application_id'] ?? 0)) === (int) $application_id;
unset($_SESSION['vk_publish_prompt_application_id']);
$participantColumns = $pdo->query("DESCRIBE participants")->fetchAll(PDO::FETCH_COLUMN);
$hasDrawingCompliantColumn = in_array('drawing_compliant', $participantColumns, true);
$hasDrawingCommentColumn = in_array('drawing_comment', $participantColumns, true);
$hasNonCompliantDrawings = false;
if ($hasDrawingCompliantColumn) {
    foreach ($works as $workRow) {
        if ((int)($workRow['drawing_compliant'] ?? 1) === 0) {
            $hasNonCompliantDrawings = true;
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
        redirect('/admin/applications');

    } elseif ($_POST['action'] === 'download_participant_diploma') {
        $workId = (int)($_POST['work_id'] ?? 0);
        if ($workId <= 0) {
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => 'Работа не найдена'], 422);
            }
            throw new RuntimeException('Работа не найдена');
        }
        $workContext = getWorkDiplomaContext($workId);
        if (!$workContext || (int) ($workContext['application_id'] ?? 0) !== (int) $application_id || !canShowIndividualDiplomaActions(['status' => (string) ($workContext['work_status'] ?? 'pending')])) {
            throw new RuntimeException('Для выбранной работы диплом недоступен');
        }
        $diploma = generateWorkDiploma($workId, false);
        if ($isAjaxRequest) {
            jsonResponse([
                'success' => true,
                'message' => 'Диплом сформирован и скачивается',
                'download_url' => '/' . ltrim((string) ($diploma['file_path'] ?? ''), '/'),
            ]);
        }
        $file = ROOT_PATH . '/' . $diploma['file_path'];
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="diploma_work_' . $workId . '.pdf"');
        readfile($file);
        exit;
    } elseif ($_POST['action'] === 'send_participant_diploma') {
        $workId = (int)($_POST['work_id'] ?? 0);
        $ctx = getWorkDiplomaContext($workId);
        if (!$ctx || (int) ($ctx['application_id'] ?? 0) !== (int) $application_id || !canShowIndividualDiplomaActions(['status' => (string) ($ctx['work_status'] ?? 'pending')])) {
            throw new RuntimeException('Для выбранной работы диплом недоступен');
        }
        $diploma = generateWorkDiploma($workId, false);
        sendDiplomaByEmail($ctx ?? [], $diploma);
        if ($isAjaxRequest) {
            jsonResponse(['success' => true, 'message' => 'Диплом участника отправлен']);
        }
        $_SESSION['success_message'] = 'Диплом участника отправлен';
        redirect('/admin/application/' . $application_id);
    } elseif ($_POST['action'] === 'link_participant_diploma') {
        $workId = (int)($_POST['work_id'] ?? 0);
        $ctx = getWorkDiplomaContext($workId);
        if (!$ctx || (int) ($ctx['application_id'] ?? 0) !== (int) $application_id || !canShowIndividualDiplomaActions(['status' => (string) ($ctx['work_status'] ?? 'pending')])) {
            throw new RuntimeException('Для выбранной работы диплом недоступен');
        }
        $diploma = generateWorkDiploma($workId, false);
        $_SESSION['success_message'] = 'Ссылка участника: ' . getPublicDiplomaUrl($diploma['public_token']);
        redirect('/admin/application/' . $application_id);
    } elseif ($_POST['action'] === 'set_work_status') {
        $workId = (int)($_POST['work_id'] ?? 0);
        $participantId = (int)($_POST['participant_id'] ?? findWorkParticipantId($works, $workId));
        $newStatus = (string)($_POST['work_status'] ?? 'pending');
        if ($workId <= 0 || !in_array($newStatus, ['pending', 'accepted', 'reviewed', 'reviewed_non_competitive'], true)) {
            if ($isAjaxRequest) {
                jsonResponse(['success' => false, 'error' => 'Некорректный статус работы'], 422);
            }
            $_SESSION['success_message'] = 'Некорректный статус работы';
            redirect('/admin/application/' . $application_id);
        }
        updateWorkStatus($workId, $newStatus);

        if ($participantId > 0 && $hasDrawingCompliantColumn) {
            $isCompliant = $newStatus === 'accepted' ? 1 : 0;
            if ($hasDrawingCommentColumn) {
                $pdo->prepare("
                    UPDATE participants
                    SET drawing_compliant = ?, drawing_comment = CASE WHEN ? = 1 THEN NULL ELSE drawing_comment END
                    WHERE id = ? AND application_id = ?
                ")->execute([$isCompliant, $isCompliant, $participantId, $application_id]);
            } else {
                $pdo->prepare("
                    UPDATE participants
                    SET drawing_compliant = ?
                    WHERE id = ? AND application_id = ?
                ")->execute([$isCompliant, $participantId, $application_id]);
            }
        }
        if ($isAjaxRequest) {
            jsonResponse([
                'success' => true,
                'message' => 'Статус работы обновлён',
                'work_status' => $newStatus,
                'status_label' => getWorkStatusLabel($newStatus),
                'status_class' => getWorkStatusBadgeClass($newStatus),
                'diploma_available' => mapWorkStatusToDiplomaType($newStatus) !== null,
            ]);
        }
        $_SESSION['success_message'] = 'Статус работы обновлён';
        redirect('/admin/application/' . $application_id);
    } elseif ($_POST['action'] === 'generate_all_diplomas') {
        if (!$canShowBulkDiplomaActions) {
            throw new RuntimeException('Массовые дипломные действия доступны только после принятия заявки.');
        }
        foreach ($works as $workRow) {
            if (mapWorkStatusToDiplomaType((string)($workRow['status'] ?? 'pending')) === null) {
                continue;
            }
            generateWorkDiploma((int)$workRow['id'], false);
        }
        $_SESSION['success_message'] = 'Дипломы сформированы';
        redirect('/admin/application/' . $application_id);
    } elseif ($_POST['action'] === 'generate_and_send_all_diplomas') {
        if (!$canShowBulkDiplomaActions) {
            throw new RuntimeException('Массовые дипломные действия доступны только после принятия заявки.');
        }
        foreach ($works as $workRow) {
            if (mapWorkStatusToDiplomaType((string)($workRow['status'] ?? 'pending')) === null) {
                continue;
            }
            $ctx = getWorkDiplomaContext((int)$workRow['id']);
            $diploma = generateWorkDiploma((int)$workRow['id'], false);
            sendDiplomaByEmail($ctx ?? [], $diploma);
        }
        $_SESSION['success_message'] = 'Дипломы сформированы и отправлены';
        redirect('/admin/application/' . $application_id);
    } elseif ($_POST['action'] === 'collect_all_diploma_links') {
        if (!$canShowBulkDiplomaActions) {
            throw new RuntimeException('Массовые дипломные действия доступны только после принятия заявки.');
        }
        foreach ($works as $workRow) {
            if (mapWorkStatusToDiplomaType((string)($workRow['status'] ?? 'pending')) === null) {
                continue;
            }
            generateWorkDiploma((int)$workRow['id'], false);
        }
        $links = collectApplicationDiplomaLinks((int)$application_id);
        $lines = array_map(static fn($it) => $it['participant'] . ': ' . $it['url'], $links);
        $_SESSION['success_message'] = "Ссылки:
" . implode("
", $lines);
        redirect('/admin/application/' . $application_id);
    } elseif ($_POST['action'] === 'download_zip_diplomas') {
        if (!$canShowBulkDiplomaActions) {
            throw new RuntimeException('Массовые дипломные действия доступны только после принятия заявки.');
        }
        foreach ($works as $workRow) {
            if (mapWorkStatusToDiplomaType((string)($workRow['status'] ?? 'pending')) === null) {
                continue;
            }
            generateWorkDiploma((int)$workRow['id'], false);
        }
        $zipRelative = buildApplicationDiplomaZip((int)$application_id);
        $zipFile = ROOT_PATH . '/' . $zipRelative;
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zipFile) . '"');
        readfile($zipFile);
        exit;
    } elseif ($_POST['action'] === 'approve_application') {
        if ($hasDrawingCompliantColumn) {
            $nonCompliantStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM participants
                WHERE application_id = ? AND drawing_compliant = 0
            ");
            $nonCompliantStmt->execute([$application_id]);
            if ((int)$nonCompliantStmt->fetchColumn() > 0) {
                $errorMessage = 'Нельзя принять заявку: есть работы, не соответствующие условиям конкурса.';
                if ($isAjaxRequest) {
                    jsonResponse(['success' => false, 'error' => $errorMessage], 422);
                }
                $_SESSION['error_message'] = $errorMessage;
                redirect('/admin/application/' . $application_id);
            }
        }
        $stmt = $pdo->prepare("UPDATE applications SET status = 'approved', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$application_id]);
        $application['status'] = 'approved';
        $isApplicationApproved = true;

        $workIdsStmt = $pdo->prepare("SELECT id FROM works WHERE application_id = ?");
        $workIdsStmt->execute([$application_id]);
        foreach ($workIdsStmt->fetchAll(PDO::FETCH_COLUMN) as $workId) {
            updateWorkStatus((int) $workId, 'accepted');
        }

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
        $_SESSION['vk_publish_prompt_application_id'] = (int) $application_id;
        redirect('/admin/application/' . $application_id);
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
 $participantId = intval($_POST['participant_id'] ?? 0);
 $isCompliant = isset($_POST['drawing_compliant']) ? 1 : 0;
 $workId = findParticipantWorkId($works, $participantId);
 $newWorkStatus = $isCompliant ? 'accepted' : 'reviewed';
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

 if ($workId > 0) {
     updateWorkStatus($workId, $newWorkStatus);
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
    redirect('/admin/applications');
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

<?php
$statusMeta = getApplicationDisplayMeta($application, buildApplicationWorkSummary($works));
$submittedAt = !empty($application['created_at']) ? date('d.m.Y H:i', strtotime($application['created_at'])) : '—';
$applicantName = trim((string) (($application['name'] ?? '') . ' ' . ($application['surname'] ?? ''))) ?: '—';
$paymentReceipt = trim((string) ($application['payment_receipt'] ?? ''));
$paymentReceiptName = $paymentReceipt !== '' ? basename($paymentReceipt) : '—';
$workStats = ['total' => count($works), 'accepted' => 0, 'reviewed' => 0, 'rejected' => 0];
$nonCompliantCount = 0;
foreach ($works as $workRow) {
    $status = (string) ($workRow['status'] ?? 'pending');
    if ($status === 'accepted') {
        $workStats['accepted']++;
    } elseif ($status === 'reviewed') {
        $workStats['reviewed']++;
    } elseif ($status === 'rejected') {
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
$approveButtonDisabled = $hasNonCompliantDrawings || $isApplicationApproved;
$approveButtonIcon = $isApplicationApproved ? 'fa-check-double' : 'fa-check';
$approveButtonText = $isApplicationApproved ? 'Заявка принята' : 'Принять заявку';
?>

<section class="application-hero card mb-lg">
    <div class="card__body">
        <div class="application-hero__head">
            <div>
                <h2 class="application-hero__title">Заявка #<?= e($application_id) ?></h2>
                <p class="application-hero__subtitle"><?= e($application['contest_title']) ?></p>
            </div>
            <span class="badge application-hero__status <?= $statusMeta['badge_class'] ?>"><?= e($statusMeta['label']) ?></span>
        </div>
        <div class="application-hero__meta">
            <span class="application-meta-chip"><i class="fas fa-calendar-alt"></i>Подана: <?= e($submittedAt) ?></span>
            <span class="application-meta-chip"><i class="fas fa-user"></i><?= e($applicantName) ?></span>
            <a class="application-meta-chip" href="mailto:<?= e($application['email'] ?? '') ?>"><i class="fas fa-envelope"></i><?= e($application['email'] ?: '—') ?></a>
            <span class="application-meta-chip"><i class="fas fa-images"></i>Работ: <?= (int) $workStats['total'] ?></span>
        </div>
        <div class="application-hero__actions">
            <a href="/admin/applications" class="btn btn--ghost"><i class="fas fa-arrow-left"></i> К списку</a>
            <a href="/admin/user/<?= (int) $application['user_id'] ?>" class="btn btn--secondary"><i class="fas fa-user-circle"></i> Профиль заявителя</a>
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
        <div class="alert__content"><div class="alert__message">Заявку нельзя принять: есть работы, отмеченные как несоответствующие условиям конкурса.</div></div>
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
                        <div><div class="font-semibold"><?= e($applicantName) ?></div><a href="mailto:<?= e($application['email'] ?? '') ?>" class="text-secondary"><?= e($application['email'] ?: '—') ?></a></div>
                    </div>
                    <dl class="application-kv-list"><dt>ФИО родителя/куратора</dt><dd><?= e($application['parent_fio'] ?: '—') ?></dd></dl>
                </div></article>
                <article class="card"><div class="card__body">
                    <h3 class="application-card-title">Заявка</h3>
                    <dl class="application-kv-list">
                        <dt>Номер</dt><dd>#<?= (int) $application_id ?></dd>
                        <dt>Конкурс</dt><dd><?= e($application['contest_title'] ?: '—') ?></dd>
                        <dt>Статус</dt><dd><span class="badge <?= e($statusMeta['badge_class']) ?>"><?= e($statusMeta['label']) ?></span></dd>
                        <dt>Дата подачи</dt><dd><?= e($submittedAt) ?></dd>
                    </dl>
                </div></article>
                <article class="card"><div class="card__body">
                    <h3 class="application-card-title">Организация</h3>
                    <dl class="application-kv-list">
                        <dt>Регион</dt><dd><?= e($application['organization_region'] ?: '—') ?></dd>
                        <dt>Название</dt><dd><?= e($application['organization_name'] ?: '—') ?></dd>
                        <dt>Адрес</dt><dd><?= e($application['organization_address'] ?: '—') ?></dd>
                    </dl>
                </div></article>
                <article class="card"><div class="card__body">
                    <h3 class="application-card-title">Дополнительная информация</h3>
                    <dl class="application-kv-list">
                        <dt>Источник</dt><dd><?= e($application['source_info'] ?: '—') ?></dd>
                        <dt>Коллеги</dt><dd><?= e($application['colleagues_info'] ?: '—') ?></dd>
                    </dl>
                    <div class="application-file-block">
                        <i class="fas fa-file-invoice"></i>
                        <?php if ($paymentReceipt !== ''): ?>
                            <div><strong><?= e($paymentReceiptName) ?></strong><a href="/uploads/documents/<?= e($paymentReceipt) ?>" target="_blank" class="application-file-block__link">Открыть квитанцию</a></div>
                        <?php else: ?>
                            <div><strong>Квитанция не приложена</strong></div>
                        <?php endif; ?>
                    </div>
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
                <?php if ($hasNonCompliantDrawings): ?><p class="application-summary-warning">Есть блокирующие причины для принятия всей заявки.</p><?php endif; ?>
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
                                    <img src="<?= e($drawingUrl) ?>" data-participant-id="<?= (int) ($p['participant_id'] ?? 0) ?>" class="js-admin-drawing work-card__image" alt="Рисунок участника">
                                    <button type="button" class="btn btn--secondary js-open-editor mt-sm" data-participant-id="<?= (int) ($p['participant_id'] ?? 0) ?>" data-image-src="<?= e($drawingUrl) ?>"><i class="fas fa-crop-alt"></i> Редактировать</button>
                                <?php else: ?>
                                    <div class="drawing-empty-state"><i class="fas fa-image"></i><strong>Рисунок отсутствует</strong><span>Участник ещё не загрузил файл.</span></div>
                                <?php endif; ?>
                            </div>
                            <div class="work-card__details">
                                <section class="work-section"><h4>Участник</h4><dl class="application-kv-list"><dt>ФИО</dt><dd><?= e($p['fio'] ?: '—') ?></dd><dt>Возраст</dt><dd><?= (int) ($p['age'] ?? 0) ?> лет</dd><dt>Регион</dt><dd><?= e($p['region'] ?? '—') ?></dd><dt>Название рисунка</dt><dd><?= e(trim((string) ($p['title'] ?? '')) ?: '—') ?></dd></dl></section>
                                <section class="work-section"><h4>Организация</h4><dl class="application-kv-list"><dt>Организация</dt><dd><?= e($p['organization_name'] ?? '—') ?></dd><dt>Адрес</dt><dd><?= e($p['organization_address'] ?? '—') ?></dd></dl></section>
                                <?php $isComplianceLocked = $isApplicationApproved || ((string) ($p['status'] ?? 'pending')) === 'accepted'; ?>
                                <section class="work-section"><h4>Проверка работы</h4>
                                    <form method="POST" class="js-drawing-compliance-form work-compliance-form">
                                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                        <input type="hidden" name="action" value="toggle_drawing_compliance">
                                        <input type="hidden" name="participant_id" value="<?= (int) ($p['participant_id'] ?? 0) ?>">
                                        <input type="hidden" name="ajax" value="1">
                                        <label class="ios-toggle-wrap"><span class="ios-toggle-label">Соответствует условиям конкурса</span><span class="ios-toggle"><input type="checkbox" name="drawing_compliant" value="1" class="js-drawing-compliant-toggle" <?= isset($p['drawing_compliant']) && (int)$p['drawing_compliant'] === 1 ? 'checked' : '' ?> <?= $isComplianceLocked ? 'disabled aria-disabled="true"' : '' ?>><span class="ios-toggle__slider"></span></span></label>
                                        <label class="form-label mt-sm">Что исправить</label>
                                        <textarea class="form-textarea js-drawing-comment" name="comment" rows="2" placeholder="Укажите, что нужно исправить" <?= $isComplianceLocked ? 'disabled aria-disabled="true"' : '' ?>><?= e($p['drawing_comment'] ?? '') ?></textarea>
                                    </form>
                                </section>
                                <section class="work-section"><h4>Действия по работе с дипломами</h4>
                                    <div class="work-actions" data-work-controls data-work-id="<?= (int) $p['id'] ?>">
                                        <?php $canAcceptWork = !$isApplicationApproved && ((string) ($p['status'] ?? 'pending')) !== 'accepted'; ?>
                                        <form method="POST" class="js-work-async-form" data-accept-work-form style="<?= $canAcceptWork ? '' : 'display:none;' ?>"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="set_work_status"><input type="hidden" name="work_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="participant_id" value="<?= (int) ($p['participant_id'] ?? 0) ?>"><input type="hidden" name="work_status" value="accepted"><button class="btn btn--primary btn--sm" type="submit">Принять работу</button></form>
                                        <div class="work-diploma-actions" style="display:<?= mapWorkStatusToDiplomaType((string)($p['status'] ?? 'pending')) !== null ? 'flex' : 'none' ?>;" data-diploma-actions>
                                            <form method="POST" class="js-work-async-form"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="download_participant_diploma"><input type="hidden" name="work_id" value="<?= (int)$p['id'] ?>"><button class="btn btn--primary btn--sm" type="submit">Скачать диплом</button></form>
                                            <form method="POST" class="js-work-async-form"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="send_participant_diploma"><input type="hidden" name="work_id" value="<?= (int)$p['id'] ?>"><button class="btn btn--secondary btn--sm" type="submit">Отправить на почту</button></form>
                                            <form method="POST"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="link_participant_diploma"><input type="hidden" name="work_id" value="<?= (int)$p['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">Получить ссылку</button></form>
                                        </div>
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
                <h3 class="application-card-title">Массовые действия по дипломам</h3>
                <?php if ($canShowBulkDiplomaActions): ?>
                <form method="POST" class="application-diploma-actions">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="generate_all_diplomas">
                    <select class="form-select" name="bulk_diploma_action" id="bulkDiplomaActionSelect">
                        <option value="generate_all_diplomas">Сформировать дипломы</option>
                        <option value="generate_and_send_all_diplomas">Сформировать и отправить</option>
                        <option value="collect_all_diploma_links">Получить ссылки</option>
                        <option value="download_zip_diplomas">Скачать ZIP</option>
                    </select>
                    <button type="submit" class="btn btn--primary" id="bulkDiplomaActionRun">Выполнить</button>
                </form>
                <?php else: ?>
                    <p class="text-secondary">Массовые дипломные действия доступны только после статуса «Заявка принята».</p>
                <?php endif; ?>
                <hr class="application-separator">
                <h3 class="application-card-title">Действия с заявкой</h3>
                <div class="card" style="margin-bottom: 14px;">
                    <div class="card__body" style="padding: 12px;">
                        <div style="font-weight: 600; margin-bottom: 6px;">Публикация в VK</div>
                        <div class="flex items-center gap-sm" style="margin-bottom:8px; flex-wrap:wrap;">
                            <span class="badge <?= e((string) ($applicationVkStatus['badge_class'] ?? 'badge--secondary')) ?>">
                                <?= e((string) ($applicationVkStatus['status_label'] ?? 'Не опубликована')) ?>
                            </span>
                            <span class="text-secondary" style="font-size:12px;">
                                Опубликовано <?= (int) ($applicationVkStatus['published_count'] ?? 0) ?> из <?= (int) ($applicationVkStatus['total_count'] ?? 0) ?>
                            </span>
                        </div>
                        <?php if (!empty($applicationVkStatus['last_attempt_at'])): ?>
                            <div class="text-secondary" style="font-size: 13px; line-height: 1.35; margin-bottom: 6px;">
                                Последняя попытка: <?= e(date('d.m.Y H:i', strtotime((string) $applicationVkStatus['last_attempt_at']))) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($applicationVkStatus['last_error'])): ?>
                            <div class="text-secondary" style="font-size: 13px; line-height: 1.35; margin-bottom: 6px;">
                                Ошибка: <?= e((string) $applicationVkStatus['last_error']) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($applicationVkStatus['last_post_url'])): ?>
                            <a class="btn btn--ghost btn--sm" href="<?= e((string) $applicationVkStatus['last_post_url']) ?>" target="_blank" style="margin-bottom:8px;">
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
                    <?php if (!$isApplicationApproved): ?>
                    <form method="POST" class="js-application-secondary-action" onsubmit="return confirm('Отправить заявку на корректировку?');"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="send_to_revision"><button type="submit" class="btn application-btn application-btn--warning"><i class="fas fa-edit"></i> На корректировку</button></form>
                    <form method="POST" class="js-application-secondary-action"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="decline_application"><button type="submit" class="btn application-btn application-btn--danger"><i class="fas fa-times-circle"></i> Отклонить</button></form>
                    <?php endif; ?>
                    <form method="POST" id="approveApplicationForm">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="approve_application">
                    </form>
                    <button
                        type="button"
                        class="btn application-btn application-btn--success"
                        id="approveApplicationButton"
                        data-id="<?= (int) $application_id ?>"
                        data-approved="<?= $isApplicationApproved ? '1' : '0' ?>"
                        data-csrf="<?= e(csrf_token()) ?>"
                        <?= $approveButtonDisabled ? 'disabled aria-disabled="true" tabindex="-1"' : '' ?>
                    >
                        <i class="fas <?= e($approveButtonIcon) ?>"></i> <?= e($approveButtonText) ?>
                    </button>
                    <?php if ($isApplicationApproved): ?>
                        <a href="/admin/applications" class="btn btn--ghost"><i class="fas fa-list"></i> Закрыть</a>
                    <?php endif; ?>
                </div>
                <?php if ($hasNonCompliantDrawings): ?><p class="application-sidebar-hint">Недоступно: есть работы, не соответствующие условиям конкурса.</p><?php endif; ?>
            </div>
        </div>
    </aside>
</div>

<style>
@media (max-width: 768px) {
    #vkPublishPromptModal .modal__content {
        width: calc(100vw - 20px);
        max-width: calc(100vw - 20px) !important;
        margin: 10px;
        max-height: calc(100vh - 20px);
        display: flex;
        flex-direction: column;
    }
    #vkPublishPromptModal .modal__body {
        overflow-y: auto;
    }
    #vkPublishPromptModal .modal__footer {
        flex-wrap: wrap;
    }
    #vkPublishPromptModal .modal__footer .btn {
        flex: 1 1 180px;
    }
    #vkPublishPreview {
        max-height: 42vh !important;
    }
}
</style>

<div class="modal" id="vkPublishPromptModal">
    <div class="modal__content" style="max-width:700px;">
        <div class="modal__header">
            <h3 class="modal__title">Публикация в VK</h3>
        </div>
        <div class="modal__body">
            <div id="vkPublishModalSummary" class="text-secondary" style="margin-bottom:10px;"></div>
            <div id="vkPublishPreview" style="display:grid; gap:8px; max-height:320px; overflow:auto; padding-right:4px;"></div>
            <div style="margin-top: 14px; border: 1px solid #E5E7EB; border-radius: 12px; padding: 12px;">
                <div style="font-weight: 600; margin-bottom: 8px;">Режим публикации</div>
                <div class="form-group" style="margin-bottom:8px;">
                    <label class="form-label" for="vkPublicationType">Тип публикации</label>
                    <select class="form-select" id="vkPublicationType">
                        <option value="standard">Обычная публикация</option>
                        <option value="donation_goal">Публикация с целью доната</option>
                    </select>
                </div>
                <div id="vkDonationSupportHint" class="text-secondary" style="display:none; margin-bottom:8px; font-size:13px;"></div>
                <div id="vkDonationFields" style="display:none;">
                <div class="form-group" style="margin-bottom:8px;">
                    <label class="form-label" for="vkDonationGoalSelect">Цель доната</label>
                    <select class="form-select" id="vkDonationGoalSelect">
                        <option value="">Выберите цель доната</option>
                    </select>
                </div>
                <div id="vkDonationGoalCard" style="display:none; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:10px;">
                    <div style="font-weight:600;" id="vkDonationGoalCardTitle">—</div>
                    <div class="text-secondary" style="margin-top:4px; font-size:13px;" id="vkDonationGoalCardDescription">—</div>
                    <div class="text-secondary" style="margin-top:6px; font-size:12px;">VK donate ID: <span id="vkDonationGoalCardVkId">—</span></div>
                </div>
                </div>
            </div>
            <div id="vkPublishPromptStatus" class="alert" style="display:none; margin-top:12px;"></div>
        </div>
        <div class="modal__footer" style="display:flex; justify-content:flex-end; gap:8px;">
            <button type="button" class="btn btn--primary" id="vkPublishPromptRun">Опубликовать</button>
            <button type="button" class="btn btn--secondary" id="vkPublishPromptSkip">Отмена</button>
        </div>
    </div>
</div>

<!-- Модальное окно отправки сообщения -->
<div class="modal" id="messageModal">
<div class="modal__content application-message-modal">
<div class="modal__header">
<h3>Отправить сообщение пользователю</h3>
<button type="button" class="modal__close" onclick="closeMessageModal()">&times;</button>
</div>
<form method="POST" action="/admin/application/<?= e($application_id) ?>">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="action" value="send_message">
<div class="modal__body">
<div class="application-recipient-card"><i class="fas fa-user-circle"></i><div><strong><?= e($applicantName) ?></strong><span><?= e($application['email'] ?? '—') ?></span></div></div>
<div class="form-group">
<label class="form-label">Приоритет сообщения</label>
<div class="priority-buttons">
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
<div class="form-group">
<label class="form-label">Шаблон темы</label>
<div class="application-subject-templates">
    <button type="button" class="btn btn--ghost btn--sm js-subject-template" data-subject="Уточнение по заявке #<?= (int) $application_id ?>">Уточнение</button>
    <button type="button" class="btn btn--ghost btn--sm js-subject-template" data-subject="Корректировка заявки #<?= (int) $application_id ?>">Корректировка</button>
    <button type="button" class="btn btn--ghost btn--sm js-subject-template" data-subject="Статус заявки #<?= (int) $application_id ?>">Статус заявки</button>
</div>
</div>
<div class="form-group">
<label class="form-label">Тема сообщения</label>
<input type="text" name="subject" class="form-input" id="messageSubjectInput" required placeholder="Введите тему">
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
const bulkDiplomaActionSelect = document.getElementById('bulkDiplomaActionSelect');
const bulkDiplomaActionRun = document.getElementById('bulkDiplomaActionRun');
if (bulkDiplomaActionSelect && bulkDiplomaActionRun) {
    bulkDiplomaActionRun.addEventListener('click', (event) => {
        const form = bulkDiplomaActionRun.closest('form');
        if (!form) return;
        const actionInput = form.querySelector('input[name=\"action\"]');
        if (!actionInput) return;
        actionInput.value = bulkDiplomaActionSelect.value;
    });
}

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
  const button = form.querySelector('button[type="submit"]');
  const defaultHtml = button ? button.innerHTML : '';
  if (button) {
   button.disabled = true;
   button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  }

  const formData = new FormData(form);
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

   const controls = form.closest('[data-work-controls]');
   if (action === 'set_work_status' && controls) {
    const card = controls.closest('.card');
    const badge = card?.querySelector('[data-work-status-badge]');
    if (badge) {
     badge.className = 'badge ' + (data.status_class || '');
     badge.textContent = data.status_label || badge.textContent;
    }
    const diplomaActions = controls.querySelector('[data-diploma-actions]');
    if (diplomaActions) {
      diplomaActions.style.display = data.diploma_available ? 'flex' : 'none';
    }
    const acceptForm = controls.querySelector('[data-accept-work-form]');
    if (acceptForm && formData.get('work_status') === 'accepted') {
      acceptForm.style.display = 'none';
    }

    const workStatus = formData.get('work_status');
    const compliantToggle = card?.querySelector('.js-drawing-compliant-toggle');
    if (compliantToggle && (workStatus === 'accepted' || workStatus === 'reviewed' || workStatus === 'pending')) {
      compliantToggle.checked = workStatus === 'accepted';
    }
    syncApproveApplicationButtonState();

    if (workStatus === 'accepted') {
      location.reload();
      return;
    }
   }

   if (action === 'download_participant_diploma' && data.download_url) {
    const link = document.createElement('a');
    link.href = data.download_url;
    link.download = '';
    document.body.appendChild(link);
    link.click();
    link.remove();
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
  const data = await parseJsonResponse(response);
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
  toggle.addEventListener('change', () => {
   saveDrawingCompliance(form);
   syncApproveApplicationButtonState();
  });
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

function syncApproveApplicationButtonState() {
 const approveButton = document.getElementById('approveApplicationButton');
 if (!approveButton) return;
 const isApproved = approveButton.dataset.approved === '1';
 if (isApproved) {
  approveButton.disabled = true;
  approveButton.setAttribute('aria-disabled', 'true');
  approveButton.setAttribute('tabindex', '-1');
  return;
 }
 const toggles = Array.from(document.querySelectorAll('.js-drawing-compliant-toggle'));
 const hasInvalid = toggles.some((toggle) => !toggle.checked);
 approveButton.disabled = hasInvalid;
 approveButton.setAttribute('aria-disabled', hasInvalid ? 'true' : 'false');
 const hint = document.querySelector('.application-sidebar-hint');
 if (hint) {
  hint.style.display = hasInvalid ? 'block' : 'none';
 }
 if (hasInvalid) {
  approveButton.setAttribute('tabindex', '-1');
 } else {
  approveButton.removeAttribute('tabindex');
 }
}

syncApproveApplicationButtonState();
ensureComplianceFieldsAvailable();

(() => {
    const modal = document.getElementById('vkPublishPromptModal');
    const approveButton = document.getElementById('approveApplicationButton');
    if (!modal || !approveButton) return;

    const publishButton = document.getElementById('vkPublishPromptRun');
    const skipButton = document.getElementById('vkPublishPromptSkip');
    const statusBox = document.getElementById('vkPublishPromptStatus');
    const previewBox = document.getElementById('vkPublishPreview');
    const summaryBox = document.getElementById('vkPublishModalSummary');
    const openModalButton = document.getElementById('openVkPublishModalBtn');
    const publicationTypeSelect = document.getElementById('vkPublicationType');
    const donationFields = document.getElementById('vkDonationFields');
    const donationGoalSelect = document.getElementById('vkDonationGoalSelect');
    const donationGoalCard = document.getElementById('vkDonationGoalCard');
    const donationGoalCardTitle = document.getElementById('vkDonationGoalCardTitle');
    const donationGoalCardDescription = document.getElementById('vkDonationGoalCardDescription');
    const donationGoalCardVkId = document.getElementById('vkDonationGoalCardVkId');
    const donationSupportHint = document.getElementById('vkDonationSupportHint');
    const applicationId = Number(approveButton.dataset.id || 0);
    const csrfToken = approveButton.dataset.csrf || '';
    let publishInProgress = false;
    let donationAttachmentMessage = '';
    let publicationCapabilities = {};

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
    };

    const toggleDonationFields = () => {
        const publicationType = publicationTypeSelect?.value || 'standard';
        const isDonationMode = publicationType === 'donation_goal';
        if (donationFields) {
            donationFields.style.display = isDonationMode ? 'block' : 'none';
        }
        if (!isDonationMode && donationGoalCard) {
            donationGoalCard.style.display = 'none';
        }
        const capability = publicationCapabilities[publicationType] || null;
        const capabilityMessage = capability?.message ? String(capability.message) : '';
        if (donationSupportHint) {
            const parts = [donationAttachmentMessage, capabilityMessage]
                .map((part) => String(part || '').trim())
                .filter(Boolean);
            const uniqueParts = parts.filter((part, index) => parts.indexOf(part) === index);
            const message = uniqueParts.join(' ');
            donationSupportHint.style.display = message ? 'block' : 'none';
            donationSupportHint.textContent = message;
        }
    };

    const renderDonationGoals = (goals) => {
        if (!donationGoalSelect) return;
        donationGoalSelect.innerHTML = '';
        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = 'Выберите цель доната';
        donationGoalSelect.appendChild(placeholderOption);
        if (!Array.isArray(goals) || goals.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'Нет активных целей';
            donationGoalSelect.appendChild(option);
            return;
        }

        goals.forEach((item) => {
            const option = document.createElement('option');
            option.value = String(item.id ?? '');
            option.textContent = String(item.title ?? '');
            option.dataset.description = String(item.description ?? '');
            option.dataset.vkDonateId = String(item.vk_donate_id ?? '');
            donationGoalSelect.appendChild(option);
        });
    };

    const updateDonationGoalCard = () => {
        if (!donationGoalCard || !donationGoalSelect) return;
        const selectedOption = donationGoalSelect.selectedOptions[0] || null;
        const selectedGoalId = Number(donationGoalSelect.value || 0);
        if (!selectedOption || selectedGoalId <= 0) {
            donationGoalCard.style.display = 'none';
            return;
        }
        donationGoalCard.style.display = 'block';
        if (donationGoalCardTitle) donationGoalCardTitle.textContent = selectedOption.textContent || '—';
        if (donationGoalCardDescription) donationGoalCardDescription.textContent = selectedOption.dataset.description || '—';
        if (donationGoalCardVkId) donationGoalCardVkId.textContent = selectedOption.dataset.vkDonateId || '—';
    };

    if (skipButton) {
        skipButton.addEventListener('click', closeModal);
    }

    const openPublishModal = async () => {
        if (!applicationId) {
            return;
        }
        modal.classList.add('active');
        if (previewBox) {
            previewBox.innerHTML = '<div class="text-secondary">Загрузка списка участников...</div>';
        }
        if (statusBox) {
            statusBox.style.display = 'none';
        }
        if (publicationTypeSelect) {
            publicationTypeSelect.value = 'standard';
        }
        if (donationGoalSelect) {
            donationGoalSelect.innerHTML = '';
        }
        donationAttachmentMessage = '';
        toggleDonationFields();
        updateDonationGoalCard();

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
                        <div style="display:flex; gap:10px; align-items:flex-start; border:1px solid #E5E7EB; border-radius:10px; padding:8px;">
                            ${item.preview_image ? `<img src="${item.preview_image}" style="width:52px;height:52px;border-radius:8px;object-fit:cover;">` : '<div style="width:52px;height:52px;border-radius:8px;background:#EEF2FF;display:flex;align-items:center;justify-content:center;"><i class="fas fa-image"></i></div>'}
                            <div style="display:grid; gap:4px;">
                                <strong>${item.fio || 'Без имени'}</strong>
                                <div>${item.work_title || 'Без названия'}</div>
                                <span class="badge ${item.is_ready_for_publish ? 'badge--success' : 'badge--warning'}">${item.is_ready_for_publish ? 'Готово к публикации' : 'Не готово'}</span>
                                ${item.skip_reason ? `<div class="text-secondary" style="font-size:12px;">${item.skip_reason}</div>` : ''}
                            </div>
                        </div>
                    `).join('');
                }
            }
            renderDonationGoals(data.donation_goals || []);
            publicationCapabilities = data.publication_capabilities || {};
            const support = data.donation_attachment_support || {};
            donationAttachmentMessage = String(support.message || '');
            toggleDonationFields();
            updateDonationGoalCard();
        } catch (error) {
            if (previewBox) {
                previewBox.innerHTML = '<div class="text-secondary">Данные недоступны.</div>';
            }
            showStatus(error.message || 'Ошибка загрузки данных публикации.', 'error');
        }
    };

    publicationTypeSelect?.addEventListener('change', () => {
        toggleDonationFields();
        updateDonationGoalCard();
    });
    if (donationGoalSelect) {
        donationGoalSelect.addEventListener('change', updateDonationGoalCard);
    }

    approveButton.addEventListener('click', async () => {
        if (approveButton.disabled || !applicationId) {
            return;
        }
        if (approveButton.dataset.approved !== '1') {
            approveButton.disabled = true;
            approveButton.setAttribute('aria-disabled', 'true');
            approveButton.setAttribute('tabindex', '-1');
            document.querySelectorAll('.js-application-secondary-action').forEach((secondaryAction) => {
                secondaryAction.style.display = 'none';
            });
            const approveForm = document.getElementById('approveApplicationForm');
            if (approveForm) {
                approveForm.submit();
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
            const publicationType = publicationTypeSelect?.value || 'standard';
            const donationEnabled = publicationType === 'donation_goal';
            const donationGoalId = Number(donationGoalSelect?.value || 0);
            if (donationEnabled && !donationGoalId) {
                showStatus('Нельзя включить донат без выбора цели.', 'error');
                return;
            }
            publishInProgress = true;
            publishButton.disabled = true;
            if (skipButton) {
                skipButton.disabled = true;
            }
            showStatus('Публикация запущена, подождите...', 'success');

            try {
                const response = await fetch('/admin/api/publish-vk.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        application_id: applicationId,
                        publication_type: publicationType,
                        donation_enabled: donationEnabled ? 1 : 0,
                        donation_goal_id: donationGoalId,
                        csrf_token: csrfToken,
                    }),
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    showStatus(data.error || 'Не удалось выполнить публикацию.', 'error');
                    return;
                }
                showStatus(`Итог: опубликовано ${data.published || 0} из ${data.total || 0}. Режим: ${data.publication_type || publicationType}.`, 'success');
                location.reload();
            } catch (e) {
                showStatus('Ошибка сети при публикации. Попробуйте ещё раз.', 'error');
            } finally {
                publishInProgress = false;
                publishButton.disabled = false;
                if (skipButton) {
                    skipButton.disabled = false;
                }
            }
        });
    }

    if (<?= $showVkPublishPrompt ? 'true' : 'false' ?>) {
        openPublishModal();
    }
})();
</script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
