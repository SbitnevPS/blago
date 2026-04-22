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

function ensureParticipantNumberCountersSchema(): void
{
    global $pdo;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS participant_number_counters (
                contest_id INT NOT NULL PRIMARY KEY,
                last_ordinal INT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
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

function getAssignedParticipantsCountInContest(int $contestId): int
{
    global $pdo;

    if ($contestId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM participants p
        INNER JOIN applications a ON a.id = p.application_id
        WHERE a.contest_id = ?
          AND p.public_number IS NOT NULL
          AND TRIM(p.public_number) <> ''
    ");
    $stmt->execute([$contestId]);
    return (int) $stmt->fetchColumn();
}

function reserveNextParticipantOrdinal(int $contestId): int
{
    global $pdo;

    if ($contestId <= 0) {
        return 0;
    }

    $initialOrdinal = getAssignedParticipantsCountInContest($contestId);

    $stmtInit = $pdo->prepare("
        INSERT INTO participant_number_counters (contest_id, last_ordinal)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE contest_id = contest_id
    ");
    $stmtInit->execute([$contestId, $initialOrdinal]);

    $stmtBump = $pdo->prepare("
        UPDATE participant_number_counters
        SET last_ordinal = LAST_INSERT_ID(last_ordinal + 1)
        WHERE contest_id = ?
    ");
    $stmtBump->execute([$contestId]);

    return (int) $pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
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
    ensureParticipantNumberCountersSchema();

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

    $startedTransaction = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $participantOrdinal = reserveNextParticipantOrdinal($contestId);
        if ($participantOrdinal <= 0) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return null;
        }

        $public = computeParticipantPublicNumber((int) ($meta['year'] ?? 0), (int) ($meta['ordinal'] ?? 0), $participantOrdinal);
        if ($public === '') {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return null;
        }

        $pdo->prepare("UPDATE participants SET public_number = ? WHERE id = ?")->execute([$public, $participantId]);

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
        return $public;
    } catch (Throwable $ignored) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
