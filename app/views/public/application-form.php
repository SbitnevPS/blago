<?php
// application-form.php - Форма заявки
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

$regions = require dirname(__DIR__, 2) . '/data/regions.php';

// Проверка авторизации
if (!isAuthenticated()) {
 redirect('/login?redirect=' . urlencode('/application-form?contest_id=' . intval($_GET['contest_id'] ?? 0)));
}

$contest_id = $_GET['contest_id'] ??0;
$contest = getContestById($contest_id);
$contestRequiresPaymentReceipt = isContestPaymentReceiptRequired($contest ?: []);

if (!$contest) {
 redirect('contests.php');
}

$user = getCurrentUser();
requireVerifiedEmailOrRedirect($user);
$error = '';
$editingApplicationId = intval($_GET['edit'] ?? ($_POST['application_id'] ?? 0));
$editingApplication = null;
$editingParticipants = [];
$formParticipants = [];
$initialFormData = [];
$postbackResolvedParticipants = [];
$postbackExistingPaymentReceipt = '';

check_csrf();
$success = '';

if ($editingApplicationId > 0) {
    $stmt = $pdo->prepare("
        SELECT * FROM applications
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$editingApplicationId, $user['id']]);
    $editingApplication = $stmt->fetch();

    if (!$editingApplication || intval($editingApplication['contest_id']) !== intval($contest_id)) {
        redirect('/my-applications');
    }

    $stmt = $pdo->prepare("SELECT * FROM participants WHERE application_id = ? ORDER BY id ASC");
    $stmt->execute([$editingApplicationId]);
    $editingParticipants = $stmt->fetchAll();

    if (!empty($editingParticipants)) {
    }

    $formParticipants = $editingParticipants;
}

$blockedContestParticipantsStmt = $pdo->prepare("
    SELECT DISTINCT
        p.id AS participant_id,
        p.fio,
        a.id AS application_id
    FROM participants p
    INNER JOIN applications a ON a.id = p.application_id
    INNER JOIN works w ON w.participant_id = p.id AND w.application_id = p.application_id
    WHERE a.user_id = ?
      AND a.contest_id = ?
      AND w.status = 'reviewed_non_competitive'
    ORDER BY p.id DESC
");
$blockedContestParticipantsStmt->execute([(int) ($user['id'] ?? 0), (int) $contest_id]);
$blockedContestParticipants = $blockedContestParticipantsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

function shouldAutoApproveApplicationAfterCorrection(int $applicationId): bool
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT status
        FROM works
        WHERE application_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$applicationId]);
    $works = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($works)) {
        return false;
    }

    $hasAccepted = false;
    foreach ($works as $work) {
        $status = (string) ($work['status'] ?? 'pending');
        if (!in_array($status, ['accepted', 'reviewed_non_competitive'], true)) {
            return false;
        }
        if ($status === 'accepted') {
            $hasAccepted = true;
        }
    }

    return $hasAccepted;
}

function normalizeParticipantFioForCompliance(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = str_replace('ё', 'е', $value);
    $value = preg_replace('/[^[:alpha:]\s]+/u', ' ', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    return trim($value);
}

function participantFioTokensForCompliance(string $fio): array
{
    $normalized = normalizeParticipantFioForCompliance($fio);
    if ($normalized === '') {
        return [];
    }

    $tokens = preg_split('/\s+/u', $normalized) ?: [];
    $tokens = array_values(array_filter(array_map(static fn($part) => trim((string) $part), $tokens), static fn($part) => $part !== ''));
    sort($tokens, SORT_STRING);

    return $tokens;
}

function normalizeFioTokenForDistance(string $token): string
{
    $token = trim($token);
    if ($token === '') {
        return '';
    }

    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $token);
    if (is_string($transliterated) && $transliterated !== '') {
        $token = $transliterated;
    }

    $token = strtolower($token);
    $token = preg_replace('/[^a-z0-9]+/', '', $token) ?? '';

    return $token;
}

function isLikelySameParticipantByFio(string $fioA, string $fioB): bool
{
    $tokensA = participantFioTokensForCompliance($fioA);
    $tokensB = participantFioTokensForCompliance($fioB);

    if (empty($tokensA) || empty($tokensB)) {
        return false;
    }

    if (implode(' ', $tokensA) === implode(' ', $tokensB)) {
        return true;
    }

    if (count($tokensA) !== count($tokensB)) {
        return false;
    }

    foreach ($tokensA as $index => $tokenA) {
        $tokenB = $tokensB[$index] ?? '';
        $distanceLeft = normalizeFioTokenForDistance($tokenA);
        $distanceRight = normalizeFioTokenForDistance($tokenB);
        if ($distanceLeft === '' || $distanceRight === '') {
            return false;
        }

        $distance = levenshtein($distanceLeft, $distanceRight);
        $maxLength = max(strlen($distanceLeft), strlen($distanceRight));
        $allowedDistance = $maxLength >= 10 ? 3 : 2;
        if ($distance > $allowedDistance) {
            return false;
        }
    }

    return true;
}

function findBlockedParticipantsInDraft(array $submittedParticipants, array $blockedParticipants): array
{
    $matches = [];

    foreach ($submittedParticipants as $candidate) {
        $candidateFio = trim((string) ($candidate['fio'] ?? ''));
        if ($candidateFio === '') {
            continue;
        }

        foreach ($blockedParticipants as $blocked) {
            $blockedFio = trim((string) ($blocked['fio'] ?? ''));
            if ($blockedFio === '') {
                continue;
            }

            if (!isLikelySameParticipantByFio($candidateFio, $blockedFio)) {
                continue;
            }

            $matches[] = [
                'fio' => $candidateFio,
                'index' => (int) ($candidate['index'] ?? 0),
                'blocked_fio' => $blockedFio,
                'blocked_participant_id' => (int) ($blocked['participant_id'] ?? 0),
                'blocked_application_id' => (int) ($blocked['application_id'] ?? 0),
            ];
            break;
        }
    }

    return $matches;
}

// Директория пользователя для рисунков
$userUploadPath = DRAWINGS_PATH . '/' . normalizeDrawingOwner($user['email'] ?? '');
$tempPath = DRAWINGS_PATH . '/temp';

// Создание директорий
if (!is_dir(DRAWINGS_PATH)) {
 mkdir(DRAWINGS_PATH,0777, true);
}
if (!is_dir(DOCUMENTS_PATH)) {
 mkdir(DOCUMENTS_PATH,0777, true);
}
if (!is_dir($userUploadPath)) {
 mkdir($userUploadPath,0777, true);
}
if (!is_dir($tempPath)) {
 mkdir($tempPath,0777, true);
}

try {
    $hasOvzColumn = (bool) $pdo->query("SHOW COLUMNS FROM participants LIKE 'has_ovz'")->fetch(PDO::FETCH_ASSOC);
    if (!$hasOvzColumn) {
        $pdo->exec("ALTER TABLE participants ADD COLUMN has_ovz TINYINT(1) NOT NULL DEFAULT 0 AFTER age");
    }
} catch (Throwable $ignored) {
}

function deleteTempDrawingArtifacts(string $tempDirectory, string $tempFile): void
{
 $tempFile = basename(trim($tempFile));
 if ($tempFile === '') {
 return;
 }

 $safeTempPath = realpath($tempDirectory);
 if ($safeTempPath === false) {
 return;
 }

 $targetFsPath = $safeTempPath . '/' . $tempFile;
 $targetRealPath = realpath($targetFsPath);

 if ($targetRealPath !== false && strpos($targetRealPath, $safeTempPath . DIRECTORY_SEPARATOR) === 0 && is_file($targetRealPath)) {
 @unlink($targetRealPath);
 }

 $pathInfo = pathinfo($tempFile);
 $thumbFile = ($pathInfo['filename'] ?? '') . '_thumb.jpg';
 $thumbFsPath = $safeTempPath . '/' . $thumbFile;
 $thumbRealPath = realpath($thumbFsPath);
 if ($thumbRealPath !== false && strpos($thumbRealPath, $safeTempPath . DIRECTORY_SEPARATOR) === 0 && is_file($thumbRealPath)) {
 @unlink($thumbRealPath);
 }
}

// Обработка AJAX загрузки изображения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_temp') {
 header('Content-Type: application/json');
    
 if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
 echo json_encode(['success' => false, 'message' => 'Ошибка безопасности']);
 exit;
 }
    
 $participantIndex = intval($_POST['participant_index'] ??0);
 $previousTempFile = (string) ($_POST['previous_temp_file'] ?? '');
    
 if (isset($_FILES['drawing']) && $_FILES['drawing']['error'] === UPLOAD_ERR_OK) {
 $file = $_FILES['drawing'];
        
 // Генерируем уникальное имя для временного файла
 $tempFilename = 'temp_' . $participantIndex . '_' . time() . '_' . bin2hex(random_bytes(4));
 $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
 // Поддерживаемые форматы
 $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'bmp'];
 if (!in_array($ext, $allowedExt)) {
 echo json_encode(['success' => false, 'message' => 'Недопустимый формат файла']);
 exit;
 }
        
 $tempFilePath = $tempPath . '/' . $tempFilename . '.' . $ext;
        
 if (move_uploaded_file($file['tmp_name'], $tempFilePath)) {
 if ($previousTempFile !== '') {
 deleteTempDrawingArtifacts($tempPath, $previousTempFile);
 }
 // Создаем превью
 $previewUrl = createThumbnail($tempFilePath, $tempPath, $tempFilename . '_thumb.jpg',630,630);
            
 echo json_encode([
 'success' => true,
 'temp_file' => basename($tempFilePath),
 'preview' => $previewUrl,
 'original_name' => $file['name'],
 'original_url' => '/uploads/drawings/temp/' . basename($tempFilePath)
 ]);
 exit;
 }
 }
    
 echo json_encode(['success' => false, 'message' => 'Ошибка загрузки файла']);
 exit;
}


