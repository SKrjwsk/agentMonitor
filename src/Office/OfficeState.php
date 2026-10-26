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
 * Stan „wirtualnego biura”: co w tej chwili robi każdy aktywny agent. Liczone z `Session` (ten sam parser co reszta widoków),
 * bez własnego czytania plików; `merge` składa wiele sesji w jedno biuro. Animacje liczy przeglądarka — tu tylko stan i od kiedy trwa.
 *
 * W biurze są tylko aktywni — reszta macha i znika (nie ma stanu „śpi”, nieaktywnych sesji bywają setki):
 * - sesja główna: do `QUIET_GONE` od ostatniej aktywności; do `STALE_GONE`, gdy trwa wywołanie narzędzia (długie testy, czekanie na zgodę —
 *   a wywołanie bez wyniku po przerwanej sesji zostaje w transkrypcie na zawsze); zostaje też, dopóki pracuje któryś jej subagent
 * - subagent: do zakończenia (odpowiedź modelu i nic nie trwa); poza tym te same progi
 *
 * Stany:
 * - `working`   — trwa wywołanie narzędzia (`tool`, `activity` = `{text, path}`) albo model pisze odpowiedź po wyniku narzędzia / prompcie
 *                 (`tool` = null, `after` = ostatnie skończone wywołanie); osobny stan „myśli” zajmowałby większość czasu
 * - `delegated` — sesja główna skończyła turę, ale pracują jej subagenci (`waitingFor` = ilu)
 * - `idle`      — model odpowiedział i nic nie trwa: sesja główna czeka na usera; `interrupted` = user przerwał turę
 * - `stuck`     — ostatnie zdarzenie agenta to limit albo błąd API
 *
 * Z transkryptu nie da się odróżnić „czeka na zgodę usera” od „długo trwającej komendy” — oba to `working` z rosnącym czasem.
 *
 * Niezależnie od stanu:
 * - `burn`     — koszt agenta ($ wg `Pricing`) z ostatnich `BURN_WINDOW` s; od `BURN_FROM` podpis pokazuje kwotę
 * - `spent`    — koszt agenta od początku sesji; przeglądarka liczy różnicę między odświeżeniami i rzuca monetę za każde `COIN_USD`
 * - `archive`  — ostatnie wywołanie z plikami (trwające albo młodsze niż `ARCHIVE_STAY`) dotyczy plików Claude Code: `~/.claude/…`, `/tmp/claude-*`.
 *                To nadal biuro (w granicy AGENT_ALLOWED_*), tylko inna jego część; te lokalizacje ustala Claude Code, nie konfiguracja
 * - `boundary` — ostatnie naruszenie granicy (`Boundary`) młodsze niż `BOUNDARY_SHOW`: `outside` = wykonało się poza granicą (wyszedł za mur),
 *                `stopped` = zablokowane (odbił się od muru), `trying` = wywołanie poza granicą jeszcze trwa (napiera na mur, np. czeka na zgodę);
 *                `target` = `{text, path}` jak `activity`
 *
 * `messages` — wiadomości między agentami z ostatnich `MESSAGE_SHOW` s (`Session::messages`: `task`, `result`, `send`, `peer`);
 * nadawca rzuca kopertą do adresata. `from` = null — z innej sesji (`peer`), `send` z adresatem spoza sesji pominięty.
 *
 * `path` = true: `text` to ścieżka, przycięta od początku (liczy się koniec — plik i jego folder); inaczej opis wywołania przycięty od końca.
 */
final class OfficeState
{
    public const QUIET_GONE = 600;
    public const STALE_GONE = 1800;
    /** Blokada ostatniego wywołania pokazywana przez ten czas. */
    public const BLOCKED_SHOW = 60;
    public const BURN_WINDOW = 300;
    public const BURN_FROM = 0.5;
    public const BOUNDARY_SHOW = 120;
    public const ARCHIVE_STAY = 20;
    public const COIN_USD = 0.01;
    public const MESSAGE_SHOW = 30;
    private const MESSAGE_LIMIT = 80;

    private const TARGET_LIMIT = 80;

    /**
     * Wiele sesji w jednym biurze: identyfikator agenta poprzedzony sesją (`<session>:<agent>`), sesje bez aktywnych agentów pominięte.
     * Kolejność sesji = kolejność startu (przeglądarka sadza sesje rzędami per projekt w tej kolejności).
     *
     * @param list<array{session: array<string, string>, agents: list<array<string, mixed>>}> $builds wyniki `build`
     * @return array{now: string, burnFrom: float, coinUsd: float, sessions: list<array<string, string>>, agents: list<array<string, mixed>>, messages: list<array<string, mixed>>}
     */
    public static function merge(array $builds, int $now): array
    {
        $builds = array_values(array_filter($builds, static fn (array $b): bool => $b['agents'] !== []));
        usort($builds, static fn (array $a, array $b): int => strcmp($a['agents'][0]['start'], $b['agents'][0]['start']));
        $agents = [];
        $messages = [];
        foreach ($builds as $b) {
            $sid = $b['session']['id'];
            foreach ($b['agents'] as $a) {
                $agents[] = ['id' => $sid . ':' . $a['id'], 'session' => $sid] + $a;
            }
            foreach ($b['messages'] ?? [] as $m) {
                $messages[] = ['id' => $sid . ':' . $m['id'], 'from' => $m['from'] !== null ? $sid . ':' . $m['from'] : null, 'to' => $sid . ':' . $m['to']] + $m;
            }
        }

        return [
            'now' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'burnFrom' => self::BURN_FROM,
            'coinUsd' => self::COIN_USD,
            'sessions' => array_column($builds, 'session'),
            'agents' => $agents,
            'messages' => $messages,
        ];
    }

