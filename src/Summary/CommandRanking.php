<?php

declare(strict_types=1);

namespace Viz\Summary;

use Viz\Extract\ToolRoles;
use Viz\Web\App;

/** Ranking programów ze wszystkich sesji (M11): częstość, fakt użycia, błędy, sąsiedztwo w potokach. */
final class CommandRanking
{
    public const SORTS = ['count', 'program', 'sessions', 'failed'];

    /**
     * @param list<array<string, mixed>> $sessions agregaty z SummaryStore / LiveSummary
     * @return list<array<string, mixed>>
     */
    public static function build(array $sessions, int $days, string $project, string $sort): array
    {
        $cutoff = $days > 0 ? gmdate('Y-m-d\TH:i:s', time() - $days * 86400) : '';
        $rows = [];
        foreach ($sessions as $s) {
            if ($project !== '' && App::fileHash((string) ($s['cwd'] ?? '')) !== $project) {
                continue;
            }
            if ($cutoff !== '' && (string) ($s['last'] ?? '') < $cutoff) {
                continue;
            }
            foreach ((array) ($s['commands'] ?? []) as $program => $c) {
                $program = (string) $program;
                $r = $rows[$program] ?? ['program' => $program, 'count' => 0, 'failed' => 0, 'blocked' => 0, 'agents' => 0, 'sessions' => [], 'last' => '', 'from' => [], 'subs' => [], 'contexts' => []];
                $r['count'] += $c['count'];
                $r['failed'] += $c['failed'];
                $r['blocked'] += $c['blocked'];
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
            $rows[$program]['role'] = ToolRoles::role($program);
        }
        uasort($rows, match ($sort) {
            'program' => static fn (array $a, array $b): int => strnatcasecmp($a['program'], $b['program']),
            'sessions' => static fn (array $a, array $b): int => [count($b['sessions']), $b['count']] <=> [count($a['sessions']), $a['count']],
            'failed' => static fn (array $a, array $b): int => [$b['failed'] + $b['blocked'], $b['count']] <=> [$a['failed'] + $a['blocked'], $a['count']],
            default => static fn (array $a, array $b): int => [$b['count'], $a['program']] <=> [$a['count'], $b['program']],
        });

        return array_values($rows);
    }
}
