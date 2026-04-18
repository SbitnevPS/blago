<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (!isAdmin()) {
    jsonResponse(['error' => ['message' => 'Доступ запрещён.']], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => ['message' => 'Метод не поддерживается.']], 405);
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(['error' => ['message' => 'Ошибка безопасности.']], 403);
}

if (empty($_FILES)) {
    jsonResponse(['error' => ['message' => 'Файл не передан.']], 422);
}

$uploadedFile = $_FILES['upload'] ?? null;
if (!is_array($uploadedFile)) {
    foreach ($_FILES as $fileCandidate) {
        if (is_array($fileCandidate) && isset($fileCandidate['tmp_name'])) {
            $uploadedFile = $fileCandidate;
            break;
        }
    }
}

if (!is_array($uploadedFile) || empty($uploadedFile['tmp_name'])) {
    jsonResponse(['error' => ['message' => 'Файл не передан.']], 422);
}

$contestId = max(0, (int) ($_POST['contest_id'] ?? 0));
$admin = getCurrentUser();
$uploadResult = saveEditorImageUpload($uploadedFile, $contestId > 0 ? $contestId : null, (int) ($admin['id'] ?? 0));

if (empty($uploadResult['success'])) {
    jsonResponse(['error' => ['message' => (string) ($uploadResult['message'] ?? 'Не удалось загрузить изображение.')]], 422);
}

jsonResponse(['url' => (string) ($uploadResult['file_url'] ?? '')]);
