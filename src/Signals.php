<?php

declare(strict_types=1);

namespace Viz;

use Viz\Model\Agent;
use Viz\Model\Event;
use Viz\Model\Session;

/**
 * Sygnały strony startowej „Overview”: co w sesji wymaga uwagi, na osiach kontekst / komunikacja / granice i z podziałem na to,
 * co może zmienić user, a co agent (KINDS).
 * Progi skalibrowane na sesjach usera 2026-10-24 (106 sesji, 1583 tury) — milestones.llm.md M17.
 * Ponowne odczyty i korekty usera celowo pominięte: na danych to głównie szum. Ślepe plamki Bash liczą się tylko jako
 * `unknown-paths` na osi granic (Boundary) — przy granicy agent/user lepiej więcej niż mniej.
 */
final class Signals
{
    /** Tura droższa niż to (cennik API) — 24 z 1583 tur. */
    public const TURN_USD = 5.0;
    /** Wynik narzędzia droższy niż to (zapis + odczyty przy dalszych wywołaniach) — 11 sesji. */
    public const RESULT_USD = 0.5;
    /** Kontekst agenta większy niż to — każde wywołanie czyta go od nowa. */
    public const CONTEXT_TOKENS = 400_000;
    /** Udział błędów narzędzi (bez blokad i Bash z kodem ≠ 0) — 90% sesji ma < 2,3%. */
    public const ERROR_RATE = 0.03;
    public const ERROR_MIN_CALLS = 20;
    /** Kolejne komunikaty o limicie w tym oknie to jedno zdarzenie (Claude Code ponawia). */
    private const LIMIT_WINDOW = 1800;
    /** Przerwa dłuższa niż czas życia cache (5 min), po której agent zapisał kontekst od nowa. */
    public const COLD_GAP = 300;
    public const COLD_TOKENS = 50_000;
    /** Odbudowa cache droższa niż to — 7 dni 2026-10-24: 47 odbudów > 50k, $66. */
    public const COLD_USD = 0.5;
    /** Krótka tura (≤ tyle wywołań modelu) w dużym kontekście — 7 dni: 502 tury > 150k, $94. */
    public const QUICK_CALLS = 3;
    public const QUICK_CONTEXT = 150_000;
    public const QUICK_USD = 0.5;
    /** Powrót do sesji po takiej przerwie z takim kontekstem — 7 dni: 12 sesji wielodniowych = 47% kosztu. */
    public const STALE_GAP = 3 * 3600;
    public const STALE_CONTEXT = 100_000;

    /**
     * Rodzaj → [oś, kto może to zmienić, waga]. Osie strony startowej: `context`, `communication`, `boundaries`;
     * `cost` = lista najdroższych tur, `env` = zakłócenia spoza wpływu usera i agenta.
     * Kto: `you` (user: prompty, higiena sesji, konfiguracja hooka), `agent`, '' = nikt.
     * Waga: `high` = do sprawdzenia od razu, `warn` = wymaga uwagi, `info` = do strojenia, nie zmienia statusu.
     */
    public const KINDS = [
        'expensive-turn' => ['cost', '', 'warn'],
        'cold-cache' => ['context', 'you', 'warn'],
        'quick-question' => ['context', 'you', 'warn'],
        'stale-session' => ['context', 'you', 'warn'],
        'huge-context' => ['context', 'you', 'warn'],
        'expensive-result' => ['context', 'agent', 'warn'],
        'error-rate' => ['context', 'agent', 'warn'],
        'interrupt' => ['communication', 'you', 'warn'],
        'report-blocked' => ['communication', 'agent', 'warn'],
        'subagent-failed' => ['communication', 'agent', 'warn'],
        'hook-text' => ['boundaries', 'you', 'info'],
        'protected-path' => ['boundaries', 'agent', 'high'],
        'auto-mode' => ['boundaries', 'agent', 'high'],
        // granica pracy agentów z `.env` (Boundary)
        'outside-executed' => ['boundaries', 'agent', 'high'],
        'unknown-paths' => ['boundaries', 'agent', 'warn'],
        'outside-stopped' => ['boundaries', 'agent', 'info'],
        'limit' => ['env', '', 'warn'],
        'api-error' => ['env', '', 'warn'],
    ];

    /** Rodzaje, których `value` to kwota w $ (cennik API) — sumowane i sortowane na stronie startowej. */
    public const USD_KINDS = ['expensive-turn', 'expensive-result', 'cold-cache', 'quick-question', 'stale-session'];

