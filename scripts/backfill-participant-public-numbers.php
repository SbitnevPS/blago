<?php
/**
 * CLI utility: backfill participant public numbers.
 *
 * Usage:
 *   php scripts/backfill-participant-public-numbers.php
 *   php scripts/backfill-participant-public-numbers.php --yes
 *
 * By default asks for confirmation to avoid accidental runs.
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/init.php';

$args = $argv ?? [];
$hasYes = in_array('--yes', $args, true) || in_array('-y', $args, true);

if (!$hasYes) {
    fwrite(STDOUT, "This will backfill participants.public_number for existing records where it is empty.\n");
    fwrite(STDOUT, "Continue? Type \"yes\": ");
    $answer = trim((string) fgets(STDIN));
    if ($answer !== 'yes') {
        fwrite(STDOUT, "Cancelled.\n");
        exit(1);
    }
}

try {
    $updated = backfillParticipantPublicNumbers();
    fwrite(STDOUT, "OK. Updated rows: " . (int) $updated . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(2);
}

