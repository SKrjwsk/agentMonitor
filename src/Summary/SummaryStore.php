<?php

declare(strict_types=1);

namespace Viz\Summary;

use Viz\Boundary;
use Viz\Model\Event;
use Viz\Model\Touch;
use Viz\Office\LiveState;
use Viz\Path;
use Viz\Permissions\Approvals;
use Viz\Permissions\Settings;
use Viz\Source\HookLog;
use Viz\Source\SessionIndex;
use Viz\Source\TranscriptReader;

/**
 * Per-session aggregates in var/summary/<session_id>.json: path → operation counters.
 * Recomputed when the transcript has grown; they remain after Claude Code deletes the transcript (cleanupPeriodDays),
 * so they are also an archive. No file contents — only paths and counters.
 */
final class SummaryStore
{
    /**
     * Parser rules version. Bump on a parser/model change: aggregates of sessions with a transcript recompute themselves,
     * while archived ones (transcript deleted) stay. Never delete var/summary to "recompute" — it is the archive.
     */
    public const PARSER = '20';

    /**
     * Maximum timeout of a foreground Bash command in s — default `BASH_MAX_TIMEOUT_MS` (code.claude.com/docs/en/env-vars,
     * tools-reference "Timeout and output limits", 2026-11-09): a command that reaches it goes to the background and the call ends.
     * A longer Bash call therefore includes waiting. Changed `BASH_MAX_TIMEOUT_MS` / `BASH_DEFAULT_TIMEOUT_MS` are not read.
     */
    public const BASH_MAX_TIMEOUT = 600;

    /**
     * From how many seconds a Bash call counts as "waited for approval": maximum timeout + 60 s margin. A command hitting the limit
     * ends after ~600 s and did not wait for approval; the limit itself would therefore be too sensitive a threshold.
     */
    public const BASH_WAIT_FROM = self::BASH_MAX_TIMEOUT + 60;

    /**
     * Tools whose duration is not the tool's own cost — excluded from the "slowest" ranking: subagent work,
     * a message between agents, the user's answer to a question or plan.
     */
    private const NOT_TIMED = ['Agent', 'Task', 'SubagentHandback', 'SendMessage', 'AskUserQuestion', 'ExitPlanMode'];

    /** How many entries stay in a session aggregate: slowest calls and largest results. */
    private const TOP = 10;

