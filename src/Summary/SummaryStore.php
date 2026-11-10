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
 * Agregaty per sesja w var/summary/<session_id>.json: ścieżka → liczniki operacji.
 * Przeliczane, gdy transkrypt urósł; zostają po usunięciu transkryptu przez Claude Code (cleanupPeriodDays),
 * więc są też archiwum. Bez treści plików — tylko ścieżki i liczniki.
 */
final class SummaryStore
{
    /**
     * Wersja reguł parsera. Podbij przy zmianie parsera/modelu: agregaty sesji z transkryptem przeliczą się same,
     * a archiwalne (transkrypt usunięty) zostaną. Nigdy nie usuwaj var/summary, żeby „przeliczyć” — to archiwum.
     */
    public const PARSER = '19';

    /**
     * Maksymalny timeout komendy Bash na pierwszym planie w s — domyślne `BASH_MAX_TIMEOUT_MS` (code.claude.com/docs/en/env-vars,
     * tools-reference „Timeout and output limits”, 2026-11-09): komenda, która go osiąga, przechodzi w tło i wywołanie się kończy.
     * Dłuższe wywołanie Bash obejmuje więc czekanie. Zmienione `BASH_MAX_TIMEOUT_MS` / `BASH_DEFAULT_TIMEOUT_MS` nie są odczytywane.
     */
    public const BASH_MAX_TIMEOUT = 600;

    /**
     * Od ilu sekund wywołanie Bash liczy się do „czekało na zgodę”: maksymalny timeout + 60 s zapasu. Komenda, która dobija do limitu,
     * kończy się po ok. 600 s i nie czekała na zgodę; sam limit byłby więc zbyt czułym progiem.
     */
    public const BASH_WAIT_FROM = self::BASH_MAX_TIMEOUT + 60;

    /**
     * Narzędzia, których czas trwania nie jest kosztem samego narzędzia — poza rankingiem „najwolniejszych”: praca subagenta,
     * wiadomość między agentami, odpowiedź usera na pytanie albo plan.
     */
    private const NOT_TIMED = ['Agent', 'Task', 'SubagentHandback', 'SendMessage', 'AskUserQuestion', 'ExitPlanMode'];

    /** Ile pozycji zostaje w agregacie sesji: najwolniejsze wywołania i największe wyniki. */
    private const TOP = 10;

