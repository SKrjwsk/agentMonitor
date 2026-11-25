<?php

declare(strict_types=1);

namespace Viz\Office;

use Viz\Boundary;
use Viz\Model\Agent;
use Viz\Model\Event;
use Viz\Model\Session;
use Viz\Model\Touch;
use Viz\Signals;
use Viz\Source\HookLog;
use Viz\Web\App;

/**
 * State of the Live view (decision board): sessions active in the `WINDOW` window and what the user can react to. Computed from the same agent
 * rows as the office (`OfficeState::rows`), but with a longer window: a session waiting for the user for 20 min matters most here, and the office has already let it go.
 * The browser builds cells from this ("You", "Boundary", "Context", "Pace") and weights — the thresholds live here so the JS does not guess them.
 *
 * Agent:
 * - states as in the office (`working`, `delegated`, `idle`, `stuck`) + `done` = finished subagent (office row `gone`) in the window
 * - `context` = context of the last response (input + cache read and write), `contextMax` = model window (`CONTEXT_WINDOWS`, otherwise 1M;
 *   when the context exceeds it — the context alone, because the table is outdated)
 * - `reportBlocked` = the subagent's last message-tool call (report, `SendMessage`, assignment) was blocked — the recipient did not get the message
 *
 * Boundary (`boundary`, null = no `AGENT_ALLOWED_*` configuration): calls in the window that `Boundary` classifies:
 * `outside` (executed outside), `trying` (outside the boundary, still running — usually waiting for permission), `stopped` (stopped), `unknown` (Bash without determined paths).
 * The transcript cannot tell a permission request from a long command — the browser shows "permission?" after `PERMISSION_GUESS` s of call duration.
 * With the hook (`HookLog::state`) the request is an event: state `waiting-permission`, in the call `permission` = moment of the request; a session in which the
 * permission hook fired at least once (`permissionHook`) no longer gets guessing. End of session with `SessionEnd` = state `ended` of the main session.
 * `Stop` / `SubagentStart` / `SubagentStop` give an exact "waiting for you" and subagent start / end (`done` from `start` to `last`) — description at `OfficeState`.
 */
final class LiveState
{
    public const WINDOW = 1800;
    public const PERMISSION_GUESS = 90;
    /** Context windows (max input tokens) per model identifier prefix; the rest = `CONTEXT_DEFAULT`. State: API documentation 2026-10-06. */
    public const CONTEXT_WINDOWS = ['claude-haiku-4' => 200_000];
    public const CONTEXT_DEFAULT = 1_000_000;
    private const BOUNDARY_LIMIT = 50;
    /** Agent calls in the window for views (timeline, list): newest. */
    public const CALL_LIMIT = 200;
    /** Session directories on the boundary map: most recently touched. */
    public const MAP_LIMIT = 150;
    private const MESSAGE_TOOLS = ['Agent', 'Task', 'SubagentHandback', 'SendMessage'];
    /** Messages to other sessions (`sends`) in the session row: newest. */
    private const SEND_LIMIT = 50;

