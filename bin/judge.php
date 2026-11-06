<?php

declare(strict_types=1);

/*
 * Ocena sesji przez sędziego LLM (uruchamia ją w tle przycisk w zakładce „Ocena”, SessionJudge::start):
 * php bin/judge.php <session_id> <folder zrzutu> <stamp>
 * → var/judge/<session_id>/<stamp>.json + .md; `<stamp>.running` znika po zakończeniu. Ręcznie: najpierw zrzut (php bin/export.php),
 * stamp w formacie RRRRMMDD-GGMMSS.
 */

use Viz\Judge\SessionJudge;
use Viz\Source\HookLog;

require __DIR__ . '/../src/autoload.php';

[, $sid, $dump, $stamp] = $argv + [null, '', '', ''];
if (!HookLog::validSessionId($sid) || !is_dir($dump) || !SessionJudge::validStamp($stamp)) {
    fwrite(STDERR, "usage: php bin/judge.php <session_id> <dump folder> <stamp YYYYMMDD-HHMMSS>\n");
    exit(2);
}
$dir = SessionJudge::dir($sid);
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}
$started = time();
$proc = proc_open(SessionJudge::command($sid, $dump), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, SessionJudge::workDir());
$stdout = $stderr = '';
$timedOut = false;
$launched = is_resource($proc);
if ($launched) {
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        if (!proc_get_status($proc)['running']) {
            break;
        }
        if (time() - $started > SessionJudge::TIMEOUT) {
            proc_terminate($proc);
            $timedOut = true;
            break;
        }
        usleep(200_000);
    }
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    proc_close($proc);
}

$r = $launched ? SessionJudge::parse($stdout)
    : ['ok' => false, 'judgment' => null, 'error' => 'claude did not start', 'costUsd' => null, 'turns' => null, 'durationMs' => null, 'judgeSession' => null];
if ($timedOut) {
    $r = ['ok' => false, 'judgment' => null, 'error' => 'timeout after ' . SessionJudge::TIMEOUT . ' s'] + $r;
}
if (!$r['ok'] && trim($stderr) !== '') {
    $r['error'] .= ' | stderr: ' . mb_substr(trim($stderr), -500);
}
$manifest = json_decode((string) @file_get_contents($dump . '/manifest.json'), true);
$title = is_array($manifest) && is_string($manifest['title'] ?? null) ? $manifest['title'] : $sid;
$record = $r + ['model' => SessionJudge::MODEL, 'dump' => $dump, 'started' => $started, 'finished' => time(), 'title' => $title,
    'transcript' => is_array($manifest) && is_string($manifest['transcript_path'] ?? null) ? $manifest['transcript_path'] : null];

file_put_contents($dir . '/' . $stamp . '.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
chmod($dir . '/' . $stamp . '.json', 0600);
if ($r['ok']) {
    file_put_contents($dir . '/' . $stamp . '.md', SessionJudge::markdown($sid, $record + ['stamp' => $stamp]));
    chmod($dir . '/' . $stamp . '.md', 0600);
}
@unlink($dir . '/' . $stamp . '.running');
exit($r['ok'] ? 0 : 1);
