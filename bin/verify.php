<?php

declare(strict_types=1);

/*
 * Weryfikacja aplikacji logiem systemowym:
 *   php bin/verify.php <log> <session_id> --root=<katalog projektu> [--src=transcript|hook] [--agent=<id>]
 * Log: `sudo fs_usage -w -f filesys` (macOS) albo `strace -f -tt -e trace=openat` (Linux). Procedura: README.md.
 */

use Viz\Model\Event;
use Viz\Source\HookLog;
use Viz\Source\TranscriptReader;
use Viz\Verify\FsLog;
use Viz\Verify\Verifier;

require __DIR__ . '/../src/autoload.php';

$pos = [];
$opts = ['src' => 'transcript'];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(root|src|agent)=(.+)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } else {
        $pos[] = $arg;
    }
}
[$logFile, $sessionId] = $pos + ['', ''];
if (!is_file($logFile) || !HookLog::validSessionId($sessionId) || !isset($opts['root'])) {
    fwrite(STDERR, "Usage: php bin/verify.php <log> <session_id> --root=<dir> [--src=transcript|hook] [--agent=<id>]\n");
    exit(2);
}

if ($opts['src'] === 'hook') {
    $session = (new HookLog())->read($sessionId);
} else {
    $path = TranscriptReader::locate($sessionId);
    if ($path === null) {
        fwrite(STDERR, "Transcript not found for session $sessionId\n");
        exit(1);
    }
    $session = (new TranscriptReader())->read($path);
}
$events = isset($opts['agent'])
    ? array_values(array_filter($session->events, static fn (Event $e): bool => $e->agentId === $opts['agent']))
    : $session->events;

$r = (new Verifier())->compare($events, FsLog::parse($logFile), $opts['root']);

echo "Time window (local): {$r['window'][0]} – {$r['window'][1]} · noise skipped: {$r['noise']}\n\n";
echo 'MATCHED (' . count($r['ok']) . ")\n";
foreach ($r['ok'] as $p) {
    echo "  $p\n";
}
echo "\nAPP ONLY (" . count($r['appOnly']) . ") — parser reported, log did not confirm\n";
foreach ($r['appOnly'] as $p) {
    echo "  $p\n";
}
echo "\nLOG ONLY — the app did not show (by process)\n";
foreach ($r['logOnly'] as $proc => $paths) {
    echo "  [$proc] " . count($paths) . "\n";
    foreach (array_slice($paths, 0, 20) as $p) {
        echo "    $p\n";
    }
}