    /** Narzędzia, których treść to wiadomość agenta — blokada hooka = raport albo polecenie nie dotarło. */
    private const MESSAGE_TOOLS = ['Agent', 'Task', 'SubagentHandback', 'SendMessage'];

    /**
     * @param ?string $cutoff ISO UTC — tylko sygnały od tej chwili; null = cała sesja
     * @return array{items: list<array{kind: string, ts: string, agent: string, turn: int, ev: ?string, value: float, text: string, detail: string}>, info: array<string, int|float>}
     *         `info` = liczniki bez alarmu: blokady reguł deny, odmowy usera, wzorzec hooka w treści komendy, ponowienia po błędzie, kompaktowania
     */
    public static function of(Session $session, ?string $cutoff = null, ?Boundary $boundary = null): array
    {
        $boundary ??= Boundary::fromEnv();
        $turns = array_values(array_filter($session->prompts, static fn (array $p): bool => $p['kind'] === 'prompt'));
        $turnOf = static function (string $ts) use ($turns): int {
            $n = 0;
            foreach ($turns as $p) {
                if ($p['ts'] > $ts) {
                    break;
                }
                $n++;
            }

            return $n;
        };
        $items = [];
        $add = static function (string $kind, string $ts, string $agent, float $value, string $text, string $detail = '', ?string $ev = null) use (&$items, $turnOf, $cutoff): void {
            if ($cutoff === null || $ts >= $cutoff) {
                $items[] = ['kind' => $kind, 'ts' => $ts, 'agent' => $agent, 'turn' => $turnOf($ts), 'ev' => $ev, 'value' => $value, 'text' => mb_strimwidth($text, 0, 300, '…'), 'detail' => $detail];
            }
        };

        foreach ($turns as $i => $p) {
            $t = $session->tokens('', $p['ts'], $turns[$i + 1]['ts'] ?? null);
            if ($t['usd'] > self::TURN_USD) {
                arsort($t['cost']);
                $top = array_key_first($t['cost']);
                $add('expensive-turn', $p['ts'], 'main', $t['usd'], $p['text'], round(100 * $t['cost'][$top] / $t['usd']) . '% ' . ['read' => 'cache read', 'write' => 'new context', 'output' => 'output', 'input' => 'uncached input'][$top] . ' · ' . $t['calls'] . ' model calls');
            }
        }
        $maxCtx = [];
        foreach ($session->usage as $u) {
            if ($u['tokens'] > ($maxCtx[$u['agent']]['tokens'] ?? self::CONTEXT_TOKENS)) {
                $maxCtx[$u['agent']] = $u;
            }
        }
        foreach ($maxCtx as $agent => $u) {
            $add('huge-context', $u['ts'], (string) $agent, (float) $u['tokens'], 'context of ' . ($agent === 'main' ? 'the main session' : 'a subagent') . ' reached ' . round($u['tokens'] / 1000) . 'k tokens', 'every later call reads it again');
        }

        // higiena kontekstu po stronie usera: przerwy (wygasły cache), powroty do starej sesji, krótkie pytania w dużym kontekście
        $main = array_values(array_filter($session->usage, static fn (array $u): bool => $u['agent'] === Agent::MAIN));
        $coldTurns = [];
        $returns = [];
        $prev = null;
        foreach ($main as $u) {
            $gap = $prev === null ? 0 : strtotime($u['ts']) - strtotime($prev['ts']);
            $turn = $turnOf($u['ts']);
            $atStart = $prev !== null && $turnOf($prev['ts']) !== $turn;
            $write = (float) ($u['cost']['write'] ?? 0);
            if ($gap > self::COLD_GAP && $u['cacheWrite'] > self::COLD_TOKENS && $write >= self::COLD_USD) {
                $coldTurns[$turn] = $atStart;
                $add('cold-cache', $u['ts'], 'main', $write, $turn > 0 ? $turns[$turn - 1]['text'] : $session->title,
                    ($atStart ? 'your prompt came ' . self::duration($gap) . ' after the last answer' : 'a ' . self::duration($gap) . ' pause inside the turn — a permission prompt, a usage limit, a long tool call or a background task')
                    . ' · ' . round($u['cacheWrite'] / 1000) . 'k context written to the cache again');
            }
            // powrót zaczęty od /compact albo /clear to właśnie dobra higiena
            if ($atStart && $gap > self::STALE_GAP && $u['tokens'] > self::STALE_CONTEXT && !preg_match('~^/(compact|clear)\b~', $turns[$turn - 1]['text'])) {
                $returns[] = [$turn, $gap, $u['tokens']];
            }
            $prev = $u;
        }
        // wartość = koszt wleczenia starego kontekstu od powrotu do następnego powrotu (albo końca sesji):
        // zapis do cache przy powrocie + część odczytów z cache przypadająca na kontekst sprzed przerwy (szacunek)
        foreach ($returns as $k => [$turn, $gap, $ctx]) {
            $from = $turns[$turn - 1]['ts'];
            $to = isset($returns[$k + 1]) ? $turns[$returns[$k + 1][0] - 1]['ts'] : null;
            $carried = 0.0;
            $calls = 0;
            foreach ($main as $u) {
                if ($u['ts'] < $from || ($to !== null && $u['ts'] >= $to) || $u['cost'] === null) {
                    continue;
                }
                $carried += $calls === 0 ? $u['cost']['write'] : 0;
                $carried += $u['cacheRead'] > 0 ? $u['cost']['read'] * min($ctx, $u['cacheRead']) / $u['cacheRead'] : 0;
                $calls++;
            }
            $add('stale-session', $from, 'main', $carried, $turns[$turn - 1]['text'],
                'back after ' . self::duration($gap) . ' to a ' . round($ctx / 1000) . 'k context, carried through ' . $calls . ' model calls');
        }
        foreach ($turns as $i => $p) {
            if (($coldTurns[$i + 1] ?? false) === true) {
                continue;
            }
            $t = $session->tokens('', $p['ts'], $turns[$i + 1]['ts'] ?? null);
            $first = null;
            foreach ($main as $u) {
                if ($u['ts'] >= $p['ts']) {
                    $first = $u;
                    break;
                }
            }
            if ($first !== null && $t['calls'] <= self::QUICK_CALLS && $t['usd'] >= self::QUICK_USD && $first['tokens'] > self::QUICK_CONTEXT) {
                $add('quick-question', $p['ts'], 'main', $t['usd'], $p['text'], 'a ' . $t['calls'] . '-call answer on top of a ' . round($first['tokens'] / 1000) . 'k context');
            }
        }

        $info = ['deny_rule' => 0, 'user_rejected' => 0, 'hook_text' => 0, 'retry_after_error' => 0, 'compactions' => 0, 'tool_errors' => 0, 'calls' => 0];
        $events = $session->events;
        $calls = 0;
        $toolErrors = 0;
        $lastError = '';
        $inScope = [];
        foreach ($events as $i => $e) {
            if ($e->tool === Event::TOOL_CONTEXT || ($cutoff !== null && $e->ts < $cutoff)) {
                continue;
            }
            $calls++;
            $inScope[$e->toolUseId] = true;
            if (($e->carry['usd'] ?? 0) > self::RESULT_USD) {
                $add('expensive-result', $e->ts, $e->agentId, (float) $e->carry['usd'], ($e->tool !== 'Bash' ? $e->tool . ' ' : '') . $e->summary,
                    '+' . round($e->carry['tokens'] / 1000) . 'k tokens × ' . $e->carry['calls'] . ' later calls', $e->toolUseId);
            }
            if ($e->failed && $e->denial === null && !$e->executed()) {
                $toolErrors++;
                $lastError = $e->ts;
                if (in_array($e->tool, ['Agent', 'Task'], true)) {
                    $add('subagent-failed', $e->ts, $e->agentId, 1, $e->summary, (string) $e->error, $e->toolUseId);
                }
            }
            $crossed = $boundary->classify($e);
            if ($crossed !== null) {
                $paths = implode(' · ', array_map(static fn ($t): string => $t->op . ' ' . $t->path, array_slice($boundary->outside($e), 0, 3)));
                $add(['outside' => 'outside-executed', 'suspicious' => 'unknown-paths', 'stopped' => 'outside-stopped'][$crossed], $e->ts, $e->agentId, 1,
                    ($e->tool !== 'Bash' ? $e->tool . ' ' : '') . $e->summary,
                    match ($crossed) {
                        Boundary::OUTSIDE => $paths,
                        Boundary::STOPPED => $paths . ' · stopped by ' . Boundary::stoppedBy($e),
                        default => 'paths not resolved: ' . implode(' · ', array_slice([...$e->unresolved, ...$e->unknown], 0, 2)),
                    }, $e->toolUseId);
            }
            if ($e->denial === null) {
                continue;
            }
            $by = $e->denial['by'];
            if ($by === 'hook') {
                // wzorzec reguły w cudzysłowie („denied by rule matching '…'”); ścieżka z tym wzorcem = próba dostępu
                $rule = preg_match("/'([^']+)'/", (string) $e->denial['detail'], $m) ? $m[1] : '';
                $touches = $rule !== '' && array_filter($e->touches, static fn ($t): bool => stripos($t->path, $rule) !== false) !== [];
                $again = self::nextCall($events, $i)?->denial !== null;
                if (in_array($e->tool, self::MESSAGE_TOOLS, true)) {
                    $add('report-blocked', $e->ts, $e->agentId, 1, $e->tool . ': ' . $e->summary, 'hook ' . (string) $e->denial['detail'], $e->toolUseId);
                } elseif ($touches) {
                    $add('protected-path', $e->ts, $e->agentId, $again ? 2 : 1, ($e->tool !== 'Bash' ? $e->tool . ' ' : '') . $e->summary,
                        (string) $e->denial['detail'] . ($again ? ' · blocked again right after' : ''), $e->toolUseId);
                } elseif ($crossed !== Boundary::STOPPED) {
                    // wzorzec tylko w tekście (komentarz, nazwa, komunikat) — zwykle fałszywy alarm, który kosztuje agenta ponowienie
                    $info['hook_text']++;
                    $add('hook-text', $e->ts, $e->agentId, 1, ($e->tool !== 'Bash' ? $e->tool . ' ' : '') . $e->summary, 'hook ' . (string) $e->denial['detail'], $e->toolUseId);
                }
            } elseif (in_array($by, ['auto mode', 'safety check'], true)) {
                $add('auto-mode', $e->ts, $e->agentId, 1, ($e->tool !== 'Bash' ? $e->tool . ' ' : '') . $e->summary, $by . ($e->denial['detail'] !== null ? ': ' . $e->denial['detail'] : ''), $e->toolUseId);
            } elseif ($by === 'deny rule') {
                $info['deny_rule']++;
            } elseif ($by === 'user') {
                $info['user_rejected']++;
            }
        }
        if ($calls >= self::ERROR_MIN_CALLS && $toolErrors / $calls > self::ERROR_RATE) {
            $add('error-rate', $lastError, 'main', $toolErrors / $calls, round(100 * $toolErrors / $calls, 1) . '% of tool calls failed (' . $toolErrors . ' of ' . $calls . ')', 'file not found, edit conflicts, refused permissions…');
        }
        $info['tool_errors'] = $toolErrors;
        $info['calls'] = $calls;
        foreach (Problems::tag($events) as $id => $reasons) {
            $info['retry_after_error'] += isset($inScope[$id]) && in_array('retry after error', $reasons, true) ? 1 : 0;
        }
        $info['compactions'] = count(array_filter($session->prompts, static fn (array $p): bool => $p['kind'] === 'compact' && ($cutoff === null || $p['ts'] >= $cutoff)));

        $lastLimit = [];
        foreach ($session->incidents as $x) {
            if ($x['kind'] === 'limit') {
                $prev = $lastLimit[$x['agent']] ?? null;
                $lastLimit[$x['agent']] = $x['ts'];
                if ($prev !== null && strtotime($x['ts']) - strtotime($prev) < self::LIMIT_WINDOW) {
                    continue;
                }
            }
            // przerwanie: pokazujemy prompt, który agent realizował — to jego treść user może poprawić
            $turn = $turnOf($x['ts']);
            $x['kind'] === 'interrupt' && $turn > 0
                ? $add('interrupt', $x['ts'], $x['agent'], 1, $turns[$turn - 1]['text'], $x['text'])
                : $add($x['kind'], $x['ts'], $x['agent'], 1, $x['text']);
        }
        usort($items, static fn (array $a, array $b): int => strcmp($a['ts'], $b['ts']));

        return ['items' => $items, 'info' => $info];
    }

    /** „12 min”, „3 h”, „2 days”. */
    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 3600 => round($seconds / 60) . ' min',
            $seconds < 2 * 86400 => round($seconds / 3600) . ' h',
            default => round($seconds / 86400) . ' days',
        };
    }

    /** Następne wywołanie tego samego agenta. */
    private static function nextCall(array $events, int $i): ?Event
    {
        for ($j = $i + 1, $n = count($events); $j < $n; $j++) {
            if ($events[$j]->agentId === $events[$i]->agentId && $events[$j]->tool !== Event::TOOL_CONTEXT) {
                return $events[$j];
            }
        }

        return null;
    }
}
