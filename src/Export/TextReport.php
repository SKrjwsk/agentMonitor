<?php

declare(strict_types=1);

namespace Viz\Export;

use Viz\Model\Agent;
use Viz\Model\Event;
use Viz\Model\Session;
use Viz\Stats;

/** Tekstowe podsumowanie sesji — wspólne dla `bin/dump.php` i `summary.md` w eksporcie. */
final class TextReport
{
    /** @param list<Event> $events */
    public static function render(Session $session, array $events, Stats $stats, string $title, bool $withBlind): string
    {
        $out = [];
        $out[] = $title !== '' ? $title : $session->id;
        $out[] = 'session: ' . $session->id;
        $out[] = 'cwd: ' . ($session->cwd ?? '?');
        $out[] = 'agents: ' . implode(' | ', array_map(static fn (Agent $a): string => $a->id . ' (' . $a->label() . ')', $session->agents));
        $out[] = '';
        $out[] = "{$stats->calls} calls: " . self::pairs($stats->perTool, ' · ');
        $out[] = "auto-injected context: {$stats->contextFiles} files · read by agent: {$stats->selfReadFiles} · re-read from context: {$stats->contextReread}";
        $out[] = count($stats->files) . ' files: ' . implode(' · ', array_map(static fn ($op, $n): string => "$op $n", array_keys($stats->filesPerOp), $stats->filesPerOp));
        $pct = $stats->bashCalls > 0 ? round(100 * $stats->blind / $stats->bashCalls) : 0;
        $out[] = "blind spots: {$stats->blind} of {$stats->bashCalls} Bash calls ($pct%) · partial parse: {$stats->incomplete} · errors: {$stats->failed}";
        if ($stats->indirect !== []) {
            $out[] = 'indirect: ' . self::pairs(array_slice($stats->indirect, 0, 8), ', ');
        }
        if ($stats->unknown !== []) {
            $out[] = 'unknown commands: ' . self::pairs(array_slice($stats->unknown, 0, 12), ', ');
        }

        $out[] = '';
        $out[] = 'FILES';
        foreach ($stats->files as $f) {
            $ops = implode(' ', array_map(static fn ($op, $n): string => "{$op}×{$n}", array_keys($f['ops']), $f['ops']));
            $flags = ($f['partialOnly'] && (isset($f['ops']['read']) || isset($f['ops']['context'])) ? ' [partial]' : '')
                . ($f['exists'] ? '' : ' [missing]');
            $out[] = '  ' . $f['path'] . '  ' . $ops . $flags;
        }

        if ($withBlind) {
            $out[] = '';
            $out[] = 'BLIND SPOTS';
            foreach ($events as $e) {
                if (!$e->failed && $e->tool === 'Bash' && $e->isBlind()) {
                    $out[] = '  ' . mb_strimwidth($e->summary, 0, 140, '…');
                    foreach (array_merge($e->unresolved, array_map(static fn ($u): string => 'unknown: ' . $u, $e->unknown)) as $why) {
                        $out[] = '      ↳ ' . $why;
                    }
                }
            }
        }

        return implode("\n", $out) . "\n";
    }

    /** @param array<string, int> $counts */
    private static function pairs(array $counts, string $glue): string
    {
        return implode($glue, array_map(static fn ($k, $n): string => "$k ×$n", array_keys($counts), $counts));
    }
}
