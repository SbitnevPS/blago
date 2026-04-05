<?php
// config.php - Конфигурация базы данных и основные настройки

// Настройки базы данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'kids_contests');
define('DB_USER', 'root');
define('DB_PASS', 'MasTrTun');

// VK API настройки
define('VK_CLIENT_ID', '54520423'); // ID приложения VK
define('VK_CLIENT_SECRET', 'W64AzyO8dPgPgIM2YQdq'); // Защищённый ключ
define('VK_REDIRECT_URI', 'https://kids-contests.ru/admin/vk-auth.php');
define('VK_API_VERSION', '5.131');

// Пути
define('ROOT_PATH', __DIR__);
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('DRAWINGS_PATH', UPLOAD_PATH . '/drawings');
define('DOCUMENTS_PATH', UPLOAD_PATH . '/documents');

// URL
define('SITE_URL', 'https://kids-contests.ru');

// Подключение к базе данных
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die('Ошибка подключения к базе данных: ' . $e->getMessage());
}

// Настройки сессии
session_start();

// Функции
function e($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function csrf_token() {
    return generateCSRFToken();
}

function check_csrf() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $token = $_POST['csrf'] ?? ($_POST['csrf_token'] ?? '');
    if (!verifyCSRFToken($token)) {
        http_response_code(403);
        exit('CSRF token validation failed');
    }
}

function isAuthenticated() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function isAdminAuthenticated() {
    return isset($_SESSION['admin_user_id']) && !empty($_SESSION['admin_user_id']);
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getCurrentAdminId() {
    return $_SESSION['admin_user_id'] ?? null;
}

function getCurrentUser() {
    $sessionUserId = getCurrentUserId() ?? getCurrentAdminId();
    if (!$sessionUserId) {
        return null;
    }
    
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$sessionUserId]);
    return $stmt->fetch();
}

function isAdmin() {
    // Миграция со старой схемы сессии (is_admin + user_id)
    if (!isAdminAuthenticated() && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true && isAuthenticated()) {
        $_SESSION['admin_user_id'] = $_SESSION['user_id'];
    }
    
    if (!isAdminAuthenticated()) {
        return false;
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT id, is_admin FROM users WHERE id = ?");
    $stmt->execute([getCurrentAdminId()]);
    $admin = $stmt->fetch();

    if ($admin && (int) $admin['is_admin'] === 1) {
        return true;
    }
    
    unset($_SESSION['admin_user_id'], $_SESSION['is_admin']);
    return false;
}

function redirect($url) {
    // Убираем .php из URL если есть
    $url = str_replace('.php', '', $url);
    
    // Если URL не начинается с /, добавляем его
    if (strpos($url, '/') !== 0 && strpos($url, 'http') !== 0) {
        $url = '/' . $url;
    }
    
    header('Location: ' . $url);
    exit;
}

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function isPostRequest() {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function jsonResponse($payload, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

// Функции для работы с загрузками
function uploadFile($file, $directory, $allowedTypes = []) {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'message' => 'Ошибка загрузки файла'];
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Ошибка: ' . $file['error']];
    }
    
    $fileName = $file['name'];
    $fileSize = $file['size'];
    $tmpName = $file['tmp_name'];
    
    // Проверка расширения
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    if (!empty($allowedTypes) && !in_array($ext, $allowedTypes)) {
        return ['success' => false, 'message' => 'Недопустимый тип файла'];
    }
    
    // Генерация уникального имени
    $newFileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $fileName);
    $targetPath = $directory . '/' . $newFileName;
    
    if (move_uploaded_file($tmpName, $targetPath)) {
        return ['success' => true, 'filename' => $newFileName];
    }
    
    return ['success' => false, 'message' => 'Не удалось сохранить файл'];
}

