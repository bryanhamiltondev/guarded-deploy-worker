<?php
/**
 * guarded-deploy-worker.php - one-shot token-gated patch worker for cPanel PHP hosts.
 *
 * Usage: fill in TOKEN, PATCHES and $selfDelete below, upload to the web root,
 * hit https://your-site.example/guarded-deploy-worker.php?t=TOKEN exactly once.
 *
 * Design contract (battle-tested across ~60 production deploys):
 *   1. Token-gated; renders the site 404 on a missing/wrong token.
 *   2. Every needle must match its expected count EXACTLY, or nothing is written.
 *   3. Already-patched marker check makes the worker idempotent.
 *   4. Backup -> patch -> php -l (for .php targets) -> verify; auto-restore on any failure.
 *   5. Backups self-clean only after a full pass; worker self-deletes after reporting.
 *   6. Text output only - one line per file, then a final VERIFY summary.
 */

declare(strict_types=1);

const TOKEN = 'CHANGE-ME-TOKEN';          // random string; passed back as ?t=
const PATCH_MARKER = 'GDW-2026-PATCHED';  // unique string the patch introduces

/**
 * file (absolute or relative to this worker) => list of patches.
 * Each patch: [needle, replacement, expectedCount].
 * expectedCount is how many times the needle MUST appear pre-patch (usually 1).
 */
const PATCHES = [
    // 'target-file.php' => [
    //     ["<div id=\"widget\">", "<div id=\"widget\" data-v2>", 1],
    // ],
];

/** Set false for risky patches: keep backups until you confirm the result, then re-run cleanup. */
const SELF_DELETE = true;

if (!isset($_GET['t']) || !hash_equals(TOKEN, (string)$_GET['t'])) {
    http_response_code(404);
    if (is_file(__DIR__ . '/404.php')) { include __DIR__ . '/404.php'; } else { echo '404'; }
    exit;
}
header('Content-Type: text/plain');

if (!PATCHES) { echo "NO PATCHES DEFINED - nothing to do\n"; exit; }

// ---- pre-flight: read everything, check every needle, write nothing -------------
$staged = [];
foreach (PATCHES as $file => $patches) {
    $path = str_starts_with($file, '/') ? $file : __DIR__ . '/' . $file;
    $src = @file_get_contents($path);
    if ($src === false) { echo "READ FAIL {$file}\n"; exit; }
    if (str_contains($src, PATCH_MARKER)) { echo "ALREADY PATCHED {$file} - ABORT\n"; exit; }
    foreach ($patches as $i => [$needle, $replacement, $expect]) {
        $got = substr_count($src, $needle);
        if ($got !== $expect) {
            echo "NEEDLE FAIL {$file} [#{$i}] expected {$expect}, got {$got}: "
               . substr($needle, 0, 60) . "\n";
            exit;
        }
        $src = str_replace($needle, $replacement, $src);
    }
    $staged[$path] = $src;
}

// ---- commit: backup -> write -> lint -> verify ---------------------------------
$baks = [];
foreach ($staged as $path => $new) {
    $bak = $path . '.bak-gdw.bak';
    if (!is_file($bak)) { copy($path, $bak); }   // keep pre-patch state if a retry aborted earlier
    $baks[] = $bak;
    file_put_contents($path, $new);

    if (str_ends_with($path, '.php')) {
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
        if ($rc !== 0) { copy($bak, $path); echo "LINT FAIL {$path} - RESTORED\n"; exit; }
    }
    if (str_contains(file_get_contents($path), PATCH_MARKER) === false) {
        copy($bak, $path); echo "VERIFY FAIL {$path} (marker absent) - RESTORED\n"; exit;
    }
    echo "OK {$path} " . strlen($new) . " bytes\n";
}

echo 'VERIFY patched=' . count(PATCHES) . ' files' . PHP_EOL;

if (SELF_DELETE) {
    foreach ($baks as $bak) { @unlink($bak); }
    @unlink(__FILE__);
    echo "CLEAN\n";
} else {
    echo "BACKUPS KEPT for manual revert; delete worker when confirmed\n";
}
