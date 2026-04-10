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

if (!$contest) {
 redirect('contests.php');
}

$user = getCurrentUser();
requireVerifiedEmailOrRedirect($user);
$error = '';
$editingApplicationId = intval($_GET['edit'] ?? 0);
$editingApplication = null;
$editingParticipants = [];
$initialFormData = [];

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
        $workStmt = $pdo->prepare("SELECT participant_id, title FROM works WHERE application_id = ?");
        $workStmt->execute([$editingApplicationId]);
        $workTitles = [];
        foreach ($workStmt->fetchAll() as $workRow) {
            $workTitles[(int)($workRow['participant_id'] ?? 0)] = (string)($workRow['title'] ?? '');
        }
        foreach ($editingParticipants as &$editingParticipant) {
            $participantId = (int)($editingParticipant['id'] ?? 0);
            if ($participantId > 0) {
                $editingParticipant['work_title'] = $workTitles[$participantId] ?? '';
            }
        }
        unset($editingParticipant);
    }
}

// Директория пользователя для рисунков
$userUploadPath = DRAWINGS_PATH . '/' . $user['email'];
$tempPath = DRAWINGS_PATH . '/temp';

// Создание директорий
if (!is_dir(DRAWINGS_PATH)) {
 mkdir(DRAWINGS_PATH,0777, true);
}
if (!is_dir($userUploadPath)) {
 mkdir($userUploadPath,0777, true);
}
if (!is_dir($tempPath)) {
 mkdir($tempPath,0777, true);
}

