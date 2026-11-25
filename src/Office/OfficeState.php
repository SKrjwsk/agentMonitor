<?php

declare(strict_types=1);

namespace Viz\Office;

use Viz\Boundary;
use Viz\Model\Agent;
use Viz\Model\Event;
use Viz\Model\Session;
use Viz\Path;
use Viz\Web\App;

/**
 * State of the "virtual office": what each active agent is doing right now. Computed from `Session` (the same parser as the other views),
 * without reading files itself; `merge` combines many sessions into one office. Animations are computed by the browser — here only the state and since when it lasts.
 *
 * Only active agents are in the office — the rest wave and vanish (there is no "sleeping" state, inactive sessions number in the hundreds):
 * - main session: until `QUIET_GONE` since last activity; until `STALE_GONE` while a tool call is running (long tests, waiting for permission —
 *   and a call without a result after an interrupted session stays in the transcript forever); it also stays while any of its subagents works
 * - subagent: until finished (model response and nothing running); otherwise the same thresholds
 *
 * States:
 * - `working`   — a tool call is running (`tool`, `activity` = `{text, path}`) or the model is writing a response after a tool result / prompt
 *                 (`tool` = null, `after` = last finished call); a separate "thinking" state would take most of the time
 * - `delegated` — the main session finished its turn, but its subagents work (`waitingFor` = how many)
 * - `idle`      — the model responded and nothing is running: the main session waits for the user; `interrupted` = the user interrupted the turn
 * - `stuck`     — the agent's last event is an API limit or error
 *
 * The transcript cannot tell "waiting for user permission" from "a long-running command" — both are `working` with growing time.
 * Hook events (`HookLog::state`, optional) settle this and add states:
 * - `waiting-permission` — a call is running and after its start came `PermissionRequest` or `Notification: permission_prompt` of the agent;
 *                 `permission` = `{ts, tool}`, `since` = moment of the request. Lasts until the call ends (the hook does not know when permission was granted)
 * - `ended`     — main session: `SessionEnd` after its last activity; `ended` = reason, `gone` = true (leaves the office at once)
 * - `stuck` also from `StopFailure` after the agent's last activity (no running call); `stuckType` = error type (`rate_limit`, `overloaded`…)
 * - `idle` exactly: `Stop` (main session) after its last activity = waits for the user since the `Stop` moment (`since`), even when the transcript
 *                 shows a call without a result; without `Stop` — heuristic from the transcript (an interrupted turn sends no `Stop`)
 * - subagent: `SubagentStart` = `start`; a start without later activity in the transcript = `working` from that moment (the agent must already be in the transcript);
 *                 `SubagentStop` after the last activity = finished at that moment (`last` = `since` = end moment, `gone`); without them — heuristic
 * An event without `agent_id` belongs to the main session.
 *
 * Regardless of state:
 * - `burn`     — agent cost ($ per `Pricing`) over the last `BURN_WINDOW` s; from `BURN_FROM` the label shows the amount
 * - `spent`    — agent cost since session start; the browser computes the difference between refreshes and throws a coin for every `COIN_USD`
 * - `archive`  — the last call with files (running or younger than `ARCHIVE_STAY`) concerns Claude Code files: `~/.claude/…`, `/tmp/claude-*`.
 *                That is still the office (within the AGENT_ALLOWED_* boundary), just another part of it; Claude Code decides these locations, not the configuration
 * - `boundary` — last boundary violation (`Boundary`) younger than `BOUNDARY_SHOW`: `outside` = executed outside the boundary (went over the wall),
 *                `stopped` = blocked (bounced off the wall), `trying` = a call outside the boundary is still running (pushing at the wall, e.g. waiting for permission);
 *                `target` = `{text, path}` like `activity`
 *
 * `messages` — messages between agents from the last `MESSAGE_SHOW` s (`Session::messages`: `task`, `result`, `send`, `peer`);
 * the sender throws an envelope to the recipient. `from` = null — from another session (`peer`), a `send` with a recipient outside the session is skipped.
 *
 * `path` = true: `text` is a path, trimmed from the start (the end counts — file and its folder); otherwise a call description trimmed from the end.
 */
