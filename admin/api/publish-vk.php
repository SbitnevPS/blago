<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/init.php';

if (!isAdmin()) {
    jsonResponse(['success' => false, 'error' => 'Доступ запрещён'], 403);
}

$rawBody = file_get_contents('php://input');
$payload = json_decode((string) $rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrfToken = (string) ($payload['csrf_token'] ?? '');
if (!verifyCSRFToken($csrfToken)) {
    jsonResponse(['success' => false, 'error' => 'Ошибка безопасности (CSRF).'], 403);
}

$applicationId = max(0, (int) ($payload['application_id'] ?? 0));
$donateIdRaw = (string) ($payload['donate_id'] ?? 'none');
if ($applicationId <= 0) {
    jsonResponse(['success' => false, 'error' => 'Некорректный идентификатор заявки.'], 422);
}

$appStmt = $pdo->prepare("SELECT id, status FROM applications WHERE id = ? LIMIT 1");
$appStmt->execute([$applicationId]);
$application = $appStmt->fetch();
if (!$application) {
    jsonResponse(['success' => false, 'error' => 'Заявка не найдена.'], 404);
}

$workIdsStmt = $pdo->prepare("
    SELECT w.id
    FROM works w
    INNER JOIN participants p ON p.id = w.participant_id
    WHERE p.application_id = ?
    ORDER BY w.id ASC
");
$workIdsStmt->execute([$applicationId]);
$workIds = array_map('intval', array_column($workIdsStmt->fetchAll() ?: [], 'id'));

if (empty($workIds)) {
    jsonResponse(['success' => false, 'error' => 'В заявке нет работ для публикации.'], 422);
}

$extraWallParams = [];
if ($donateIdRaw !== 'none') {
    $donateId = max(0, (int) $donateIdRaw);
    if ($donateId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Некорректный донат.'], 422);
    }

    ensureVkDonatesSchema();

    $donateStmt = $pdo->prepare("SELECT id, title, vk_donate_id FROM vk_donates WHERE id = ? AND is_active = 1 LIMIT 1");
    $donateStmt->execute([$donateId]);
    $donate = $donateStmt->fetch();

    if (!$donate) {
        jsonResponse(['success' => false, 'error' => 'Выбранный донат недоступен.'], 422);
    }

    $vkDonateId = trim((string) ($donate['vk_donate_id'] ?? ''));
    if ($vkDonateId === '') {
        jsonResponse(['success' => false, 'error' => 'У выбранного доната не задан vk_donate_id.'], 422);
    }

    $extraWallParams['donut'] = $vkDonateId;
}

ensureVkPublicationSchema();
$filters = normalizeVkTaskFilters([
    'application_status' => 'approved',
    'work_status' => 'accepted',
    'exclude_vk_published' => 1,
    'required_data_only' => 1,
    'work_ids_raw' => implode(',', $workIds),
]);

$preview = buildVkTaskPreview($filters, null);
if ((int) ($preview['ready_items'] ?? 0) <= 0) {
    jsonResponse([
        'success' => false,
        'error' => 'Нет готовых работ для публикации. Проверьте изображения и данные участников.',
    ], 422);
}

$taskId = createVkTaskFromPreview(
    'Публикация по заявке #' . $applicationId . ' от ' . date('d.m.Y H:i'),
    (int) getCurrentAdminId(),
    $preview,
    'immediate'
);

$publishResult = publishVkTask($taskId, [
    'wall_params' => $extraWallParams,
]);

if ((int) ($publishResult['failed'] ?? 0) > 0 || (int) ($publishResult['published'] ?? 0) <= 0) {
    jsonResponse([
        'success' => false,
        'error' => trim((string) ($publishResult['error'] ?? 'VK вернул ошибку при публикации.')),
        'task_id' => $taskId,
    ], 422);
}

$pdo->prepare("UPDATE applications SET status = 'approved', updated_at = NOW() WHERE id = ?")
    ->execute([$applicationId]);

jsonResponse([
    'success' => true,
    'task_id' => $taskId,
    'published' => (int) ($publishResult['published'] ?? 0),
    'failed' => (int) ($publishResult['failed'] ?? 0),
    'total' => (int) ($publishResult['total'] ?? 0),
]);
