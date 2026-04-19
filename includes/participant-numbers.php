<?php

function ensureParticipantPublicNumberSchema(): void
{
    global $pdo;

    try {
        $hasColumn = (bool) $pdo->query("SHOW COLUMNS FROM participants LIKE 'public_number'")->fetch(PDO::FETCH_ASSOC);
        if (!$hasColumn) {
            $pdo->exec("ALTER TABLE participants ADD COLUMN public_number VARCHAR(32) NULL AFTER application_id");
        }
    } catch (Throwable $ignored) {
    }
}

function buildContestOrdinalMapByYear(): array
{
    global $pdo;

    $rows = $pdo->query("SELECT id, created_at FROM contests ORDER BY created_at ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    $seqByYear = [];
    foreach ($rows as $row) {
        $contestId = (int) ($row['id'] ?? 0);
        $createdAt = (string) ($row['created_at'] ?? '');
        if ($contestId <= 0 || $createdAt === '') {
            continue;
        }
        $year = (int) date('Y', strtotime($createdAt));
        $seqByYear[$year] = (int) ($seqByYear[$year] ?? 0) + 1;
        $map[$contestId] = [
            'year' => $year,
            'year2' => str_pad((string) ($year % 100), 2, '0', STR_PAD_LEFT),
            'ordinal' => (int) $seqByYear[$year],
        ];
    }
    return $map;
}

function computeParticipantOrdinalInContest(int $contestId, int $participantId): int
{
    global $pdo;

    if ($contestId <= 0 || $participantId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM participants p
        INNER JOIN applications a ON a.id = p.application_id
        WHERE a.contest_id = ?
          AND p.id <= ?
    ");
    $stmt->execute([$contestId, $participantId]);
    return (int) $stmt->fetchColumn();
}

function computeParticipantPublicNumber(int $contestYear, int $contestOrdinal, int $participantOrdinal): string
{
    $yy = str_pad((string) ($contestYear % 100), 2, '0', STR_PAD_LEFT);
    return $yy . (string) max(0, $contestOrdinal) . (string) max(0, $participantOrdinal);
}

function assignParticipantPublicNumber(int $participantId, int $contestId, ?array $contestOrdinalMap = null): ?string
{
    global $pdo;

    if ($participantId <= 0 || $contestId <= 0) {
        return null;
    }

    ensureParticipantPublicNumberSchema();

    try {
        $stmt = $pdo->prepare("SELECT public_number FROM participants WHERE id = ? LIMIT 1");
        $stmt->execute([$participantId]);
        $existing = trim((string) $stmt->fetchColumn());
        if ($existing !== '') {
            return $existing;
        }
    } catch (Throwable $ignored) {
        // Column might not exist yet on some installations; schema ensure above should handle it.
    }

    $map = is_array($contestOrdinalMap) ? $contestOrdinalMap : buildContestOrdinalMapByYear();
    $meta = $map[$contestId] ?? null;
    if (!$meta) {
        return null;
    }

    $participantOrdinal = computeParticipantOrdinalInContest($contestId, $participantId);
    if ($participantOrdinal <= 0) {
        return null;
    }

    $public = computeParticipantPublicNumber((int) ($meta['year'] ?? 0), (int) ($meta['ordinal'] ?? 0), $participantOrdinal);
    if ($public === '') {
        return null;
    }

    try {
        $pdo->prepare("UPDATE participants SET public_number = ? WHERE id = ?")->execute([$public, $participantId]);
        return $public;
    } catch (Throwable $ignored) {
        return null;
    }
}

function backfillParticipantPublicNumbers(): int
{
    global $pdo;

    ensureParticipantPublicNumberSchema();

    $map = buildContestOrdinalMapByYear();
    if (empty($map)) {
        return 0;
    }

    $contestIds = array_map('intval', array_keys($map));
    sort($contestIds);

    $updated = 0;
    $stmtParticipants = $pdo->prepare("
        SELECT p.id
        FROM participants p
        INNER JOIN applications a ON a.id = p.application_id
        WHERE a.contest_id = ?
        ORDER BY p.id ASC
    ");
    $stmtUpdate = $pdo->prepare("UPDATE participants SET public_number = ? WHERE id = ? AND (public_number IS NULL OR TRIM(public_number) = '')");

    foreach ($contestIds as $contestId) {
        $meta = $map[$contestId] ?? null;
        if (!$meta) {
            continue;
        }

        $stmtParticipants->execute([$contestId]);
        $ids = $stmtParticipants->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $seq = 0;
        foreach ($ids as $pidRaw) {
            $pid = (int) $pidRaw;
            if ($pid <= 0) {
                continue;
            }
            $seq++;
            $public = computeParticipantPublicNumber((int) ($meta['year'] ?? 0), (int) ($meta['ordinal'] ?? 0), $seq);
            $stmtUpdate->execute([$public, $pid]);
            $updated += (int) $stmtUpdate->rowCount();
        }
    }

    return $updated;
}

function getParticipantDisplayNumber(array $row): string
{
    $public = trim((string) ($row['public_number'] ?? $row['participant_public_number'] ?? ''));
    if ($public !== '') {
        return $public;
    }

    $id = (int) ($row['id'] ?? $row['participant_id'] ?? 0);
    return $id > 0 ? (string) $id : '';
}