// Обработка AJAX удаления временного изображения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_temp') {
 header('Content-Type: application/json');

 if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
 echo json_encode(['success' => false, 'message' => 'Ошибка безопасности']);
 exit;
 }

 $tempFile = basename((string) ($_POST['temp_file'] ?? ''));
 if ($tempFile === '') {
 echo json_encode(['success' => true]);
 exit;
 }

 deleteTempDrawingArtifacts($tempPath, $tempFile);

 echo json_encode(['success' => true]);
 exit;
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
 $isAutoSaveRequest = (int) ($_POST['autosave'] ?? 0) === 1;
 $isAjaxRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
     || $isAutoSaveRequest;
 if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
 $error = 'Ошибка безопасности. Обновите страницу.';
 if ($isAjaxRequest) {
     jsonResponse(['success' => false, 'message' => $error], 422);
 }
 } else {
 $action = $_POST['action'];
 $existingParticipantsById = [];
        
 if ($action === 'save_draft' || $action === 'submit') {
 try {
 $transactionStarted = $pdo->beginTransaction();
                $receiptFilesToDelete = [];
 $uploadedReceiptFiles = [];
 $existingParticipantsRows = [];
                
 // Данные родителя - теперь раздельно
 $parent_name = trim($_POST['parent_name'] ?? ''); // Имя
 $parent_patronymic = trim($_POST['parent_patronymic'] ?? ''); // Отчество
 $parent_surname = trim($_POST['parent_surname'] ?? ''); // Фамилия
                
 $source_info = trim($_POST['source_info'] ?? '');
 $colleagues_info = trim($_POST['colleagues_info'] ?? '');
 $recommendations_wishes = trim($_POST['recommendations_wishes'] ?? '');
                
 // Валидация ФИО
 if (empty($parent_name) || empty($parent_patronymic) || empty($parent_surname)) {
 throw new Exception('Заполните все поля ФИО родителя');
 }
                
 $parent_fio = $parent_surname . ' ' . $parent_name . ' ' . $parent_patronymic;
 $paymentReceipt = '';
 $removePaymentReceipt = (int)($_POST['remove_payment_receipt'] ?? 0) === 1;
                
 if (isset($_POST['application_id']) && !empty($_POST['application_id'])) {
 $application_id = $_POST['application_id'];
 $existingApplicationStmt = $pdo->prepare("
     SELECT id, status, allow_edit, payment_receipt
     FROM applications
     WHERE id = ? AND user_id = ?
     LIMIT 1
 ");
 $existingApplicationStmt->execute([$application_id, $user['id']]);
 $existingApplication = $existingApplicationStmt->fetch();

 if (!$existingApplication) {
 throw new Exception('Заявка для редактирования не найдена');
 }

 $existingParticipantsStmt = $pdo->prepare("
     SELECT *
     FROM participants
     WHERE application_id = ?
     ORDER BY id ASC
 ");
 $existingParticipantsStmt->execute([$application_id]);
 $existingParticipantsRows = $existingParticipantsStmt->fetchAll() ?: [];
 $existingParticipantsById = [];
 foreach ($existingParticipantsRows as $existingParticipantRow) {
     $existingParticipantsById[(int) ($existingParticipantRow['id'] ?? 0)] = $existingParticipantRow;
 }

 $paymentReceipt = trim((string)($existingApplication['payment_receipt'] ?? ''));

 if ($removePaymentReceipt && $paymentReceipt !== '') {
     $receiptFilesToDelete[] = DOCUMENTS_PATH . '/' . $paymentReceipt;
     $paymentReceipt = '';
 }

 if (isset($_FILES['payment_receipt']) && (int)($_FILES['payment_receipt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
     $receiptUpload = uploadFile($_FILES['payment_receipt'], DOCUMENTS_PATH, ['jpg', 'jpeg', 'png', 'webp', 'pdf']);
     if (empty($receiptUpload['success'])) {
         throw new Exception((string)($receiptUpload['message'] ?? 'Не удалось загрузить квитанцию'));
     }

     $oldReceipt = trim((string)($existingApplication['payment_receipt'] ?? ''));
     $paymentReceipt = (string)($receiptUpload['filename'] ?? '');
     if ($paymentReceipt !== '') {
         $uploadedReceiptFiles[] = DOCUMENTS_PATH . '/' . $paymentReceipt;
     }
     if ($oldReceipt !== '' && $oldReceipt !== $paymentReceipt) {
         $receiptFilesToDelete[] = DOCUMENTS_PATH . '/' . $oldReceipt;
     }
 } elseif (isset($_FILES['payment_receipt']) && !in_array((int)($_FILES['payment_receipt']['error'] ?? UPLOAD_ERR_NO_FILE), [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE], true)) {
     throw new Exception('Не удалось загрузить квитанцию. Попробуйте ещё раз.');
 }

 $shouldMarkAsCorrected = $action === 'submit'
     && (int) ($existingApplication['allow_edit'] ?? 0) === 1
     && !in_array(normalizeApplicationStoredStatus((string) ($existingApplication['status'] ?? 'draft')), ['approved', 'draft'], true);
 $currentStoredStatus = normalizeApplicationStoredStatus((string) ($existingApplication['status'] ?? 'draft'));
 $nextStatus = $action === 'submit'
     ? ($shouldMarkAsCorrected ? 'corrected' : 'submitted')
     : 'draft';
 $nextAllowEdit = $action === 'submit'
     ? 0
     : 1;
 $agreementDeclined = $action === 'submit' ? 0 : ((int) ($_POST['agreement_declined'] ?? 0) === 1 ? 1 : 0);
                    
 $stmt = $pdo->prepare("
	 UPDATE applications SET 
	 parent_fio = ?,
	 source_info = ?,
	 colleagues_info = ?,
	 recommendations_wishes = ?,
         payment_receipt = ?,
	 status = ?,
	 allow_edit = ?,
         agreement_declined = ?,
	 updated_at = NOW()
 WHERE id = ? AND user_id = ?
 ");
 $stmt->execute([
	 $parent_fio,
	 $source_info,
	 $colleagues_info,
	 $recommendations_wishes,
         $paymentReceipt,
	 $nextStatus,
 $nextAllowEdit,
 $agreementDeclined,
 $application_id,
 $user['id']
 ]);
                    
 } else {
                if (isset($_FILES['payment_receipt']) && (int)($_FILES['payment_receipt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $receiptUpload = uploadFile($_FILES['payment_receipt'], DOCUMENTS_PATH, ['jpg', 'jpeg', 'png', 'webp', 'pdf']);
                    if (empty($receiptUpload['success'])) {
                        throw new Exception((string)($receiptUpload['message'] ?? 'Не удалось загрузить квитанцию'));
                    }
                    $paymentReceipt = (string)($receiptUpload['filename'] ?? '');
                    if ($paymentReceipt !== '') {
                        $uploadedReceiptFiles[] = DOCUMENTS_PATH . '/' . $paymentReceipt;
                    }
                } elseif (isset($_FILES['payment_receipt']) && !in_array((int)($_FILES['payment_receipt']['error'] ?? UPLOAD_ERR_NO_FILE), [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE], true)) {
                    throw new Exception('Не удалось загрузить квитанцию. Попробуйте ещё раз.');
                }

                $stmt = $pdo->prepare("
                INSERT INTO applications (user_id, contest_id, parent_fio, source_info, colleagues_info, recommendations_wishes, payment_receipt, status, allow_edit, agreement_declined)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                $user['id'],
                $contest_id,
                $parent_fio,
                $source_info,
                $colleagues_info,
                $recommendations_wishes,
                $paymentReceipt,
                $action === 'submit' ? 'submitted' : 'draft',
                $action === 'submit' ? 0 : 1,
                $action === 'submit' ? 0 : ((int) ($_POST['agreement_declined'] ?? 0) === 1 ? 1 : 0),
                ]);
 $application_id = $pdo->lastInsertId();
 }

 if ($action === 'submit' && $contestRequiresPaymentReceipt && trim($paymentReceipt) === '') {
     throw new Exception('Приложите квитанцию или скриншот об оплате участия, чтобы отправить заявку.');
 }

$postbackExistingPaymentReceipt = trim((string) $paymentReceipt);
                
// Данные организации (общие для всех участников)
$postedOrgRegion = normalizeRegionName(trim($_POST['organization_region'] ?? ''));
$org_region = $postedOrgRegion;
if ($org_region === '') {
    $fallbackRegion = trim((string) ($existingParticipantsRows[0]['region'] ?? ($user['organization_region'] ?? '')));
    $org_region = normalizeRegionName($fallbackRegion);
}
$org_name = trim($_POST['organization_name'] ?? '');
$requestedUserType = trim((string) ($_POST['user_type'] ?? (string) ($user['user_type'] ?? 'parent')));
$userTypeOptions = getUserTypeOptions();
$user_type = array_key_exists($requestedUserType, $userTypeOptions) ? $requestedUserType : 'parent';
$org_address = $user_type === 'parent' ? '' : trim($_POST['organization_address'] ?? '');
$org_email = trim($_POST['organization_email'] ?? '');

if ($action === 'submit' && (int) ($_POST['user_agreement_signed'] ?? 0) !== 1) {
    throw new Exception('Чтобы отправить заявку, подпишите пользовательское соглашение.');
}

$pdo->prepare("
 UPDATE users
 SET name = ?, patronymic = ?, surname = ?, organization_region = ?, organization_name = ?, organization_address = ?, user_type = ?, updated_at = NOW()
 WHERE id = ?
 ")->execute([
 $parent_name,
 $parent_patronymic,
 $parent_surname,
 $org_region,
 $org_name,
 $org_address,
 $user_type,
 (int) $user['id']
 ]);
 $user['name'] = $parent_name;
 $user['patronymic'] = $parent_patronymic;
 $user['surname'] = $parent_surname;
$user['organization_region'] = $org_region;
$user['organization_name'] = $org_name;
$user['organization_address'] = $org_address;
$user['user_type'] = $user_type;
                
 // Участники
 $participants = $_POST['participants'] ?? [];
 $participant_count =0;
 $keptParticipantIds = [];
 $drawingFilesToDelete = [];
 $autosaveParticipantSync = [];
 $submittedParticipantsForCompliance = [];
                
 foreach ($participants as $pIndex => $participant) {
 $surname = trim($participant['surname'] ?? '');
 $name = trim($participant['name'] ?? '');
 $patronymic = trim($participant['patronymic'] ?? '');
 $legacyFio = trim($participant['fio'] ?? '');
 $fio = trim($surname . ' ' . $name . ' ' . $patronymic);
 if ($fio === '' && $legacyFio !== '') {
 $fio = $legacyFio;
 $parts = preg_split('/\s+/', $legacyFio);
 $surname = $parts[0] ?? '';
 $name = $parts[1] ?? '';
 $patronymic = $parts[2] ?? '';
 }
 if (empty($fio)) continue;
 $submittedParticipantsForCompliance[] = [
     'index' => (int) $pIndex,
     'fio' => $fio,
 ];
                    
 $age = intval($participant['age'] ??0);
 if ($age < 5 || $age > 17) {
 throw new Exception('Возраст участника "' . ($fio !== '' ? $fio : ('#' . ($pIndex + 1))) . '" должен быть от 5 до 17 лет.');
 }
 $hasOvz = !empty($participant['has_ovz']) ? 1 : 0;
 $workTitle = '';
 $existingParticipantId = (int) ($participant['participant_id'] ?? 0);
 $persistedExistingDrawing = '';
 if (!empty($existingParticipantsById) && $existingParticipantId > 0 && isset($existingParticipantsById[$existingParticipantId])) {
 $persistedExistingDrawing = trim((string) ($existingParticipantsById[$existingParticipantId]['drawing_file'] ?? ''));
 }
                    
 // Обработка временного файла рисунка
 $drawing_file = null;
 $tempFile = $participant['temp_file'] ?? '';
 $existingDrawing = trim($participant['existing_drawing_file'] ?? '');
                    
 if (!empty($tempFile)) {
 $tempFilePath = $tempPath . '/' . $tempFile;
 if (file_exists($tempFilePath)) {
 // Используем раздельные ФИО для названия файла
 $fileSurname = $surname !== '' ? $surname : 'Unknown';
 $fileName = $name;
 $filePatronymic = $patronymic;

 // Новое имя файла: Фамилия_Имя_Отчество_возраст.jpg
 $newFilename = sanitizeFilename($fileSurname) . '_' . 
 sanitizeFilename($fileName) . '_' . 
 sanitizeFilename($filePatronymic) . '_' . 
 intval($age) . '_' . bin2hex(random_bytes(4)) . '.jpg';

 // Сохраняем оригинал и отдельную превью-копию с тем же именем в подпапке thumb
 $savedDrawing = saveParticipantDrawingWithThumbnail($tempFilePath, $userUploadPath, $newFilename);

 if (!empty($savedDrawing['success'])) {
 $drawing_file = (string)($savedDrawing['filename'] ?? '');
 }

 // Удаляем временный файл и превью после сохранения постоянного рисунка
 deleteTempDrawingArtifacts($tempPath, $tempFile);
 }
 }
                    
 if (empty($drawing_file) && !empty($existingDrawing)) {
 $drawing_file = $existingDrawing;
 }

 if (empty($drawing_file) && !empty($persistedExistingDrawing)) {
 $drawing_file = $persistedExistingDrawing;
 }

 $postbackResolvedParticipants[(int) $pIndex] = [
 'participant_id' => $existingParticipantId > 0 ? $existingParticipantId : 0,
 'drawing_file' => (string) $drawing_file,
 ];

 if ($action === 'submit' && empty($drawing_file)) {
 throw new Exception('Загрузите рисунок для участника "' . ($fio !== '' ? $fio : ('#' . ($pIndex + 1))) . '", чтобы отправить заявку.');
 }

 $participant_id = 0;
 if (!empty($existingParticipantsById) && $existingParticipantId > 0 && isset($existingParticipantsById[$existingParticipantId])) {
 $existingParticipantRow = $existingParticipantsById[$existingParticipantId];
 $oldDrawingFile = trim((string) ($existingParticipantRow['drawing_file'] ?? ''));
 if ($oldDrawingFile !== '' && $drawing_file !== '' && $oldDrawingFile !== $drawing_file) {
     $drawingFilesToDelete[] = getParticipantDrawingFsPath($user['email'] ?? '', $oldDrawingFile);
     $drawingFilesToDelete[] = getParticipantDrawingThumbFsPath($user['email'] ?? '', $oldDrawingFile);
 }
 $stmt = $pdo->prepare("
 UPDATE participants
 SET fio = ?, age = ?, has_ovz = ?, region = ?, organization_name = ?, organization_address = ?, organization_email = ?, drawing_file = ?
 WHERE id = ? AND application_id = ?
 ");
 $stmt->execute([
 $fio,
 $age,
 $hasOvz,
 $org_region,
 $org_name,
 $org_address,
 $org_email,
 $drawing_file,
 $existingParticipantId,
 $application_id
 ]);
 $participant_id = $existingParticipantId;
 $keptParticipantIds[] = $participant_id;
 } else {
 $stmt = $pdo->prepare("
 INSERT INTO participants (
 application_id, fio, age, has_ovz, region, organization_name, organization_address,
 organization_email, drawing_file
 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
 ");
 $stmt->execute([
 $application_id,
 $fio,
 $age,
 $hasOvz,
 $org_region,
 $org_name,
 $org_address,
 $org_email,
 $drawing_file
 ]);
 $participant_id = (int)$pdo->lastInsertId();
 }

 if ($participant_id > 0) {
 try {
     assignParticipantPublicNumber($participant_id, (int) $contest_id);
 } catch (Throwable $ignored) {
 }
 try {
 $workStmt = $pdo->prepare("
 INSERT INTO works (contest_id, application_id, participant_id, title, image_path, status, created_at, updated_at)
 VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW())
 ON DUPLICATE KEY UPDATE
 contest_id = VALUES(contest_id),
 application_id = VALUES(application_id),
 title = COALESCE(NULLIF(VALUES(title), ''), works.title),
 image_path = VALUES(image_path),
 updated_at = NOW()
 ");
 $workStmt->execute([
 (int)$contest_id,
 (int)$application_id,
 $participant_id,
 $workTitle !== '' ? $workTitle : ('Рисунок участника #' . $participant_id),
 (string)$drawing_file
 ]);
 } catch (Throwable $ignored) {
 }
 }
                    
 $participant_count++;

 if ($isAutoSaveRequest) {
 $autosaveParticipantSync[] = [
 'index' => (int) $pIndex,
 'participant_id' => (int) $participant_id,
 'drawing_file' => (string) $drawing_file,
 ];
 }
 }

 if (!empty($existingParticipantsById)) {
 $participantIdsToDelete = array_diff(array_keys($existingParticipantsById), $keptParticipantIds);
 if (!empty($participantIdsToDelete)) {
     foreach ($participantIdsToDelete as $participantIdToDelete) {
         $participantRow = $existingParticipantsById[(int) $participantIdToDelete] ?? null;
         $drawingFileToDelete = trim((string) ($participantRow['drawing_file'] ?? ''));
         if ($drawingFileToDelete !== '') {
             $drawingFilesToDelete[] = getParticipantDrawingFsPath($user['email'] ?? '', $drawingFileToDelete);
             $drawingFilesToDelete[] = getParticipantDrawingThumbFsPath($user['email'] ?? '', $drawingFileToDelete);
         }
     }
     $deletePlaceholders = implode(',', array_fill(0, count($participantIdsToDelete), '?'));
     $deleteParams = array_merge([(int) $application_id], array_map('intval', array_values($participantIdsToDelete)));
     $pdo->prepare("
         DELETE FROM participants
         WHERE application_id = ?
           AND id IN ($deletePlaceholders)
     ")->execute($deleteParams);
 }
 }

 if ($action === 'submit' && $participant_count === 0) {
 throw new Exception('Добавьте хотя бы одного участника с рисунком, чтобы отправить заявку.');
 }

 if ($action === 'submit') {
 $blockedInDraft = findBlockedParticipantsInDraft($submittedParticipantsForCompliance, $blockedContestParticipants);
 if (!empty($blockedInDraft)) {
     $blockedNames = array_values(array_unique(array_map(static fn($row) => trim((string) ($row['fio'] ?? '')), $blockedInDraft)));
     $blockedLabel = implode(', ', array_filter($blockedNames, static fn($name) => $name !== ''));
     throw new Exception(
         'Обнаружены участники, ранее нарушившие пользовательское соглашение в этом конкурсе: '
         . $blockedLabel
         . '. Удалите этих участников из заявки, после этого отправка станет доступна.'
     );
 }
 }

 if (!empty($application_id)) {
 $pdo->prepare("
 UPDATE application_corrections ac
 LEFT JOIN participants p
   ON p.id = ac.participant_id
  AND p.application_id = ac.application_id
 SET ac.is_resolved = 1,
     ac.resolved_at = NOW()
 WHERE ac.application_id = ?
   AND ac.participant_id IS NOT NULL
   AND ac.is_resolved = 0
   AND p.id IS NULL
 ")->execute([$application_id]);
 }

 if ($shouldMarkAsCorrected && shouldAutoApproveApplicationAfterCorrection((int) $application_id)) {
 $pdo->prepare("
 UPDATE applications
 SET status = 'approved', allow_edit = 0, updated_at = NOW()
 WHERE id = ? AND user_id = ?
 ")->execute([(int) $application_id, (int) $user['id']]);
 }
                
 if ($transactionStarted && $pdo->inTransaction()) {
 $pdo->commit();
 }
 foreach ($drawingFilesToDelete as $drawingFilePath) {
 if (is_string($drawingFilePath) && $drawingFilePath !== '' && is_file($drawingFilePath)) {
 @unlink($drawingFilePath);
 }
 }
 foreach ($receiptFilesToDelete as $receiptFilePath) {
 if (is_file($receiptFilePath)) {
 @unlink($receiptFilePath);
 }
 }
                
 if ($action === 'submit') {
 $show_success_modal = true;
 $success_application_id = $application_id;
 $success_participant_count = $participant_count;
 } else {
 if ($isAjaxRequest) {
     jsonResponse([
         'success' => true,
         'message' => 'Черновик сохранён',
         'application_id' => (int) $application_id,
         'participant_count' => (int) $participant_count,
         'participant_sync' => $isAutoSaveRequest ? $autosaveParticipantSync : [],
     ]);
 }
 $_SESSION['success_message'] = 'Заявка сохранена как черновик';
 redirect('/my-applications');
 }
                
 } catch (Exception $e) {
 if (($transactionStarted ?? false) && $pdo->inTransaction()) {
 $pdo->rollBack();
 }
 $error = 'Ошибка при сохранении заявки: ' . $e->getMessage();
 if ($isAjaxRequest) {
     jsonResponse(['success' => false, 'message' => $error], 422);
 }
 }
 }
 }
}

if ($editingApplication) {
    $parentParts = preg_split('/\s+/', trim($editingApplication['parent_fio'] ?? ''));
    $initialFormData = [
        'parent_surname' => $parentParts[0] ?? ($user['surname'] ?? ''),
        'parent_name' => $parentParts[1] ?? ($user['name'] ?? ''),
        'parent_patronymic' => $parentParts[2] ?? ($user['patronymic'] ?? ''),
        'organization_region' => $editingParticipants[0]['region'] ?? ($user['organization_region'] ?? ''),
        'organization_name' => $editingParticipants[0]['organization_name'] ?? ($user['organization_name'] ?? ''),
        'organization_address' => $editingParticipants[0]['organization_address'] ?? ($user['organization_address'] ?? ''),
        'user_type' => $user['user_type'] ?? 'parent',
	        'organization_email' => $editingParticipants[0]['organization_email'] ?? ($user['email'] ?? ''),
	        'source_info' => $editingApplication['source_info'] ?? '',
	        'colleagues_info' => $editingApplication['colleagues_info'] ?? '',
	        'recommendations_wishes' => $editingApplication['recommendations_wishes'] ?? '',
	    ];
} else {
    $initialFormData = [
        'parent_surname' => $user['surname'] ?? '',
        'parent_name' => $user['name'] ?? '',
        'parent_patronymic' => $user['patronymic'] ?? '',
        'organization_region' => $user['organization_region'] ?? '',
        'organization_name' => $user['organization_name'] ?? '',
        'organization_address' => $user['organization_address'] ?? '',
        'user_type' => $user['user_type'] ?? 'parent',
	        'organization_email' => $user['email'] ?? '',
	        'source_info' => '',
	        'colleagues_info' => '',
	        'recommendations_wishes' => '',
	    ];
}

$existingPaymentReceipt = trim((string)($editingApplication['payment_receipt'] ?? ''));

if ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $initialFormData = [
        'parent_surname' => trim((string) ($_POST['parent_surname'] ?? '')),
        'parent_name' => trim((string) ($_POST['parent_name'] ?? '')),
        'parent_patronymic' => trim((string) ($_POST['parent_patronymic'] ?? '')),
        'organization_region' => normalizeRegionName(trim((string) ($_POST['organization_region'] ?? ''))),
        'organization_name' => trim((string) ($_POST['organization_name'] ?? '')),
        'organization_address' => trim((string) ($_POST['organization_address'] ?? '')),
        'user_type' => trim((string) ($_POST['user_type'] ?? ($initialFormData['user_type'] ?? 'parent'))),
        'organization_email' => trim((string) ($_POST['organization_email'] ?? '')),
        'source_info' => trim((string) ($_POST['source_info'] ?? '')),
        'colleagues_info' => trim((string) ($_POST['colleagues_info'] ?? '')),
        'recommendations_wishes' => trim((string) ($_POST['recommendations_wishes'] ?? '')),
    ];

    $rawParticipants = $_POST['participants'] ?? [];
    $formParticipants = [];

    foreach ($rawParticipants as $pIndex => $participant) {
        $surname = trim((string) ($participant['surname'] ?? ''));
        $name = trim((string) ($participant['name'] ?? ''));
        $patronymic = trim((string) ($participant['patronymic'] ?? ''));
        $legacyFio = trim((string) ($participant['fio'] ?? ''));
        if ($surname === '' && $name === '' && $patronymic === '' && $legacyFio !== '') {
            $parts = preg_split('/\s+/u', $legacyFio) ?: [];
            $surname = trim((string) ($parts[0] ?? ''));
            $name = trim((string) ($parts[1] ?? ''));
            $patronymic = trim((string) ($parts[2] ?? ''));
        }

        $participantId = (int) ($participant['participant_id'] ?? 0);
        $resolvedDrawing = trim((string) ($postbackResolvedParticipants[(int) $pIndex]['drawing_file'] ?? ($participant['existing_drawing_file'] ?? '')));
        if ($resolvedDrawing === '' && $participantId > 0 && isset($existingParticipantsById[$participantId])) {
            $resolvedDrawing = trim((string) ($existingParticipantsById[$participantId]['drawing_file'] ?? ''));
        }

        $tempFile = basename(trim((string) ($participant['temp_file'] ?? '')));
        $previewUrl = null;
        $fullPreviewUrl = null;
        if ($resolvedDrawing !== '') {
            $previewUrl = getParticipantDrawingPreviewWebPath($user['email'] ?? '', $resolvedDrawing);
            $fullPreviewUrl = getParticipantDrawingWebPath($user['email'] ?? '', $resolvedDrawing);
        } elseif ($tempFile !== '') {
            $tempBaseName = pathinfo($tempFile, PATHINFO_FILENAME);
            $previewUrl = '/uploads/drawings/temp/' . $tempBaseName . '_thumb.jpg';
            $fullPreviewUrl = '/uploads/drawings/temp/' . $tempFile;
        }

        $formParticipants[] = [
            'fio' => trim($surname . ' ' . $name . ' ' . $patronymic),
            'surname' => $surname,
            'name' => $name,
            'patronymic' => $patronymic,
            'age' => $participant['age'] ?? '',
            'has_ovz' => !empty($participant['has_ovz']),
            'participant_id' => $participantId > 0 ? $participantId : 0,
            'temp_file' => $tempFile,
            'existing_drawing_file' => $resolvedDrawing,
            'preview' => $previewUrl,
            'full_preview' => $fullPreviewUrl,
        ];
    }

    $existingPaymentReceipt = trim((string) ($postbackExistingPaymentReceipt !== '' ? $postbackExistingPaymentReceipt : ($_POST['existing_payment_receipt'] ?? $existingPaymentReceipt)));
}

$editingApplicationStatus = $editingApplication
    ? normalizeApplicationStoredStatus((string) ($editingApplication['status'] ?? 'draft'))
    : 'draft';
$canAutoSaveCurrentApplication = !isset($show_success_modal)
    && (
        !$editingApplication
        || $editingApplicationStatus === 'draft'
        || (int) ($editingApplication['allow_edit'] ?? 0) === 1
    );

$hasExistingPaymentReceipt = $existingPaymentReceipt !== '';
$existingPaymentReceiptUrl = $hasExistingPaymentReceipt ? getPaymentReceiptWebPath($existingPaymentReceipt) : '';
$existingPaymentReceiptIsImage = $hasExistingPaymentReceipt && preg_match('/\.(jpg|jpeg|png|webp)$/i', $existingPaymentReceipt);
$paymentStepNumber = $contestRequiresPaymentReceipt ? 3 : null;
$agreementStepNumber = $contestRequiresPaymentReceipt ? 4 : 3;
$reviewStepNumber = $contestRequiresPaymentReceipt ? 5 : 4;

generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(sitePageTitle('Заявка на конкурс'), ENT_QUOTES, 'UTF-8') ?></title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="container application-wizard-page">
    <div class="flex items-center gap-md mb-lg application-wizard-page__back">
        <a href="/contest/<?= e($contest_id) ?>" class="btn btn--ghost"><i class="fas fa-arrow-left"></i> Назад</a>
    </div>

    <section class="application-hero mb-lg">
        <div class="application-hero__inner">
            <div class="application-hero__eyebrow"><i class="fas fa-bolt"></i> Онлайн-подача</div>
            <h1 class="application-hero__title"><?= e($contest['title']) ?></h1>
            <div class="application-prep-grid">
                <div class="application-prep-item">👤 Данные участника</div>
                <div class="application-prep-item">🖼 Отдельный рисунок</div>
                <?php if ($contestRequiresPaymentReceipt): ?>
                    <div class="application-prep-item">🧾 Квитанция или скриншот оплаты</div>
                <?php else: ?>
                    <div class="application-prep-item">⏱ 3–5 минут</div>
                <?php endif; ?>
                <div class="application-prep-item">✅ Отправка онлайн</div>
            </div>
        </div>
    </section>

    <?php if ($contestRequiresPaymentReceipt): ?>
        <section class="payment-receipt-note mb-lg" aria-label="Информация об оплате">
            <div class="payment-receipt-note__icon"><i class="fas fa-receipt"></i></div>
            <div>
                <h2 class="payment-receipt-note__title">Для этого конкурса нужна квитанция об оплате</h2>
                <p class="payment-receipt-note__text">На одном из шагов мы попросим приложить фото, скриншот или PDF-квитанцию. Без этого кнопка отправки заявки останется неактивной.</p>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert--error mb-lg">
            <i class="fas fa-exclamation-circle alert__icon"></i>
            <div class="alert__content"><div class="alert__message"><?= htmlspecialchars($error) ?></div></div>
        </div>
    <?php endif; ?>

    <div class="application-layout">
        <div class="application-main">
            <form method="POST" enctype="multipart/form-data" id="applicationForm" novalidate>
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="submit" id="formAction">
                <input type="hidden" name="application_id" value="<?= $editingApplication ? intval($editingApplication['id']) : '' ?>">
                <input type="hidden" name="agreement_declined" id="agreementDeclined" value="<?= (int) ($editingApplication['agreement_declined'] ?? 0) === 1 ? '1' : '0' ?>">
                <input type="hidden" name="existing_payment_receipt" id="existingPaymentReceipt" value="<?= e($existingPaymentReceipt) ?>">
                <input type="hidden" name="remove_payment_receipt" id="removePaymentReceipt" value="0">
                <input type="hidden" name="user_type" id="applicationUserType" value="<?= e((string) ($initialFormData['user_type'] ?? 'parent')) ?>">
                <input type="hidden" name="user_agreement_signed" id="userAgreementSigned" value="0">
                <input type="hidden" name="current_step" id="currentStepInput" value="<?= max(1, min((int) ($_POST['current_step'] ?? 1), (int) $reviewStepNumber)) ?>">

                <section class="wizard-progress card mb-lg">
                    <div class="card__body">
                        <div class="wizard-progress__head">
                            <strong id="mobileStepLabel">Шаг 1 из <?= (int) $reviewStepNumber ?></strong>
                            <span id="progressPercent">25%</span>
                        </div>
                        <div class="wizard-progress__bar"><span id="progressBar"></span></div>
                        <div class="wizard-steps" id="wizardSteps"></div>
                    </div>
                </section>

                <section class="wizard-step card mb-lg" data-step="1">
                    <div class="card__header"><h3>Шаг 1. Данные заявителя</h3></div>
                    <div class="card__body">
                        <div class="form-grid form-grid--3">
                            <div class="form-group">
                                <label class="form-label form-label--required">Имя</label>
                                <input type="text" name="parent_name" class="form-input" required placeholder="Например: Анна" value="<?= htmlspecialchars($initialFormData['parent_name']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label form-label--required">Отчество</label>
                                <input type="text" name="parent_patronymic" class="form-input" required placeholder="Например: Сергеевна" value="<?= htmlspecialchars($initialFormData['parent_patronymic']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label form-label--required">Фамилия</label>
                                <input type="text" name="parent_surname" class="form-input" required placeholder="Например: Иванова" value="<?= htmlspecialchars($initialFormData['parent_surname']) ?>">
                            </div>
                        </div>
                        <div class="form-grid form-grid--2">
                            <div class="form-group">
                                <label class="form-label">Регион</label>
                                <select name="organization_region" class="form-select" id="orgRegion">
                                    <option value="">Выберите регион</option>
                                    <?php foreach ($regions as $r): ?>
                                        <?php $regionValue = normalizeRegionName((string) $r); ?>
                                        <option value="<?= e($regionValue) ?>" <?= ($initialFormData['organization_region'] ?? '') === $regionValue ? 'selected' : '' ?>><?= e(getRegionSelectLabel((string) $r)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email организации</label>
                                <input type="email" name="organization_email" class="form-input" id="orgEmail" value="<?= htmlspecialchars($initialFormData['organization_email']) ?>" placeholder="school@example.ru">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Название образовательного учреждения</label>
                                <input type="text" name="organization_name" class="form-input" id="orgName" placeholder="Например: ДШИ №1" value="<?= htmlspecialchars($initialFormData['organization_name']) ?>">
                            </div>
                            <div class="form-group" id="orgAddressGroup" <?= (($initialFormData['user_type'] ?? 'parent') === 'parent') ? 'style="display:none;"' : '' ?>>
                                <label class="form-label">Контактная информация организации</label>
                                <textarea name="organization_address" class="form-textarea" rows="2" id="orgAddress" placeholder="Город, улица, дом"><?= htmlspecialchars($initialFormData['organization_address']) ?></textarea>
                            </div>
                        </div>
                        <div class="form-section form-section--boxed">
                            <h4 class="form-section__title">Дополнительная информация</h4>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label" for="sourceInfo">Поделитесь, пожалуйста, откуда вы узнали о конкурсе?</label>
                                    <textarea
                                        name="source_info"
                                        class="form-textarea"
                                        id="sourceInfo"
                                        rows="2"
                                        placeholder="Например: сайт конкурса, соцсети, рассылка, знакомые"
                                    ><?= htmlspecialchars($initialFormData['source_info']) ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="colleaguesInfo">Рассказывали ли вы о конкурсе своим коллегам и знакомым из других организаций? Если да, укажите, пожалуйста, примерное количество</label>
                                    <textarea
                                        name="colleagues_info"
                                        class="form-textarea"
                                        id="colleaguesInfo"
                                        rows="3"
                                        placeholder="Например: Да, примерно 5-7 коллегам"
                                    ><?= htmlspecialchars($initialFormData['colleagues_info']) ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="recommendationsWishes">Пожелания и рекомендации</label>
                                    <textarea
                                        name="recommendations_wishes"
                                        class="form-textarea"
                                        id="recommendationsWishes"
                                        rows="3"
                                        placeholder="Если хотите, напишите пожелания по конкурсу или рекомендации"
                                    ><?= htmlspecialchars($initialFormData['recommendations_wishes']) ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="wizard-step card mb-lg" data-step="2" hidden>
                    <div class="card__header">
                        <h3>Шаг 2. Участники</h3>
                    </div>
                    <div class="card__body">
                        <div id="participantsEmpty" class="empty-state" style="display:none;">
                            <div class="empty-state__icon"><i class="fas fa-users"></i></div>
                            <div class="empty-state__title">Добавьте первого участника</div>
                            <div class="empty-state__text">В карточке участника сразу заполняются данные и загружается рисунок.</div>
                        </div>
                        <div id="participantsContainer"></div>
                    </div>
                </section>

                <?php if ($contestRequiresPaymentReceipt): ?>
                <section class="wizard-step card mb-lg" data-step="<?= (int) $paymentStepNumber ?>" hidden>
                    <div class="card__header"><h3>Шаг 3. Оплата участия</h3></div>
                    <div class="card__body">
                        <div class="payment-receipt-grid">
                            <article class="payment-receipt-card payment-receipt-card--info">
                                <div class="payment-receipt-card__head">
                                    <span class="payment-receipt-card__badge">Обязательно</span>
                                    <h4>Что нужно приложить</h4>
                                </div>
                                <p class="payment-receipt-card__text">Подойдёт фотография бумажной квитанции, скриншот банковского приложения или PDF-файл с подтверждением оплаты участия в конкурсе.</p>
                                <ul class="payment-receipt-card__list">
                                    <li>Форматы: JPG, JPEG, PNG, WEBP или PDF.</li>
                                    <li>Файл должен быть читаемым: видны сумма, дата и назначение платежа.</li>
                                    <li>Отправка заявки станет доступна только после прикрепления квитанции.</li>
                                </ul>
                            </article>

                            <article class="payment-receipt-card">
                                <div class="payment-receipt-card__head">
                                    <span class="payment-receipt-card__badge payment-receipt-card__badge--neutral">Шаг для отправки</span>
                                    <h4>Загрузка квитанции</h4>
                                </div>
                                <div class="upload-area payment-receipt-upload <?= $hasExistingPaymentReceipt ? 'has-file' : '' ?>" id="paymentReceiptUploadArea">
                                    <input type="file" name="payment_receipt" id="paymentReceiptInput" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" class="file-upload__input">
                                    <div class="upload-area__icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                    <div class="upload-area__title" id="paymentReceiptUploadTitle"><?= $hasExistingPaymentReceipt ? 'Квитанция уже прикреплена' : 'Нажмите или перетащите файл квитанции' ?></div>
                                    <div class="upload-area__hint" id="paymentReceiptUploadHint">JPG, PNG, WEBP или PDF до 10MB</div>
                                </div>
                                <div class="payment-receipt-preview <?= $hasExistingPaymentReceipt ? 'visible' : '' ?>" id="paymentReceiptPreview">
                                    <div class="payment-receipt-preview__visual" id="paymentReceiptPreviewVisual">
                                        <?php if ($hasExistingPaymentReceipt): ?>
                                            <?php if ($existingPaymentReceiptIsImage): ?>
                                                <img src="<?= e($existingPaymentReceiptUrl) ?>" alt="Квитанция об оплате" class="payment-receipt-preview__image" id="paymentReceiptPreviewImage">
                                            <?php else: ?>
                                                <div class="payment-receipt-preview__file-icon" id="paymentReceiptPreviewImage"><i class="fas fa-file-pdf"></i></div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="payment-receipt-preview__meta">
                                        <strong id="paymentReceiptFileName"><?= $hasExistingPaymentReceipt ? e(basename($existingPaymentReceipt)) : 'Файл ещё не выбран' ?></strong>
                                        <span id="paymentReceiptPreviewText"><?= $hasExistingPaymentReceipt ? 'Файл уже прикреплён к этой заявке.' : 'После выбора файла здесь появится карточка квитанции.' ?></span>
                                    </div>
                                    <div class="drawing-preview__actions">
                                        <button type="button" class="drawing-preview__action" id="paymentReceiptViewBtn" <?= $hasExistingPaymentReceipt ? '' : 'disabled' ?>>Посмотреть</button>
                                        <button type="button" class="drawing-preview__action drawing-preview__action--danger" id="paymentReceiptRemoveBtn" <?= $hasExistingPaymentReceipt ? '' : 'disabled' ?>>Убрать</button>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </div>
                </section>
                <?php endif; ?>

                <section class="wizard-step card mb-lg" data-step="<?= (int) $agreementStepNumber ?>" hidden>
                    <div class="card__header"><h3>Шаг <?= (int) $agreementStepNumber ?>. Подписание пользовательского соглашения</h3></div>
                    <div class="card__body">
                        <div class="user-agreement">
                            <p>Я подтверждаю, что направленные на участие в Конкурсе рисунки созданы самостоятельно (своеручно) указанными участниками, без помощи третьих лиц (в том числе взрослых), без копирования или срисовки с готовых изображений, а также без использования компьютерных программ и технологий.</p>
                            <p>Я также подтверждаю, что получено согласие на обработку персональных данных от всех участников либо их законных представителей в соответствии с действующим законодательством Российской Федерации.</p>
                            <p>Мне известно и понятно, что подача на Конкурс работ, созданных полностью или частично не заявленными участниками, является нарушением условий Конкурса и может быть расценена как введение в заблуждение или мошенничество в соответствии с действующим законодательством Российской Федерации.</p>
                            <p>В случае несоблюдения указанных требований, а также при выявлении фактов нарушения правил Конкурса, представленная работа подлежит отклонению без уведомления и без права повторного участия.</p>
                        </div>
                        <div class="user-agreement__actions">
                            <button type="button" class="btn user-agreement__choice-btn user-agreement__choice-btn--accept" id="signAgreementBtn" aria-pressed="false">Подписать пользовательское соглашение!</button>
                            <button type="submit" class="btn user-agreement__choice-btn user-agreement__choice-btn--decline" id="declineAgreementBtn" data-form-action="save_draft" aria-pressed="false">Я не принимаю условия.</button>
                        </div>
                        <div class="user-agreement__hint" id="agreementSignedHint" hidden>Теперь Вы можете продолжить заполенени заявки!</div>
                    </div>
                </section>

                <section class="wizard-step card mb-lg" data-step="<?= (int) $reviewStepNumber ?>" hidden>
                    <div class="card__header"><h3>Шаг <?= (int) $reviewStepNumber ?>. Проверка и отправка</h3></div>
                    <div class="card__body" id="reviewContainer"></div>
                </section>

                <div class="wizard-nav mb-lg">
                    <div class="wizard-nav__top" id="wizardParticipantAction" hidden>
                        <button type="button" class="btn btn--secondary btn--lg" id="addParticipantBtn"><i class="fas fa-plus"></i> Добавить ещё одного участника</button>
                    </div>
                    <div class="wizard-nav__actions">
                        <button type="button" class="btn btn--primary" id="prevStepBtn" disabled><i class="fas fa-arrow-left"></i> Назад</button>
                        <button type="submit" class="btn btn--secondary" id="saveDraftBtn" data-form-action="save_draft"><i class="fas fa-save"></i> Сохранить черновик</button>
                        <button type="button" class="btn btn--primary" id="nextStepBtn">Далее <i class="fas fa-arrow-right"></i></button>
                        <button type="submit" class="btn btn--primary" id="submitBtn" data-form-action="submit" disabled><i class="fas fa-paper-plane"></i> Отправить заявку</button>
                    </div>
                </div>
            </form>
        </div>

        <aside class="application-sidebar card">
            <div class="card__body">
                <div class="application-sidebar__eyebrow">Состояние формы</div>
                <h4 class="application-sidebar__title">Прогресс заявки</h4>
                <p class="application-sidebar__subtitle">Панель обновляется автоматически по мере заполнения шагов.</p>
                <div class="sidebar-stat" id="statParticipants">Участников: 0</div>
                <div class="sidebar-stat" id="statDrawings">Рисунков: 0</div>
                <?php if ($contestRequiresPaymentReceipt): ?>
                    <div class="sidebar-stat" id="statReceipt">Квитанция: не добавлена</div>
                <?php endif; ?>
                <div class="sidebar-stat" id="statConfidence">Заполнено: 0%</div>
            </div>
        </aside>
    </div>
</main>

<div class="modal modal--success" id="successModal">
    <div class="modal__content">
        <div class="modal__icon"><i class="fas fa-check"></i></div>
        <div class="modal__body">
            <h2>Заявка успешно отправлена!</h2>
            <p class="text-secondary mt-md">Количество участников: <strong><?= $success_participant_count ??0 ?></strong></p>
            <button class="btn btn--primary btn--lg mt-xl" onclick="goToMyApplications()" style="width:100%;">К моим заявкам</button>
        </div>
    </div>
</div>

<div class="modal" id="drawingPreviewModal" aria-hidden="true">
    <div class="modal__content" style="max-width: 1100px; width: 96%;">
        <div class="modal__header">
            <h3 class="modal__title">Просмотр рисунка</h3>
            <button type="button" class="modal__close" onclick="closeDrawingPreviewModal()" aria-label="Закрыть">&times;</button>
        </div>
        <div class="modal__body">
            <img id="drawingPreviewModalImage" src="" alt="Рисунок участника" style="width:100%; max-height:min(70vh, calc(100vh - 260px)); object-fit:contain; border-radius:12px; background:#111;">
        </div>
    </div>
</div>

<div class="modal" id="duplicateParticipantsModal" aria-hidden="true">
    <div class="modal__content application-duplicates-modal">
        <div class="modal__header">
            <h3 class="modal__title">Обнаружены повторяющиеся участники</h3>
            <button type="button" class="modal__close" id="duplicateParticipantsModalClose" aria-label="Закрыть">&times;</button>
        </div>
        <div class="modal__body">
            <p>Мы нашли совпадающие ФИО у участников в этой заявке.</p>
            <p>Скорее всего, один и тот же участник был добавлен случайно дважды или более раз. Для каждого участника можно загрузить только один рисунок, поэтому удалите повторяющегося участника и попробуйте снова.</p>
            <ul class="review-list application-duplicates-list" id="duplicateParticipantsList"></ul>
        </div>
        <div class="modal__footer">
            <button type="button" class="btn btn--primary" id="duplicateParticipantsModalConfirm">Хорошо</button>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>

<script>
const initialParticipants = <?= json_encode(array_map(function($p) use ($user) {
    $parts = preg_split('/\s+/', trim((string)($p['fio'] ?? '')));
    $participantDrawingFile = (string) ($p['drawing_file'] ?? ($p['existing_drawing_file'] ?? ''));
    $participantPreview = $p['preview'] ?? (!empty($participantDrawingFile) ? getParticipantDrawingPreviewWebPath($user['email'] ?? '', $participantDrawingFile) : null);
    $participantFullPreview = $p['full_preview'] ?? (!empty($participantDrawingFile) ? getParticipantDrawingWebPath($user['email'] ?? '', $participantDrawingFile) : null);
    return [
        'fio' => $p['fio'] ?? '',
        'surname' => $parts[0] ?? '',
        'name' => $parts[1] ?? '',
        'patronymic' => $parts[2] ?? '',
        'age' => $p['age'] ?? '',
        'has_ovz' => !empty($p['has_ovz']),
        'participant_id' => (int) ($p['participant_id'] ?? ($p['id'] ?? 0)),
        'temp_file' => (string) ($p['temp_file'] ?? ''),
        'existing_drawing_file' => $participantDrawingFile,
        'preview' => $participantPreview,
        'full_preview' => $participantFullPreview,
    ];
}, $formParticipants), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const blockedContestParticipants = <?= json_encode(array_map(static function ($row) {
    return [
        'participant_id' => (int) ($row['participant_id'] ?? 0),
        'application_id' => (int) ($row['application_id'] ?? 0),
        'fio' => trim((string) ($row['fio'] ?? '')),
    ];
}, $blockedContestParticipants), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const csrfToken = '<?= generateCSRFToken() ?>';
const contestId = <?= e($contest_id) ?>;
const needsPaymentReceipt = <?= $contestRequiresPaymentReceipt ? 'true' : 'false' ?>;
const paymentStepNumber = <?= $contestRequiresPaymentReceipt ? (int) $paymentStepNumber : 'null' ?>;
const agreementStepNumber = <?= (int) $agreementStepNumber ?>;
const finalReviewStep = <?= (int) $reviewStepNumber ?>;
const minParticipantAge = 5;
const maxParticipantAge = 17;
const duplicateParticipantsModal = document.getElementById('duplicateParticipantsModal');
const duplicateParticipantsList = document.getElementById('duplicateParticipantsList');
const steps = needsPaymentReceipt
    ? ['Заявитель', 'Участники и рисунки', 'Квитанция об оплате', 'Пользовательское соглашение', 'Проверка и отправка']
    : ['Заявитель', 'Участники и рисунки', 'Пользовательское соглашение', 'Проверка и отправка'];
let currentStep = <?= ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST')
    ? max(1, min((int) ($_POST['current_step'] ?? 2), (int) $reviewStepNumber))
    : 1 ?>;
let participantCount = 0;
let isSubmittingApplication = false;
let userAgreementSigned = false;
let agreementDeclined = <?= (int) ($editingApplication['agreement_declined'] ?? 0) === 1 ? 'true' : 'false' ?>;
let lastFormSubmitter = null;
const initialPaymentReceipt = <?= json_encode([
    'fileName' => $hasExistingPaymentReceipt ? basename($existingPaymentReceipt) : '',
    'url' => $existingPaymentReceiptUrl,
    'isImage' => (bool) $existingPaymentReceiptIsImage,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let paymentReceiptObjectUrl = null;

function createFieldError(input, message) {
    let error = input.parentElement.querySelector('.field-error');
    if (!error) {
        error = document.createElement('div');
        error.className = 'field-error';
        input.parentElement.appendChild(error);
    }
    error.textContent = message;
    input.classList.add('is-invalid');
}

function clearFieldError(input) {
    const error = input.parentElement.querySelector('.field-error');
    if (error) error.remove();
    input.classList.remove('is-invalid');
}

function getParticipantAgeError(value) {
    if (value === '') {
        return 'Это поле обязательно.';
    }

    const age = Number(value);
    if (!Number.isInteger(age) || age < minParticipantAge || age > maxParticipantAge) {
        return `Возраст участника должен быть от ${minParticipantAge} до ${maxParticipantAge} лет.`;
    }

    return '';
}

function validateAgeField(input) {
    if (!input || !input.matches('input[name^="participants["][name$="[age]"]')) {
        return true;
    }

    const message = getParticipantAgeError(String(input.value || '').trim());
    if (message) {
        createFieldError(input, message);
        return false;
    }

    clearFieldError(input);
    return true;
}

function getPaymentReceiptElements() {
    return {
        area: document.getElementById('paymentReceiptUploadArea'),
        input: document.getElementById('paymentReceiptInput'),
        preview: document.getElementById('paymentReceiptPreview'),
        previewVisual: document.getElementById('paymentReceiptPreviewVisual'),
        fileName: document.getElementById('paymentReceiptFileName'),
        previewText: document.getElementById('paymentReceiptPreviewText'),
        uploadTitle: document.getElementById('paymentReceiptUploadTitle'),
        uploadHint: document.getElementById('paymentReceiptUploadHint'),
        existingInput: document.getElementById('existingPaymentReceipt'),
        removeInput: document.getElementById('removePaymentReceipt'),
        viewBtn: document.getElementById('paymentReceiptViewBtn'),
        removeBtn: document.getElementById('paymentReceiptRemoveBtn'),
    };
}

function revokePaymentReceiptObjectUrl() {
    if (paymentReceiptObjectUrl) {
        URL.revokeObjectURL(paymentReceiptObjectUrl);
        paymentReceiptObjectUrl = null;
    }
}

function hasPaymentReceipt() {
    if (!needsPaymentReceipt) return true;
    const {input, existingInput} = getPaymentReceiptElements();
    return !!(input?.files?.length || existingInput?.value);
}

function getPaymentReceiptSource() {
    if (!needsPaymentReceipt) return null;
    const {input, existingInput} = getPaymentReceiptElements();
    if (input?.files?.length) {
        const file = input.files[0];
        return {
            name: file.name,
            url: paymentReceiptObjectUrl,
            isImage: /^image\//.test(file.type) || /\.(jpg|jpeg|png|webp)$/i.test(file.name),
            isSelectedFile: true,
        };
    }

    if (existingInput?.value) {
        return {
            name: initialPaymentReceipt.fileName || existingInput.value.split('/').pop(),
            url: initialPaymentReceipt.url,
            isImage: !!initialPaymentReceipt.isImage,
            isSelectedFile: false,
        };
    }

    return null;
}

function renderPaymentReceiptPreview(source = null) {
    if (!needsPaymentReceipt) return;
    const {area, preview, previewVisual, fileName, previewText, uploadTitle, uploadHint, viewBtn, removeBtn} = getPaymentReceiptElements();
    if (!area || !preview || !previewVisual || !fileName || !previewText || !uploadTitle || !uploadHint) return;

    if (!source || !source.isSelectedFile) {
        revokePaymentReceiptObjectUrl();
    }

    if (!source) {
        preview.classList.remove('visible');
        area.classList.remove('has-file');
        area.classList.remove('is-invalid');
        previewVisual.innerHTML = '';
        fileName.textContent = 'Файл ещё не выбран';
        previewText.textContent = 'После выбора файла здесь появится карточка квитанции.';
        uploadTitle.textContent = 'Нажмите или перетащите файл квитанции';
        uploadHint.textContent = 'JPG, PNG, WEBP или PDF до 10MB';
        if (viewBtn) viewBtn.disabled = true;
        if (removeBtn) removeBtn.disabled = true;
        return;
    }

    preview.classList.add('visible');
    area.classList.add('has-file');
    area.classList.remove('is-invalid');
    uploadTitle.textContent = source.isSelectedFile ? 'Файл квитанции выбран' : 'Квитанция уже прикреплена';
    uploadHint.textContent = source.isSelectedFile ? 'Файл будет загружен при сохранении заявки' : 'Файл уже сохранён в заявке';
    fileName.textContent = source.name;
    previewText.textContent = source.isSelectedFile ? 'Проверьте файл перед отправкой заявки.' : 'Файл уже прикреплён к этой заявке.';
    previewVisual.innerHTML = source.isImage && source.url
        ? `<img src="${source.url}" alt="Квитанция об оплате" class="payment-receipt-preview__image">`
        : '<div class="payment-receipt-preview__file-icon"><i class="fas fa-file-pdf"></i></div>';
    if (viewBtn) viewBtn.disabled = !source.url;
    if (removeBtn) removeBtn.disabled = false;
}

function handlePaymentReceiptSelection(file) {
    if (!needsPaymentReceipt || !file) return;
    if (file.size > 10 * 1024 * 1024) {
        const {uploadTitle, area} = getPaymentReceiptElements();
        if (uploadTitle) uploadTitle.textContent = 'Файл слишком большой. Допустимо до 10MB.';
        if (area) area.classList.add('is-invalid');
        return;
    }

    revokePaymentReceiptObjectUrl();
    paymentReceiptObjectUrl = URL.createObjectURL(file);
    const {removeInput} = getPaymentReceiptElements();
    if (removeInput) removeInput.value = '0';
    renderPaymentReceiptPreview({
        name: file.name,
        url: paymentReceiptObjectUrl,
        isImage: /^image\//.test(file.type) || /\.(jpg|jpeg|png|webp)$/i.test(file.name),
        isSelectedFile: true,
    });
    updateSidebar();
    if (currentStep === finalReviewStep) renderReview();
}

function removePaymentReceiptSelection() {
    if (!needsPaymentReceipt) return;
    const {input, existingInput, removeInput} = getPaymentReceiptElements();
    const hasSelectedFile = !!input?.files?.length;

    if (hasSelectedFile && input) {
        input.value = '';
        if (existingInput?.value) {
            renderPaymentReceiptPreview(getPaymentReceiptSource());
        } else {
            renderPaymentReceiptPreview(null);
        }
    } else if (existingInput?.value) {
        existingInput.value = '';
        if (removeInput) removeInput.value = '1';
        renderPaymentReceiptPreview(null);
    } else {
        renderPaymentReceiptPreview(null);
    }

    updateSidebar();
    if (currentStep === finalReviewStep) renderReview();
}

function openPaymentReceiptPreview() {
    if (!needsPaymentReceipt) return;
    const source = getPaymentReceiptSource();
    if (source?.url) {
        window.open(source.url, '_blank', 'noopener');
    }
}

function isApplicationReadyToSubmit() {
    const requiredFieldsFilled = [...document.querySelectorAll('#applicationForm [required]')].every((input) => String(input.value || '').trim());
    const agesValid = [...document.querySelectorAll('input[name^="participants["][name$="[age]"]')].every((input) => !getParticipantAgeError(String(input.value || '').trim()));
    const participantsReady = participantCount > 0 && [...document.querySelectorAll('[id^="temp_file_"]')].every((field) => {
        const idx = field.id.split('_').pop();
        const existing = document.getElementById(`existing_file_${idx}`);
        return !!(field.value || (existing && existing.value));
    });

    const blockedParticipants = getBlockedParticipantsInDraft();
    return requiredFieldsFilled
        && agesValid
        && participantsReady
        && (!needsPaymentReceipt || hasPaymentReceipt())
        && userAgreementSigned
        && blockedParticipants.length === 0;
}

function validateStep(step) {
    let valid = true;
    let firstMissingDrawingIndex = null;
    const stepNode = document.querySelector(`.wizard-step[data-step="${step}"]`);
    if (!stepNode) return true;
    stepNode.querySelectorAll('[required]').forEach((input) => {
        if (!String(input.value || '').trim()) {
            createFieldError(input, 'Это поле обязательно.');
            valid = false;
        } else if (input.matches('input[name^="participants["][name$="[age]"]')) {
            if (!validateAgeField(input)) valid = false;
        } else {
            clearFieldError(input);
        }
    });

    if (step === 2) {
        if (participantCount === 0) valid = false;
        document.querySelectorAll('[id^="temp_file_"]').forEach((field) => {
            const idx = field.id.split('_').pop();
            const existing = document.getElementById(`existing_file_${idx}`);
            if (!field.value && !(existing && existing.value)) {
                const box = document.getElementById(`drawingUpload_${idx}`);
                if (box) box.classList.add('is-invalid');
                if (firstMissingDrawingIndex === null) {
                    firstMissingDrawingIndex = Number(idx);
                }
                valid = false;
            }
        });

        if (firstMissingDrawingIndex !== null) {
            focusParticipantWithMissingDrawing(firstMissingDrawingIndex);
        }
    }

    if (needsPaymentReceipt && step === paymentStepNumber && !hasPaymentReceipt()) {
        const {area} = getPaymentReceiptElements();
        if (area) {
            area.classList.add('is-invalid');
        }
        valid = false;
    }

    if (step === agreementStepNumber && !userAgreementSigned) {
        valid = false;
    }
    return valid;
}

function normalizeParticipantFioPart(value) {
    return String(value || '')
        .trim()
        .replace(/\s+/g, ' ')
        .toLowerCase()
        .replace(/ё/g, 'е');
}

function normalizeFioComparable(value) {
    return normalizeParticipantFioPart(value).replace(/[^a-zа-я0-9\s-]/gi, '');
}

function tokenizeFioComparable(value) {
    const normalized = normalizeFioComparable(value);
    if (!normalized) return [];
    return normalized.split(/\s+/).map((part) => part.trim()).filter(Boolean).sort((a, b) => a.localeCompare(b, 'ru'));
}

function levenshteinDistance(left, right) {
    const a = Array.from(String(left || ''));
    const b = Array.from(String(right || ''));
    const n = a.length;
    const m = b.length;
    if (n === 0) return m;
    if (m === 0) return n;

    const dp = Array.from({ length: n + 1 }, () => new Array(m + 1).fill(0));
    for (let i = 0; i <= n; i += 1) dp[i][0] = i;
    for (let j = 0; j <= m; j += 1) dp[0][j] = j;

    for (let i = 1; i <= n; i += 1) {
        for (let j = 1; j <= m; j += 1) {
            const cost = a[i - 1] === b[j - 1] ? 0 : 1;
            dp[i][j] = Math.min(
                dp[i - 1][j] + 1,
                dp[i][j - 1] + 1,
                dp[i - 1][j - 1] + cost
            );
        }
    }

    return dp[n][m];
}

function isLikelySameParticipantFio(left, right) {
    const tokensLeft = tokenizeFioComparable(left);
    const tokensRight = tokenizeFioComparable(right);
    if (!tokensLeft.length || !tokensRight.length) {
        return false;
    }

    if (tokensLeft.join(' ') === tokensRight.join(' ')) {
        return true;
    }

    if (tokensLeft.length !== tokensRight.length) {
        return false;
    }

    for (let i = 0; i < tokensLeft.length; i += 1) {
        const tokenLeft = tokensLeft[i];
        const tokenRight = tokensRight[i];
        const maxLength = Math.max(tokenLeft.length, tokenRight.length);
        const allowedDistance = maxLength >= 10 ? 3 : 2;
        if (levenshteinDistance(tokenLeft, tokenRight) > allowedDistance) {
            return false;
        }
    }

    return true;
}

function getParticipantDuplicateGroups() {
    const groups = new Map();
    const cards = [...document.querySelectorAll('#participantsContainer .participant-form')];

    cards.forEach((card) => {
        const index = Number(card.dataset.index);
        const surname = card.querySelector(`[name="participants[${index}][surname]"]`)?.value || '';
        const name = card.querySelector(`[name="participants[${index}][name]"]`)?.value || '';
        const patronymic = card.querySelector(`[name="participants[${index}][patronymic]"]`)?.value || '';
        const key = [
            normalizeParticipantFioPart(surname),
            normalizeParticipantFioPart(name),
            normalizeParticipantFioPart(patronymic),
        ].join('|');

        if (!key || key === '||') {
            return;
        }

        const previewNode = document.getElementById(`preview_img_${index}`);
        const displayName = [surname, name, patronymic].map((part) => String(part || '').trim()).filter(Boolean).join(' ');
        const entry = {
            index,
            label: `Участник ${index + 1}`,
            fio: displayName || `Участник ${index + 1}`,
            previewUrl: previewNode?.src || '',
            fullPreviewUrl: previewNode?.dataset.fullSrc || previewNode?.src || '',
        };

        if (!groups.has(key)) {
            groups.set(key, []);
        }
        groups.get(key).push(entry);
    });

    return [...groups.values()].filter((items) => items.length > 1);
}

function clearDuplicateParticipantHighlights() {
    document.querySelectorAll('#participantsContainer .participant-form').forEach((card) => {
        card.classList.remove('participant-form--duplicate');
    });
}

function clearBlockedParticipantHighlights() {
    document.querySelectorAll('#participantsContainer .participant-form').forEach((card) => {
        card.classList.remove('participant-form--blocked');
    });
}

function highlightDuplicateParticipantGroups(groups) {
    clearDuplicateParticipantHighlights();
    groups.forEach((group) => {
        group.forEach((item) => {
            const card = document.querySelector(`.participant-form[data-index="${item.index}"]`);
            card?.classList.add('participant-form--duplicate');
        });
    });
}

function getBlockedParticipantsInDraft() {
    const cards = [...document.querySelectorAll('#participantsContainer .participant-form')];
    const blocked = [];

    cards.forEach((card) => {
        const index = Number(card.dataset.index);
        const surname = card.querySelector(`[name="participants[${index}][surname]"]`)?.value || '';
        const name = card.querySelector(`[name="participants[${index}][name]"]`)?.value || '';
        const patronymic = card.querySelector(`[name="participants[${index}][patronymic]"]`)?.value || '';
        const candidateFio = [surname, name, patronymic].map((part) => String(part || '').trim()).filter(Boolean).join(' ');

        if (!candidateFio) {
            return;
        }

        const matchedBlocked = (blockedContestParticipants || []).find((entry) => isLikelySameParticipantFio(candidateFio, entry?.fio || ''));
        if (!matchedBlocked) {
            return;
        }

        blocked.push({
            index,
            fio: candidateFio,
            blockedFio: matchedBlocked.fio || candidateFio,
            blockedParticipantId: Number(matchedBlocked.participant_id || 0),
            blockedApplicationId: Number(matchedBlocked.application_id || 0),
        });
    });

    syncAgreementDeclinedState(blocked.length > 0);
    return blocked;
}

function syncAgreementDeclinedState(hasBlockedParticipants) {
    const nextValue = !!hasBlockedParticipants;
    if (agreementDeclined === nextValue) {
        return;
    }

    agreementDeclined = nextValue;
    syncAgreementButtons();
}

function highlightBlockedParticipants(blockedEntries) {
    clearBlockedParticipantHighlights();
    blockedEntries.forEach((entry) => {
        const card = document.querySelector(`.participant-form[data-index="${entry.index}"]`);
        card?.classList.add('participant-form--blocked');
    });
}

function focusFirstBlockedParticipant(blockedEntries) {
    const firstBlocked = blockedEntries?.[0];
    if (!firstBlocked) return;
    focusDuplicateParticipantByIndex(firstBlocked.index);
}

function focusFirstDuplicateParticipant(groups) {
    const firstDuplicate = groups[0]?.[0];
    if (!firstDuplicate) return;

    focusDuplicateParticipantByIndex(firstDuplicate.index);
}

function focusDuplicateParticipantByIndex(index) {
    const targetIndex = Number(index);
    if (Number.isNaN(targetIndex)) return;

    const card = document.querySelector(`.participant-form[data-index="${targetIndex}"]`);
    const body = card?.querySelector('.participant-form__body');
    if (body) {
        body.hidden = false;
    }

    card?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    window.setTimeout(() => {
        card?.querySelector('input, textarea, select')?.focus();
    }, 160);
}

function focusParticipantWithMissingDrawing(index) {
    const targetIndex = Number(index);
    if (Number.isNaN(targetIndex)) return;

    const card = document.querySelector(`.participant-form[data-index="${targetIndex}"]`);
    const body = card?.querySelector('.participant-form__body');
    if (body) {
        body.hidden = false;
    }

    card?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    window.setTimeout(() => {
        const uploadArea = document.getElementById(`drawingUpload_${targetIndex}`);
        uploadArea?.focus?.();
    }, 160);
}

function openDuplicateParticipantsModal(groups) {
    if (!duplicateParticipantsModal || !duplicateParticipantsList) {
        return;
    }

    highlightDuplicateParticipantGroups(groups);

    duplicateParticipantsList.innerHTML = groups.map((group) => {
        return group.map((item) => {
            const thumbnail = item.previewUrl
                ? `<img src="${item.previewUrl}" alt="${item.fio}" class="review-item__thumb">`
                : '<div class="review-item__thumb review-item__thumb--empty">Нет рисунка</div>';

            return `
                <li class="review-item review-item--card application-duplicates-list__item" data-duplicate-participant-index="${item.index}" tabindex="0" role="button" aria-label="Перейти к ${item.fio}">
                    <div class="review-item__thumb-wrap">${thumbnail}</div>
                    <div class="review-item__meta">
                        <strong>${item.fio}</strong>
                        <span>${item.label}</span>
                        <span>Совпадающее ФИО в этой заявке</span>
                    </div>
                </li>
            `;
        }).join('');
    }).join('');

    duplicateParticipantsList.querySelectorAll('[data-duplicate-participant-index]').forEach((item) => {
        item.addEventListener('click', () => {
            const index = item.getAttribute('data-duplicate-participant-index');
            closeDuplicateParticipantsModal();
            focusDuplicateParticipantByIndex(index);
        });
        item.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                const index = item.getAttribute('data-duplicate-participant-index');
                closeDuplicateParticipantsModal();
                focusDuplicateParticipantByIndex(index);
            }
        });
    });

    duplicateParticipantsModal.classList.add('active');
    duplicateParticipantsModal.setAttribute('aria-hidden', 'false');
}

function closeDuplicateParticipantsModal(options = {}) {
    if (!duplicateParticipantsModal) {
        return;
    }

    duplicateParticipantsModal.classList.remove('active');
    duplicateParticipantsModal.setAttribute('aria-hidden', 'true');

    if (options.focusFirstDuplicate) {
        focusFirstDuplicateParticipant(getParticipantDuplicateGroups());
    }
}

function syncNavigationButtons(isReadyToSubmit = false) {
    const prevBtn = document.getElementById('prevStepBtn');
    const nextBtn = document.getElementById('nextStepBtn');
    const submitBtn = document.getElementById('submitBtn');
    const isLastStep = currentStep === steps.length;

    if (prevBtn) {
        prevBtn.disabled = currentStep === 1;
    }

    if (nextBtn) {
        const waitingAgreementSign = currentStep === agreementStepNumber && !userAgreementSigned;
        nextBtn.disabled = isLastStep || waitingAgreementSign;
    }

    if (submitBtn) {
        submitBtn.disabled = !isLastStep || !isReadyToSubmit;
    }
}

function syncParticipantActionVisibility() {
    const participantAction = document.getElementById('wizardParticipantAction');
    if (participantAction) {
        const isVisible = currentStep === 2;
        participantAction.hidden = !isVisible;
        participantAction.classList.toggle('is-visible', isVisible);
    }
}

function syncAgreementButtons() {
    const signBtn = document.getElementById('signAgreementBtn');
    const declineBtn = document.getElementById('declineAgreementBtn');
    const agreementHint = document.getElementById('agreementSignedHint');
    const agreementField = document.getElementById('userAgreementSigned');
    const agreementDeclinedField = document.getElementById('agreementDeclined');
    if (agreementField) {
        agreementField.value = userAgreementSigned ? '1' : '0';
    }
    if (agreementDeclinedField) {
        agreementDeclinedField.value = agreementDeclined ? '1' : '0';
    }
    if (signBtn) {
        signBtn.classList.toggle('is-active', userAgreementSigned);
        signBtn.classList.toggle('is-signed', userAgreementSigned);
        signBtn.setAttribute('aria-pressed', userAgreementSigned ? 'true' : 'false');
        signBtn.textContent = userAgreementSigned
            ? 'Соглашение подписано ✓'
            : 'Подписать пользовательское соглашение!';
    }
    if (declineBtn) {
        declineBtn.classList.toggle('is-active', agreementDeclined);
        declineBtn.setAttribute('aria-pressed', agreementDeclined ? 'true' : 'false');
        declineBtn.hidden = currentStep !== agreementStepNumber;
    }
    if (agreementHint) {
        agreementHint.hidden = !(currentStep === agreementStepNumber && userAgreementSigned);
    }
}

function updateProgress() {
    const percent = Math.round((currentStep / steps.length) * 100);
    const currentStepField = document.getElementById('currentStepInput');
    if (currentStepField) {
        currentStepField.value = String(currentStep);
    }
    document.getElementById('progressBar').style.width = `${percent}%`;
    document.getElementById('progressPercent').textContent = `${percent}%`;
    document.getElementById('mobileStepLabel').textContent = `Шаг ${currentStep} из ${steps.length}`;

    document.querySelectorAll('.wizard-step').forEach((s) => {
        s.hidden = Number(s.dataset.step) !== currentStep;
    });

    const stepContainer = document.getElementById('wizardSteps');
    stepContainer.innerHTML = steps.map((title, index) => {
        const n = index + 1;
        const cls = n === currentStep ? 'active' : (n < currentStep ? 'done' : '');
        return `<button type="button" class="wizard-step-pill ${cls}" onclick="goStep(${n})">${n}. ${title}</button>`;
    }).join('');

    if (currentStep === finalReviewStep) renderReview();
    syncAgreementButtons();
    syncParticipantActionVisibility();
    syncNavigationButtons(currentStep === finalReviewStep && isApplicationReadyToSubmit());
    updateSidebar();
}

function scrollToStepHeader(step) {
    const targetStep = Number(step);
    if (Number.isNaN(targetStep)) return;

    window.requestAnimationFrame(() => {
        const header = document.querySelector(`.wizard-step[data-step="${targetStep}"] .card__header`);
        if (!header) return;
        header.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
}

function goStep(step) {
    document.getElementById('formAction').value = 'submit';
    if (step > currentStep && !validateStep(currentStep)) return;
    if (currentStep === 2 && step > currentStep) {
        const duplicateGroups = getParticipantDuplicateGroups();
        if (duplicateGroups.length > 0) {
            openDuplicateParticipantsModal(duplicateGroups);
            return;
        }
    }
    currentStep = step;
    updateProgress();
    scrollToStepHeader(currentStep);
}

function buildDrawingActionButtons(index, hasFile, isLoading = false) {
    if (isLoading) {
        return '<button type="button" class="btn btn--secondary" disabled><span class="loading-spinner"></span> Загружаем...</button>';
    }

    if (!hasFile) {
        return `<button type="button" class="btn btn--secondary" onclick="openDrawingPicker(${index}); return false;">Загрузить</button>`;
    }

    return `
        <button type="button" class="btn btn--ghost" onclick="viewDrawing(${index}); return false;"><i class="fas fa-search-plus"></i> Посмотреть рисунок</button>
        <button type="button" class="btn btn--ghost" onclick="replaceDrawing(${index}); return false;"><i class="fas fa-arrows-rotate"></i> Заменить рисунок</button>
    `;
}

function renderDrawingUploader(index, options = {}) {
    const area = document.getElementById(`drawingUpload_${index}`);
    const title = document.getElementById(`upload_title_${index}`);
    const hint = document.getElementById(`upload_hint_${index}`);
    const preview = document.getElementById(`preview_${index}`);
    const actions = document.getElementById(`drawingActions_${index}`);

    if (!area || !title || !hint || !preview || !actions) return;

    const hasFile = !!options.previewUrl;
    const isLoading = options.isLoading === true;
    const fileName = String(options.fileName || '');
    const fullPreviewUrl = String(options.fullPreviewUrl || options.previewUrl || '');

    area.classList.toggle('has-file', hasFile);
    area.classList.toggle('is-loading', isLoading);
    if (!options.keepInvalid) {
        area.removeAttribute('title');
    }

    if (hasFile) {
        title.textContent = fileName ? `Файл: ${fileName}` : 'Файл загружен';
        hint.textContent = 'Нажмите на изображение, чтобы заменить файл';
        preview.classList.add('visible');
        preview.innerHTML = `
            <img src="${options.previewUrl}" data-full-src="${fullPreviewUrl}" alt="Рисунок участника" class="drawing-upload-image" id="preview_img_${index}">
            <div class="drawing-upload-overlay">
                <i class="fas fa-arrows-rotate"></i>
                Нажмите, чтобы выбрать другой рисунок
            </div>
        `;
    } else {
        title.textContent = options.message || 'Перетащите рисунок сюда или нажмите для выбора файла';
        hint.textContent = options.hint || 'JPG, PNG, GIF, WebP, TIF до 15MB';
        preview.classList.remove('visible');
        preview.innerHTML = '';
    }

    actions.innerHTML = buildDrawingActionButtons(index, hasFile, isLoading);

    if (!isLoading && !options.keepInvalid) {
        area.classList.remove('is-invalid');
    }
}

function openDrawingPicker(index) {
    const input = document.querySelector(`#drawingUpload_${index} input[type="file"]`);
    if (input) {
        input.click();
    }
}

function replaceDrawing(index) {
    openDrawingPicker(index);
}

function createParticipantForm(index, data = null) {
    const hasPreview = !!(data?.preview);
    const fullPreview = data?.full_preview || data?.preview || '';
    const container = document.createElement('article');
    container.className = 'participant-form';
    container.dataset.index = index;
    container.innerHTML = `
        <div class="participant-form__header">
            <div class="participant-form__title"><span class="participant-form__number">${index + 1}</span> Участник ${index + 1}</div>
            <div class="flex gap-sm">
                <button type="button" class="btn btn--ghost" onclick="toggleParticipant(${index})">Свернуть</button>
            </div>
        </div>
        <div class="participant-form__body">
            <div class="form-grid form-grid--3">
                <div class="form-group"><label class="form-label form-label--required">Фамилия</label><input type="text" required name="participants[${index}][surname]" class="form-input" value="${data?.surname || ''}" placeholder="Иванов"></div>
                <div class="form-group"><label class="form-label form-label--required">Имя</label><input type="text" required name="participants[${index}][name]" class="form-input" value="${data?.name || ''}" placeholder="Иван"></div>
                <div class="form-group"><label class="form-label form-label--required">Отчество</label><input type="text" required name="participants[${index}][patronymic]" class="form-input" value="${data?.patronymic || ''}" placeholder="Иванович"></div>
                <div class="form-group"><label class="form-label form-label--required">Возраст</label><input type="number" required min="5" max="17" name="participants[${index}][age]" class="form-input" value="${data?.age || ''}"></div>
                <div class="form-group">
                    <span class="form-label">Особенности</span>
                    <label class="field-correction-checkbox">
                        <input type="checkbox" name="participants[${index}][has_ovz]" value="1" ${data?.has_ovz ? 'checked' : ''}>
                        <span>Участник с ОВЗ</span>
                    </label>
                </div>
            </div>
            <input type="hidden" name="participants[${index}][participant_id]" value="${data?.participant_id || ''}">
            <input type="hidden" name="participants[${index}][temp_file]" id="temp_file_${index}" value="${data?.temp_file || ''}">
            <input type="hidden" name="participants[${index}][existing_drawing_file]" id="existing_file_${index}" value="${data?.existing_drawing_file || ''}">
            <div class="form-section form-section--boxed">
                <h4 class="form-section__title">Рисунок участника</h4>
                <div class="drawing-layout">
                    <div class="drawing-upload-card">
                        <div class="upload-area upload-area--drawing ${hasPreview ? 'has-file' : ''}" id="drawingUpload_${index}">
                            <input type="file" accept="image/*" class="file-upload__input" data-index="${index}" style="display:none;">
                            <div class="drawing-upload-placeholder">
                                <div class="upload-area__icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                <div class="upload-area__title" id="upload_title_${index}">${hasPreview ? 'Файл загружен' : 'Перетащите рисунок сюда или нажмите для выбора файла'}</div>
                                <div class="upload-area__hint" id="upload_hint_${index}">${hasPreview ? 'Нажмите на изображение, чтобы заменить файл' : 'JPG, PNG, GIF, WebP, TIF до 15MB'}</div>
                            </div>
                            <div class="drawing-upload-preview ${hasPreview ? 'visible' : ''}" id="preview_${index}">
                                ${hasPreview ? `
                                    <img src="${data.preview}" data-full-src="${fullPreview}" alt="Рисунок участника" class="drawing-upload-image" id="preview_img_${index}">
                                    <div class="drawing-upload-overlay">
                                        <i class="fas fa-arrows-rotate"></i>
                                        Нажмите, чтобы выбрать другой рисунок
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                        <div class="drawing-upload-actions" id="drawingActions_${index}">
                            ${buildDrawingActionButtons(index, hasPreview)}
                        </div>
                    </div>
                </div>
            </div>
            <div class="participant-form__footer">
                <button type="button" class="participant-form__remove participant-form__remove--full" data-remove-participant-button onclick="removeParticipant(${index})">
                    <i class="fas fa-trash"></i> Удалить участника
                </button>
            </div>
        </div>`;
    return container;
}

function syncParticipantRemoveButtons() {
    const cards = [...document.querySelectorAll('#participantsContainer .participant-form')];
    const canRemove = cards.length >= 2;

    cards.forEach((card) => {
        const button = card.querySelector('[data-remove-participant-button]');
        if (!button) return;
        button.hidden = !canRemove;
        button.disabled = !canRemove;
    });
}

function toggleParticipant(index) {
    const card = document.querySelector(`.participant-form[data-index="${index}"] .participant-form__body`);
    if (!card) return;
    card.hidden = !card.hidden;
}

function scrollToParticipantForm(card) {
    if (!card) return;
    requestAnimationFrame(() => {
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        const firstInput = card.querySelector('input, textarea, select');
        if (firstInput) {
            firstInput.focus({ preventScroll: true });
        }
    });
}

function addParticipant(data = null, options = {}) {
    const container = document.getElementById('participantsContainer');
    const card = createParticipantForm(participantCount, data);
    container.appendChild(card);
    initUploadArea(participantCount);
    participantCount++;
    syncParticipantRemoveButtons();
    updateParticipantsState();
    if (options.scrollIntoView) {
        scrollToParticipantForm(card);
    }
}

function removeParticipant(index) {
    const item = document.querySelector(`.participant-form[data-index="${index}"]`);
    if (item) item.remove();

    const container = document.getElementById('participantsContainer');
    participantCount = 0;
    [...container.querySelectorAll('.participant-form')].forEach((f) => {
        const old = Number(f.dataset.index);
        const fresh = createParticipantForm(participantCount, {
            surname: f.querySelector(`[name="participants[${old}][surname]"]`)?.value || '',
            name: f.querySelector(`[name="participants[${old}][name]"]`)?.value || '',
            patronymic: f.querySelector(`[name="participants[${old}][patronymic]"]`)?.value || '',
            age: f.querySelector(`[name="participants[${old}][age]"]`)?.value || '',
            has_ovz: f.querySelector(`[name="participants[${old}][has_ovz]"]`)?.checked || false,
            participant_id: f.querySelector(`[name="participants[${old}][participant_id]"]`)?.value || '',
            temp_file: f.querySelector(`#temp_file_${old}`)?.value || '',
            existing_drawing_file: f.querySelector(`#existing_file_${old}`)?.value || '',
            preview: f.querySelector(`#preview_img_${old}`)?.src || '',
            full_preview: f.querySelector(`#preview_img_${old}`)?.dataset.fullSrc || f.querySelector(`#preview_img_${old}`)?.src || ''
        });
        f.replaceWith(fresh);
        initUploadArea(participantCount);
        participantCount++;
    });
    syncParticipantRemoveButtons();
    updateParticipantsState();
}

function updateParticipantsState() {
    document.getElementById('participantsEmpty').style.display = participantCount ? 'none' : 'block';
    updateSidebar();
}

function initUploadArea(index) {
    const area = document.getElementById(`drawingUpload_${index}`);
    if (!area) return;
    const input = area.querySelector('input[type="file"]');

    area.addEventListener('click', (e) => {
        if (area.classList.contains('is-loading')) return;
        if (e.target !== input) input.click();
    });
    area.addEventListener('dragover', (e) => { e.preventDefault(); area.classList.add('dragover'); });
    area.addEventListener('dragleave', (e) => { e.preventDefault(); area.classList.remove('dragover'); });
    area.addEventListener('drop', (e) => {
        e.preventDefault();
        area.classList.remove('dragover');
        if (area.classList.contains('is-loading')) return;
        if (e.dataTransfer.files.length > 0) handleFileSelect(e.dataTransfer.files[0], index);
    });
    input.addEventListener('change', function () {
        if (this.files.length > 0) handleFileSelect(this.files[0], index);
    });
}

function handleFileSelect(file, index) {
    if (file.size > 15 * 1024 * 1024) {
        renderDrawingUploader(index, {
            previewUrl: document.getElementById(`preview_img_${index}`)?.src || '',
            fileName: '',
            message: 'Файл слишком большой. Допустимо до 15MB.',
            hint: 'Выберите изображение меньшего размера',
            keepInvalid: true,
        });
        const area = document.getElementById(`drawingUpload_${index}`);
        if (area) area.classList.add('is-invalid');
        return;
    }
    const previousPreviewUrl = document.getElementById(`preview_img_${index}`)?.src || '';
    const previousFileName = previousPreviewUrl ? 'Файл загружен' : '';
    renderDrawingUploader(index, {
        previewUrl: previousPreviewUrl,
        fileName: previousFileName,
        isLoading: true,
    });

    const formData = new FormData();
    formData.append('action', 'upload_temp');
    formData.append('csrf_token', csrfToken);
    formData.append('participant_index', index);
    formData.append('previous_temp_file', document.getElementById(`temp_file_${index}`)?.value || '');
    formData.append('drawing', file);

    fetch('/application-form?contest_id=' + contestId, { method: 'POST', body: formData })
        .then((r) => r.json())
        .then((data) => {
            if (!data.success) throw new Error(data.message || 'Ошибка загрузки');
            document.getElementById(`temp_file_${index}`).value = data.temp_file;
            renderDrawingUploader(index, {
                previewUrl: data.preview,
                fullPreviewUrl: data.original_url || data.preview,
                fileName: data.original_name || 'Файл загружен',
            });
            updateSidebar();
        })
        .catch((error) => {
            if (previousPreviewUrl) {
                renderDrawingUploader(index, {
                    previewUrl: previousPreviewUrl,
                    fileName: previousFileName,
                });
                const area = document.getElementById(`drawingUpload_${index}`);
                if (area) {
                    area.classList.add('is-invalid');
                    area.title = error.message;
                }
            } else {
                renderDrawingUploader(index, {
                    message: error.message,
                    hint: 'Попробуйте выбрать другой файл',
                    keepInvalid: true,
                });
                const area = document.getElementById(`drawingUpload_${index}`);
                if (area) area.classList.add('is-invalid');
            }
        });
}

function removeDrawing(index) {
    const tempFile = document.getElementById(`temp_file_${index}`)?.value || '';
    const clearUi = () => {
        renderDrawingUploader(index);
        const input = document.querySelector(`#drawingUpload_${index} input[type="file"]`);
        const temp = document.getElementById(`temp_file_${index}`);
        const existing = document.getElementById(`existing_file_${index}`);
        if (input) input.value = '';
        if (temp) temp.value = '';
        if (existing) existing.value = '';
        updateSidebar();
    };

    if (!tempFile) { clearUi(); return; }
    const formData = new FormData();
    formData.append('action', 'delete_temp');
    formData.append('csrf_token', csrfToken);
    formData.append('temp_file', tempFile);
    fetch('/application-form?contest_id=' + contestId, { method: 'POST', body: formData }).then(() => clearUi());
}

function renderReview() {
    const review = document.getElementById('reviewContainer');
    const parentName = document.querySelector('[name="parent_surname"]').value + ' ' + document.querySelector('[name="parent_name"]').value;
    const parentEmail = document.querySelector('[name="organization_email"]').value || '—';
    const sourceInfo = document.querySelector('[name="source_info"]')?.value?.trim() || '—';
    const colleaguesInfo = document.querySelector('[name="colleagues_info"]')?.value?.trim() || '—';
    const recommendationsWishes = document.querySelector('[name="recommendations_wishes"]')?.value?.trim() || '—';
    const paymentReceiptSource = needsPaymentReceipt ? getPaymentReceiptSource() : null;
    const participants = [...document.querySelectorAll('#participantsContainer .participant-form')].map((card, index) => {
        const fio = `${card.querySelector(`[name="participants[${index}][surname]"]`)?.value || ''} ${card.querySelector(`[name="participants[${index}][name]"]`)?.value || ''} ${card.querySelector(`[name="participants[${index}][patronymic]"]`)?.value || ''}`.trim();
        const age = card.querySelector(`[name="participants[${index}][age]"]`)?.value || '—';
        const hasOvz = !!card.querySelector(`[name="participants[${index}][has_ovz]"]`)?.checked;
        const hasDrawing = !!(document.getElementById(`temp_file_${index}`)?.value || document.getElementById(`existing_file_${index}`)?.value);
        const previewUrl = document.getElementById(`preview_img_${index}`)?.src || '';
        return `
            <li class="review-item review-item--card">
                <div class="review-item__thumb-wrap">
                    ${hasDrawing && previewUrl
                        ? `<img src="${previewUrl}" alt="Рисунок участника ${index + 1}" class="review-item__thumb drawing-preview__image--clickable" onclick="viewDrawing(${index})">`
                        : '<div class="review-item__thumb review-item__thumb--empty">Нет рисунка</div>'}
                </div>
                <div class="review-item__meta">
                    <strong>${fio || 'Участник'}</strong>
                    <span>Возраст: ${age}</span>
                    <span>${hasOvz ? 'Участник с ОВЗ' : 'Без отметки ОВЗ'}</span>
                </div>
                <button type="button" class="btn btn--ghost" onclick="goStep(2)">Изменить</button>
            </li>
        `;
    });

    const blockedParticipants = getBlockedParticipantsInDraft();
    highlightBlockedParticipants(blockedParticipants);
    const ready = isApplicationReadyToSubmit();
    const blockedParticipantsListHtml = blockedParticipants.map((item) => `<li><strong>${item.fio}</strong> (участник ${item.index + 1})</li>`).join('');
    review.innerHTML = `
        <div class="review-block">
            <h4>Заявитель</h4>
            <p>${parentName}</p>
            <p>${parentEmail}</p>
        </div>
        <div class="review-block">
            <h4>Дополнительная информация</h4>
            <div class="review-info-list">
                <div class="review-info-item">
                    <div class="review-info-item__label">Поделитесь, пожалуйста, откуда вы узнали о конкурсе?</div>
                    <div class="review-info-item__value">${sourceInfo}</div>
                </div>
                <div class="review-info-item">
                    <div class="review-info-item__label">Рассказывали ли вы о конкурсе своим коллегам и знакомым из других организаций? Если да, укажите, пожалуйста, примерное количество</div>
                    <div class="review-info-item__value">${colleaguesInfo}</div>
                </div>
                <div class="review-info-item">
                    <div class="review-info-item__label">Пожелания и рекомендации</div>
                    <div class="review-info-item__value">${recommendationsWishes}</div>
                </div>
            </div>
        </div>
        <div class="review-block">
            <h4>Участники</h4>
            <ul class="review-list">${participants.join('')}</ul>
        </div>
        <div class="review-block">
            <h4>Пользовательское соглашение</h4>
            <p>${userAgreementSigned ? 'Подписано.' : 'Не подписано.'}</p>
            <button type="button" class="btn btn--ghost" onclick="goStep(${agreementStepNumber})">Перейти к шагу подписания</button>
        </div>
        ${needsPaymentReceipt ? `
        <div class="review-block">
            <h4>Квитанция об оплате</h4>
            <p>${paymentReceiptSource ? paymentReceiptSource.name : 'Квитанция пока не прикреплена'}</p>
            <p>${paymentReceiptSource ? 'Файл готов к отправке вместе с заявкой.' : 'Без квитанции кнопка отправки останется неактивной.'}</p>
            <button type="button" class="btn btn--ghost" onclick="goStep(${paymentStepNumber})">Изменить</button>
        </div>` : ''}
        ${blockedParticipants.length > 0 ? `
        <div class="alert alert--error application-review__agreement-alert">
            <strong>Обнаружены участники, которые ранее нарушили пользовательское соглашение в этом конкурсе.</strong>
            <p class="mt-sm">Удалите их из заявки. Пока они остаются в списке участников, отправка заявки невозможна.</p>
            <ul>${blockedParticipantsListHtml}</ul>
            <button type="button" class="btn btn--ghost" onclick="goStep(2)">Перейти к участникам и удалить</button>
        </div>` : ''}
        <div class="alert ${ready ? 'alert--success' : 'alert--error'}">${ready ? 'Заявка готова к отправке.' : needsPaymentReceipt ? 'Заполните все обязательные поля, добавьте рисунки, проверьте участников и прикрепите квитанцию об оплате.' : 'Заполните все обязательные поля, добавьте рисунки и проверьте участников.'}</div>`;
    syncNavigationButtons(ready);
}

function updateSidebar() {
    const drawings = [...document.querySelectorAll('[id^="temp_file_"]')].filter((f) => f.value).length + [...document.querySelectorAll('[id^="existing_file_"]')].filter((f) => f.value).length;
    document.getElementById('statParticipants').textContent = `Участников: ${participantCount}`;
    document.getElementById('statDrawings').textContent = `Рисунков: ${drawings}`;
    if (needsPaymentReceipt) {
        const statReceipt = document.getElementById('statReceipt');
        if (statReceipt) {
            statReceipt.textContent = `Квитанция: ${hasPaymentReceipt() ? 'добавлена' : 'не добавлена'}`;
        }
    }

    const required = document.querySelectorAll('#applicationForm [required]');
    const done = [...required].filter((el) => String(el.value || '').trim()).length;
    const additionalRequired = needsPaymentReceipt ? 1 : 0;
    const additionalDone = needsPaymentReceipt && hasPaymentReceipt() ? 1 : 0;
    const fillPercent = (required.length + additionalRequired) ? Math.round(((done + additionalDone) / (required.length + additionalRequired)) * 100) : 0;
    document.getElementById('statConfidence').textContent = `Заполнено: ${fillPercent}%`;
}

function saveDraft() {
    const form = document.getElementById('applicationForm');
    const saveDraftBtn = document.getElementById('saveDraftBtn');
    document.getElementById('formAction').value = 'save_draft';
    if (form && typeof form.requestSubmit === 'function' && saveDraftBtn) {
        form.requestSubmit(saveDraftBtn);
        return;
    }
    if (form) {
        form.submit();
    }
}

function hasPendingDrawingUploads() {
    return document.querySelectorAll('.upload-area--drawing.is-loading').length > 0;
}

function viewDrawing(index) {
    const img = document.getElementById(`preview_img_${index}`);
    if (!img || !img.src) return;
    const modal = document.getElementById('drawingPreviewModal');
    document.getElementById('drawingPreviewModalImage').src = img.dataset.fullSrc || img.src;
    modal.classList.add('active');
}

function closeDrawingPreviewModal() {
    const modal = document.getElementById('drawingPreviewModal');
    modal.classList.remove('active');
    document.getElementById('drawingPreviewModalImage').src = '';
}

function goToMyApplications() { window.location.href = '/my-applications'; }

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('duplicateParticipantsModalClose')?.addEventListener('click', () => closeDuplicateParticipantsModal());
    document.getElementById('duplicateParticipantsModalConfirm')?.addEventListener('click', () => closeDuplicateParticipantsModal({ focusFirstDuplicate: true }));
    duplicateParticipantsModal?.addEventListener('click', function (event) {
        if (event.target === duplicateParticipantsModal) {
            closeDuplicateParticipantsModal();
        }
    });

    const applicationUserType = document.getElementById('applicationUserType');
    const orgAddressGroup = document.getElementById('orgAddressGroup');
    const orgAddress = document.getElementById('orgAddress');
    if (applicationUserType && orgAddressGroup) {
        const isParent = String(applicationUserType.value || 'parent') === 'parent';
        orgAddressGroup.style.display = isParent ? 'none' : '';
        if (isParent && orgAddress) {
            orgAddress.value = '';
        }
    }

    const agreementField = document.getElementById('userAgreementSigned');
    userAgreementSigned = agreementField?.value === '1';
    document.getElementById('signAgreementBtn')?.addEventListener('click', () => {
        userAgreementSigned = true;
        agreementDeclined = false;
        syncAgreementButtons();
        syncNavigationButtons(currentStep === finalReviewStep && isApplicationReadyToSubmit());
    });
    document.getElementById('declineAgreementBtn')?.addEventListener('click', () => {
        agreementDeclined = true;
        userAgreementSigned = false;
        syncAgreementButtons();
    });
    document.querySelectorAll('#applicationForm button[type="submit"][data-form-action]').forEach((button) => {
        button.addEventListener('click', () => {
            lastFormSubmitter = button;
            const actionField = document.getElementById('formAction');
            if (actionField && button.dataset.formAction) {
                actionField.value = button.dataset.formAction;
            }
        });
    });

    if (needsPaymentReceipt) {
        const {area, input, viewBtn, removeBtn, existingInput, removeInput} = getPaymentReceiptElements();
        if (area && input) {
            area.addEventListener('click', (event) => {
                if (event.target !== input) input.click();
            });
            area.addEventListener('dragover', (event) => {
                event.preventDefault();
                area.classList.add('dragover');
            });
            area.addEventListener('dragleave', (event) => {
                event.preventDefault();
                area.classList.remove('dragover');
            });
            area.addEventListener('drop', (event) => {
                event.preventDefault();
                area.classList.remove('dragover');
                if (event.dataTransfer?.files?.length) {
                    const droppedFile = event.dataTransfer.files[0];
                    if (typeof DataTransfer !== 'undefined') {
                        const transfer = new DataTransfer();
                        transfer.items.add(droppedFile);
                        input.files = transfer.files;
                    }
                    handlePaymentReceiptSelection(droppedFile);
                }
            });
            input.addEventListener('change', () => {
                if (input.files?.length) {
                    if (removeInput) removeInput.value = '0';
                    handlePaymentReceiptSelection(input.files[0]);
                }
            });
        }
        viewBtn?.addEventListener('click', openPaymentReceiptPreview);
        removeBtn?.addEventListener('click', removePaymentReceiptSelection);
        if (existingInput?.value) {
            renderPaymentReceiptPreview(getPaymentReceiptSource());
        }
    }

    if (Array.isArray(initialParticipants) && initialParticipants.length) {
        initialParticipants.forEach((p) => addParticipant(p));
    } else {
        addParticipant();
    }
    syncParticipantRemoveButtons();
    userAgreementSigned = document.getElementById('userAgreementSigned')?.value === '1';
    syncAgreementButtons();
    updateProgress();
    updateSidebar();

    document.getElementById('addParticipantBtn').addEventListener('click', () => {
        addParticipant(null, { scrollIntoView: true });
    });
    document.getElementById('nextStepBtn').addEventListener('click', () => goStep(Math.min(currentStep + 1, steps.length)));
    document.getElementById('prevStepBtn').addEventListener('click', () => goStep(Math.max(currentStep - 1, 1)));
    document.getElementById('applicationForm').addEventListener('input', (e) => {
        if (e.target.matches('input[name^="participants["][name$="[surname]"], input[name^="participants["][name$="[name]"], input[name^="participants["][name$="[patronymic]"]')) {
            clearDuplicateParticipantHighlights();
            highlightBlockedParticipants(getBlockedParticipantsInDraft());
        }
        if (e.target.matches('input[name^="participants["][name$="[age]"]')) {
            validateAgeField(e.target);
        } else if (e.target.matches('[required]') && String(e.target.value || '').trim()) {
            clearFieldError(e.target);
        }
        updateSidebar();
    });
    document.getElementById('applicationForm').addEventListener('change', () => {
        updateSidebar();
    });
    document.getElementById('applicationForm').addEventListener('submit', function (e) {
        const actionField = document.getElementById('formAction');
        const submitter = e.submitter || lastFormSubmitter || (document.activeElement instanceof HTMLElement ? document.activeElement : null);
        const submitterAction = submitter?.dataset?.formAction || '';
        if (submitterAction) {
            actionField.value = submitterAction;
        }
        const action = actionField.value;
        if (hasPendingDrawingUploads()) {
            e.preventDefault();
            isSubmittingApplication = false;
            alert('Дождитесь завершения загрузки рисунков, затем повторите сохранение.');
            return;
        }
        if (action === 'submit') {
            isSubmittingApplication = true;
            const blockedParticipants = getBlockedParticipantsInDraft();
            if (blockedParticipants.length > 0) {
                isSubmittingApplication = false;
                e.preventDefault();
                highlightBlockedParticipants(blockedParticipants);
                goStep(2);
                focusFirstBlockedParticipant(blockedParticipants);
                alert('В заявке есть участники, которые ранее нарушили условия пользовательского соглашения в этом конкурсе. Удалите этих участников, после этого отправка станет доступна.');
                return;
            }
            if (currentStep !== steps.length || !isApplicationReadyToSubmit()) {
                isSubmittingApplication = false;
                e.preventDefault();
                if (!validateStep(1)) {
                    goStep(1);
                } else if (!validateStep(2)) {
                    goStep(2);
                } else if (needsPaymentReceipt && !validateStep(paymentStepNumber)) {
                    goStep(paymentStepNumber);
                } else if (!validateStep(agreementStepNumber)) {
                    goStep(agreementStepNumber);
                } else {
                    renderReview();
                }
                return;
            }
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="loading-spinner"></span> Отправляем...';
        } else {
            const btn = submitter;
            if (btn) {
                btn.disabled = true;
            }
        }
        lastFormSubmitter = null;
    });

    <?php if (isset($show_success_modal) && $show_success_modal): ?>
    document.getElementById('successModal').classList.add('active');
    <?php endif; ?>
});
</script>
</body>
</html>
