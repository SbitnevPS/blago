<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/init.php';

if (!isAdmin()) {
    jsonResponse(['success' => false, 'error' => 'Доступ запрещён'], 403);
}

$applicationId = max(0, (int) ($_GET['id'] ?? 0));
if ($applicationId <= 0) {
    jsonResponse(['success' => false, 'error' => 'Некорректный идентификатор заявки'], 422);
}

$stmt = $pdo->prepare("SELECT a.id, a.status, u.email AS applicant_email FROM applications a LEFT JOIN users u ON u.id = a.user_id WHERE a.id = ? LIMIT 1");
$stmt->execute([$applicationId]);
$application = $stmt->fetch();

if (!$application) {
    jsonResponse(['success' => false, 'error' => 'Заявка не найдена'], 404);
}

$worksStmt = $pdo->prepare("
    SELECT w.id AS work_id
    FROM works w
    INNER JOIN participants p ON p.id = w.participant_id
    WHERE p.application_id = ?
    ORDER BY w.id ASC
");
$worksStmt->execute([$applicationId]);
$workIds = array_map('intval', array_column($worksStmt->fetchAll() ?: [], 'work_id'));

$participants = [];
$summary = [
    'total_items' => 0,
    'ready_items' => 0,
    'skipped_items' => 0,
];
$publicationStatus = getApplicationVkPublicationStatus($applicationId);

if (!empty($workIds)) {
    ensureVkPublicationSchema();
    $filters = normalizeVkTaskFilters([
        'work_status' => 'accepted',
        'exclude_vk_published' => 1,
        'required_data_only' => 1,
        'work_ids_raw' => implode(',', $workIds),
    ]);

    $preview = buildVkTaskPreview($filters, null);
    $summary = [
        'total_items' => (int) ($preview['total_items'] ?? 0),
        'ready_items' => (int) ($preview['ready_items'] ?? 0),
        'skipped_items' => (int) ($preview['skipped_items'] ?? 0),
    ];

    foreach (($preview['items'] ?? []) as $item) {
        $source = (array) ($item['source'] ?? []);
        $imagePath = trim((string) ($item['work_image_path'] ?? ''));
        $participants[] = [
            'participant_id' => (int) ($item['participant_id'] ?? 0),
            'work_id' => (int) ($item['work_id'] ?? 0),
            'fio' => trim((string) ($source['participant_fio'] ?? '')) ?: 'Без имени',
            'preview_image' => $imagePath !== ''
                ? getParticipantDrawingPreviewWebPath((string) ($application['applicant_email'] ?? ''), $imagePath)
                : '',
            'item_status' => (string) ($item['item_status'] ?? 'pending'),
            'skip_reason' => (string) ($item['skip_reason'] ?? ''),
            'is_ready_for_publish' => (string) ($item['item_status'] ?? '') === 'ready',
            'already_published' => !empty($source['vk_published_at']),
        ];
    }
}

jsonResponse([
    'success' => true,
    'participants' => $participants,
    'summary' => $summary,
    'application_vk_status' => $publicationStatus,
]);
