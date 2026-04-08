<?php
// application-form.php - Форма заявки
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

// Регионы России
$regions = [
'01. Адыгея', '02. Башкортостан', '03. Бурятия', '04. Алтай', '05. Дагестан', '06. Ингушетия',
'07. Кабардино-Балкария', '08. Калмыкия', '09. Карачаево-Черкесия', '10. Карелия', '11. Коми',
'12. Марий Эл', '13. Мордовия', '14. Саха (Якутия)', '15. Северная Осетия', '16. Татарстан',
'17. Тыва', '18. Удмуртия', '19. Хакасия', '20. Чечня', '21. Чувашия', '22. Алтайский край',
'23. Краснодарский край', '24. Красноярский край', '25. Приморский край', '26. Ставропольский край',
'27. Хабаровский край', '28. Амурская область', '29. Архангельская область', '30. Астраханская область',
'31. Белгородская область', '32. Брянская область', '33. Владимирская область', '34. Волгоградская область',
'35. Вологодская область', '36. Воронежская область', '37. Ивановская область', '38. Иркутская область',
'39. Калининградская область', '40. Калужская область', '41. Кемеровская область', '42. Кировская область',
'43. Костромская область', '44. Курганская область', '45. Курская область', '46. Ленинградская область',
'47. Липецкая область', '48. Магаданская область', '49. Московская область', '50. Мурманская область',
'51. Нижегородская область', '52. Новгородская область', '53. Новосибирская область', '54. Омская область',
'55. Оренбургская область', '56. Орловская область', '57. Пензенская область', '58. Псковская область',
'59. Ростовская область', '60. Рязанская область', '61. Самарская область', '62. Саратовская область',
'63. Сахалинская область', '64. Свердловская область', '65. Смоленская область', '66. Тамбовская область',
'67. Тверская область', '68. Томская область', '69. Тульская область', '70. Тюменская область',
'71. Ульяновская область', '72. Челябинская область', '73. Ярославская область', '74. Москва',
'75. Санкт-Петербург', '76. Севастополь', '77. Еврейская АО', '78. Ненецкий АО',
'79. Ханты-Мансийский АО', '80. Чукотский АО', '81. Ямало-Ненецкий АО', '82. Крым'
];

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
 'original_name' => $file['name']
 ]);
 exit;
 }
 }
    
 echo json_encode(['success' => false, 'message' => 'Ошибка загрузки файла']);
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
 status = ?,
 allow_edit = ?,
 updated_at = NOW()
 WHERE id = ? AND user_id = ?
 ");
 $stmt->execute([
 $parent_fio,
 $source_info,
 $colleagues_info,
 $action === 'submit' ? 'submitted' : 'draft',
 $action === 'submit' ? 0 : 1,
 $application_id,
 $user['id']
 ]);
                    
 $pdo->prepare("DELETE FROM participants WHERE application_id = ?")->execute([$application_id]);
 } else {
                $stmt = $pdo->prepare("
                INSERT INTO applications (user_id, contest_id, parent_fio, source_info, colleagues_info, status, allow_edit)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                $user['id'],
                $contest_id,
                $parent_fio,
                $source_info,
                $colleagues_info,
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

<main class="container" style="padding: var(--space-xl) var(--space-lg); max-width:900px;">
<div class="flex items-center gap-md mb-lg">
<a href="/contest/<?= e($contest_id) ?>" class="btn btn--ghost"><i class="fas fa-arrow-left"></i> Назад</a>
</div>
        
<h1 class="mb-lg"><?= $editingApplication ? 'Редактирование заявки' : 'Заявка на участие в конкурсе' ?></h1>
        
<div class="card mb-lg"><div class="card__body"><h3><?= htmlspecialchars($contest['title']) ?></h3></div></div>

<div class="application-note">
    <strong>Как заполнить заявку</strong>
    <span>Шаг 1 — данные родителя. Шаг 2 — общие данные организации. Шаг 3 — добавьте участников: 1 участник = 1 работа.</span>
</div>
        
 <?php if ($error): ?>
<div class="alert alert--error mb-lg">
<i class="fas fa-exclamation-circle alert__icon"></i>
<div class="alert__content"><div class="alert__message"><?= htmlspecialchars($error) ?></div></div>
</div>
 <?php endif; ?>
        
<div class="application-note">
<strong>Важная информация:</strong>
 Если участие происходит самостоятельно, заполняются:1 (организация),3,6 (ФИО и контактная информация родителя),8,9,10,12. В остальных графах — прочерк.
</div>
        
<form method="POST" enctype="multipart/form-data" id="applicationForm">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="action" value="submit" id="formAction">
<input type="hidden" name="application_id" value="<?= $editingApplication ? intval($editingApplication['id']) : '' ?>">
            
 <!-- Данные родителя -->
<div class="card mb-lg">
<div class="card__header"><h3>Данные родителя/куратора</h3></div>
<div class="card__body">
<div class="form-row">
<div class="form-group">
<label class="form-label form-label--required">Имя</label>
<input type="text" name="parent_name" class="form-input" required placeholder="Иван" value="<?= htmlspecialchars($initialFormData['parent_name']) ?>">
</div>
<div class="form-group">
<label class="form-label form-label--required">Отчество</label>
<input type="text" name="parent_patronymic" class="form-input" required placeholder="Иванович" value="<?= htmlspecialchars($initialFormData['parent_patronymic']) ?>">
</div>
<div class="form-group">
<label class="form-label form-label--required">Фамилия</label>
<input type="text" name="parent_surname" class="form-input" required placeholder="Петров" value="<?= htmlspecialchars($initialFormData['parent_surname']) ?>">
</div>
</div>
</div>
</div>
            
<!-- Место обучения (общее для всех участников) -->
<div class="card mb-lg">
<div class="card__header"><h3>Место обучения</h3></div>
<div class="card__body">
<div class="form-row">
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
<label class="form-label">Название организации</label>
<input type="text" name="organization_name" class="form-input" id="orgName" placeholder="Детская художественная школа №1" value="<?= htmlspecialchars($initialFormData['organization_name']) ?>">
</div>
</div>
<div class="form-group mt-md">
<label class="form-label">Фактический адрес организации</label>
<textarea name="organization_address" class="form-textarea" rows="2" id="orgAddress"><?= htmlspecialchars($initialFormData['organization_address']) ?></textarea>
</div>
<div class="form-group mt-md">
<label class="form-label">Email организации</label>
<input type="email" name="organization_email" class="form-input" id="orgEmail" value="<?= htmlspecialchars($initialFormData['organization_email']) ?>">
</div>
</div>
</div>
            
 <!-- Участники -->
<div id="participantsContainer"></div>
            
<button type="button" class="btn btn--secondary btn--lg btn--block mb-lg" id="addParticipantBtn">
<i class="fas fa-plus"></i> Добавить ещё одного участника
</button>

<p class="text-secondary mb-lg" style="margin-top:-8px;">Каждая карточка участника — отдельная работа с отдельным рисунком.</p>
            
 <!-- Дополнительная информация -->
<div class="card mb-lg">
<div class="card__header"><h3>Дополнительная информация</h3></div>
<div class="card__body">
<div class="form-group">
<label class="form-label">Откуда Вы узнали о Конкурсе?</label>
<input type="text" name="source_info" class="form-input" placeholder="Например: от друзей, из социальных сетей..." value="<?= htmlspecialchars($initialFormData['source_info']) ?>">
</div>
<div class="form-group">
<label class="form-label">Проинформировали ли Вы коллег о Конкурсе?</label>
<input type="text" name="colleagues_info" class="form-input" placeholder="Например: да, около5 человек" value="<?= htmlspecialchars($initialFormData['colleagues_info']) ?>">
</div>
</div>
</div>
            
<div class="flex gap-md">
<button type="button" class="btn btn--secondary btn--lg" onclick="saveDraft()">
<i class="fas fa-save"></i> Сохранить без отправки
</button>
<button type="button" class="btn btn--ghost btn--lg" onclick="cancelApplication()">
<i class="fas fa-times"></i> Отменить
</button>
<button type="submit" class="btn btn--primary btn--lg" style="flex:1;">
<i class="fas fa-paper-plane"></i> Отправить заявку
</button>
</div>
</form>
</main>
    
 <!-- Модальное окно успеха -->
<div class="modal modal--success" id="successModal">
<div class="modal__content">
<div class="modal__icon"><i class="fas fa-check"></i></div>
<div class="modal__body">
<h2>Заявка успешно отправлена!</h2>
<p class="text-secondary mt-md">Количество участников:<strong><?= $success_participant_count ??0 ?></strong></p>
<button class="btn btn--primary btn--lg mt-xl" onclick="goToMyApplications()" style="width:100%;">Хорошо</button>
</div>
</div>
</div>

<footer class="footer">
<div class="container">
<div class="footer__inner">
<p class="footer__text">© <?= date('Y') ?> ДетскиеКонкурсы.рф</p>
</div>
</div>
</footer>
    
<script>
 const initialParticipants = <?= json_encode(array_map(function($p) use ($user) {
     $parts = preg_split('/\s+/', trim((string)($p['fio'] ?? '')));
     return [
         'fio' => $p['fio'] ?? '',
         'surname' => $parts[0] ?? '',
         'name' => $parts[1] ?? '',
         'patronymic' => $parts[2] ?? '',
         'age' => $p['age'] ?? '',
         'temp_file' => '',
         'existing_drawing_file' => $p['drawing_file'] ?? '',
         'preview' => !empty($p['drawing_file']) ? getParticipantDrawingWebPath($user['email'] ?? '', $p['drawing_file']) : null,
     ];
 }, $editingParticipants), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

 let participantCount =0;
 const csrfToken = '<?= generateCSRFToken() ?>';
        
 function createParticipantForm(index, data = null) {
 const container = document.createElement('div');
 container.className = 'participant-form';
 container.dataset.index = index;
            
 const previewHtml = data && data.preview ? `
<div class="drawing-preview visible" id="preview_${index}">
<img src="${data.preview}" alt="Рисунок" class="drawing-preview__image drawing-preview__image--large" id="preview_img_${index}">
<div class="drawing-preview__info" id="preview_info_${index}">Файл загружен</div>
<button type="button" class="drawing-preview__remove" onclick="removeDrawing(${index})">Удалить</button>
</div>` : '';
            
 container.innerHTML = `
<div class="participant-form__header">
<div class="participant-form__title">
<span class="participant-form__number">${index +1}</span>
 Участник ${index +1}
</div>
 ${index >0 ? `<button type="button" class="participant-form__remove" onclick="removeParticipant(${index})"><i class="fas fa-trash"></i></button>` : ''}
</div>
                
<div class="form-section form-section--boxed">
<div class="form-section__title">Данные участника</div>
<div class="form-row">
<div class="form-group">
<label class="form-label form-label--required">Фамилия участника</label>
<input type="text" name="participants[${index}][surname]" class="form-input" required value="${data?.surname || ''}" placeholder="Иванов">
</div>
<div class="form-group">
<label class="form-label form-label--required">Имя участника</label>
<input type="text" name="participants[${index}][name]" class="form-input" required value="${data?.name || ''}" placeholder="Иван">
</div>
<div class="form-group">
<label class="form-label form-label--required">Отчество участника</label>
<input type="text" name="participants[${index}][patronymic]" class="form-input" required value="${data?.patronymic || ''}" placeholder="Иванович">
</div>
</div>
<div class="form-row">
<div class="form-group">
<label class="form-label form-label--required">Возраст</label>
<input type="number" name="participants[${index}][age]" class="form-input" min="1" max="18" required value="${data?.age || ''}">
</div>
</div>
</div>
                
<div class="form-section form-section--boxed form-section--drawing">
<div class="form-section__title">Рисунок участника</div>
<input type="hidden" name="participants[${index}][temp_file]" id="temp_file_${index}" value="${data?.temp_file || ''}">
<input type="hidden" name="participants[${index}][existing_drawing_file]" id="existing_file_${index}" value="${data?.existing_drawing_file || ''}">
<div class="form-group drawing-layout">
<div class="upload-area" id="drawingUpload_${index}">
<input type="file" name="participants[${index}][drawing_file]" accept="image/*" class="file-upload__input" data-index="${index}">
<div class="upload-area__icon"><i class="fas fa-cloud-upload-alt"></i></div>
<div class="upload-area__title" id="upload_title_${index}">Нажмите или перетащите рисунок</div>
<div class="upload-area__hint" id="upload_hint_${index}">JPG, PNG, GIF, WebP, TIF. Можно заменить позже.</div>
</div>
 <div class="drawing-preview-wrap">${previewHtml}</div>
</div>
</div>
 `;
 return container;
 }
                    
 function addParticipant(data = null) {
 const container = document.getElementById('participantsContainer');
 container.appendChild(createParticipantForm(participantCount, data));
 initUploadArea(participantCount);
 participantCount++;
 }
        
 function removeParticipant(index) {
 const container = document.getElementById('participantsContainer');
 const form = container.querySelector(`[data-index="${index}"]`);
 if (form) {
 form.remove();
 let newIndex =0;
 container.querySelectorAll('.participant-form').forEach(f => {
 f.dataset.index = newIndex;
 f.querySelector('.participant-form__number').textContent = newIndex +1;
 f.querySelector('.participant-form__title').textContent = `Участник ${newIndex +1}`;
 newIndex++;
 });
 participantCount = newIndex;
 }
 }
        
 function initUploadArea(index) {
 const area = document.getElementById(`drawingUpload_${index}`);
 const input = area.querySelector('input[type="file"]');
 if (!input) return;
            
 area.addEventListener('click', function(e) {
 if (e.target !== input && !e.target.closest('.drawing-preview')) input.click();
 });
            
 area.addEventListener('dragover', function(e) { e.preventDefault(); area.classList.add('dragover'); });
 area.addEventListener('dragleave', function(e) { e.preventDefault(); area.classList.remove('dragover'); });
 area.addEventListener('drop', function(e) {
 e.preventDefault();
 area.classList.remove('dragover');
 if (e.dataTransfer.files.length >0) handleFileSelect(e.dataTransfer.files[0], index);
 });
            
 input.addEventListener('change', function() {
 if (this.files.length >0) handleFileSelect(this.files[0], index);
 });
 }
        
 const contestId = <?= e($contest_id) ?>;
        
function handleFileSelect(file, index) {
 const area = document.getElementById(`drawingUpload_${index}`);
 const title = document.getElementById(`upload_title_${index}`);
 const hint = document.getElementById(`upload_hint_${index}`);
            
 title.innerHTML = '<span class="loading-spinner"></span> Загрузка...';
            
 const formData = new FormData();
 formData.append('action', 'upload_temp');
 formData.append('csrf_token', csrfToken);
 formData.append('participant_index', index);
 formData.append('drawing', file);
            
 fetch('/application-form?contest_id=' + contestId, {
 method: 'POST',
 body: formData
 })
 .then(response => response.json())
 .then(data => {
 if (data.success) {
 document.getElementById(`temp_file_${index}`).value = data.temp_file;
                    
 let previewContainer = document.getElementById(`preview_${index}`);
 if (!previewContainer) {
 previewContainer = document.createElement('div');
 previewContainer.className = 'drawing-preview visible';
 previewContainer.id = `preview_${index}`;
 previewContainer.innerHTML = `
<img src="${data.preview}" alt="Рисунок" class="drawing-preview__image drawing-preview__image--large" id="preview_img_${index}">
<div class="drawing-preview__info" id="preview_info_${index}">Загружено</div>
<button type="button" class="drawing-preview__remove" onclick="removeDrawing(${index})">Удалить</button>
 `;
 const previewWrap = area.parentElement.querySelector('.drawing-preview-wrap') || area.parentElement;
 previewWrap.appendChild(previewContainer);
 } else {
 previewContainer.classList.add('visible');
 document.getElementById(`preview_img_${index}`).src = data.preview;
 }
        
 area.classList.add('has-file');
 title.textContent = 'Файл загружен: ' + data.original_name;
 title.style.color = 'var(--color-success)';
 hint.textContent = 'Нажмите, чтобы изменить';
 } else {
 title.textContent = 'Ошибка: ' + data.message;
 title.style.color = 'var(--color-error)';
 }
 })
 .catch(error => {
 console.error('Upload error:', error);
 title.textContent = 'Ошибка загрузки';
 title.style.color = 'var(--color-error)';
 });
 }
        
 function removeDrawing(index) {
 const area = document.getElementById(`drawingUpload_${index}`);
 const title = document.getElementById(`upload_title_${index}`);
 const hint = document.getElementById(`upload_hint_${index}`);
 const preview = document.getElementById(`preview_${index}`);
 const tempFile = document.getElementById(`temp_file_${index}`);
 const existingFile = document.getElementById(`existing_file_${index}`);
            
 if (tempFile) tempFile.value = '';
 if (existingFile) existingFile.value = '';
 if (preview) preview.classList.remove('visible');
 area.classList.remove('has-file');
 title.textContent = 'Нажмите или перетащите рисунок';
 title.style.color = '';
 hint.textContent = 'JPG, PNG, GIF, WebP, TIF. Можно заменить позже.';
            
 const input = area.querySelector('input[type="file"]');
 if (input) input.value = '';
 }
        
 function saveDraft() {
 document.getElementById('formAction').value = 'save_draft';
 document.getElementById('applicationForm').submit();
 }
        
function cancelApplication() {
 if (confirm('Вы уверены, что хотите отменить заявку?')) {
 window.location.href = '/contests';
 }
}
        
function goToMyApplications() {
 window.location.href = '/my-applications';
}
        
 document.addEventListener('DOMContentLoaded', function() {
 if (Array.isArray(initialParticipants) && initialParticipants.length >0) {
 initialParticipants.forEach((participant) => addParticipant(participant));
 } else {
 addParticipant();
 }
 document.getElementById('addParticipantBtn').addEventListener('click', function() { addParticipant(); });
            
 <?php if (isset($show_success_modal) && $show_success_modal): ?>
 document.getElementById('successModal').classList.add('active');
 document.body.style.overflow = 'hidden';
 <?php endif; ?>
 });
</script>
</body>
</html>
