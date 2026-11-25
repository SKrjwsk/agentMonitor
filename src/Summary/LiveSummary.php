<?php

declare(strict_types=1);

namespace Viz\Summary;

use Viz\Source\HookLog;
use Viz\Source\SessionIndex;
use Viz\Source\TranscriptReader;

/**
 * Short windows ("5 min", "1 h") computed from events, not from aggregates: an aggregate knows only whole-session sums
 * and the last touch time, so in a one-minute window it would inflate the numbers.
 */
final class LiveSummary
{
    public const WINDOWS = ['5m' => 300, '1h' => 3600];

    /** @return list<array<string, mixed>> aggregates (SummaryStore format) of sessions active in the window */
    public static function aggregates(int $seconds): array
    {
        $cutoff = gmdate('Y-m-d\TH:i:s', time() - $seconds);
        $reader = new TranscriptReader();
        $out = [];
        foreach (self::candidates($seconds) as $path) {
            $agg = SummaryStore::aggregate($reader->read($path), '', $cutoff);
            if ($agg['calls'] > 0 || $agg['files'] !== []) {
                $out[] = $agg;
            }
        }

        return $out;
    }

    /** Version for live refresh: sizes of active transcripts + a sliding window (every 30 s). */
    public static function version(int $seconds): string
    {
        $v = '';
        foreach (self::candidates($seconds) as $path) {
            $v .= basename($path, '.jsonl') . ':' . SessionIndex::version($path) . ';';
        }

        return substr(sha1($v), 0, 16) . '-' . intdiv(time(), 30);
    }

    /** Transcripts changed in the window (mtime as a cheap superset; the exact filter is the event time boundary). */
    public static function candidates(int $seconds): array
    {
        $since = time() - $seconds;
        $out = [];
        foreach (glob(TranscriptReader::projectsDir() . '/*/*.jsonl') ?: [] as $path) {
            if (!HookLog::validSessionId(basename($path, '.jsonl'))) {
                continue;
            }
            $mtime = (int) @filemtime($path);
            foreach (glob(substr($path, 0, -6) . '/subagents/*.jsonl') ?: [] as $sub) {
                $mtime = max($mtime, (int) @filemtime($sub));
            }
            if ($mtime >= $since) {
                $out[] = $path;
            }
        }

        return $out;
    }
}