// Обработка AJAX загрузки изображения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_temp') {
 header('Content-Type: application/json');
    
 if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
 echo json_encode(['success' => false, 'message' => 'Ошибка безопасности']);
 exit;
 }
    
 $participantIndex = intval($_POST['participant_index'] ??0);
    
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
 // Создаем превью
 $previewUrl = createThumbnail($tempFilePath, $tempPath, $tempFilename . '_thumb.jpg',300,300);
            
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

 $safeTempPath = realpath($tempPath);
 if ($safeTempPath === false) {
 echo json_encode(['success' => false, 'message' => 'Временная директория недоступна']);
 exit;
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

 echo json_encode(['success' => true]);
 exit;
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
 if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
 $error = 'Ошибка безопасности. Обновите страницу.';
 } else {
 $action = $_POST['action'];
        
 if ($action === 'save_draft' || $action === 'submit') {
 try {
 $pdo->beginTransaction();
                
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
                
 if (isset($_POST['application_id']) && !empty($_POST['application_id'])) {
 $application_id = $_POST['application_id'];
                    
 $stmt = $pdo->prepare("
	 UPDATE applications SET 
	 parent_fio = ?,
	 source_info = ?,
	 colleagues_info = ?,
	 recommendations_wishes = ?,
	 status = ?,
	 allow_edit = ?,
	 updated_at = NOW()
 WHERE id = ? AND user_id = ?
 ");
 $stmt->execute([
	 $parent_fio,
	 $source_info,
	 $colleagues_info,
	 $recommendations_wishes,
	 $action === 'submit' ? 'submitted' : 'draft',
 $action === 'submit' ? 0 : 1,
 $application_id,
 $user['id']
 ]);
                    
 $pdo->prepare("DELETE FROM participants WHERE application_id = ?")->execute([$application_id]);
 } else {
                $stmt = $pdo->prepare("
                INSERT INTO applications (user_id, contest_id, parent_fio, source_info, colleagues_info, recommendations_wishes, status, allow_edit)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                $user['id'],
                $contest_id,
                $parent_fio,
                $source_info,
                $colleagues_info,
                $recommendations_wishes,
                $action === 'submit' ? 'submitted' : 'draft',
                $action === 'submit' ? 0 : 1,
                ]);
 $application_id = $pdo->lastInsertId();
 }
                
 // Данные организации (общие для всех участников)
 $org_region = trim($_POST['organization_region'] ?? '');
 $org_name = trim($_POST['organization_name'] ?? '');
 $org_address = trim($_POST['organization_address'] ?? '');
 $org_email = trim($_POST['organization_email'] ?? '');
                
 // Участники
 $participants = $_POST['participants'] ?? [];
 $participant_count =0;
                
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
                    
 $age = intval($participant['age'] ??0);
 $workTitle = trim((string)($participant['work_title'] ?? ''));
                    
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

 // Обработка изображения (resize + конвертация в JPG)
 $finalPath = processAndSaveImage($tempFilePath, $userUploadPath, $newFilename);

 if ($finalPath) {
 $drawing_file = basename($finalPath);
 }

 // Удаляем временный файл
 @unlink($tempFilePath);

 // Удаляем превью
 $thumbFile = str_replace(basename($tempFile), 'thumb_' . basename($tempFile), $tempFilePath);
 @unlink($thumbFile);
 }
 }
                    
 if (empty($drawing_file) && !empty($existingDrawing)) {
 $drawing_file = $existingDrawing;
 }
                    
 $stmt = $pdo->prepare("
 INSERT INTO participants (
 application_id, fio, age, region, organization_name, organization_address,
 organization_email, drawing_file
 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
 ");
 $stmt->execute([
 $application_id,
 $fio,
 $age,
 $org_region,
 $org_name,
 $org_address,
 $org_email,
 $drawing_file
 ]);
 $participant_id = (int)$pdo->lastInsertId();

 if ($participant_id > 0) {
 try {
 $workStmt = $pdo->prepare("
 INSERT INTO works (contest_id, application_id, participant_id, title, image_path, status, created_at, updated_at)
 VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW())
 ON DUPLICATE KEY UPDATE
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
 }
                
 $pdo->commit();
                
 if ($action === 'submit') {
 $show_success_modal = true;
 $success_application_id = $application_id;
 $success_participant_count = $participant_count;
 } else {
 $_SESSION['success_message'] = 'Заявка сохранена как черновик';
 redirect('/my-applications');
 }
                
 } catch (Exception $e) {
 $pdo->rollBack();
 $error = 'Ошибка при сохранении заявки: ' . $e->getMessage();
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
	        'organization_email' => $user['email'] ?? '',
	        'source_info' => '',
	        'colleagues_info' => '',
	        'recommendations_wishes' => '',
	    ];
}

generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Заявка на конкурс - ДетскиеКонкурсы.рф</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="container application-wizard-page">
    <div class="flex items-center gap-md mb-lg">
        <a href="/contest/<?= e($contest_id) ?>" class="btn btn--ghost"><i class="fas fa-arrow-left"></i> Назад</a>
    </div>

    <section class="application-hero mb-lg">
        <div class="application-hero__inner">
            <div class="application-hero__eyebrow"><i class="fas fa-bolt"></i> Онлайн-подача</div>
            <h1 class="application-hero__title"><?= e($contest['title']) ?></h1>
            <p class="application-hero__text">Заполните форму по шагам: быстро, спокойно и без потери данных. Добавляйте нескольких участников и загружайте рисунки с превью.</p>
            <div class="application-prep-grid">
                <div class="application-prep-item">👤 Данные участника</div>
                <div class="application-prep-item">🖼 Отдельный рисунок</div>
                <div class="application-prep-item">⏱ 3–5 минут</div>
                <div class="application-prep-item">✅ Отправка онлайн</div>
            </div>
        </div>
    </section>

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

                <section class="wizard-progress card mb-lg">
                    <div class="card__body">
                        <div class="wizard-progress__head">
                            <strong id="mobileStepLabel">Шаг 1 из 3</strong>
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
                                        <option value="<?= e($r) ?>" <?= ($initialFormData['organization_region'] ?? '') === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email организации</label>
                                <input type="email" name="organization_email" class="form-input" id="orgEmail" value="<?= htmlspecialchars($initialFormData['organization_email']) ?>" placeholder="school@example.ru">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Название организации</label>
                                <input type="text" name="organization_name" class="form-input" id="orgName" placeholder="Например: ДШИ №1" value="<?= htmlspecialchars($initialFormData['organization_name']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Адрес организации</label>
                                <textarea name="organization_address" class="form-textarea" rows="2" id="orgAddress" placeholder="Город, улица, дом"><?= htmlspecialchars($initialFormData['organization_address']) ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="wizard-step card mb-lg" data-step="2" hidden>
                    <div class="card__header wizard-card-head">
                        <h3>Шаг 2. Участники</h3>
                        <button type="button" class="btn btn--secondary" id="addParticipantBtn"><i class="fas fa-plus"></i> Добавить участника</button>
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

                <section class="wizard-step card mb-lg" data-step="3" hidden>
                    <div class="card__header"><h3>Шаг 3. Проверка и отправка</h3></div>
                    <div class="card__body" id="reviewContainer"></div>
                </section>

                <div class="wizard-nav mb-lg">
                    <button type="button" class="btn btn--ghost" id="prevStepBtn" disabled><i class="fas fa-arrow-left"></i> Назад</button>
                    <button type="button" class="btn btn--secondary" onclick="saveDraft()"><i class="fas fa-save"></i> Сохранить черновик</button>
                    <button type="button" class="btn btn--primary" id="nextStepBtn">Далее <i class="fas fa-arrow-right"></i></button>
                    <button type="submit" class="btn btn--primary" id="submitBtn" hidden><i class="fas fa-paper-plane"></i> Отправить заявку</button>
                </div>
            </form>
        </div>

        <aside class="application-sidebar card">
            <div class="card__body">
                <h4>Прогресс заявки</h4>
                <div class="sidebar-stat" id="statParticipants">Участников: 0</div>
                <div class="sidebar-stat" id="statDrawings">Рисунков: 0</div>
                <div class="sidebar-stat" id="statConfidence">Заполнено: 0%</div>
                <button type="button" class="btn btn--ghost btn--block mt-md" id="goReviewBtn">Перейти к проверке</button>
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

<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>

<script>
const initialParticipants = <?= json_encode(array_map(function($p) use ($user) {
    $parts = preg_split('/\s+/', trim((string)($p['fio'] ?? '')));
    return [
        'fio' => $p['fio'] ?? '',
        'surname' => $parts[0] ?? '',
        'name' => $parts[1] ?? '',
        'patronymic' => $parts[2] ?? '',
        'age' => $p['age'] ?? '',
        'work_title' => $p['work_title'] ?? '',
        'temp_file' => '',
        'existing_drawing_file' => $p['drawing_file'] ?? '',
        'preview' => !empty($p['drawing_file']) ? getParticipantDrawingWebPath($user['email'] ?? '', $p['drawing_file']) : null,
    ];
}, $editingParticipants), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const csrfToken = '<?= generateCSRFToken() ?>';
const contestId = <?= e($contest_id) ?>;
const steps = ['Заявитель', 'Участники и рисунки', 'Проверка и отправка'];
const draftKey = `applicationDraft:${contestId}:<?= intval($user['id'] ?? 0) ?>`;
let currentStep = 1;
let participantCount = 0;
const isEditingServerDraft = <?= $editingApplication ? 'true' : 'false' ?>;

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

function validateStep(step) {
    let valid = true;
    const stepNode = document.querySelector(`.wizard-step[data-step="${step}"]`);
    if (!stepNode) return true;
    stepNode.querySelectorAll('[required]').forEach((input) => {
        if (!String(input.value || '').trim()) {
            createFieldError(input, 'Это поле обязательно.');
            valid = false;
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
                valid = false;
            }
        });
    }
    return valid;
}

function updateProgress() {
    const percent = Math.round((currentStep / steps.length) * 100);
    document.getElementById('progressBar').style.width = `${percent}%`;
    document.getElementById('progressPercent').textContent = `${percent}%`;
    document.getElementById('mobileStepLabel').textContent = `Шаг ${currentStep} из ${steps.length}`;
    document.getElementById('prevStepBtn').disabled = currentStep === 1;
    document.getElementById('nextStepBtn').hidden = currentStep === steps.length;
    document.getElementById('submitBtn').hidden = currentStep !== steps.length;

    document.querySelectorAll('.wizard-step').forEach((s) => {
        s.hidden = Number(s.dataset.step) !== currentStep;
    });

    const stepContainer = document.getElementById('wizardSteps');
    stepContainer.innerHTML = steps.map((title, index) => {
        const n = index + 1;
        const cls = n === currentStep ? 'active' : (n < currentStep ? 'done' : '');
        return `<button type="button" class="wizard-step-pill ${cls}" onclick="goStep(${n})">${n}. ${title}</button>`;
    }).join('');

    if (currentStep === 3) renderReview();
    updateSidebar();
}

function goStep(step) {
    document.getElementById('formAction').value = 'submit';
    if (step > currentStep && !validateStep(currentStep)) return;
    currentStep = step;
    updateProgress();
    saveLocalDraft();
}

function createParticipantForm(index, data = null) {
    const container = document.createElement('article');
    container.className = 'participant-form';
    container.dataset.index = index;
    container.innerHTML = `
        <div class="participant-form__header">
            <div class="participant-form__title"><span class="participant-form__number">${index + 1}</span> Участник ${index + 1}</div>
            <div class="flex gap-sm">
                <button type="button" class="btn btn--ghost" onclick="toggleParticipant(${index})">Свернуть</button>
                ${index > 0 ? `<button type="button" class="participant-form__remove" onclick="removeParticipant(${index})"><i class="fas fa-trash"></i></button>` : ''}
            </div>
        </div>
        <div class="participant-form__body">
            <div class="form-grid form-grid--3">
                <div class="form-group"><label class="form-label form-label--required">Фамилия</label><input type="text" required name="participants[${index}][surname]" class="form-input" value="${data?.surname || ''}" placeholder="Иванов"></div>
                <div class="form-group"><label class="form-label form-label--required">Имя</label><input type="text" required name="participants[${index}][name]" class="form-input" value="${data?.name || ''}" placeholder="Иван"></div>
                <div class="form-group"><label class="form-label form-label--required">Отчество</label><input type="text" required name="participants[${index}][patronymic]" class="form-input" value="${data?.patronymic || ''}" placeholder="Иванович"></div>
                <div class="form-group"><label class="form-label form-label--required">Возраст</label><input type="number" required min="1" max="18" name="participants[${index}][age]" class="form-input" value="${data?.age || ''}"></div>
                <div class="form-group" style="grid-column: span 2;"><label class="form-label form-label--required">Название рисунка</label><input type="text" required name="participants[${index}][work_title]" class="form-input" value="${data?.work_title || ''}" placeholder="Например: Весеннее настроение"></div>
            </div>
            <input type="hidden" name="participants[${index}][temp_file]" id="temp_file_${index}" value="${data?.temp_file || ''}">
            <input type="hidden" name="participants[${index}][existing_drawing_file]" id="existing_file_${index}" value="${data?.existing_drawing_file || ''}">
            <div class="form-section form-section--boxed">
                <h4 class="form-section__title">Рисунок участника</h4>
                <div class="drawing-layout">
                    <div class="upload-area ${data?.temp_file || data?.existing_drawing_file ? 'has-file' : ''}" id="drawingUpload_${index}">
                        <input type="file" accept="image/*" class="file-upload__input" data-index="${index}">
                        <div class="upload-area__icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div class="upload-area__title" id="upload_title_${index}">${data?.temp_file || data?.existing_drawing_file ? 'Файл загружен' : 'Перетащите рисунок сюда или нажмите для выбора файла'}</div>
                        <div class="upload-area__hint" id="upload_hint_${index}">JPG, PNG, GIF, WebP, TIF до 15MB</div>
                    </div>
                    <div class="drawing-preview-wrap">
                        <div class="drawing-preview ${data?.preview ? 'visible' : ''}" id="preview_${index}">
                            ${data?.preview ? `<img src="${data.preview}" alt="Рисунок" class="drawing-preview__image drawing-preview__image--large drawing-preview__image--clickable" id="preview_img_${index}" onclick="viewDrawing(${index})">` : ''}
                            <div class="drawing-preview__actions">
                                <button type="button" class="drawing-preview__action" onclick="viewDrawing(${index})">Посмотреть</button>
                                <button type="button" class="drawing-preview__action drawing-preview__action--danger" onclick="removeDrawing(${index})">Удалить</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
    return container;
}

function toggleParticipant(index) {
    const card = document.querySelector(`.participant-form[data-index="${index}"] .participant-form__body`);
    if (!card) return;
    card.hidden = !card.hidden;
}

function addParticipant(data = null) {
    const container = document.getElementById('participantsContainer');
    const card = createParticipantForm(participantCount, data);
    container.appendChild(card);
    initUploadArea(participantCount);
    participantCount++;
    updateParticipantsState();
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
            work_title: f.querySelector(`[name="participants[${old}][work_title]"]`)?.value || '',
            temp_file: f.querySelector(`#temp_file_${old}`)?.value || '',
            existing_drawing_file: f.querySelector(`#existing_file_${old}`)?.value || '',
            preview: f.querySelector(`#preview_img_${old}`)?.src || ''
        });
        f.replaceWith(fresh);
        initUploadArea(participantCount);
        participantCount++;
    });
    updateParticipantsState();
}

