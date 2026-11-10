<?php

declare(strict_types=1);

namespace Viz\Source;

use Viz\Model\Agent;
use Viz\Model\Event;
use Viz\Model\Session;
use Viz\Model\Touch;
use Viz\Permissions\Settings;

/**
 * Odczyt zdarzeń zapisanych przez hook. Ścieżki są zamrożone w chwili zdarzenia.
 * Trzy pliki na sesję: `<id>.jsonl` — wywołania narzędzi (`read`), `<id>.state.jsonl` — zdarzenia stanu (`state`),
 * `<id>.context.jsonl` — pliki instrukcji i kompaktowanie (`instructions`, `compactions`).
 */
final class HookLog
{
    /** Zdarzenia stanu (osobny plik, bo `<id>.jsonl` istnieje tylko przy hooku narzędzi — od niego zależy źródło „hook” sesji). */
    public const STATE_EVENTS = ['Notification', 'PermissionRequest', 'StopFailure', 'SessionEnd', 'Stop', 'SubagentStart', 'SubagentStop'];
    /** Zdarzenia kontekstu (`<id>.context.jsonl`): ładowanie plików instrukcji i kompaktowanie; osobny plik, żeby nie wypychały stanu z `STATE_TAIL`. */
    public const CONTEXT_EVENTS = ['InstructionsLoaded', 'PreCompact', 'PostCompact'];
    /** Końcówka pliku kontekstu czytana przez `instructions` / `compactions`. */
    private const CONTEXT_TAIL = 1048576;
    /** Końcówka pliku stanu czytana przy każdym odświeżeniu feedu. */
    private const STATE_TAIL = 65536;

    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/var/events';
    }

    /** `$sessionId` musi być zwalidowany przez validSessionId(). */
    public static function statePath(string $sessionId): string
    {
        return self::dir() . '/' . $sessionId . '.state.jsonl';
    }

    /** `$sessionId` musi być zwalidowany przez validSessionId(). */
    public static function contextPath(string $sessionId): string
    {
        return self::dir() . '/' . $sessionId . '.context.jsonl';
    }

    /**
     * Pliki instrukcji (CLAUDE.md, reguły) załadowane do kontekstu sesji, od najstarszego; niepoprawne id albo brak pliku = [].
     *
     * @return list<array{ts: string, agent: string, file: string, memoryType: ?string, reason: ?string, globs: list<string>, trigger: ?string, parent: ?string}>
     */
    public static function instructions(string $sessionId): array
    {
        return self::validSessionId($sessionId) ? self::readInstructions(self::contextPath($sessionId)) : [];
    }

    /** @return list<array{ts: string, agent: string, file: string, memoryType: ?string, reason: ?string, globs: list<string>, trigger: ?string, parent: ?string}> */
    public static function readInstructions(string $file): array
    {
        $out = [];
        foreach (self::contextLines($file, 'InstructionsLoaded') as $d) {
            if (!is_string($d['file_path'] ?? null)) {
                continue;
            }
            $str = static fn (string $k): ?string => is_string($d[$k] ?? null) ? $d[$k] : null;
            $out[] = ['ts' => $d['ts'], 'agent' => $d['agent'], 'file' => $d['file_path'], 'memoryType' => $str('memory_type'), 'reason' => $str('load_reason'),
                'globs' => array_values(array_filter(is_array($d['globs'] ?? null) ? $d['globs'] : [], 'is_string')),
                'trigger' => $str('trigger_file_path'), 'parent' => $str('parent_file_path')];
        }

        return $out;
    }

    /**
     * Kompaktowania kontekstu sesji, od najstarszego: `phase` = `pre` (PreCompact) / `post` (PostCompact), `trigger` = `manual` / `auto` / null.
     *
     * @return list<array{ts: string, agent: string, phase: string, trigger: ?string}>
     */
    public static function compactions(string $sessionId): array
    {
        return self::validSessionId($sessionId) ? self::readCompactions(self::contextPath($sessionId)) : [];
    }

    /** @return list<array{ts: string, agent: string, phase: string, trigger: ?string}> */
    public static function readCompactions(string $file): array
    {
        $out = [];
        foreach (self::contextLines($file, 'PreCompact', 'PostCompact') as $d) {
            $out[] = ['ts' => $d['ts'], 'agent' => $d['agent'], 'phase' => $d['event'] === 'PreCompact' ? 'pre' : 'post',
                'trigger' => is_string($d['trigger'] ?? null) ? $d['trigger'] : null];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> linie pliku kontekstu wybranych zdarzeń (z końcówki `CONTEXT_TAIL`), `agent` dopisany */
    private static function contextLines(string $file, string ...$events): array
    {
        $out = [];
        foreach (explode("\n", self::tail($file, self::CONTEXT_TAIL)) as $line) {
            $d = json_decode($line, true);
            if (is_array($d) && in_array($d['event'] ?? null, $events, true) && is_string($d['ts'] ?? null)) {
                $out[] = $d + ['agent' => is_string($d['agent_id'] ?? null) ? $d['agent_id'] : Agent::MAIN];
            }
        }

        return $out;
    }

    /**
     * Zdarzenia stanu sesji (z końcówki pliku), od najstarszego; niepoprawne id albo brak pliku = [].
     *
     * @return list<array{ts: string, event: string, agent: string, detail: ?string, commands: list<string>, rules: list<string>}>
     */
    public static function state(string $sessionId): array
    {
        return self::validSessionId($sessionId) ? self::readState(self::statePath($sessionId)) : [];
    }

    /**
     * `detail` = `notification_type` (Notification), `tool_name` (PermissionRequest), `error` (StopFailure), `reason` (SessionEnd),
     * `agent_type` (SubagentStart, SubagentStop), null (Stop).
     * PermissionRequest ma też `commands` (kształty komendy Bash: program + podkomenda) i `rules` (reguły zaproponowane przez
     * Claude Code, `Tool(treść)`); zapis sprzed tych pól i inne zdarzenia = puste listy.
     * `mode` = `permission_mode` zdarzenia (pole wspólne; w zapisie mają je m.in. PermissionRequest, Stop, SubagentStop; Notification,
     * SubagentStart i SessionEnd — null); spoza `Settings::MODES` = null.
     *
     * @return list<array{ts: string, event: string, agent: string, detail: ?string, commands: list<string>, rules: list<string>, mode: ?string}>
     */
    public static function readState(string $file): array
    {
        $out = [];
        foreach (explode("\n", self::tail($file, self::STATE_TAIL)) as $line) {
            $d = json_decode($line, true);
            if (!is_array($d) || !in_array($d['event'] ?? null, self::STATE_EVENTS, true) || !is_string($d['ts'] ?? null)) {
                continue;
            }
            $detail = $d['notification_type'] ?? $d['tool_name'] ?? $d['error'] ?? $d['reason']
                ?? (in_array($d['event'], ['SubagentStart', 'SubagentStop'], true) ? $d['agent_type'] ?? null : null);
            $rules = [];
            foreach (is_array($d['rules'] ?? null) ? $d['rules'] : [] as $r) {
                if (is_string($r['tool'] ?? null)) {
                    $rules[] = $r['tool'] . (is_string($r['content'] ?? null) ? '(' . $r['content'] . ')' : '');
                }
            }
            $out[] = ['ts' => $d['ts'], 'event' => $d['event'], 'agent' => is_string($d['agent_id'] ?? null) ? $d['agent_id'] : Agent::MAIN,
                'detail' => is_string($detail) ? $detail : null,
                'commands' => array_values(array_filter(is_array($d['commands'] ?? null) ? $d['commands'] : [], 'is_string')), 'rules' => $rules,
                'mode' => in_array($d['permission_mode'] ?? null, Settings::MODES, true) ? $d['permission_mode'] : null];
        }

        return $out;
    }

    /** Ostatnie `$bytes` bajtów pliku (pierwsza linia bywa ucięta — json_decode ją odrzuci); brak pliku = ''. */
    public static function tail(string $file, int $bytes): string
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return '';
        }
        fseek($handle, max(0, (int) filesize($file) - $bytes));
        $data = (string) stream_get_contents($handle);
        fclose($handle);

        return $data;
    }

    public static function validSessionId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1;
    }

    /** `$sessionId` musi być zwalidowany przez validSessionId(). */
    public static function path(string $sessionId): string
    {
        return self::dir() . '/' . $sessionId . '.jsonl';
    }

    public function read(string $sessionId): Session
    {
        $file = self::path($sessionId);
        $agents = [Agent::MAIN => new Agent(Agent::MAIN, 'main', 'main session', null)];
        $meta = $this->subagentMeta($sessionId);
        $events = [];
        $cwd = null;
        $handle = @fopen($file, 'rb');
        while ($handle !== false && ($line = fgets($handle)) !== false) {
            $d = json_decode($line, true);
            if (!is_array($d)) {
                continue;
            }
            $agentId = (string) ($d['agent_id'] ?? Agent::MAIN);
            if (!isset($agents[$agentId])) {
                $agents[$agentId] = new Agent(
                    $agentId,
                    (string) ($meta[$agentId]['agentType'] ?? $d['agent_type'] ?? 'subagent'),
                    (string) ($meta[$agentId]['description'] ?? $agentId),
                    isset($meta[$agentId]['toolUseId']) ? (string) $meta[$agentId]['toolUseId'] : null,
                );
            }
            $cwd ??= $d['cwd_before'] ?? null;
            // zdarzenie hooka powstaje po wykonaniu: ts = koniec, start = koniec − duration_ms
            $end = (string) $d['ts'];
            $start = $end;
            if (($d['duration_ms'] ?? 0) > 0) {
                $start = (new \DateTimeImmutable($end))->modify('-' . (int) $d['duration_ms'] . ' milliseconds')->format('Y-m-d\TH:i:s.v\Z');
            }
            $events[] = new Event(
                $start, $sessionId, $agentId, (string) $d['tool'], (string) ($d['tool_use_id'] ?? ''),
                TranscriptReader::summary((string) $d['tool'], (array) ($d['input'] ?? [])),
                $d['cwd_before'] ?? null, (bool) ($d['failed'] ?? false),
                array_map(static fn (array $t): Touch => new Touch(
                    (string) $t['path'], (string) $t['op'], (bool) $t['exists'], (bool) ($t['partial'] ?? false), $t['range'] ?? null,
                ), (array) ($d['touches'] ?? [])),
                (array) ($d['unresolved'] ?? []), (array) ($d['indirect'] ?? []), (array) ($d['unknown'] ?? []),
                endTs: $end,
                error: isset($d['error']) ? (string) $d['error'] : null,
                commands: array_map(static fn (array $c): \Viz\Model\Command => \Viz\Model\Command::fromArray($c), (array) ($d['commands'] ?? [])),
                command: $d['tool'] === 'Bash' && is_string($d['input']['command'] ?? null) ? $d['input']['command'] : null,
            );
        }
        if ($handle !== false) {
            fclose($handle);
        }

        return new Session($sessionId, $file, '', $cwd, $agents, $events, count($events));
    }

    /** @return array<string, array<string, mixed>> opisy subagentów z transkryptu, o ile istnieje */
    private function subagentMeta(string $sessionId): array
    {
        $main = TranscriptReader::locate($sessionId);
        if ($main === null) {
            return [];
        }
        $out = [];
        foreach (glob(substr($main, 0, -6) . '/subagents/agent-*.meta.json') ?: [] as $f) {
            $out[substr(basename($f, '.meta.json'), 6)] = json_decode((string) file_get_contents($f), true) ?: [];
        }

        return $out;
    }
}
