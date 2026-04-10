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
$donationEnabled = (int) ($payload['donation_enabled'] ?? 0) === 1;
$donationGoalId = max(0, (int) ($payload['donation_goal_id'] ?? 0));
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

ensureVkPublicationSchema();
$settings = getVkPublicationSettings();
if ((int) ($settings['group_id'] ?? 0) <= 0) {
    jsonResponse(['success' => false, 'error' => 'Не указан group_id сообщества VK. Проверьте настройки публикации.'], 422);
}
if (trim((string) ($settings['publication_token'] ?? '')) === '') {
    jsonResponse(['success' => false, 'error' => 'Не задан токен публикации VK. Проверьте настройки публикации.'], 422);
}

$extraWallParams = [];
$goal = null;
$publicationMeta = [
    'donation_enabled' => 0,
    'donation_goal_id' => null,
    'vk_donate_id' => null,
];

if ($donationEnabled) {
    $donationSupport = getVkDonationAttachmentSupport();
    if (empty($donationSupport['supported'])) {
        $error = (string) ($donationSupport['message'] ?? 'Публикация с целью доната через VK API недоступна.');
        vkPublicationLog('application_publish_donation_not_supported', [
            'application_id' => $applicationId,
            'support_code' => (string) ($donationSupport['code'] ?? 'unknown'),
            'requested_goal_id' => $donationGoalId,
        ]);
        jsonResponse(['success' => false, 'error' => $error], 422);
    }

    if ($donationGoalId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Нельзя включить донат без выбора цели.'], 422);
    }

    $goal = getVkDonateById($donationGoalId);
    if (!$goal || (int) ($goal['is_active'] ?? 0) !== 1) {
        jsonResponse(['success' => false, 'error' => 'Выбранная цель доната неактивна или не найдена.'], 422);
    }

    $vkDonateId = trim((string) ($goal['vk_donate_id'] ?? ''));
    if ($vkDonateId === '') {
        jsonResponse(['success' => false, 'error' => 'Нельзя публиковать с пустым vk_donate_id.'], 422);
    }

    $publicationMeta = [
        'donation_enabled' => 1,
        'donation_goal_id' => $donationGoalId,
        'vk_donate_id' => $vkDonateId,
    ];
}

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
    'immediate',
    $publicationMeta
);

$publishResult = publishVkTask($taskId, [
    'wall_params' => $extraWallParams,
]);
$taskAfterPublish = getVkTaskById($taskId);
$postUrl = trim((string) ($taskAfterPublish['vk_post_url'] ?? ''));
$published = (int) ($publishResult['published'] ?? 0);
$failed = (int) ($publishResult['failed'] ?? 0);
$apiError = trim((string) ($publishResult['error'] ?? ''));
$isDonationHardFail = $donationEnabled && $failed > 0;

if ($failed > 0 || $published <= 0 || $isDonationHardFail) {
    vkPublicationLog('application_publish_failed', [
        'application_id' => $applicationId,
        'task_id' => $taskId,
        'donation_enabled' => $donationEnabled ? 1 : 0,
        'donation_goal_id' => $donationGoalId,
        'vk_donate_id' => $publicationMeta['vk_donate_id'] ?? '',
        'error' => $apiError,
        'failed' => $failed,
        'published' => $published,
    ]);
    jsonResponse([
        'success' => false,
        'publication_type' => $donationEnabled ? 'with_donation' : 'regular',
        'task_id' => $taskId,
        'published' => $published,
        'failed' => $failed,
        'error' => $apiError !== '' ? $apiError : ($isDonationHardFail
            ? 'Публикация остановлена: не все работы удалось опубликовать с выбранным донатом.'
            : 'VK вернул ошибку при публикации.'),
        'donation_title' => $goal['title'] ?? '',
        'post_url' => $postUrl !== '' ? $postUrl : null,
    ], 422);
}

$pdo->prepare("UPDATE applications SET status = 'approved', updated_at = NOW() WHERE id = ?")
    ->execute([$applicationId]);

jsonResponse([
    'success' => true,
    'task_id' => $taskId,
    'publication_type' => $donationEnabled ? 'with_donation' : 'regular',
    'donation_title' => $goal['title'] ?? '',
    'published' => $published,
    'failed' => $failed,
    'total' => (int) ($publishResult['total'] ?? 0),
    'error' => $apiError,
    'post_url' => $postUrl !== '' ? $postUrl : null,
]);
