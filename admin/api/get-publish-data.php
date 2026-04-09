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

$previewHtml = '<div class="text-secondary">В заявке нет работ для публикации.</div>';

if (!empty($workIds)) {
    ensureVkPublicationSchema();
    $filters = normalizeVkTaskFilters([
        'application_status' => 'approved',
        'work_status' => 'accepted',
        'exclude_vk_published' => 1,
        'required_data_only' => 1,
        'work_ids_raw' => implode(',', $workIds),
    ]);

    $preview = buildVkTaskPreview($filters, null);
    $firstReady = null;
    foreach (($preview['items'] ?? []) as $item) {
        if (($item['item_status'] ?? '') === 'ready') {
            $firstReady = $item;
            break;
        }
    }

    if ($firstReady) {
        $postText = nl2br(htmlspecialchars((string) ($firstReady['post_text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $imagePath = trim((string) ($firstReady['work_image_path'] ?? ''));
        $imageWebPath = $imagePath !== ''
            ? getParticipantDrawingWebPath((string) ($application['applicant_email'] ?? ''), $imagePath)
            : '';

        $previewHtml = '<div style="display:grid; gap:10px;">';
        if ($imageWebPath !== '') {
            $previewHtml .= '<img src="' . htmlspecialchars($imageWebPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="Превью" style="width:100%; max-height:260px; object-fit:cover; border-radius:10px;">';
        }
        $previewHtml .= '<div style="white-space:normal; line-height:1.45;">' . $postText . '</div>';
        $previewHtml .= '</div>';
    }
}

try {
    $readiness = verifyVkPublicationReadiness(true);
    if (empty($readiness['ok'])) {
        throw new RuntimeException((string) ($readiness['issues'][0] ?? 'VK не готов к загрузке целей донатов.'));
    }

    $settings = $readiness['settings'];
    $client = new VkApiClient((string) $settings['publication_token'], (int) $settings['group_id'], (string) $settings['api_version']);
    $vkGoals = $client->getDonutGoals();

    $donationGoals = [];
    $seenVkDonateIds = [];

    foreach ($vkGoals as $goal) {
        if (!is_array($goal)) {
            continue;
        }

        $vkDonateId = trim((string) ($goal['id'] ?? $goal['goal_id'] ?? $goal['vk_donate_id'] ?? ''));
        if ($vkDonateId === '') {
            continue;
        }

        $isActive = !array_key_exists('is_active', $goal) || (int) $goal['is_active'] === 1;
        $title = trim((string) ($goal['title'] ?? $goal['name'] ?? ''));
        $description = trim((string) ($goal['description'] ?? ''));

        upsertVkDonate([
            'vk_donate_id' => $vkDonateId,
            'title' => $title,
            'description' => $description,
            'is_active' => $isActive ? 1 : 0,
        ]);

        $cachedGoal = getVkDonateByVkId($vkDonateId);
        if ($cachedGoal) {
            $seenVkDonateIds[] = $vkDonateId;
        }

        if (!$isActive) {
            continue;
        }

        $donationGoals[] = [
            'id' => (int) ($cachedGoal['id'] ?? 0),
            'title' => $title !== '' ? $title : ('Цель #' . $vkDonateId),
            'description' => $description,
            'vk_donate_id' => $vkDonateId,
        ];
    }

    if (empty($seenVkDonateIds)) {
        $pdo->exec("UPDATE vk_donates SET is_active = 0");
    } else {
        $placeholders = implode(',', array_fill(0, count($seenVkDonateIds), '?'));
        $stmt = $pdo->prepare("UPDATE vk_donates SET is_active = 0 WHERE vk_donate_id NOT IN ($placeholders)");
        $stmt->execute($seenVkDonateIds);
    }

    usort($donationGoals, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    });
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'error' => 'Не удалось получить список целей донатов из VK сообщества: ' . $e->getMessage(),
    ], 422);
}

jsonResponse([
    'success' => true,
    'preview_html' => $previewHtml,
    'donation_goals' => $donationGoals,
]);