function updateParticipantsState() {
    document.getElementById('participantsEmpty').style.display = participantCount ? 'none' : 'block';
    updateSidebar();
    saveLocalDraft();
}

function initUploadArea(index) {
    const area = document.getElementById(`drawingUpload_${index}`);
    if (!area) return;
    const input = area.querySelector('input[type="file"]');

    area.addEventListener('click', (e) => {
        if (e.target !== input) input.click();
    });
    area.addEventListener('dragover', (e) => { e.preventDefault(); area.classList.add('dragover'); });
    area.addEventListener('dragleave', (e) => { e.preventDefault(); area.classList.remove('dragover'); });
    area.addEventListener('drop', (e) => {
        e.preventDefault();
        area.classList.remove('dragover');
        if (e.dataTransfer.files.length > 0) handleFileSelect(e.dataTransfer.files[0], index);
    });
    input.addEventListener('change', function () {
        if (this.files.length > 0) handleFileSelect(this.files[0], index);
    });
}

function handleFileSelect(file, index) {
    if (file.size > 15 * 1024 * 1024) {
        const title = document.getElementById(`upload_title_${index}`);
        if (title) {
            title.textContent = 'Файл слишком большой. Допустимо до 15MB.';
            title.style.color = 'var(--color-error)';
        }
        return;
    }
    const area = document.getElementById(`drawingUpload_${index}`);
    const title = document.getElementById(`upload_title_${index}`);
    const hint = document.getElementById(`upload_hint_${index}`);
    title.innerHTML = '<span class="loading-spinner"></span> Загрузка...';

    const formData = new FormData();
    formData.append('action', 'upload_temp');
    formData.append('csrf_token', csrfToken);
    formData.append('participant_index', index);
    formData.append('drawing', file);

    fetch('/application-form?contest_id=' + contestId, { method: 'POST', body: formData })
        .then((r) => r.json())
        .then((data) => {
            if (!data.success) throw new Error(data.message || 'Ошибка загрузки');
            document.getElementById(`temp_file_${index}`).value = data.temp_file;
            let preview = document.getElementById(`preview_${index}`);
            if (!preview) return;
            preview.classList.add('visible');
            preview.innerHTML = `<img src="${data.original_url || data.preview}" alt="Рисунок" class="drawing-preview__image drawing-preview__image--large drawing-preview__image--clickable" id="preview_img_${index}" onclick="viewDrawing(${index})"><div class="drawing-preview__actions"><button type="button" class="drawing-preview__action" onclick="viewDrawing(${index})">Посмотреть</button><button type="button" class="drawing-preview__action drawing-preview__action--danger" onclick="removeDrawing(${index})">Удалить</button></div>`;
            area.classList.add('has-file');
            area.classList.remove('is-invalid');
            title.textContent = `Файл: ${data.original_name}`;
            hint.textContent = 'Загрузка успешна. Можно заменить файл.';
            saveLocalDraft();
            updateSidebar();
        })
        .catch((error) => {
            title.textContent = error.message;
            title.style.color = 'var(--color-error)';
        });
}

