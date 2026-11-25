<?php

declare(strict_types=1);

namespace Viz\Web;

use Viz\Model\Event;
use Viz\Model\Touch;
use Viz\Path;

/**
 * Rows of the "Read" and "Changed" views: a file with operation count, agents and time of first/last touch.
 * Computed from calls that executed (`Event::executed`) — like `Stats`.
 */
final class FileTable
{
    public const SORTS = ['count', 'path', 'agents', 'first', 'last', 'sessions', 'cost', 'time'];

    /**
     * @param list<Event> $events sorted by time
     * @param array<string, list<string>> $problems toolUseId => reasons
     * @param bool $attempted also calls that did not execute (blocked, tool error) — files the agent tried to reach
     * @return list<array{path: string, hash: string, ops: array<string, int>, reads: int, mods: int, partialOnly: bool, agents: array<string, true>, modAgents: array<string, true>, first: string, last: string, firstMod: ?string, exists: bool, blindEdit: bool, usd: float}>
     *         `usd` = cost of call results in context (Event::carry), split equally among the files read by the call
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
            $seen = array_filter($e->touches, static fn (Touch $t): bool => !in_array($t->op, [Touch::EDIT, Touch::WRITE], true));
            $share = $seen === [] ? 0.0 : ($e->carry['usd'] ?? 0.0) / count($seen);
            foreach ($e->touches as $t) {
                $key = Path::key($t->path);
                $r = $rows[$key] ?? [
                    'path' => $t->path, 'hash' => App::fileHash($t->path), 'ops' => [], 'reads' => 0, 'mods' => 0,
                    'partialOnly' => true, 'agents' => [], 'modAgents' => [], 'first' => $e->ts, 'last' => $e->ts, 'firstMod' => null, 'exists' => $t->exists, 'blindEdit' => false, 'usd' => 0.0,
                ];
                $r['usd'] += in_array($t->op, [Touch::EDIT, Touch::WRITE], true) ? 0.0 : $share;
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
     * Flat list of file touches in call order (not grouped by file): one row = one touch.
     * `usd`/`tokens` = cost of the call result in context, split equally among the files read by that call (writes: 0).
     *
     * @param list<Event> $events sorted by time
     * @param list<string> $ops operations that enter the list
     * @param array<string, list<string>> $problems toolUseId => reasons
     * @return list<array{ts: string, endTs: ?string, id: string, tool: string, agentId: string, path: string, op: string, partial: bool, range: ?string, exists: bool, usd: float, tokens: int, blindEdit: bool}>
     */
    public static function calls(array $events, array $ops, array $problems, bool $attempted = false): array
    {
        $rows = [];
        foreach ($events as $e) {
            if (!$e->executed() && !$attempted) {
                continue;
            }
            $seen = array_filter($e->touches, static fn (Touch $t): bool => !in_array($t->op, [Touch::EDIT, Touch::WRITE], true));
            $usd = $seen === [] ? 0.0 : ($e->carry['usd'] ?? 0.0) / count($seen);
            $tokens = $seen === [] ? 0 : intdiv((int) ($e->carry['tokens'] ?? 0), count($seen));
            foreach ($e->touches as $t) {
                if (!in_array($t->op, $ops, true)) {
                    continue;
                }
                $mod = in_array($t->op, [Touch::EDIT, Touch::WRITE], true);
                $blind = false;
                foreach ($problems[$e->toolUseId] ?? [] as $reason) {
                    $blind = $blind || (str_starts_with($reason, 'edit without prior read') && str_contains(implode("\n", $problems[$e->toolUseId]), basename($t->path)));
                }
                $rows[] = [
                    'ts' => $e->ts, 'endTs' => $e->endTs, 'id' => $e->toolUseId, 'tool' => $e->tool, 'agentId' => $e->agentId,
                    'path' => $t->path, 'op' => $t->op, 'partial' => $t->partial, 'range' => $t->range, 'exists' => $t->exists,
                    'usd' => $mod ? 0.0 : $usd, 'tokens' => $mod ? 0 : $tokens, 'blindEdit' => $mod && $blind,
                ];
            }
        }

        return $rows;
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
            // "all" scope only: number of sessions in which the file appeared
            'sessions' => [count($b['sessions'] ?? []), $b[$countKey]] <=> [count($a['sessions'] ?? []), $a[$countKey]],
            'cost' => [$b['usd'] ?? 0, $b[$countKey]] <=> [$a['usd'] ?? 0, $a[$countKey]],
            default => [$b[$countKey], $a['path']] <=> [$a[$countKey], $b['path']],
        });

        return $rows;
    }
}