final class OfficeState
{
    public const QUIET_GONE = 600;
    public const STALE_GONE = 1800;
    /** A block of the last call shown for this long. */
    public const BLOCKED_SHOW = 60;
    public const BURN_WINDOW = 300;
    public const BURN_FROM = 0.5;
    public const BOUNDARY_SHOW = 120;
    public const ARCHIVE_STAY = 20;
    public const COIN_USD = 0.01;
    public const MESSAGE_SHOW = 30;
    private const MESSAGE_LIMIT = 80;

    private const TARGET_LIMIT = 80;

    /** @return array{now: string, session: array{id: string, title: string, project: string, cwd: string, projectColor: string}, agents: list<array<string, mixed>>, messages: list<array<string, mixed>>} */
    public static function build(Session $session, int $now, ?Boundary $limits = null): array
    {
        ['main' => $main, 'subs' => $subs] = self::rows($session, $now, $limits);
        $agents = array_values(array_filter($subs, static fn (array $a): bool => !$a['gone']));
        if ($main !== null && (!$main['gone'] || $agents !== [])) {
            array_unshift($agents, $main);
        }
        usort($agents, static fn (array $a, array $b): int => [$a['kind'] !== 'main', $a['start']] <=> [$b['kind'] !== 'main', $b['start']]);
        $agents = array_map(static function (array $a): array {
            unset($a['gone']);

            return $a;
        }, $agents);

        return [
            'now' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'burnFrom' => self::BURN_FROM,
            'session' => ['id' => $session->id, 'title' => $session->title, 'project' => basename((string) $session->cwd), 'cwd' => App::shortPath((string) $session->cwd, null),
                'projectColor' => self::projectColor(basename((string) $session->cwd))],
            'agents' => $agents,
            'messages' => self::messages($session, $now),
        ];
    }

    /**
     * Rows of all agents of the session, before the office decides who is in it (`gone` = inactive per the office thresholds).
     * Shared by the office and the Live view (`LiveState`, which has a longer window). A main session waiting for the user
     * while its subagents work (not `gone`) has state `delegated`.
     *
     * @param list<array{ts: string, event: string, agent: string, detail: ?string}> $hooks state events from the hook (`HookLog::state`)
     * @return array{main: ?array<string, mixed>, subs: list<array<string, mixed>>}
     */
    public static function rows(Session $session, int $now, ?Boundary $limits = null, array $hooks = []): array
    {
        $hooksByAgent = [];
        foreach ($hooks as $h) {
            $hooksByAgent[$h['agent']][] = $h;
        }
        $byAgent = [];
        foreach ($session->events as $e) {
            if ($e->tool !== Event::TOOL_CONTEXT) {
                $byAgent[$e->agentId][] = $e;
            }
        }
        $usage = [];
        foreach ($session->usage as $u) {
            $usage[$u['agent']][] = $u;
        }
        $incidents = [];
        foreach ($session->incidents as $i) {
            $incidents[$i['agent']] = $i;
        }
        // prompts the main session answers with the model (commands like /model do not run the model)
        $lastPrompt = null;
        foreach ($session->prompts as $pr) {
            if (in_array($pr['kind'], ['prompt', 'notify', 'peer'], true)) {
                $lastPrompt = max($lastPrompt ?? '', $pr['ts']);
            }
        }

        $main = null;
        $agents = [];
        foreach ($session->agents as $agent) {
            $events = $byAgent[$agent->id] ?? [];
            $responses = $usage[$agent->id] ?? [];
            // a subagent without calls and responses is in the office only after the hook's `SubagentStart` (the agent must be in the transcript — the hook alone does not create it)
            $started = in_array('SubagentStart', array_column($hooksByAgent[$agent->id] ?? [], 'event'), true);
            if ($events === [] && $responses === [] && $agent->id !== Agent::MAIN && !$started) {
                continue;
            }
            $row = self::agent($agent, $events, $responses, $incidents[$agent->id] ?? null, $agent->id === Agent::MAIN ? $lastPrompt : null, $now, $session->cwd) + [
                'burn' => self::burn($responses, $now),
                'spent' => self::spent($responses),
                'archive' => self::inArchive($events, $now),
                'boundary' => $limits?->enabled() ? self::boundary($limits, $events, $now, $session->cwd) : null,
            ];
            $row = self::withHooks($row, $hooksByAgent[$agent->id] ?? [], $now);
            if ($agent->id === Agent::MAIN) {
                $main = $row;
            } else {
                $agents[] = $row;
            }
        }
        // the main session waits for the user or for background subagents — it stays while any of them works
        $active = count(array_filter($agents, static fn (array $a): bool => !$a['gone']));
        if ($main !== null && $main['state'] === 'idle' && $active > 0) {
            $main['state'] = 'delegated';
            $main['waitingFor'] = $active;
            $main['interrupted'] = false;
        }

        return ['main' => $main, 'subs' => $agents];
    }

