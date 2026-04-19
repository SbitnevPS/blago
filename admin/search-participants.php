<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

if (!isAdmin()) {
    echo json_encode([]);
    exit;
}

try {
    $queryRaw = trim((string) ($_GET['q'] ?? ''));
    // UI shows numbers with `#`, so accept `#26123` too.
    $query = ltrim($queryRaw, "# \t\n\r\0\x0B");
    $isNumericQuery = ctype_digit($query);
    if (!$isNumericQuery && mb_strlen($query) < 2) {
        echo json_encode([]);
        exit;
    }

    $limit = (int) ($_GET['limit'] ?? 7);
    $limit = max(1, min(7, $limit));

    if ($isNumericQuery) {
        // Numeric input searches by assigned public number (e.g. 26123).
        $publicLike = $query . '%';
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.public_number,
                p.fio,
                p.region,
                u.email,
                a.id AS application_id
            FROM participants p
            JOIN applications a ON a.id = p.application_id
            JOIN users u ON u.id = a.user_id
            WHERE p.public_number LIKE ?
            ORDER BY p.public_number ASC, p.id ASC
            LIMIT $limit
        ");
        $stmt->execute([$publicLike]);
    } else {
        $searchTerm = '%' . $query . '%';
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.public_number,
                p.fio,
                p.region,
                u.email,
                a.id AS application_id
            FROM participants p
            JOIN applications a ON a.id = p.application_id
            JOIN users u ON u.id = a.user_id
            WHERE p.fio LIKE ?
               OR p.region LIKE ?
               OR u.email LIKE ?
            ORDER BY p.fio ASC, p.id ASC
            LIMIT $limit
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
    }

    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([]);
}
