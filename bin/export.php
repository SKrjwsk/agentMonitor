<?php

declare(strict_types=1);

/*
 * Zrzut sesji (to samo co przycisk „zrzut sesji” w UI):
 * php bin/export.php <session_id> [--agent=main|<agentId>]          → var/session_dump/<nazwa>/
 * php bin/export.php <session_id> [--agent=…] --zip=plik.zip         → ZIP
 */

use Viz\Export\SessionExport;
use Viz\Model\Event;
use Viz\Source\HookLog;
use Viz\Source\TranscriptReader;
use Viz\Stats;

require __DIR__ . '/../src/autoload.php';

$sessionId = '';
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(agent|zip)=(.+)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } else {
        $sessionId = $arg;
    }
}
if (!HookLog::validSessionId($sessionId)) {
    fwrite(STDERR, "Usage: php bin/export.php <session_id> [--agent=main|<agentId>] [--zip=file.zip]\n");
    exit(2);
}
$path = TranscriptReader::locate($sessionId);
if ($path === null) {
    fwrite(STDERR, "Transcript not found for session $sessionId\n");
    exit(1);
}

$session = (new TranscriptReader())->read($path);
$agent = (string) ($opts['agent'] ?? '');
$events = $agent === '' ? $session->events : array_values(array_filter($session->events, static fn (Event $e): bool => $e->agentId === $agent));
$export = new SessionExport();
if (isset($opts['zip'])) {
    $export->writeZip($opts['zip'], $session, $events, Stats::of($events), $session->title, 'transcript', $agent);
    echo $opts['zip'], "\n";
    exit(0);
}
$dir = SessionExport::dumpRoot() . '/' . SessionExport::dumpName($session, $agent);
$export->writeDir($dir, $session, $events, Stats::of($events), $session->title, 'transcript', $agent);
echo $dir, "\n";