    /**
     * Session for the board or null if nobody in it was active in the window. `$whole` = the whole session without window and without call limit
     * (Flow tab in session details) — then null only without a main session. `$hooks` = state events from the hook; null = from `HookLog::state`.
     * `$compactions` = compactions from the hook (`HookLog::compactions`); null = from the session file.
     *
     * @param ?list<array{ts: string, event: string, agent: string, detail: ?string}> $hooks
     * @param ?list<array{ts: string, agent: string, phase: string, trigger: ?string}> $compactions
     * @return ?array<string, mixed>
     */
    public static function session(Session $session, int $now, ?Boundary $limits = null, bool $whole = false, ?array $hooks = null, ?array $compactions = null): ?array
    {
        $hooks ??= HookLog::state($session->id);
        $asks = [];
        foreach ($hooks as $h) {
            if (OfficeState::isPermission($h)) {
                $asks[$h['agent']][] = self::time($h['ts']);
            }
        }
        ['main' => $main, 'subs' => $subs] = OfficeState::rows($session, $now, $limits, $hooks);
        $from = $whole ? 0 : $now - self::WINDOW;
        $subs = array_values(array_filter($subs, static fn (array $a): bool => self::time($a['last']) >= $from));
        if ($main === null || (self::time($main['last']) < $from && $subs === [])) {
            return null;
        }
        $context = [];
        foreach ($session->usage as $u) {
            $context[$u['agent']] = $u;
        }
        $byAgent = [];
        foreach ($session->events as $e) {
            if ($e->tool !== Event::TOOL_CONTEXT) {
                $byAgent[$e->agentId][] = $e;
            }
        }
        // cost of model responses in the window per agent; Flow (`$whole`) has none
        $inWindow = [];
        foreach ($whole ? [] : $session->usage as $u) {
            if ($u['cost'] !== null && self::time($u['ts']) >= $from) {
                $inWindow[$u['agent']] = ($inWindow[$u['agent']] ?? 0.0) + array_sum($u['cost']);
            }
        }
        // files read / changed (unique paths) and Bash commands run in the window — per agent and for the whole session
        $sets = ['' => ['read' => [], 'write' => [], 'bash' => 0]];
        foreach ($byAgent as $id => $events) {
            $sets[$id] = ['read' => [], 'write' => [], 'bash' => 0];
            foreach ($events as $e) {
                if (self::time($e->endTs ?? $e->ts) < $from || !$e->executed()) {
                    continue;
                }
                foreach ($e->touches as $t) {
                    $op = match ($t->op) { Touch::READ => 'read', Touch::EDIT, Touch::WRITE => 'write', default => null };
                    if ($op !== null) {
                        $sets[$id][$op][$t->path] = $sets[''][$op][$t->path] = true;
                    }
                }
                if ($e->tool === 'Bash') {
                    $sets[$id]['bash']++;
                    $sets['']['bash']++;
                }
            }
        }
        $counts = static fn (string $id): array => isset($sets[$id])
            ? ['read' => count($sets[$id]['read']), 'write' => count($sets[$id]['write']), 'bash' => $sets[$id]['bash']] : ['read' => 0, 'write' => 0, 'bash' => 0];
        usort($subs, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
        $agents = [];
        foreach ([$main, ...$subs] as $row) {
            $usage = $context[$row['id']] ?? null;
            $tokens = $usage['tokens'] ?? 0;
            $agents[] = [
                'id' => $row['id'],
                'kind' => $row['kind'],
                'label' => $row['label'],
                'description' => $row['description'],
                'modelName' => $row['modelName'],
                'state' => $row['kind'] === 'sub' && $row['gone'] ? 'done' : $row['state'],
                'since' => $row['kind'] === 'sub' && $row['gone'] ? $row['last'] : $row['since'],
                'last' => $row['last'],
                'tool' => $row['tool'],
                'activity' => $row['activity'],
                'after' => $row['after'],
                'waitingFor' => $row['waitingFor'],
                'interrupted' => $row['interrupted'],
                'stuck' => $row['stuck'],
                // from the hook: permission request `{ts, tool}`, API error type, session end reason
                'permission' => $row['permission'],
                'stuckType' => $row['stuckType'],
                'ended' => $row['ended'],
                'blocked' => $row['blocked'],
                'burn' => $row['burn'],
                'spent' => $row['spent'],
                'counts' => $counts($row['id']),
                'usdWindow' => $whole ? null : round($inWindow[$row['id']] ?? 0.0, 4),
                // agent tokens from the whole session (not the window): input, output, cache write and read
                'tokens' => array_intersect_key($session->tokens($row['id']), array_flip(['input', 'output', 'cacheWrite', 'cacheRead'])),
                'context' => $tokens,
                'contextMax' => max($tokens, self::contextWindow($row['model'])),
                'reportBlocked' => $row['kind'] === 'sub' ? self::reportBlocked($byAgent[$row['id']] ?? [], $from) : null,
                'calls' => self::calls($byAgent[$row['id']] ?? [], $from, $limits, $session->cwd, $whole ? PHP_INT_MAX : self::CALL_LIMIT, $asks[$row['id']] ?? [], $now),
                // for the 3D office: `gone` = outside the office per its thresholds (OfficeState), head, ~/.claude archive, boundary violation from the last 2 min
                'gone' => $row['gone'],
                'start' => $row['start'],
                'persona' => $row['persona'],
                'archive' => $row['archive'],
                'boundaryRecent' => $row['boundary'],
            ];
        }

        return [
            'id' => $session->id,
            'title' => $session->title,
            'project' => basename((string) $session->cwd),
            'projectKey' => App::fileHash((string) $session->cwd),
            'projectColor' => OfficeState::projectColor(basename((string) $session->cwd)),
            'cwd' => App::shortPath((string) $session->cwd, null),
            'last' => max(array_column($agents, 'last')),
            'spent' => round(array_sum(array_column($agents, 'spent')), 4),
            'counts' => $counts(''),
            // the permission hook works in this session: a call without a request event is a long command, not "permission?"
            'permissionHook' => $asks !== [],
            'agents' => $agents,
            'boundary' => $limits?->enabled() ? self::boundary($limits, $session, $from) : null,
            'map' => $limits?->enabled() ? self::map($limits, $session, $from) : null,
            'messages' => OfficeState::messages($session, $now, $whole ? PHP_INT_MAX : self::WINDOW),
            // work axis: user prompts (without text), past waits for the user and messages to other sessions
            'prompts' => self::prompts($session, $from),
            'waits' => self::waits($session, $from),
            'sends' => self::sends($session, $from),
            'compactions' => self::compactions($compactions ?? HookLog::compactions($session->id), $from),
        ];
    }

    /**
     * Context compactions of the main session in the window (work axis: a tick on the track): one per `PreCompact` → `PostCompact` pair,
     * at the moment of `PreCompact`; a lone `PostCompact` (pair start outside the file tail) — at its own moment. `trigger` = manual / auto / null.
     *
     * @param list<array{ts: string, agent: string, phase: string, trigger: ?string}> $events
     * @return list<array{ts: string, trigger: ?string}>
     */
    private static function compactions(array $events, int $from): array
    {
        $out = [];
        $open = false;
        foreach ($events as $c) {
            if ($c['agent'] !== Agent::MAIN) {
                continue;
            }
            if ($c['phase'] === 'post' && $open) {
                $open = false;
                continue;
            }
            $open = $c['phase'] === 'pre';
            if (self::time($c['ts']) >= $from) {
                $out[] = ['ts' => $c['ts'], 'trigger' => $c['trigger']];
            }
        }

        return $out;
    }

    /**
     * Prompts in the window: `prompt` (user), `command` (`/…` command), `peer` (message from another session) — only time and kind.
     *
     * @return list<array{ts: string, kind: string}>
     */
    private static function prompts(Session $session, int $from): array
    {
        $out = [];
        foreach ($session->prompts as $p) {
            if (in_array($p['kind'], ['prompt', 'command', 'peer'], true) && self::time($p['ts']) >= $from) {
                $out[] = ['ts' => $p['ts'], 'kind' => $p['kind']];
            }
        }

        return $out;
    }

    /**
     * Past waits of the main session for the user: from the main session's last activity (model response or end of a call)
     * to the user's prompt (`prompt`, `command`), ending in the window. The current wait is state `idle` + `since`, not this list.
     *
     * @return list<array{from: string, to: string}>
     */
    private static function waits(Session $session, int $from): array
    {
        $acts = [];
        foreach ($session->usage as $u) {
            if ($u['agent'] === Agent::MAIN) {
                $acts[] = self::time($u['ts']);
            }
        }
        foreach ($session->events as $e) {
            if ($e->agentId === Agent::MAIN && $e->tool !== Event::TOOL_CONTEXT) {
                $acts[] = self::time($e->endTs ?? $e->ts);
            }
        }
        sort($acts);
        $out = [];
        $i = 0;
        $prev = null;
        foreach ($session->prompts as $p) {
            if (!in_array($p['kind'], ['prompt', 'command'], true)) {
                continue;
            }
            $t = self::time($p['ts']);
            while ($i < count($acts) && $acts[$i] < $t) {
                $prev = $acts[$i++];
            }
            if ($prev !== null && $t >= $from && $t - $prev >= 1) {
                $out[] = ['from' => gmdate('Y-m-d\TH:i:s\Z', $prev), 'to' => $p['ts']];
            }
        }

        return $out;
    }

    /**
     * `SendMessage` messages to a recipient outside the session (another session: name or socket address) in the window — for arrows between sessions.
     *
     * @return list<array{ts: string, to: string}>
     */
    private static function sends(Session $session, int $from): array
    {
        $out = [];
        foreach ($session->messages as $m) {
            if ($m['kind'] === 'send' && !isset($session->agents[$m['to']]) && $m['to'] !== '' && self::time($m['ts']) >= $from) {
                $out[] = ['ts' => $m['ts'], 'to' => $m['to']];
            }
        }

        return array_slice($out, -self::SEND_LIMIT);
    }

    /**
     * Agent tool calls finished or running in the window, newest `$limit` (usually `CALL_LIMIT`). `status` (most important first):
     * `blocked` (Claude Code block), `outside` / `trying` / `stopped` (boundary), `failed` (did not run or exit code ≠ 0), `unknown` (Bash without paths), `ok`.
     * `tokens` / `usd` = cost of the result in context from `Event::carry` (write + further reads; null = no estimate or model outside the price list).
     * `permission` = first permission request from the hook (`$asks`, unix times) between the call's start and end (running — until `$now`), null = none.
     *
     * @param list<Event> $events
     * @param list<int> $asks
     * @return list<array{id: string, ts: string, end: ?string, tool: string, text: string, path: bool, status: string, tokens: ?int, usd: ?float, permission: ?string}>
     */
    private static function calls(array $events, int $from, ?Boundary $limits, ?string $cwd, int $limit, array $asks = [], int $now = PHP_INT_MAX): array
    {
        sort($asks);
        $out = [];
        foreach ($events as $e) {
            // a running call from before the window is skipped: usually interrupted (without a result in the transcript forever)
            if (self::time($e->endTs ?? $e->ts) < $from) {
                continue;
            }
            $kind = $limits?->classify($e);
            // on a boundary violation the target = the first path outside the boundary (a call can be outside through the second of several paths)
            $touch = in_array($kind, [Boundary::OUTSIDE, Boundary::STOPPED], true) ? ($limits->outside($e)[0] ?? null) : ($e->touches[0] ?? null);
            $target = OfficeState::target($touch !== null ? App::shortPath($touch->path, $cwd) : null, $e->summary);
            [$start, $stop] = [self::time($e->ts), $e->endTs !== null ? self::time($e->endTs) : $now];
            $ask = array_values(array_filter($asks, static fn (int $t): bool => $t >= $start && $t <= $stop))[0] ?? null;
            $out[] = [
                'id' => $e->toolUseId,
                'ts' => $e->ts,
                'end' => $e->endTs,
                'tool' => $e->tool,
                'text' => $target['text'],
                'path' => $target['path'],
                'status' => match (true) {
                    $e->denial !== null => 'blocked',
                    $kind === Boundary::OUTSIDE => $e->endTs === null ? 'trying' : 'outside',
                    $kind === Boundary::STOPPED => 'stopped',
                    !$e->executed() || $e->osDenied || $e->error !== null => 'failed',
                    $kind === Boundary::SUSPICIOUS => 'unknown',
                    default => 'ok',
                },
                'tokens' => $e->carry['tokens'] ?? null,
                'usd' => isset($e->carry['usd']) ? round($e->carry['usd'], 4) : null,
                'permission' => $ask !== null ? gmdate('Y-m-d\TH:i:s\Z', $ask) : null,
            ];
        }

        return array_slice($out, -$limit);
    }

    /**
     * Session boundary map: directories touched in the window, grouped by zone (`Boundary::zoneOf`: `rw`, `r`, `out`; system programs skipped).
     * `dir` relative to the zone root (`.` = the root itself), outside a zone — with `~`. Counters per operation kind; `stopped` = touches
     * from calls that did not run. Most recent `MAP_LIMIT`.
     *
     * @return list<array{zone: string, root: ?string, dir: string, read: int, look: int, write: int, exec: int, stopped: int, last: string, agents: list<string>}>
     */
    private static function map(Boundary $limits, Session $session, int $from): array
    {
        $out = [];
        foreach ($session->events as $e) {
            if ($e->tool === Event::TOOL_CONTEXT || $e->touches === [] || self::time($e->ts) < $from) {
                continue;
            }
            $ran = $e->executed() && !$e->osDenied;
            foreach ($e->touches as $t) {
                ['zone' => $zone, 'root' => $root] = $limits->zoneOf($t);
                if ($zone === 'system') {
                    continue;
                }
                $dir = $t->op === Touch::LIST ? rtrim($t->path, '/') : dirname($t->path);
                $key = $zone . '|' . $root . '|' . strtolower($dir);
                $out[$key] ??= ['zone' => $zone, 'root' => $root !== null ? App::shortPath($root, null) : null,
                    'dir' => $root !== null && ($dir === $root || str_starts_with($dir, $root . '/')) ? ($dir === $root ? '.' : substr($dir, strlen($root) + 1)) : App::shortPath($dir, null),
                    'read' => 0, 'look' => 0, 'write' => 0, 'exec' => 0, 'stopped' => 0, 'last' => '', 'agents' => []];
                $row = &$out[$key];
                $row[match ($t->op) {
                    Touch::READ, Touch::CONTEXT => 'read',
                    Touch::EDIT, Touch::WRITE => 'write',
                    Touch::EXEC => 'exec',
                    default => 'look',
                }]++;
                $row['stopped'] += $ran ? 0 : 1;
                $row['last'] = max($row['last'], $e->ts);
                if (!in_array($e->agentId, $row['agents'], true)) {
                    $row['agents'][] = $e->agentId;
                }
                unset($row);
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['last'], $a['last']));

        return array_slice($out, 0, self::MAP_LIMIT);
    }

    /**
     * @param list<array<string, mixed>> $sessions `session` results (without null)
     * @return array{now: string, window: int, permissionGuess: int, contextHigh: int, boundary: bool, zones: array{rw: list<string>, r: list<string>}, burnFrom: float, coinUsd: float, sessions: list<array<string, mixed>>}
     */
    public static function merge(array $sessions, int $now, ?Boundary $limits = null): array
    {
        $boundary = $limits?->enabled() ?? false;
        $zones = $boundary ? $limits->zones() : ['rw' => [], 'r' => []];
        usort($sessions, static fn (array $a, array $b): int => strcmp($b['last'], $a['last']));

        return [
            'now' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'window' => self::WINDOW,
            'permissionGuess' => self::PERMISSION_GUESS,
            'contextHigh' => Signals::CONTEXT_TOKENS,
            'boundary' => $boundary,
            // zones from the configuration for display (boundary map), paths with `~`
            'zones' => ['rw' => array_map(static fn (string $p): string => App::shortPath($p, null), $zones['rw']), 'r' => array_map(static fn (string $p): string => App::shortPath($p, null), $zones['r'])],
            'burnFrom' => OfficeState::BURN_FROM,
            'coinUsd' => OfficeState::COIN_USD,
            'sessions' => array_values($sessions),
        ];
    }

    public static function contextWindow(string $model): int
    {
        foreach (self::CONTEXT_WINDOWS as $prefix => $tokens) {
            if (str_starts_with(strtolower($model), $prefix)) {
                return $tokens;
            }
        }

        return self::CONTEXT_DEFAULT;
    }

    /**
     * The subagent's last message-tool call blocked (in the window) — nothing of that kind went through after it.
     *
     * @param list<Event> $events
     * @return ?array{ts: string, tool: string, by: string}
     */
    private static function reportBlocked(array $events, int $from): ?array
    {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            $e = $events[$i];
            if (!in_array($e->tool, self::MESSAGE_TOOLS, true)) {
                continue;
            }
            if ($e->denial === null || self::time($e->ts) < $from) {
                return null;
            }

            return ['ts' => $e->ts, 'tool' => $e->tool, 'by' => $e->denial['by']];
        }

        return null;
    }