    /**
     * Fresh messages between the session's agents (from the last `$window` s); recipient and sender are agent identifiers (`from` = null: from another session).
     *
     * @return list<array{id: string, ts: string, kind: string, from: ?string, to: string, text: string}>
     */
    public static function messages(Session $session, int $now, int $window = self::MESSAGE_SHOW): array
    {
        $ids = array_flip(array_map(static fn (Agent $a): string => $a->id, $session->agents));
        $out = [];
        foreach ($session->messages as $m) {
            if (!in_array($m['kind'], ['task', 'result', 'send', 'peer'], true) || $now - self::time($m['ts']) > $window) {
                continue;
            }
            $from = $m['kind'] === 'peer' ? null : $m['from'];
            if (($from !== null && !isset($ids[$from])) || !isset($ids[$m['to']])) {
                continue;
            }
            $text = trim((string) preg_replace('/\s+/', ' ', $m['text']));
            $out[] = [
                'id' => substr(sha1($m['ts'] . '|' . $m['kind'] . '|' . $m['from'] . '|' . $m['to']), 0, 16),
                'ts' => $m['ts'],
                'kind' => $m['kind'],
                'from' => $from,
                'to' => $m['to'],
                'text' => mb_strlen($text) > self::MESSAGE_LIMIT ? mb_substr($text, 0, self::MESSAGE_LIMIT - 1) . '…' : $text,
            ];
        }

        return $out;
    }

    /** Permission request event from the hook: `PermissionRequest` (at once) or `Notification: permission_prompt` (after ~6 s of waiting). */
    public static function isPermission(array $hook): bool
    {
        return $hook['event'] === 'PermissionRequest' || ($hook['event'] === 'Notification' && $hook['detail'] === 'permission_prompt');
    }

