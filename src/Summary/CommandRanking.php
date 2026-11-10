<?php

declare(strict_types=1);

namespace Viz\Summary;

use Viz\Extract\ToolRoles;
use Viz\Web\Scope;

/** Ranking programów ze wszystkich sesji (M11): częstość, fakt użycia, błędy, sąsiedztwo w potokach. */
final class CommandRanking
{
    public const SORTS = ['count', 'program', 'sessions', 'failed', 'time'];

    /**
     * @param list<array<string, mixed>> $sessions agregaty z SummaryStore / LiveSummary
     * @param Scope $scope zakres projektów (katalog roboczy sesji)
     * @return list<array<string, mixed>>
     */
    public static function build(array $sessions, int $days, Scope $scope, string $sort): array
    {
        $cutoff = $days > 0 ? gmdate('Y-m-d\TH:i:s', time() - $days * 86400) : '';
        $rows = [];
        foreach ($sessions as $s) {
            if (!$scope->includes((string) ($s['cwd'] ?? ''))) {
                continue;
            }
            if ($cutoff !== '' && (string) ($s['last'] ?? '') < $cutoff) {
                continue;
            }
            foreach ((array) ($s['commands'] ?? []) as $program => $c) {
                $program = (string) $program;
                $r = $rows[$program] ?? ['program' => $program, 'count' => 0, 'failed' => 0, 'blocked' => 0, 'agents' => 0, 'sessions' => [], 'last' => '', 'from' => [], 'subs' => [], 'contexts' => [], 'ms' => 0, 'max_ms' => 0];
                $r['count'] += $c['count'];
                $r['failed'] += $c['failed'];
                $r['blocked'] += $c['blocked'];
                $r['ms'] += (int) ($c['ms'] ?? 0);
                $r['max_ms'] = max($r['max_ms'], (int) ($c['max_ms'] ?? 0));
                $r['agents'] += $c['agents'];
                $r['sessions'][(string) $s['session_id']] = $c['count'];
                $r['last'] = max($r['last'], (string) $c['last']);
                foreach (['from', 'subs', 'contexts'] as $k) {
                    foreach ((array) $c[$k] as $name => $n) {
                        $r[$k][$name] = ($r[$k][$name] ?? 0) + $n;
                    }
                }
                $rows[$program] = $r;
            }
        }
        foreach ($rows as $program => $r) {
            foreach (['from', 'subs', 'contexts'] as $k) {
                arsort($rows[$program][$k]);
            }
            // klucz „2026” PHP zamienia na int — nazwę bierzemy z wiersza
            $rows[$program]['role'] = ToolRoles::role($r['program']);
        }
        uasort($rows, match ($sort) {
            'program' => static fn (array $a, array $b): int => strnatcasecmp($a['program'], $b['program']),
            'sessions' => static fn (array $a, array $b): int => [count($b['sessions']), $b['count']] <=> [count($a['sessions']), $a['count']],
            'time' => static fn (array $a, array $b): int => [$b['ms'], $b['count']] <=> [$a['ms'], $a['count']],
            'failed' => static fn (array $a, array $b): int => [$b['failed'] + $b['blocked'], $b['count']] <=> [$a['failed'] + $a['blocked'], $a['count']],
            default => static fn (array $a, array $b): int => [$b['count'], $a['program']] <=> [$a['count'], $b['program']],
        });

        return array_values($rows);
    }
}