    public const OPS = [Touch::READ, Touch::CONTEXT, Touch::EDIT, Touch::WRITE, Touch::SEARCH, Touch::LIST, Touch::EXEC];

    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/var/summary';
    }

    /** Przelicza sesje, których transkrypt się zmienił; zwraca liczbę przeliczonych. */
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
            // odcisk granicy z `.env`: zmiana konfiguracji przelicza sesje z transkryptem (archiwalne zostają z poprzednią)
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

    /** @return list<array<string, mixed>> wszystkie agregaty, także sesji bez transkryptu */
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
     * Wyjście modelu per sesja do lewego panelu — z zapisanych agregatów, bez przeliczania (agregat bywa sprzed
     * ostatnich wywołań, stąd `upTo` = czas ostatniego policzonego wywołania). Agregaty sprzed `PARSER` 6 nie mają tokenów.
     *
     * @param list<string> $ids
     * @return array<string, array{usd: ?float, output: int, partial: bool, upTo: ?string}> `usd` = null w agregatach sprzed `PARSER` 7
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
     * @param ?string $cutoff ISO UTC — tylko zdarzenia od tej chwili (okna „5 min”, „1 h”); null = cała sesja
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
            // zablokowane wywołanie się nie wykonało — jego czas to nie praca narzędzia
            if ($ms !== null && self::timed($e)) {
                $call = ['ts' => $e->ts, 'agent' => $e->agentId, 'tool' => $e->tool, 'summary' => mb_strimwidth($e->summary, 0, 160, '…'), 'ev' => $e->toolUseId, 'ms' => $ms];
                if (self::waitedTool($e)) {
                    // z transkryptu nie da się odróżnić czekania na zgodę od długiej pracy — Bash od swojego maksymalnego timeoutu
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
                $equipment[$kind[0]][$kind[1]] = ['count' => $row['count'] + 1, 'last' => max($row['last'], $e->ts)];
            }
            $crossed = $limits->classify($e);
            if ($crossed !== null) {
                // naruszenia granicy: ścieżki poza nią (przy `suspicious` brak — parser ich nie ustalił)
                $boundary[] = ['ts' => $e->ts, 'agent' => $e->agentId, 'tool' => $e->tool, 'summary' => $e->summary, 'kind' => $crossed,
                    'by' => $crossed === Boundary::STOPPED ? Boundary::stoppedBy($e) : null,
                    'paths' => array_map(static fn (Touch $t): array => ['path' => $t->path, 'op' => $t->op], $limits->outside($e))];
            }
            if ($e->denial !== null) {
                // log blokad: komenda (tekst wywołania, bez wyniku) + kto i jaką regułą zablokował
                $blocked[] = ['ts' => $e->ts, 'agent' => $e->agentId, 'tool' => $e->tool, 'summary' => $e->summary, 'by' => $e->denial['by'], 'detail' => $e->denial['detail']];
            }
            foreach ($e->commands as $c) {
                $row = $commands[$c->program] ?? ['count' => 0, 'failed' => 0, 'blocked' => 0, 'agents' => [], 'last' => '', 'from' => [], 'subs' => [], 'contexts' => [], 'ms' => 0, 'max_ms' => 0];
                $row['count']++;
                // czas całego wywołania Bash, w którym program wystąpił (potok i `&&` dzielą jedno wywołanie)
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
            // pytania o zgodę (zakładka „Zgody”, od `PARSER` 18; tryb uprawnień wiersza `mode` od 19): przyczyna według ustawień
            // z chwili przeliczenia; tekst wywołania skrócony
            'approvals' => array_values(array_map(
                static fn (array $r): array => ['summary' => mb_strimwidth($r['summary'], 0, 300, '…')] + $r,
                array_filter(
                    Approvals::rows($events, HookLog::state($session->id), Settings::forSession($session->cwd), $session->cwd, time(), $session->modes),
                    static fn (array $r): bool => $cutoff === null || $r['ts'] >= $cutoff,
                ),
            )),
            'boundary' => $boundary,
            'tokens' => self::tokens($session, $cutoff),
            // strona startowa „Overview” — sygnały do obejrzenia (Signals), zostają w archiwum po usunięciu transkryptu
            'signals' => \Viz\Signals::of($session, $cutoff),
            // czas narzędzi (`endTs − ts`, od `PARSER` 15; próg Bash od 16): `slow` i `tool_time` — wywołania poniżej progu `waitedTool`,
            // `waits` — od progu (może czekało na zgodę); wyposażenie: MCP / skille / subagenci
            'slow' => array_slice(self::sortedBy($timed, 'ms'), 0, self::TOP),
            'waits' => ['top' => array_slice(self::sortedBy($waits['top'], 'ms'), 0, self::TOP)] + $waits,
            'tool_time' => $toolTime,
            'equipment' => $equipment,
            // rozmiary plików `tool-results/` (tylko stat, bez treści); null = zakres czasowy albo brak katalogu
            'results' => $cutoff === null ? self::resultSizes($session) : null,
            'commands' => array_map(static fn (array $c): array => ['agents' => count($c['agents'])] + $c, $commands),
            'files' => array_values(array_map(static fn (array $f): array => [
                'path' => $f['path'], 'ops' => $f['ops'], 'agents' => count($f['agents']), 'last' => $f['last'],
            ], $files)),
        ];
    }

    /** Wywołanie, którego czas liczy się jako praca narzędzia: nie zablokowane, nie delegowanie ani pytanie do usera, nie kontekst. */
    public static function timed(Event $e): bool
    {
        return $e->denial === null && !in_array($e->tool, self::NOT_TIMED, true) && $e->tool !== Event::TOOL_CONTEXT;
    }

    /**
     * Wywołanie bez blokady trwające od progu „zgoda?” (`LiveState::PERMISSION_GUESS`) — sygnał „prawdopodobnie pytało” w zakładce
     * „Zgody” (`Permissions\Approvals`). Z transkryptu nie da się odróżnić czekania na zgodę od długiej pracy.
     */
    public static function waited(Event $e): bool
    {
        return self::timed($e) && (self::durationMs($e) ?? 0) >= LiveState::PERMISSION_GUESS * 1000;
    }

    /**
     * `waits` w czasie narzędzi (poza `slow` i `tool_time`): Bash od `BASH_WAIT_FROM` (limit 10 min + 60 s zapasu; krótszy mógł po prostu długo pracować),
     * inne narzędzia od progu „zgoda?” jak `waited`.
     */
    public static function waitedTool(Event $e): bool
    {
        return self::timed($e) && (self::durationMs($e) ?? 0) >= ($e->tool === 'Bash' ? self::BASH_WAIT_FROM : LiveState::PERMISSION_GUESS) * 1000;
    }

    /** Czas trwania wywołania w ms (`endTs − ts`); null, gdy koniec nieznany albo ujemny. */
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
     * Wyposażenie użyte przez wywołanie: serwer MCP (`mcp__<serwer>__<narzędzie>`), skill (`Skill`), subagent (`Agent`/`Task`, `subagent_type`).
     *
     * @return ?array{0: string, 1: string} [rodzaj, nazwa]
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
            // summary = „<subagent_type>: <opis>”
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
     * Rozmiary wyników narzędzi zapisanych na dysku przez Claude Code (`<sesja>/tool-results/*`): tylko `filesize`, nigdy treść.
     * Nazwa pliku bez rozszerzenia to id wywołania — z niego bierzemy nazwę narzędzia.
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
     * Zużycie tokenów sesji (od `cutoff`): suma i rozbicie per model. Liczniki osobno — patrz Session::tokens.
     * `output_partial` = odpowiedzi bez końcowego usage (wyjście zaniżone, subagenci w Claude Code ≤ 2.1.300).
     * `usd` = koszt według cennika API (Pricing, od `PARSER` 7); `unpriced` = odpowiedzi modelu spoza cennika.
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