    /**
     * Agent row state corrected by hook events (state description in the class header). Without events — the row unchanged (+ empty fields).
     *
     * @param array<string, mixed> $row
     * @param list<array{ts: string, event: string, agent: string, detail: ?string}> $hooks events of this agent, oldest first
     * @return array<string, mixed>
     */
    private static function withHooks(array $row, array $hooks, int $now): array
    {
        $row += ['permission' => null, 'stuckType' => null, 'ended' => null];
        // subagent: `SubagentStart` = exact start; a start after the last activity (still without a model response) = works from that moment
        $starts = $row['kind'] === 'sub' ? array_values(array_filter($hooks, static fn (array $h): bool => $h['event'] === 'SubagentStart')) : [];
        $restart = $starts === [] ? null : $starts[count($starts) - 1];
        if ($starts !== [] && ($row['start'] === '' || self::time($starts[0]['ts']) < self::time($row['start']))) {
            $row['start'] = $starts[0]['ts'];
        }
        if ($restart !== null && self::time($restart['ts']) > self::time($row['last'])) {
            $row = ['state' => 'working', 'since' => $restart['ts'], 'last' => $restart['ts'], 'tool' => null, 'activity' => null, 'after' => null,
                'interrupted' => false, 'stuck' => null, 'gone' => $now - self::time($restart['ts']) > self::QUIET_GONE] + $row;
        }
        $last = self::time($row['last']);
        $end = null;
        $failure = null;
        $ask = null;
        $stop = null;
        foreach ($hooks as $h) {
            $t = self::time($h['ts']);
            if ($h['event'] === 'SessionEnd' && $row['kind'] === 'main' && $t >= $last) {
                $end = $h;
            } elseif ($h['event'] === 'StopFailure' && $t >= $last) {
                $failure = $h;
            } elseif ($h['event'] === ($row['kind'] === 'main' ? 'Stop' : 'SubagentStop') && $t >= $last) {
                $stop = $h;
            } elseif ($ask === null && $row['tool'] !== null && self::isPermission($h) && $t >= self::time($row['since'])) {
                // first request after the start of the running call
                $ask = $h;
            }
        }
        if ($end !== null) {
            return ['state' => 'ended', 'since' => $end['ts'], 'ended' => $end['detail'] ?? 'other', 'gone' => true,
                'tool' => null, 'activity' => null, 'interrupted' => false] + $row;
        }
        // turn finished (`Stop` / `SubagentStop`) after the last activity: exact moment; a call without a result from before it is not running
        if ($stop !== null && ($failure === null || self::time($stop['ts']) >= self::time($failure['ts']))) {
            $done = ['state' => 'idle', 'since' => $stop['ts'], 'tool' => null, 'activity' => null, 'after' => null, 'interrupted' => false, 'stuck' => null];

            return $row['kind'] === 'main'
                ? $done + ['gone' => $now - self::time($stop['ts']) > self::QUIET_GONE] + $row
                // subagent finished: `last` = end (views draw its life from `start` to `last`)
                : $done + ['last' => $stop['ts'], 'gone' => true] + $row;
        }
        if ($ask !== null) {
            return ['state' => 'waiting-permission', 'since' => $ask['ts'],
                'permission' => ['ts' => $ask['ts'], 'tool' => $ask['event'] === 'PermissionRequest' ? $ask['detail'] : null]] + $row;
        }
        if ($failure !== null && $row['tool'] === null) {
            $type = $failure['detail'] ?? 'unknown';

            return ['state' => 'stuck', 'since' => $failure['ts'], 'stuck' => $type === 'rate_limit' ? 'limit' : 'api-error', 'stuckType' => $type,
                'interrupted' => false] + $row;
        }

        return $row;
    }