    public const OPS = [Touch::READ, Touch::CONTEXT, Touch::EDIT, Touch::WRITE, Touch::SEARCH, Touch::LIST, Touch::EXEC];

    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/var/summary';
    }

    /** Recomputes sessions whose transcript changed; returns the number recomputed. */
    public function refresh(): int
    {
        if (!is_dir(self::dir())) {
            mkdir(self::dir(), 0700, true);
        }
        $reader = new TranscriptReader();
        $done = 0;
        foreach (glob(TranscriptReader::projectsDir() . '/*/*.jsonl') ?: [] as $path) {
            $id = basename($path, '.jsonl');
            if (!HookLog::validSessionId($id)) {
                continue;
            }
            // boundary fingerprint from `.env`: a configuration change recomputes sessions with a transcript (archived ones keep the previous one)
            $version = self::PARSER . ':' . Boundary::fromEnv()->fingerprint() . ':' . SessionIndex::version($path);
            $file = self::dir() . '/' . $id . '.json';
            $cached = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (is_array($cached) && ($cached['version'] ?? '') === $version) {
                continue;
            }
            file_put_contents($file, (string) json_encode(self::aggregate($reader->read($path), $version), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            chmod($file, 0600);
            $done++;
        }

        return $done;
    }

    /** @return list<array<string, mixed>> all aggregates, including sessions without a transcript */
    public function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            $d = json_decode((string) file_get_contents($file), true);
            if (is_array($d) && HookLog::validSessionId((string) ($d['session_id'] ?? ''))) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /**
     * Model output per session for the left panel — from stored aggregates, without recomputing (an aggregate may predate
     * the latest calls, hence `upTo` = time of the last counted call). Aggregates before `PARSER` 6 have no tokens.
     *
     * @param list<string> $ids
     * @return array<string, array{usd: ?float, output: int, partial: bool, upTo: ?string}> `usd` = null in aggregates before `PARSER` 7
     */
    public static function tokenTotals(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $file = self::dir() . '/' . $id . '.json';
            if (!HookLog::validSessionId($id) || !is_file($file)) {
                continue;
            }
            $d = json_decode((string) file_get_contents($file), true);
            $t = $d['tokens']['total'] ?? null;
            if (is_array($t) && ($t['calls'] ?? 0) > 0) {
                $out[$id] = ['usd' => isset($t['usd']) && $t['calls'] > ($t['unpriced'] ?? 0) ? (float) $t['usd'] : null, 'output' => (int) $t['output'], 'partial' => ($t['output_partial'] ?? 0) > 0, 'upTo' => $d['last'] ?? null];
            }
        }

        return $out;
    }

    /**
     * @param ?string $cutoff ISO UTC — only events from this moment ("5 min", "1 h" windows); null = whole session
     */
    public static function aggregate(\Viz\Model\Session $session, string $version, ?string $cutoff = null): array
    {
        $files = [];
        $events = $cutoff === null ? $session->events : array_values(array_filter($session->events, static fn (Event $e): bool => $e->ts >= $cutoff));
        $calls = array_values(array_filter($events, static fn (Event $e): bool => $e->tool !== Event::TOOL_CONTEXT));
        $active = [];
        $commands = [];
        $blocked = [];
        $boundary = [];
        $timed = [];
        $waits = ['count' => 0, 'ms' => 0, 'top' => []];
        $toolTime = [];
        $equipment = ['mcp' => [], 'skills' => [], 'subagents' => []];
        $limits = Boundary::fromEnv();
        foreach ($events as $e) {
            $active[$e->agentId] = true;
            $ms = self::durationMs($e);
            // a blocked call did not execute — its time is not tool work
            if ($ms !== null && self::timed($e)) {
                $call = ['ts' => $e->ts, 'agent' => $e->agentId, 'tool' => $e->tool, 'summary' => mb_strimwidth($e->summary, 0, 160, '…'), 'ev' => $e->toolUseId, 'ms' => $ms];
                if (self::waitedTool($e)) {
                    // from the transcript, waiting for approval cannot be told apart from long work — Bash from its maximum timeout
                    $waits = ['count' => $waits['count'] + 1, 'ms' => $waits['ms'] + $ms, 'top' => [...$waits['top'], $call]];
                } else {
                    $timed[] = $call;
                    $t = $toolTime[$e->tool] ?? ['count' => 0, 'ms' => 0, 'max' => 0];
                    $toolTime[$e->tool] = ['count' => $t['count'] + 1, 'ms' => $t['ms'] + $ms, 'max' => max($t['max'], $ms)];
                }
            }
            $kind = self::equipmentKind($e);
            if ($kind !== null) {
                $row = $equipment[$kind[0]][$kind[1]] ?? ['count' => 0, 'last' => ''];
                $row = ['count' => $row['count'] + 1, 'last' => max($row['last'], $e->ts)] + $row;
                if ($kind[0] === 'mcp') {
                    // MCP server (from `PARSER` 20): time as in `tool_time` (without blocks and without calls from the "approval?" threshold) and tool errors;
                    // a hook or rule block is not a server error
                    $row += ['timed' => 0, 'ms' => 0, 'max' => 0, 'failed' => 0];
                    if ($ms !== null && self::timed($e) && !self::waitedTool($e)) {
                        $row['timed']++;
                        $row['ms'] += $ms;
                        $row['max'] = max($row['max'], $ms);
                    }
                    if ($e->failed && $e->denial === null) {
                        $row['failed']++;
                    }
                }
                $equipment[$kind[0]][$kind[1]] = $row;
            }
            $crossed = $limits->classify($e);
            if ($crossed !== null) {
                // boundary violations: paths outside it (no paths for `suspicious` — the parser did not determine them)
                $boundary[] = ['ts' => $e->ts, 'agent' => $e->agentId, 'tool' => $e->tool, 'summary' => $e->summary, 'kind' => $crossed,
                    'by' => $crossed === Boundary::STOPPED ? Boundary::stoppedBy($e) : null,
                    'paths' => array_map(static fn (Touch $t): array => ['path' => $t->path, 'op' => $t->op], $limits->outside($e))];
            }
            if ($e->denial !== null) {
                // block log: command (call text, without result) + who blocked it and with which rule
                $blocked[] = ['ts' => $e->ts, 'agent' => $e->agentId, 'tool' => $e->tool, 'summary' => $e->summary, 'by' => $e->denial['by'], 'detail' => $e->denial['detail']];
            }
            foreach ($e->commands as $c) {
                $row = $commands[$c->program] ?? ['count' => 0, 'failed' => 0, 'blocked' => 0, 'agents' => [], 'last' => '', 'from' => [], 'subs' => [], 'contexts' => [], 'ms' => 0, 'max_ms' => 0];
                $row['count']++;
                // time of the whole Bash call in which the program occurred (pipe and `&&` share one call)
                $row['ms'] += $ms ?? 0;
                $row['max_ms'] = max($row['max_ms'], $ms ?? 0);
                $row['failed'] += $e->failed && $e->executed() ? 1 : 0;
                $row['blocked'] += $e->executed() ? 0 : 1;
                $row['agents'][$e->agentId] = true;
                $row['last'] = max($row['last'], $e->ts);
                if ($c->pipedFrom !== null) {
                    $row['from'][$c->pipedFrom] = ($row['from'][$c->pipedFrom] ?? 0) + 1;
                }
                if ($c->sub !== null) {
                    $row['subs'][$c->sub] = ($row['subs'][$c->sub] ?? 0) + 1;
                }
                foreach ([...$c->contexts, ...$c->wrappers] as $ctx) {
                    $row['contexts'][$ctx] = ($row['contexts'][$ctx] ?? 0) + 1;
                }
                $commands[$c->program] = $row;
            }
            if (!$e->executed()) {
                continue;
            }
            foreach ($e->touches as $t) {
                $key = Path::key($t->path);
                $f = $files[$key] ?? ['path' => $t->path, 'ops' => [], 'agents' => [], 'last' => ''];
                $f['ops'][$t->op] = ($f['ops'][$t->op] ?? 0) + 1;
                $f['agents'][$e->agentId] = true;
                $f['last'] = max($f['last'], $e->ts);
                $files[$key] = $f;
            }
        }

        return [
            'session_id' => $session->id,
            'version' => $version,
            'parser' => self::PARSER,
            'title' => $session->title,
            'cwd' => $session->cwd,
            'first' => $calls[0]->ts ?? null,
            'last' => $calls === [] ? null : end($calls)->ts,
            'calls' => count($calls),
            'agents' => count($session->agents),
            'agents_active' => count($active),
            'blocked' => $blocked,
            // approval questions ("Approvals" tab, from `PARSER` 18; permission mode of the `mode` row from 19): cause per settings
            // at recompute time; call text shortened
            'approvals' => array_values(array_map(
                static fn (array $r): array => ['summary' => mb_strimwidth($r['summary'], 0, 300, '…')] + $r,
                array_filter(
                    Approvals::rows($events, HookLog::state($session->id), Settings::forSession($session->cwd), $session->cwd, time(), $session->modes),
                    static fn (array $r): bool => $cutoff === null || $r['ts'] >= $cutoff,
                ),
            )),
            'boundary' => $boundary,
            'tokens' => self::tokens($session, $cutoff),
            // "Overview" start page — signals to look at (Signals), they stay in the archive after the transcript is deleted
            'signals' => \Viz\Signals::of($session, $cutoff),
            // tool time (`endTs − ts`, from `PARSER` 15; Bash threshold from 16): `slow` and `tool_time` — calls below the `waitedTool` threshold,
            // `waits` — from the threshold (may have waited for approval); equipment: MCP / skills / subagents
            'slow' => array_slice(self::sortedBy($timed, 'ms'), 0, self::TOP),
            'waits' => ['top' => array_slice(self::sortedBy($waits['top'], 'ms'), 0, self::TOP)] + $waits,
            'tool_time' => $toolTime,
            'equipment' => $equipment,
            // sizes of `tool-results/` files (stat only, no content); null = time range or missing directory
            'results' => $cutoff === null ? self::resultSizes($session) : null,
            'commands' => array_map(static fn (array $c): array => ['agents' => count($c['agents'])] + $c, $commands),
            'files' => array_values(array_map(static fn (array $f): array => [
                'path' => $f['path'], 'ops' => $f['ops'], 'agents' => count($f['agents']), 'last' => $f['last'],
            ], $files)),
        ];
    }

    /** A call whose time counts as tool work: not blocked, not delegation or a question to the user, not context. */
    public static function timed(Event $e): bool
    {
        return $e->denial === null && !in_array($e->tool, self::NOT_TIMED, true) && $e->tool !== Event::TOOL_CONTEXT;
    }

    /**
     * A call without a block lasting from the "approval?" threshold (`LiveState::PERMISSION_GUESS`) — a "probably asked" signal in the
     * "Approvals" tab (`Permissions\Approvals`). From the transcript, waiting for approval cannot be told apart from long work.
     */
    public static function waited(Event $e): bool
    {
        return self::timed($e) && (self::durationMs($e) ?? 0) >= LiveState::PERMISSION_GUESS * 1000;
    }

    /**
     * `waits` in tool time (besides `slow` and `tool_time`): Bash from `BASH_WAIT_FROM` (limit 10 min + 60 s margin; a shorter one may simply have worked long),
     * other tools from the "approval?" threshold, like `waited`.
     */
    public static function waitedTool(Event $e): bool
    {
        return self::timed($e) && (self::durationMs($e) ?? 0) >= ($e->tool === 'Bash' ? self::BASH_WAIT_FROM : LiveState::PERMISSION_GUESS) * 1000;
    }

    /** Call duration in ms (`endTs − ts`); null when the end is unknown or negative. */
    public static function durationMs(Event $e): ?int
    {
        if ($e->endTs === null) {
            return null;
        }
        try {
            $a = (float) (new \DateTimeImmutable($e->ts))->format('U.u');
            $b = (float) (new \DateTimeImmutable($e->endTs))->format('U.u');
        } catch (\Exception) {
            return null;
        }

        return $b >= $a ? (int) round(($b - $a) * 1000) : null;
    }

    /**
     * Equipment used by a call: MCP server (`mcp__<server>__<tool>`), skill (`Skill`), subagent (`Agent`/`Task`, `subagent_type`).
     *
     * @return ?array{0: string, 1: string} [kind, name]
     */
    private static function equipmentKind(Event $e): ?array
    {
        if (str_starts_with($e->tool, 'mcp__')) {
            $server = explode('__', $e->tool)[1] ?? '';

            return $server !== '' ? ['mcp', $server] : null;
        }
        if ($e->tool === 'Skill' && $e->summary !== '') {
            return ['skills', $e->summary];
        }
        if (in_array($e->tool, ['Agent', 'Task'], true)) {
            // summary = "<subagent_type>: <description>"
            return ['subagents', explode(': ', $e->summary, 2)[0] ?: 'agent'];
        }

        return null;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function sortedBy(array $rows, string $key): array
    {
        usort($rows, static fn (array $a, array $b): int => $b[$key] <=> $a[$key]);

        return $rows;
    }

    /**
     * Sizes of tool results saved to disk by Claude Code (`<session>/tool-results/*`): only `filesize`, never content.
     * File name without extension is the call id — the tool name is taken from it.
     *
     * @return ?array{files: int, bytes: int, top: list<array{id: string, bytes: int, tool: string}>}
     */
    private static function resultSizes(\Viz\Model\Session $session): ?array
    {
        $dir = substr($session->path, 0, -6) . '/tool-results';
        if (!is_dir($dir)) {
            return null;
        }
        $tools = [];
        foreach ($session->events as $e) {
            $tools[$e->toolUseId] = $e->tool;
        }
        $rows = [];
        foreach (glob($dir . '/*') ?: [] as $f) {
            $size = is_file($f) && !is_link($f) ? @filesize($f) : false;
            if ($size !== false) {
                $id = pathinfo($f, PATHINFO_FILENAME);
                $rows[] = ['id' => $id, 'bytes' => $size, 'tool' => $tools[$id] ?? ''];
            }
        }

        return ['files' => count($rows), 'bytes' => array_sum(array_column($rows, 'bytes')), 'top' => array_slice(self::sortedBy($rows, 'bytes'), 0, self::TOP)];
    }

    /**
     * Session token usage (from `cutoff`): sum and per-model breakdown. Counters separate — see Session::tokens.
     * `output_partial` = responses without final usage (output understated, subagents in Claude Code ≤ 2.1.300).
     * `usd` = cost per API price list (Pricing, from `PARSER` 7); `unpriced` = model responses not in the price list.
     *
     * @return array{total: array<string, int|float>, models: array<string, array<string, int|float>>}
     */
    private static function tokens(\Viz\Model\Session $session, ?string $cutoff): array
    {
        $zero = ['calls' => 0, 'subagent_calls' => 0, 'output_partial' => 0, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0, 'input' => 0, 'usd' => 0.0, 'unpriced' => 0];
        $total = $zero;
        $models = [];
        foreach ($session->usage as $u) {
            if ($cutoff !== null && $u['ts'] < $cutoff) {
                continue;
            }
            $row = [
                'calls' => 1, 'subagent_calls' => $u['agent'] === \Viz\Model\Agent::MAIN ? 0 : 1, 'output_partial' => $u['final'] ? 0 : 1,
                'output' => $u['output'], 'cache_write' => $u['cacheWrite'], 'cache_read' => $u['cacheRead'], 'input' => $u['input'],
                'usd' => $u['cost'] === null ? 0.0 : array_sum($u['cost']), 'unpriced' => $u['cost'] === null ? 1 : 0,
            ];
            $models[$u['model']] ??= $zero;
            foreach ($row as $k => $v) {
                $total[$k] += $v;
                $models[$u['model']][$k] += $v;
            }
        }
        uasort($models, static fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);

        return ['total' => $total, 'models' => $models];
    }
}
