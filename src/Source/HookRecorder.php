<?php

declare(strict_types=1);

namespace Viz\Source;

use Viz\Clock;
use Viz\Extract\ToolTouches;
use Viz\Model\Agent;
use Viz\Model\Touch;

/**
 * Zapis jednego zdarzenia z hooka Claude Code:
 * - `PostToolUse` / `PostToolUseFailure` (także payload bez `hook_event_name` — stary zapis) → var/events/<session_id>.jsonl (`HookLog::path`)
 * - `Notification`, `PermissionRequest`, `StopFailure`, `SessionEnd` → var/events/<session_id>.state.jsonl (`HookLog::statePath`)
 * - `Stop`, `SubagentStart`, `SubagentStop` → ten sam plik stanu (subagent z pustym `agent_type` = agent wewnętrzny Claude Code, pomijany)
 * - `InstructionsLoaded`, `PreCompact`, `PostCompact` → var/events/<session_id>.context.jsonl (`HookLog::contextPath`)
 * - inne zdarzenia pomijane.
 *
 * Ze Stop / SubagentStop nigdy `last_assistant_message`; ze Stop z `background_tasks` tylko liczba, bez `session_crons`;
 * z PreCompact / PostCompact bez `custom_instructions` i `compact_summary`. Ścieżki (`file_path`, `trigger_file_path`, `parent_file_path`,
 * `agent_transcript_path`) tylko bezwzględne, bez znaków sterujących, do 4096 bajtów.
 *
 * Zapisuje wyłącznie białą listę pól strukturalnych; wartości spoza znanych zbiorów zamieniane na null / `unknown` / `other`.
 * Nigdy treść: bez `tool_response`, treści Write/Edit, `message` i `title` z Notification, `last_assistant_message` i `error_details`
 * ze StopFailure. Z PermissionRequest: zamiast `tool_input` tylko kształt komendy Bash (`commands`: program + podkomenda), z
 * `permission_suggestions` tylko `toolName` + `ruleContent` wpisów `addRules` (`rules`).
 */