    /**
     * @param list<Event> $events
     * @param list<array{ts: string, model: string}> $responses
     * @param ?array{ts: string, kind: string, text: string} $incident
     * @return array<string, mixed> `gone` = inactive, leaves the office
     */
    private static function agent(Agent $agent, array $events, array $responses, ?array $incident, ?string $lastPrompt, int $now, ?string $cwd): array
    {
        $lastResponse = $responses === [] ? null : max(array_column($responses, 'ts'));
        $model = $responses === [] ? '' : $responses[count($responses) - 1]['model'];
        $lastResult = null;
        $open = [];
        foreach ($events as $e) {
            if ($e->endTs !== null) {
                $lastResult = max($lastResult ?? '', $e->endTs);
            } elseif ($lastResponse === null || $e->ts >= $lastResponse) {
                // a call without a result from before the model's last response = interrupted, not running
                $open[] = $e;
            }
        }
        $starts = array_merge(array_column($responses, 'ts'), array_map(static fn (Event $e): string => $e->ts, $events));
        $last = max([...$starts, $lastResult ?? '', $lastPrompt ?? '']);
        $start = $starts === [] ? $last : min($starts);

        $current = $open === [] ? null : $open[count($open) - 1];
        if ($current !== null) {
            $state = 'working';
            $since = $open[0]->ts;
        } elseif ($incident !== null && in_array($incident['kind'], ['limit', 'api-error'], true) && $incident['ts'] >= $last) {
            $state = 'stuck';
            $since = $incident['ts'];
        } elseif ($lastResponse === null || max($lastResult ?? '', $lastPrompt ?? '') > $lastResponse) {
            // the model is writing a response — also work
            $state = 'working';
            $since = max($lastResult ?? '', $lastPrompt ?? '', $lastResponse ?? '');
        } else {
            $state = 'idle';
            $since = $lastResponse;
        }
        // events from the future (clock skew) must not produce a negative gap
        $quiet = max(0, $now - self::time($last));
        // 30 min only for a running tool call; a model writing a response appends lines more often
        $gone = ($state === 'idle' && $agent->id !== Agent::MAIN)
            || $quiet > ($current !== null ? self::STALE_GONE : self::QUIET_GONE);
        $done = null;
        for ($i = count($events) - 1; $i >= 0 && $current === null && $state === 'working'; $i--) {
            if ($events[$i]->endTs !== null) {
                $done = $events[$i];
                break;
            }
        }
        $lastEvent = $events === [] ? null : $events[count($events) - 1];
        $blocked = $lastEvent !== null && $lastEvent->denial !== null && $now - self::time($lastEvent->ts) <= self::BLOCKED_SHOW;

        return [
            'id' => $agent->id,
            'kind' => $agent->id === Agent::MAIN ? 'main' : 'sub',
            'label' => $agent->id === Agent::MAIN ? 'main' : $agent->type,
            'description' => $agent->id === Agent::MAIN ? '' : $agent->description,
            'model' => $model,
            'modelName' => self::modelName($model),
            'persona' => self::persona($model),
            'state' => $state,
            'since' => $since,
            'last' => $last,
            'start' => $start,
            'tool' => $current?->tool,
            'activity' => $current !== null ? self::activity($current, $cwd) : null,
            'after' => $done !== null ? ['tool' => $done->tool, 'activity' => self::activity($done, $cwd)] : null,
            'waitingFor' => 0,
            'interrupted' => $state === 'idle' && $incident !== null && $incident['kind'] === 'interrupt' && $incident['ts'] >= ($lastResponse ?? ''),
            'stuck' => $state === 'stuck' ? $incident['kind'] : null,
            'blocked' => $blocked ? ($lastEvent->denial['by'] ?? 'blocked') : null,
            'calls' => count($events),
            'gone' => $gone,
        ];
    }