    /**
     * Calls in the window that crossed the boundary or cannot be determined; newest `BOUNDARY_LIMIT`.
     *
     * @return list<array{id: string, ts: string, agent: string, tool: string, text: string, path: bool, more: int, kind: string, by: ?string}>
     */
    private static function boundary(Boundary $limits, Session $session, int $from): array
    {
        $out = [];
        foreach ($session->events as $e) {
            if ($e->tool === Event::TOOL_CONTEXT || self::time($e->ts) < $from) {
                continue;
            }
            $kind = $limits->classify($e);
            if ($kind === null) {
                continue;
            }
            $paths = $kind === Boundary::SUSPICIOUS ? [] : $limits->outside($e);
            $target = OfficeState::target($paths !== [] ? App::shortPath($paths[0]->path, $session->cwd) : null, $e->summary);
            $out[] = [
                'id' => $e->toolUseId,
                'ts' => $e->ts,
                'agent' => $e->agentId,
                'tool' => $e->tool,
                'text' => $target['text'],
                'path' => $target['path'],
                'more' => max(0, count($paths) - 1),
                'kind' => match ($kind) {
                    Boundary::OUTSIDE => $e->endTs === null ? 'trying' : 'outside',
                    Boundary::STOPPED => 'stopped',
                    default => 'unknown',
                },
                'by' => $kind === Boundary::STOPPED ? Boundary::stoppedBy($e) : null,
            ];
        }

        return array_slice($out, -self::BOUNDARY_LIMIT);
    }

    private static function time(string $ts): int
    {
        return $ts === '' ? 0 : (int) strtotime($ts);
    }
}
