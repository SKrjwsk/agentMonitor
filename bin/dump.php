<?php

declare(strict_types=1);

/*
 * Interfejs tekstowy: php bin/dump.php <session_id> [--agent=main|<agentId>] [--blind]
 */

use Viz\Export\TextReport;
use Viz\Model\Event;
use Viz\Source\TranscriptReader;
use Viz\Stats;

require __DIR__ . '/../src/autoload.php';

$sessionId = '';
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(agent)=(.+)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif ($arg === '--blind') {
        $opts['blind'] = true;
    } else {
        $sessionId = $arg;
    }
}
if (!Viz\Source\HookLog::validSessionId($sessionId)) {
    fwrite(STDERR, "Usage: php bin/dump.php <session_id> [--agent=main|<agentId>] [--blind]\n");
    exit(2);
}
$path = TranscriptReader::locate($sessionId);
if ($path === null) {
    fwrite(STDERR, "Transcript not found for session $sessionId\n");
    exit(1);
}

$session = (new TranscriptReader())->read($path);
$events = $session->events;
if (isset($opts['agent'])) {
    $events = array_values(array_filter($events, static fn (Event $e): bool => $e->agentId === $opts['agent']));
}

echo TextReport::render($session, $events, Stats::of($events), $session->title, isset($opts['blind']));