    /**
     * Whether the agent is in the archive: decided by the last call with files (running or younger than `ARCHIVE_STAY`) —
     * when it returns to project files, it returns to the desk.
     *
     * @param list<Event> $events
     */
    private static function inArchive(array $events, int $now): bool
    {
        $home = Path::home();
        $prefixes = array_filter([$home !== '' ? $home . '/.claude/' : '', '/tmp/claude-', '/private/tmp/claude-']);
        for ($i = count($events) - 1; $i >= 0; $i--) {
            $e = $events[$i];
            if ($e->touches === []) {
                continue;
            }
            if ($e->endTs !== null && $now - self::time($e->endTs) > self::ARCHIVE_STAY) {
                return false;
            }
            foreach ($e->touches as $t) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($t->path, $prefix)) {
                        return true;
                    }
                }
            }

            return false;
        }

        return false;
    }

    /** @param list<array{ts: string, cost: ?array<string, float>}> $responses */
    private static function burn(array $responses, int $now): float
    {
        $usd = 0.0;
        foreach ($responses as $u) {
            if ($u['cost'] !== null && $now - self::time($u['ts']) <= self::BURN_WINDOW) {
                $usd += array_sum($u['cost']);
            }
        }

        return round($usd, 4);
    }

    /** @param list<array{cost: ?array<string, float>}> $responses */
    private static function spent(array $responses): float
    {
        $usd = 0.0;
        foreach ($responses as $u) {
            $usd += $u['cost'] !== null ? array_sum($u['cost']) : 0.0;
        }

        return round($usd, 4);
    }

    /**
     * Agent's last boundary violation in the `BOUNDARY_SHOW` window (without `suspicious` — a parser blind spot is not an attempt to leave).
     *
     * @param list<Event> $events
     * @return ?array{kind: string, path: string, ts: string}
     */
    private static function boundary(Boundary $limits, array $events, int $now, ?string $cwd): ?array
    {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            $e = $events[$i];
            if ($now - self::time($e->ts) > self::BOUNDARY_SHOW) {
                return null;
            }
            $kind = $limits->classify($e);
            if ($kind === Boundary::OUTSIDE || $kind === Boundary::STOPPED) {
                $paths = $limits->outside($e);

                return ['kind' => $e->endTs === null ? 'trying' : $kind, 'target' => self::target($paths !== [] ? App::shortPath($paths[0]->path, $cwd) : null, $e->summary), 'ts' => $e->ts];
            }
        }

        return null;
    }

    /**
     * Shirt colors: project → color from a fixed palette, no configuration. Colors are clearly distinguishable;
     * with more projects than colors collisions occur (the desk row and the legend still tell them apart).
     */
    public const PROJECT_COLORS = ['#e6194b', '#f58231', '#e6c200', '#bfef45', '#3cb44b', '#469990', '#42d4f4', '#4363d8', '#911eb4', '#f032e6', '#9a6324'];

    /** Project color from its name (session directory): the same project always gets the same color, case does not matter. */
    public static function projectColor(string $project): string
    {
        return self::PROJECT_COLORS[crc32(mb_strtolower($project)) % count(self::PROJECT_COLORS)];
    }

    /** Character look (head: hair, beard, glasses, headphones): model family from the identifier (`claude-opus-5-5` → `opus`), unknown (also `fable`) → `default`. */
    public static function persona(string $model): string
    {
        foreach (['haiku', 'sonnet', 'opus'] as $family) {
            if (str_contains(strtolower($model), $family)) {
                return $family;
            }
        }

        return 'default';
    }

    /** `claude-opus-5-5` → `Opus 5.5`, `claude-3-5-haiku-20241022` → `Haiku 3.5`; without a recognized family — the identifier unchanged. */
    public static function modelName(string $model): string
    {
        if (!preg_match('/(fable|haiku|sonnet|opus)/i', $model, $m)) {
            return $model;
        }
        preg_match_all('/(?<![\d])\d{1,2}(?![\d])/', $model, $v);

        return trim(ucfirst(strtolower($m[1])) . ' ' . implode('.', $v[0]));
    }

    /** What the agent is doing: file (shortened path) or call description. */
    private static function activity(Event $e, ?string $cwd): array
    {
        return self::target($e->touches !== [] ? App::shortPath($e->touches[0]->path, $cwd) : null, $e->summary);
    }

    /** Bubble target: path trimmed from the start, description from the end. */
    public static function target(?string $path, string $summary): array
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $path ?? $summary));
        if (mb_strlen($text) > self::TARGET_LIMIT) {
            $text = $path !== null ? '…' . mb_substr($text, -(self::TARGET_LIMIT - 1)) : mb_substr($text, 0, self::TARGET_LIMIT - 1) . '…';
        }

        return ['text' => $text, 'path' => $path !== null];
    }

    private static function time(string $ts): int
    {
        return $ts === '' ? 0 : (int) strtotime($ts);
    }
}