// Функция для получения конкурса
function getContestById($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM contests WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Функция для получения активных конкурсов
function getActiveContests() {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT * FROM contests 
        WHERE is_published = 1 
        AND (date_from IS NULL OR date_from <= CURRENT_DATE)
        AND (date_to IS NULL OR date_to >= CURRENT_DATE)
        ORDER BY date_from DESC, created_at DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

// Функция для получения заявок пользователя
function getUserApplications($userId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT a.*, c.title as contest_title, c.id as contest_id,
               (SELECT COUNT(*) FROM participants WHERE application_id = a.id) as participants_count
        FROM applications a
        JOIN contests c ON a.contest_id = c.id
        WHERE a.user_id = ?
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// Функция для создания превью изображения
function createThumbnail($sourcePath, $outputDir, $outputFilename, $maxWidth, $maxHeight) {
    if (!extension_loaded('gd')) {
        return false;
    }
    
    $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    
    // Определяем тип изображения
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $image = imagecreatefromjpeg($sourcePath);
            break;
        case 'png':
            $image = imagecreatefrompng($sourcePath);
            break;
        case 'gif':
            $image = imagecreatefromgif($sourcePath);
            break;
        case 'webp':
            $image = imagecreatefromwebp($sourcePath);
            break;
        case 'tif':
        case 'tiff':
            $image = imagecreatefromstring(file_get_contents($sourcePath));
            break;
        case 'bmp':
            $image = imagecreatefromstring(file_get_contents($sourcePath));
            break;
        default:
            return false;
    }
    
    if (!$image) {
        return false;
    }
    
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Вычисляем размеры превью
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = round($width * $ratio);
    $newHeight = round($height * $ratio);
    
    // Создаем новое изображение
    $thumb = imagecreatetruecolor($newWidth, $newHeight);
    
    // Для PNG сохраняем прозрачность
    if ($ext === 'png') {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
        imagefill($thumb, 0, 0, $transparent);
    }
    
    // Изменяем размер
    imagecopyresampled($thumb, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    // Сохраняем как JPEG
    $outputPath = $outputDir . '/' . $outputFilename;
    imagejpeg($thumb, $outputPath, 85);
    
    // Освобождаем память
    imagedestroy($image);
    imagedestroy($thumb);
    
 // Возвращаем URL для превью
 return '/uploads/drawings/temp/' . $outputFilename;
}

// Функция для очистки имени файла
function sanitizeFilename($name) {
 $name = preg_replace('/[^а-яёА-ЯЁa-zA-Z0-9]/u', '', $name);
 return mb_substr($name,0,50);
}

// Функция для обработки и сохранения изображения с ресайзом до1500x1500 и конвертацией в JPG
function processAndSaveImage($sourcePath, $outputDir, $filename) {
 if (!extension_loaded('gd')) {
 return false;
 }
    
 $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    
 // Определяем тип изображения
 switch ($ext) {
 case 'jpg':
 case 'jpeg':
 $image = imagecreatefromjpeg($sourcePath);
 break;
 case 'png':
 $image = imagecreatefrompng($sourcePath);
 break;
 case 'gif':
 $image = imagecreatefromgif($sourcePath);
 break;
 case 'webp':
 $image = imagecreatefromwebp($sourcePath);
 break;
 case 'tif':
 case 'tiff':
 case 'bmp':
 $image = imagecreatefromstring(file_get_contents($sourcePath));
 break;
 default:
 $image = imagecreatefromstring(file_get_contents($sourcePath));
 }
    
 if (!$image) {
 return false;
 }
    
 $width = imagesx($image);
 $height = imagesy($image);
 $maxSize =1500;
    
 // Если изображение меньше нужного размера, не увеличиваем
 if ($width <= $maxSize && $height <= $maxSize) {
 $newWidth = $width;
 $newHeight = $height;
 } else {
 // Вычисляем новые размеры (пропорционально)
 if ($width > $height) {
 $newWidth = $maxSize;
 $newHeight = round($height * ($maxSize / $width));
 } else {
 $newHeight = $maxSize;
 $newWidth = round($width * ($maxSize / $height));
 }
 }
    
 // Создаем новое изображение с белым фоном
 $resized = imagecreatetruecolor($newWidth, $newHeight);
 $white = imagecolorallocate($resized,255,255,255);
 imagefill($resized,0,0, $white);
    
 // Изменяем размер
 imagecopyresampled($resized, $image,0,0,0,0, $newWidth, $newHeight, $width, $height);
    
 // Обеспечиваем расширение .jpg
 if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'jpg') {
 $filename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
 }
    
 $outputPath = $outputDir . '/' . $filename;
    
 // Сохраняем как JPEG с качеством90%
 imagejpeg($resized, $outputPath,90);
    
 // Освобождаем память
 imagedestroy($image);
 imagedestroy($resized);
    
 return $outputPath;
}

function normalizeDrawingOwner($userEmail) {
 $email = trim((string) ($userEmail ?? ''));
 return str_replace(['/', '\\', "\0"], '', $email);
}

function normalizeDrawingFilename($drawingFile) {
 return basename(str_replace("\0", '', (string) ($drawingFile ?? '')));
}

// Получить web-путь рисунка участника с учетом старого и нового формата хранения
function getParticipantDrawingWebPath($userEmail, $drawingFile) {
 $safeFile = normalizeDrawingFilename($drawingFile);
 if ($safeFile === '') {
 return null;
 }

 $safeEmail = normalizeDrawingOwner($userEmail);
 $userScopedPath = '/uploads/drawings/' . $safeEmail . '/' . $safeFile;
 $legacyPath = '/uploads/drawings/' . $safeFile;

 $userScopedFs = DRAWINGS_PATH . '/' . $safeEmail . '/' . $safeFile;
 $legacyFs = DRAWINGS_PATH . '/' . $safeFile;

 if ($safeEmail !== '' && file_exists($userScopedFs)) {
 return $userScopedPath;
 }

 if (file_exists($legacyFs)) {
 return $legacyPath;
 }

 return $userScopedPath;
}

// Получить абсолютный путь к файлу рисунка участника на диске
function getParticipantDrawingFsPath($userEmail, $drawingFile) {
 $safeFile = normalizeDrawingFilename($drawingFile);
 if ($safeFile === '') {
 return null;
 }

 $safeEmail = normalizeDrawingOwner($userEmail);
 $userScopedFs = DRAWINGS_PATH . '/' . $safeEmail . '/' . $safeFile;
 if ($safeEmail !== '' && file_exists($userScopedFs)) {
 return $userScopedFs;
 }

 $legacyFs = DRAWINGS_PATH . '/' . $safeFile;
 if (file_exists($legacyFs)) {
 return $legacyFs;
 }

 return null;
}

// Функция для получения корректировок заявки
function getApplicationCorrections($applicationId) {
 global $pdo;
 $stmt = $pdo->prepare("
 SELECT ac.*, u.name as admin_name, u.surname as admin_surname,
 p.fio as participant_fio
 FROM application_corrections ac
 LEFT JOIN users u ON ac.created_by = u.id
 LEFT JOIN participants p ON ac.participant_id = p.id
 WHERE ac.application_id = ?
 ORDER BY ac.created_at DESC
 ");
 $stmt->execute([$applicationId]);
 return $stmt->fetchAll();
}

// Функция для получения неразрешённых корректировок
function getUnresolvedCorrections($applicationId) {
 global $pdo;
 $stmt = $pdo->prepare("
 SELECT * FROM application_corrections 
 WHERE application_id = ? AND is_resolved =0
 ORDER BY created_at DESC
 ");
 $stmt->execute([$applicationId]);
 return $stmt->fetchAll();
}

// Функция для добавления корректировки
function addCorrection($applicationId, $fieldName, $comment, $participantId = null) {
 global $pdo;
 $userId = getCurrentUserId();
 $stmt = $pdo->prepare("
 INSERT INTO application_corrections (application_id, participant_id, field_name, comment, created_by)
 VALUES (?, ?, ?, ?, ?)
 ");
 $stmt->execute([$applicationId, $participantId, $fieldName, $comment, $userId]);
 return $pdo->lastInsertId();
}

// Функция для разрешения корректировки
function resolveCorrection($correctionId) {
 global $pdo;
 $stmt = $pdo->prepare("
 UPDATE application_corrections SET is_resolved =1, resolved_at = NOW()
 WHERE id = ?
 ");
 $stmt->execute([$correctionId]);
}

// Функция для получения сообщений пользователя
function getUserMessages($userId, $limit =20) {
 global $pdo;
 $stmt = $pdo->prepare("
 SELECT m.*, c.title as contest_title, a.id as application_id
 FROM messages m
 LEFT JOIN applications a ON m.application_id = a.id
 LEFT JOIN contests c ON a.contest_id = c.id
 WHERE m.user_id = ?
 ORDER BY m.created_at DESC
 LIMIT ?
 ");
 $stmt->execute([$userId, $limit]);
 return $stmt->fetchAll();
}

// Функция для получения непрочитанных сообщений
function getUnreadMessageCount($userId) {
 global $pdo;
 $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_messages WHERE user_id = ? AND is_read =0");
 $stmt->execute([$userId]);
 return $stmt->fetchColumn();
}

// Функция для создания сообщения
function createMessage($userId, $applicationId, $title, $content) {
 global $pdo;
 $createdBy = getCurrentUserId();
 $stmt = $pdo->prepare("
 INSERT INTO messages (user_id, application_id, title, content, created_by)
 VALUES (?, ?, ?, ?, ?)
 ");
 $stmt->execute([$userId, $applicationId, $title, $content, $createdBy]);
 return $pdo->lastInsertId();
}

// Функция для отметки сообщения как прочитанного
function markMessageAsRead($messageId) {
 global $pdo;
 $stmt = $pdo->prepare("UPDATE messages SET is_read =1 WHERE id = ?");
 $stmt->execute([$messageId]);
}

// Функция для отметки всех сообщений как прочитанных
function markAllMessagesAsRead($userId) {
 global $pdo;
 $stmt = $pdo->prepare("UPDATE messages SET is_read =1 WHERE user_id = ? AND is_read =0");
 $stmt->execute([$userId]);
}

// Функция для поиска пользователей (для автодополнения)
function searchUsers($query) {
 global $pdo;
 $stmt = $pdo->prepare("
 SELECT id, name, surname, email 
 FROM users 
 WHERE name LIKE ? OR surname LIKE ? OR email LIKE ?
 LIMIT 10
 ");
 $searchTerm = '%' . $query . '%';
 $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
 return $stmt->fetchAll();
}

// Функция для проверки разрешено ли редактирование заявки
function canEditApplication($applicationId) {
 global $pdo;
 $stmt = $pdo->prepare("SELECT allow_edit FROM applications WHERE id = ?");
 $stmt->execute([$applicationId]);
 $result = $stmt->fetch();
 return $result && $result['allow_edit'] ==1;
}

// Функция для разрешения редактирования заявки
function allowApplicationEdit($applicationId) {
 global $pdo;
 $stmt = $pdo->prepare("UPDATE applications SET allow_edit =1 WHERE id = ?");
 $stmt->execute([$applicationId]);
}

// Функция для запрета редактирования заявки
function disallowApplicationEdit($applicationId) {
 global $pdo;
 $stmt = $pdo->prepare("UPDATE applications SET allow_edit =0 WHERE id = ?");
 $stmt->execute([$applicationId]);
}