function removeDrawing(index) {
    const tempFile = document.getElementById(`temp_file_${index}`)?.value || '';
    const clearUi = () => {
        const area = document.getElementById(`drawingUpload_${index}`);
        const title = document.getElementById(`upload_title_${index}`);
        const hint = document.getElementById(`upload_hint_${index}`);
        const preview = document.getElementById(`preview_${index}`);
        if (preview) preview.classList.remove('visible');
        if (area) area.classList.remove('has-file');
        if (title) title.textContent = 'Перетащите рисунок сюда или нажмите для выбора файла';
        if (hint) hint.textContent = 'JPG, PNG, GIF, WebP, TIF до 15MB';
        const temp = document.getElementById(`temp_file_${index}`);
        const existing = document.getElementById(`existing_file_${index}`);
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
    const participants = [...document.querySelectorAll('#participantsContainer .participant-form')].map((card, index) => {
        const fio = `${card.querySelector(`[name="participants[${index}][surname]"]`)?.value || ''} ${card.querySelector(`[name="participants[${index}][name]"]`)?.value || ''} ${card.querySelector(`[name="participants[${index}][patronymic]"]`)?.value || ''}`.trim();
        const age = card.querySelector(`[name="participants[${index}][age]"]`)?.value || '—';
        const workTitle = card.querySelector(`[name="participants[${index}][work_title]"]`)?.value || '—';
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
                    <span>Название рисунка: ${workTitle}</span>
                </div>
                <button type="button" class="btn btn--ghost" onclick="goStep(2)">Изменить</button>
            </li>
        `;
    });

    const ready = participants.length > 0 && participants.every((_, i) => document.getElementById(`temp_file_${i}`)?.value || document.getElementById(`existing_file_${i}`)?.value);
    review.innerHTML = `
        <div class="review-block">
            <h4>Заявитель</h4>
            <p>${parentName}</p>
            <p>${parentEmail}</p>
        </div>
        <div class="review-block">
            <h4>Участники</h4>
            <ul class="review-list">${participants.join('')}</ul>
        </div>
        <div class="alert ${ready ? 'alert--success' : 'alert--error'}">${ready ? 'Заявка готова к отправке.' : 'Заполните все обязательные поля и добавьте рисунки.'}</div>`;
    document.getElementById('submitBtn').disabled = !ready;
}

function updateSidebar() {
    const drawings = [...document.querySelectorAll('[id^="temp_file_"]')].filter((f) => f.value).length + [...document.querySelectorAll('[id^="existing_file_"]')].filter((f) => f.value).length;
    document.getElementById('statParticipants').textContent = `Участников: ${participantCount}`;
    document.getElementById('statDrawings').textContent = `Рисунков: ${drawings}`;

    const required = document.querySelectorAll('#applicationForm [required]');
    const done = [...required].filter((el) => String(el.value || '').trim()).length;
    const fillPercent = required.length ? Math.round((done / required.length) * 100) : 0;
    document.getElementById('statConfidence').textContent = `Заполнено: ${fillPercent}%`;
}

function saveDraft() {
    document.getElementById('formAction').value = 'save_draft';
    document.getElementById('applicationForm').submit();
}

function saveLocalDraft() {
    if (isEditingServerDraft) return;
    const data = Object.fromEntries(new FormData(document.getElementById('applicationForm')).entries());
    data.currentStep = currentStep;
    localStorage.setItem(draftKey, JSON.stringify(data));
}

function tryRestoreDraft() {
    if (isEditingServerDraft) return;
    const raw = localStorage.getItem(draftKey);
    if (!raw) return;
    const data = JSON.parse(raw);
    Object.entries(data).forEach(([key, value]) => {
        const field = document.querySelector(`[name="${CSS.escape(key)}"]`);
        if (field && field.type !== 'file') field.value = value;
    });
    currentStep = Number(data.currentStep || 1);
    const note = document.createElement('div');
    note.className = 'alert alert--info mb-md';
    note.innerHTML = '<i class="fas fa-info-circle alert__icon"></i><div class="alert__content"><div class="alert__message">Данные формы восстановлены из локального черновика.</div></div>';
    const form = document.getElementById('applicationForm');
    form.insertBefore(note, form.firstElementChild.nextElementSibling);
}

function viewDrawing(index) {
    const img = document.getElementById(`preview_img_${index}`);
    if (!img || !img.src) return;
    const modal = document.getElementById('drawingPreviewModal');
    document.getElementById('drawingPreviewModalImage').src = img.src;
    modal.classList.add('active');
}

function closeDrawingPreviewModal() {
    const modal = document.getElementById('drawingPreviewModal');
    modal.classList.remove('active');
    document.getElementById('drawingPreviewModalImage').src = '';
}

function goToMyApplications() { window.location.href = '/my-applications'; }

document.addEventListener('DOMContentLoaded', function () {
    if (Array.isArray(initialParticipants) && initialParticipants.length) {
        initialParticipants.forEach((p) => addParticipant(p));
    } else {
        addParticipant();
    }

    tryRestoreDraft();
    updateProgress();
    updateSidebar();

    document.getElementById('addParticipantBtn').addEventListener('click', () => {
        addParticipant();
    });
    document.getElementById('goReviewBtn').addEventListener('click', () => goStep(3));
    document.getElementById('nextStepBtn').addEventListener('click', () => goStep(Math.min(currentStep + 1, steps.length)));
    document.getElementById('prevStepBtn').addEventListener('click', () => goStep(Math.max(currentStep - 1, 1)));
    document.getElementById('submitBtn').addEventListener('click', () => {
        document.getElementById('formAction').value = 'submit';
    });
    document.getElementById('applicationForm').addEventListener('input', (e) => {
        if (e.target.matches('[required]') && String(e.target.value || '').trim()) clearFieldError(e.target);
        updateSidebar();
        saveLocalDraft();
    });
    document.getElementById('applicationForm').addEventListener('submit', function (e) {
        const action = document.getElementById('formAction').value;
        if (action === 'submit') {
            if (currentStep !== steps.length || !validateStep(3)) {
                e.preventDefault();
                return;
            }
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="loading-spinner"></span> Отправляем...';
            localStorage.removeItem(draftKey);
        } else {
            const btn = e.submitter;
            if (btn) {
                btn.disabled = true;
            }
        }
    });

    <?php if (isset($show_success_modal) && $show_success_modal): ?>
    document.getElementById('successModal').classList.add('active');
    <?php endif; ?>
});
</script>
</body>
</html>
