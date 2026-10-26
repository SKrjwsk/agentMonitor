<?php

declare(strict_types=1);

namespace Viz\Summary;

use Viz\Source\HookLog;
use Viz\Source\SessionIndex;
use Viz\Source\TranscriptReader;

/**
 * Krótkie okna („5 min”, „1 h”) liczone ze zdarzeń, nie z agregatów: agregat zna tylko sumy całej sesji
 * i czas ostatniego dotknięcia, więc w oknie minutowym zawyżałby liczby.
 */
final class LiveSummary
{
    public const WINDOWS = ['5m' => 300, '1h' => 3600];

    /** @return list<array<string, mixed>> agregaty (format SummaryStore) sesji aktywnych w oknie */
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

    /** Wersja do odświeżania na żywo: rozmiary aktywnych transkryptów + przesuwające się okno (co 30 s). */
    public static function version(int $seconds): string
    {
        $v = '';
        foreach (self::candidates($seconds) as $path) {
            $v .= basename($path, '.jsonl') . ':' . SessionIndex::version($path) . ';';
        }

        return substr(sha1($v), 0, 16) . '-' . intdiv(time(), 30);
    }

    /** Transkrypty zmienione w oknie (mtime jako tani nadzbiór; dokładny filtr robi granica czasu zdarzeń). */
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
