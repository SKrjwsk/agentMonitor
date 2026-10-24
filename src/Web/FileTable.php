<?php

declare(strict_types=1);

namespace Viz\Web;

use Viz\Model\Event;
use Viz\Model\Touch;
use Viz\Path;

/**
 * Wiersze widoków „Read” i „Changed”: plik z liczbą operacji, agentami i czasem pierwszego/ostatniego dotknięcia.
 * Liczone z wywołań, które się wykonały (`Event::executed`) — jak `Stats`.
 */
final class FileTable
{
    public const SORTS = ['count', 'path', 'agents', 'first', 'last', 'sessions'];

    /**
     * @param list<Event> $events posortowane po czasie
     * @param array<string, list<string>> $problems toolUseId => powody
     * @param bool $attempted także wywołania, które się nie wykonały (zablokowane, błąd narzędzia) — pliki, do których agent próbował sięgnąć
     * @return list<array{path: string, hash: string, ops: array<string, int>, reads: int, mods: int, partialOnly: bool, agents: array<string, true>, modAgents: array<string, true>, first: string, last: string, firstMod: ?string, exists: bool, blindEdit: bool}>
     */
    public static function build(array $events, array $problems, bool $attempted = false): array
    {
        $rows = [];
        foreach ($events as $e) {
            if (!$e->executed() && !$attempted) {
                continue;
            }
            $blindEdit = false;
            foreach ($problems[$e->toolUseId] ?? [] as $reason) {
                $blindEdit = $blindEdit || str_starts_with($reason, 'edit without prior read');
            }
            foreach ($e->touches as $t) {
                $key = Path::key($t->path);
                $r = $rows[$key] ?? [
                    'path' => $t->path, 'hash' => App::fileHash($t->path), 'ops' => [], 'reads' => 0, 'mods' => 0,
                    'partialOnly' => true, 'agents' => [], 'modAgents' => [], 'first' => $e->ts, 'last' => $e->ts, 'firstMod' => null, 'exists' => $t->exists, 'blindEdit' => false,
                ];
                $r['ops'][$t->op] = ($r['ops'][$t->op] ?? 0) + 1;
                $r['agents'][$e->agentId] = true;
                $r['last'] = $e->ts;
                $r['exists'] = $t->exists;
                if (in_array($t->op, [Touch::READ, Touch::CONTEXT], true)) {
                    $r['reads']++;
                    $r['partialOnly'] = $r['partialOnly'] && $t->partial;
                }
                if (in_array($t->op, [Touch::EDIT, Touch::WRITE], true)) {
                    $r['mods']++;
                    $r['firstMod'] ??= $e->ts;
                    $r['modAgents'][$e->agentId] = true;
                    $r['blindEdit'] = $r['blindEdit'] || ($blindEdit && str_contains(implode("\n", $problems[$e->toolUseId]), basename($t->path)));
                }
                $rows[$key] = $r;
            }
        }

        return array_values($rows);
    }

    /**
     * @param list<array> $rows
     * @return list<array>
     */
    public static function sort(array $rows, string $sort, string $countKey): array
    {
        usort($rows, static fn (array $a, array $b): int => match ($sort) {
            'path' => strnatcasecmp($a['path'], $b['path']),
            'agents' => [count($b[$countKey === 'mods' ? 'modAgents' : 'agents']), $b[$countKey]] <=> [count($a[$countKey === 'mods' ? 'modAgents' : 'agents']), $a[$countKey]],
            'first' => strcmp($a['first'], $b['first']),
            'last' => strcmp($b['last'], $a['last']),
            // tylko zakres „all”: liczba sesji, w których plik się pojawił
            'sessions' => [count($b['sessions'] ?? []), $b[$countKey]] <=> [count($a['sessions'] ?? []), $a[$countKey]],
            default => [$b[$countKey], $a['path']] <=> [$a[$countKey], $b['path']],
        });

        return $rows;
    }
}