final class HookRecorder
{
    /** Pola tool_input potrzebne do ustalenia ścieżek; reszta (treść Write/Edit) nie jest zapisywana. */
    private const INPUT_FIELDS = ['command', 'file_path', 'notebook_path', 'path', 'pattern', 'glob', 'offset', 'limit', 'filePath', 'description', 'subagent_type', 'skill'];
    private const TOOL_EVENTS = ['PostToolUse', 'PostToolUseFailure'];
    /** Wartości według dokumentacji hooków Claude Code (code.claude.com/docs/en/hooks, stan 2026-11-09). */
    private const NOTIFICATION_TYPES = ['permission_prompt', 'idle_prompt'];
    private const STOP_ERRORS = ['rate_limit', 'overloaded', 'authentication_failed', 'oauth_org_not_allowed', 'account_on_hold', 'billing_error',
        'invalid_request', 'model_not_found', 'server_error', 'max_output_tokens', 'cloud_credential_error', 'unknown'];
    private const END_REASONS = ['clear', 'resume', 'logout', 'prompt_input_exit', 'other'];
    private const PERMISSION_MODES = ['default', 'plan', 'acceptEdits', 'auto', 'dontAsk', 'bypassPermissions'];
    private const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];
    private const MEMORY_TYPES = ['User', 'Project', 'Local', 'Managed'];
    private const LOAD_REASONS = ['session_start', 'nested_traversal', 'path_glob_match', 'include', 'compact'];
    private const COMPACT_TRIGGERS = ['manual', 'auto'];
    private const MAX_GLOBS = 10;
    private const MAX_SHAPES = 8;
    private const MAX_RULES = 5;

    public function __construct(private string $dir = '', private ToolTouches $tools = new ToolTouches())
    {
        $this->dir = $dir !== '' ? $dir : HookLog::dir();
    }

    /** @param array<string, mixed> $payload */
    public function record(array $payload): void
    {
        $sessionId = (string) ($payload['session_id'] ?? '');
        if (!HookLog::validSessionId($sessionId)) {
            return;
        }
        $agentId = (string) ($payload['agent_id'] ?? Agent::MAIN);
        if ($agentId !== Agent::MAIN && !preg_match('/^[a-f0-9]{1,40}$/', $agentId)) {
            return;
        }
        $event = (string) ($payload['hook_event_name'] ?? '');
        if (in_array($event, HookLog::STATE_EVENTS, true)) {
            $this->recordState($payload, $sessionId, $agentId, $event);

            return;
        }
        if (in_array($event, HookLog::CONTEXT_EVENTS, true)) {
            $this->recordContext($payload, $sessionId, $agentId, $event);

            return;
        }
        if ($event !== '' && !in_array($event, self::TOOL_EVENTS, true)) {
            return;
        }
        $tool = (string) ($payload['tool_name'] ?? '');
        $input = array_intersect_key((array) ($payload['tool_input'] ?? []), array_flip(self::INPUT_FIELDS));
        $cwdAfter = isset($payload['cwd']) ? (string) $payload['cwd'] : null;
        $file = $this->dir . '/' . $sessionId . '.jsonl';

        // cwd w payloadzie to katalog PO wykonaniu (M0). Katalog PRZED: linia tool_use w transkrypcie,
        // awaryjnie cwd_after poprzedniego zdarzenia tego agenta
        $cwdBefore = $this->transcriptCwd((string) ($payload['transcript_path'] ?? ''), $agentId, (string) ($payload['tool_use_id'] ?? ''))
            ?? $this->lastCwd($file, $agentId)
            ?? $cwdAfter;
        $x = $this->tools->extract($tool, $input, $cwdBefore);

        $this->append($file, [
            'ts' => Clock::nowUtc(),
            'session_id' => $sessionId,
            'agent_id' => $agentId,
            'agent_type' => (string) ($payload['agent_type'] ?? ($agentId === Agent::MAIN ? 'main' : 'subagent')),
            'tool' => $tool,
            'tool_use_id' => (string) ($payload['tool_use_id'] ?? ''),
            'duration_ms' => (int) ($payload['duration_ms'] ?? 0),
            'input' => $input,
            'cwd_before' => $cwdBefore,
            'cwd_after' => $cwdAfter,
            'failed' => $event === 'PostToolUseFailure',
            // tylko pierwsza linia — dalej bywa wyjście komendy, czyli potencjalnie treść pliku
            'error' => isset($payload['error']) ? mb_strimwidth(trim(strtok((string) $payload['error'], "\n") ?: ''), 0, 200, '…') : null,
            'touches' => array_map(static fn (Touch $t): array => [
                'path' => $t->path, 'op' => $t->op, 'exists' => $t->exists, 'partial' => $t->partial, 'range' => $t->range,
            ], $x->touches),
            'unresolved' => $x->unresolved,
            'indirect' => $x->indirect,
            'unknown' => $x->unknown,
            'commands' => array_map(static fn (\Viz\Model\Command $c): array => $c->toArray(), $x->commands),
            'permission_mode' => self::oneOf($payload['permission_mode'] ?? null, self::PERMISSION_MODES),
            'effort' => self::oneOf($payload['effort']['level'] ?? null, self::EFFORT_LEVELS),
        ]);
    }

    /**
     * Zdarzenie stanu: pola wspólne + jedno pole zdarzenia (`notification_type`, `tool_name`, `error`, `reason`).
     * Notification innego typu niż `NOTIFICATION_TYPES` pomijane.
     *
     * @param array<string, mixed> $payload
     */
    private function recordState(array $payload, string $sessionId, string $agentId, string $event): void
    {
        $line = [
            'ts' => Clock::nowUtc(),
            'event' => $event,
            'session_id' => $sessionId,
            'agent_id' => $agentId,
            'agent_type' => is_string($payload['agent_type'] ?? null) && preg_match('/^[\w.:@-]{1,80}$/', $payload['agent_type']) ? $payload['agent_type'] : null,
            'permission_mode' => self::oneOf($payload['permission_mode'] ?? null, self::PERMISSION_MODES),
            'effort' => self::oneOf($payload['effort']['level'] ?? null, self::EFFORT_LEVELS),
            'cwd' => is_string($payload['cwd'] ?? null) && strlen($payload['cwd']) <= 4096 && !preg_match('/[\x00-\x1f]/', $payload['cwd']) ? $payload['cwd'] : null,
        ];
        switch ($event) {
            case 'Notification':
                $type = self::oneOf($payload['notification_type'] ?? null, self::NOTIFICATION_TYPES);
                if ($type === null) {
                    return;
                }
                $line['notification_type'] = $type;
                break;
            case 'PermissionRequest':
                $tool = $payload['tool_name'] ?? null;
                $line['tool_name'] = is_string($tool) && preg_match('/^[\w.:-]{1,120}$/', $tool) ? $tool : null;
                $line['commands'] = $line['tool_name'] === 'Bash' ? $this->shapes($payload['tool_input']['command'] ?? null, $line['cwd']) : [];
                $line['rules'] = self::suggestedRules($payload['permission_suggestions'] ?? null);
                break;
            case 'StopFailure':
                $line['error'] = self::oneOf($payload['error'] ?? null, self::STOP_ERRORS) ?? 'unknown';
                break;
            case 'SessionEnd':
                $line['reason'] = self::oneOf($payload['reason'] ?? null, self::END_REASONS) ?? 'other';
                break;
            case 'Stop':
                // bez `last_assistant_message` (treść), z `background_tasks` tylko liczba (opisy i komendy to treść), bez `session_crons` (prompty)
                $line['stop_hook_active'] = is_bool($payload['stop_hook_active'] ?? null) ? $payload['stop_hook_active'] : null;
                $line['background_tasks'] = is_array($payload['background_tasks'] ?? null) ? min(count($payload['background_tasks']), 1000) : null;
                break;
            case 'SubagentStart':
            case 'SubagentStop':
                // agenci wewnętrzni Claude Code (podpowiedzi promptów, `/btw`) mają pusty `agent_type` — nie są subagentami, pomijani
                if ($agentId === Agent::MAIN || $line['agent_type'] === null) {
                    return;
                }
                if ($event === 'SubagentStop') {
                    $line['stop_hook_active'] = is_bool($payload['stop_hook_active'] ?? null) ? $payload['stop_hook_active'] : null;
                    $line['agent_transcript_path'] = self::absolutePath($payload['agent_transcript_path'] ?? null);
                }
                break;
        }
        $this->append($this->dir . '/' . $sessionId . '.state.jsonl', $line);
    }

    /**
     * Kształt komendy Bash z prośby o zgodę: program + podkomenda każdej części (`git commit`, `composer precommit`), bez argumentów —
     * pełna treść komendy nie jest zapisywana. Maks. `MAX_SHAPES`, każdy kształt z białej listy znaków.
     *
     * @return list<string>
     */
    private function shapes(mixed $command, ?string $cwd): array
    {
        if (!is_string($command) || $command === '' || strlen($command) > 100_000) {
            return [];
        }
        $out = [];
        foreach ($this->tools->extract('Bash', ['command' => $command], $cwd)->commands as $c) {
            $shape = $c->program . ($c->sub !== null ? ' ' . $c->sub : '');
            if (preg_match('/^[\w.+-]{1,60}( [\w.:+-]{1,60})?$/', $shape) && !in_array($shape, $out, true)) {
                $out[] = $shape;
            }
        }

        return array_slice($out, 0, self::MAX_SHAPES);
    }

    /**
     * Reguły z `permission_suggestions` (wpisy `addRules`): wyłącznie `toolName` i `ruleContent` (to, co Claude Code zapisałby
     * w ustawieniach po „Yes, and don't ask again”); bez `behavior`, `destination` i innych typów wpisów. Maks. `MAX_RULES`.
     *
     * @return list<array{tool: string, content: ?string}>
     */
    private static function suggestedRules(mixed $suggestions): array
    {
        $out = [];
        foreach (is_array($suggestions) ? $suggestions : [] as $s) {
            if (!is_array($s) || ($s['type'] ?? null) !== 'addRules' || !is_array($s['rules'] ?? null)) {
                continue;
            }
            foreach ($s['rules'] as $r) {
                $tool = $r['toolName'] ?? null;
                $content = $r['ruleContent'] ?? null;
                if (!is_string($tool) || !preg_match('/^[\w.:*-]{1,120}$/', $tool)
                    || ($content !== null && (!is_string($content) || strlen($content) > 200 || preg_match('/[\x00-\x1f]/', $content)))) {
                    continue;
                }
                $out[] = ['tool' => $tool, 'content' => $content];
            }
        }

        return array_slice($out, 0, self::MAX_RULES);
    }

    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * Zdarzenie kontekstu → var/events/<session_id>.context.jsonl (`HookLog::contextPath`): `ts`, `event`, `session_id`, `agent_id` + pola zdarzenia.
     * - `InstructionsLoaded`: `file_path`, `memory_type`, `load_reason`, `globs`, `trigger_file_path`, `parent_file_path`; bez poprawnego `file_path` pomijane
     * - `PreCompact` / `PostCompact`: `trigger`; bez `custom_instructions` (tekst usera) i `compact_summary` (streszczenie rozmowy)
     *
     * @param array<string, mixed> $payload
     */
    private function recordContext(array $payload, string $sessionId, string $agentId, string $event): void
    {
        $line = ['ts' => Clock::nowUtc(), 'event' => $event, 'session_id' => $sessionId, 'agent_id' => $agentId];
        if ($event === 'InstructionsLoaded') {
            $file = self::absolutePath($payload['file_path'] ?? null);
            if ($file === null) {
                return;
            }
            $globs = [];
            foreach (is_array($payload['globs'] ?? null) ? $payload['globs'] : [] as $g) {
                if (is_string($g) && $g !== '' && strlen($g) <= 200 && !preg_match('/[\x00-\x1f]/', $g)) {
                    $globs[] = $g;
                }
            }
            $line += [
                'file_path' => $file,
                'memory_type' => self::oneOf($payload['memory_type'] ?? null, self::MEMORY_TYPES),
                'load_reason' => self::oneOf($payload['load_reason'] ?? null, self::LOAD_REASONS),
                'globs' => array_slice($globs, 0, self::MAX_GLOBS),
                'trigger_file_path' => self::absolutePath($payload['trigger_file_path'] ?? null),
                'parent_file_path' => self::absolutePath($payload['parent_file_path'] ?? null),
            ];
        } else {
            $line['trigger'] = self::oneOf($payload['trigger'] ?? null, self::COMPACT_TRIGGERS);
        }
        $this->append($this->dir . '/' . $sessionId . '.context.jsonl', $line);
    }

    /** Ścieżka bezwzględna bez znaków sterujących, maks. 4096 bajtów; inaczej null. */
    private static function absolutePath(mixed $path): ?string
    {
        return is_string($path) && str_starts_with($path, '/') && strlen($path) <= 4096 && !preg_match('/[\x00-\x1f\x7f]/', $path) ? $path : null;
    }

    /** @param array<string, mixed> $line */
    private function append(string $file, array $line): void
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0700, true);
        }
        $new = !is_file($file);
        file_put_contents($file, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        if ($new) {
            chmod($file, 0600);
        }
    }

    /** cwd z linii tool_use w transkrypcie (sesja główna albo plik subagenta) — czyta tylko koniec pliku. */
    private function transcriptCwd(string $transcriptPath, string $agentId, string $toolUseId): ?string
    {
        if ($toolUseId === '' || !str_ends_with($transcriptPath, '.jsonl')
            || !str_starts_with($transcriptPath, TranscriptReader::projectsDir() . '/') || str_contains($transcriptPath, '..')) {
            return null;
        }
        $file = $agentId === Agent::MAIN ? $transcriptPath : substr($transcriptPath, 0, -6) . '/subagents/agent-' . $agentId . '.jsonl';
        foreach (array_reverse(explode("\n", HookLog::tail($file, 524288))) as $line) {
            if (!str_contains($line, $toolUseId) || !str_contains($line, '"tool_use"')) {
                continue;
            }
            $d = json_decode($line, true);
            if (is_array($d) && ($d['type'] ?? '') === 'assistant' && isset($d['cwd'])) {
                return (string) $d['cwd'];
            }
        }

        return null;
    }

    /** cwd_after ostatniego zdarzenia tego agenta — czyta tylko koniec pliku. */
    private function lastCwd(string $file, string $agentId): ?string
    {
        foreach (array_reverse(explode("\n", trim(HookLog::tail($file, 65536)))) as $line) {
            $d = json_decode($line, true);
            if (is_array($d) && ($d['agent_id'] ?? '') === $agentId && isset($d['cwd_after'])) {
                return (string) $d['cwd_after'];
            }
        }

        return null;
    }
}