    /** @return array{now: string, session: array{id: string, title: string, project: string, cwd: string, projectColor: string}, agents: list<array<string, mixed>>, messages: list<array<string, mixed>>} */
    public static function build(Session $session, int $now, ?Boundary $limits = null): array
    {
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
        // prompty, na które sesja główna odpowiada modelem (komendy typu /model nie uruchamiają modelu)
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
            if ($events === [] && $responses === [] && $agent->id !== Agent::MAIN) {
                continue;
            }
            $row = self::agent($agent, $events, $responses, $incidents[$agent->id] ?? null, $agent->id === Agent::MAIN ? $lastPrompt : null, $now, $session->cwd) + [
                'burn' => self::burn($responses, $now),
                'spent' => self::spent($responses),
                'archive' => self::inArchive($events, $now),
                'boundary' => $limits?->enabled() ? self::boundary($limits, $events, $now, $session->cwd) : null,
            ];
            if ($agent->id === Agent::MAIN) {
                $main = $row;
            } elseif (!$row['gone']) {
                $agents[] = $row;
            }
        }
        // sesja główna czeka na usera albo na subagentów w tle — zostaje, dopóki któryś z nich pracuje
        if ($main !== null && $main['state'] === 'idle' && $agents !== []) {
            $main['state'] = 'delegated';
            $main['waitingFor'] = count($agents);
            $main['interrupted'] = false;
        }
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
     * Świeże wiadomości między agentami sesji; adresat i nadawca to identyfikatory agentów (`from` = null: z innej sesji).
     *
     * @return list<array{id: string, ts: string, kind: string, from: ?string, to: string, text: string}>
     */
    private static function messages(Session $session, int $now): array
    {
        $ids = array_flip(array_map(static fn (Agent $a): string => $a->id, $session->agents));
        $out = [];
        foreach ($session->messages as $m) {
            if (!in_array($m['kind'], ['task', 'result', 'send', 'peer'], true) || $now - self::time($m['ts']) > self::MESSAGE_SHOW) {
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

    /**
     * @param list<Event> $events
     * @param list<array{ts: string, model: string}> $responses
     * @param ?array{ts: string, kind: string, text: string} $incident
     * @return array<string, mixed> `gone` = nieaktywny, wychodzi z biura
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
                // wywołanie bez wyniku sprzed ostatniej odpowiedzi modelu = przerwane, nie trwa
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
            // model pisze odpowiedź — też praca
            $state = 'working';
            $since = max($lastResult ?? '', $lastPrompt ?? '', $lastResponse ?? '');
        } else {
            $state = 'idle';
            $since = $lastResponse;
        }
        // wydarzenia z przyszłości (rozjazd zegarów) nie mogą dać ujemnej przerwy
        $quiet = max(0, $now - self::time($last));
        // 30 min tylko dla trwającego wywołania narzędzia; model piszący odpowiedź dopisuje linie częściej
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
     * Czy agent jest w archiwum: decyduje ostatnie wywołanie z plikami (trwające albo młodsze niż `ARCHIVE_STAY`) —
     * kiedy wraca do plików projektu, wraca do biurka.
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
     * Ostatnie naruszenie granicy agenta w oknie `BOUNDARY_SHOW` (bez `suspicious` — ślepa plamka parsera to nie próba wyjścia).
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
     * Kolory koszulek: projekt → kolor ze stałej palety, bez konfiguracji. Kolory są wyraźnie odróżnialne;
     * przy większej liczbie projektów niż kolorów zdarzają się kolizje (rząd biurek i legenda nadal je rozróżniają).
     */
    public const PROJECT_COLORS = ['#e6194b', '#f58231', '#e6c200', '#bfef45', '#3cb44b', '#469990', '#42d4f4', '#4363d8', '#911eb4', '#f032e6', '#9a6324'];

    /** Kolor projektu z jego nazwy (katalog sesji): ten sam projekt zawsze dostaje ten sam kolor, wielkość liter bez znaczenia. */
    public static function projectColor(string $project): string
    {
        return self::PROJECT_COLORS[crc32(mb_strtolower($project)) % count(self::PROJECT_COLORS)];
    }

    /** Krój postaci (głowa: włosy, broda, okulary, słuchawki): rodzina modelu z identyfikatora (`claude-opus-5-5` → `opus`), nieznany (też `fable`) → `default`. */
    public static function persona(string $model): string
    {
        foreach (['haiku', 'sonnet', 'opus'] as $family) {
            if (str_contains(strtolower($model), $family)) {
                return $family;
            }
        }

        return 'default';
    }

    /** `claude-opus-5-5` → `Opus 5.5`, `claude-3-5-haiku-20241022` → `Haiku 3.5`; bez rozpoznanej rodziny — identyfikator bez zmian. */
    public static function modelName(string $model): string
    {
        if (!preg_match('/(fable|haiku|sonnet|opus)/i', $model, $m)) {
            return $model;
        }
        preg_match_all('/(?<![\d])\d{1,2}(?![\d])/', $model, $v);

        return trim(ucfirst(strtolower($m[1])) . ' ' . implode('.', $v[0]));
    }

    /** Co agent robi: plik (skrócona ścieżka) albo opis wywołania. */
    private static function activity(Event $e, ?string $cwd): array
    {
        return self::target($e->touches !== [] ? App::shortPath($e->touches[0]->path, $cwd) : null, $e->summary);
    }

    /** Cel do dymka: ścieżka przycięta od początku, opis od końca. */
    private static function target(?string $path, string $summary): array
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
