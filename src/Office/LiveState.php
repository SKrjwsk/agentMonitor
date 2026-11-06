<?php

declare(strict_types=1);

namespace Viz\Office;

use Viz\Boundary;
use Viz\Model\Agent;
use Viz\Model\Event;
use Viz\Model\Session;
use Viz\Model\Touch;
use Viz\Signals;
use Viz\Web\App;

/**
 * Stan widoku Live (tablica decyzji): sesje aktywne w oknie `WINDOW` i to, na co user może zareagować. Liczone z tych samych wierszy
 * agentów co biuro (`OfficeState::rows`), ale z dłuższym oknem: sesja czekająca na usera 20 min jest tu najważniejsza, a biuro już ją wypuściło.
 * Przeglądarka składa z tego komórki („Ty”, „Granica”, „Kontekst”, „Tempo”) i wagi — progi są tu, żeby JS ich nie zgadywał.
 *
 * Agent:
 * - stany jak w biurze (`working`, `delegated`, `idle`, `stuck`) + `done` = subagent zakończony (wiersz biura `gone`) w oknie
 * - `context` = kontekst ostatniej odpowiedzi (input + odczyt i zapis cache), `contextMax` = okno modelu (`CONTEXT_WINDOWS`, inaczej 1M;
 *   gdy kontekst je przekracza — sam kontekst, bo tabela jest nieaktualna)
 * - `reportBlocked` = ostatnie wywołanie narzędzia wiadomości subagenta (raport, `SendMessage`, zlecenie) zablokowane — adresat nie dostał wiadomości
 *
 * Granica (`boundary`, null = bez konfiguracji `AGENT_ALLOWED_*`): wywołania w oknie, które `Boundary` klasyfikuje:
 * `outside` (wykonane poza), `trying` (poza granicą, jeszcze trwa — zwykle czeka na zgodę), `stopped` (zatrzymane), `unknown` (Bash bez ustalonych ścieżek).
 * Z transkryptu nie da się odróżnić prośby o zgodę od długiej komendy — przeglądarka pisze „zgoda?” po `PERMISSION_GUESS` s trwania wywołania.
 */
final class LiveState
{
    public const WINDOW = 1800;
    public const PERMISSION_GUESS = 90;
    /** Okna kontekstu (max input tokens) per prefiks identyfikatora modelu; reszta = `CONTEXT_DEFAULT`. Stan: dokumentacja API 2026-10-06. */
    public const CONTEXT_WINDOWS = ['claude-haiku-4' => 200_000];
    public const CONTEXT_DEFAULT = 1_000_000;
    private const BOUNDARY_LIMIT = 50;
    /** Wywołania agenta w oknie dla widoków (oś czasu, lista): najnowsze. */
    public const CALL_LIMIT = 200;
    /** Katalogi sesji na mapie granicy: najświeżej dotknięte. */
    public const MAP_LIMIT = 150;
    private const MESSAGE_TOOLS = ['Agent', 'Task', 'SubagentHandback', 'SendMessage'];
    /** Wiadomości do innych sesji (`sends`) w wierszu sesji: najnowsze. */
    private const SEND_LIMIT = 50;

    /**
     * Sesja do tablicy albo null, gdy nikt w niej nie był aktywny w oknie. `$whole` = cała sesja bez okna i bez limitu wywołań
     * (zakładka Flow w szczegółach sesji) — wtedy null tylko bez sesji głównej.
     *
     * @return ?array<string, mixed>
     */
    public static function session(Session $session, int $now, ?Boundary $limits = null, bool $whole = false): ?array
    {
        ['main' => $main, 'subs' => $subs] = OfficeState::rows($session, $now, $limits);
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
        // koszt odpowiedzi modelu w oknie per agent; Flow (`$whole`) go nie ma
        $inWindow = [];
        foreach ($whole ? [] : $session->usage as $u) {
            if ($u['cost'] !== null && self::time($u['ts']) >= $from) {
                $inWindow[$u['agent']] = ($inWindow[$u['agent']] ?? 0.0) + array_sum($u['cost']);
            }
        }
        // pliki odczytane / zmienione (unikalne ścieżki) i komendy Bash wykonane w oknie — per agent i dla całej sesji
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
                'blocked' => $row['blocked'],
                'burn' => $row['burn'],
                'spent' => $row['spent'],
                'counts' => $counts($row['id']),
                'usdWindow' => $whole ? null : round($inWindow[$row['id']] ?? 0.0, 4),
                // tokeny agenta z całej sesji (nie z okna): wejście, wyjście, zapis i odczyt cache
                'tokens' => array_intersect_key($session->tokens($row['id']), array_flip(['input', 'output', 'cacheWrite', 'cacheRead'])),
                'context' => $tokens,
                'contextMax' => max($tokens, self::contextWindow($row['model'])),
                'reportBlocked' => $row['kind'] === 'sub' ? self::reportBlocked($byAgent[$row['id']] ?? [], $from) : null,
                'calls' => self::calls($byAgent[$row['id']] ?? [], $from, $limits, $session->cwd, $whole ? PHP_INT_MAX : self::CALL_LIMIT),
                // dla biura 3D: `gone` = poza biurem według jego progów (OfficeState), głowa, archiwum ~/.claude, naruszenie granicy z 2 min
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
            'agents' => $agents,
            'boundary' => $limits?->enabled() ? self::boundary($limits, $session, $from) : null,
            'map' => $limits?->enabled() ? self::map($limits, $session, $from) : null,
            'messages' => OfficeState::messages($session, $now, $whole ? PHP_INT_MAX : self::WINDOW),
            // oś pracy: prompty usera (bez treści), minione oczekiwania na usera i wiadomości do innych sesji
            'prompts' => self::prompts($session, $from),
            'waits' => self::waits($session, $from),
            'sends' => self::sends($session, $from),
        ];
    }

    /**
     * Prompty w oknie: `prompt` (user), `command` (komenda `/…`), `peer` (wiadomość z innej sesji) — sam czas i rodzaj.
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
     * Minione oczekiwania sesji głównej na usera: od ostatniej aktywności sesji głównej (odpowiedź modelu albo koniec wywołania)
     * do promptu usera (`prompt`, `command`), kończące się w oknie. Bieżące oczekiwanie to stan `idle` + `since`, nie ta lista.
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
     * Wiadomości `SendMessage` do adresata spoza sesji (inna sesja: nazwa albo adres gniazda) w oknie — do strzałek między sesjami.
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
     * Wywołania narzędzi agenta zakończone albo trwające w oknie, najnowsze `$limit` (zwykle `CALL_LIMIT`). `status` (od najważniejszego):
     * `blocked` (blokada Claude Code), `outside` / `trying` / `stopped` (granica), `failed` (nie wykonało się albo kod ≠ 0), `unknown` (Bash bez ścieżek), `ok`.
     * `tokens` / `usd` = koszt wyniku w kontekście z `Event::carry` (zapis + dalsze odczyty; null = brak szacunku albo model spoza cennika).
     *
     * @param list<Event> $events
     * @return list<array{id: string, ts: string, end: ?string, tool: string, text: string, path: bool, status: string, tokens: ?int, usd: ?float}>
     */
    private static function calls(array $events, int $from, ?Boundary $limits, ?string $cwd, int $limit): array
    {
        $out = [];
        foreach ($events as $e) {
            // trwające wywołanie sprzed okna pominięte: zwykle przerwane (bez wyniku w transkrypcie na zawsze)
            if (self::time($e->endTs ?? $e->ts) < $from) {
                continue;
            }
            $kind = $limits?->classify($e);
            // przy naruszeniu granicy cel = pierwsza ścieżka poza granicą (wywołanie bywa poza przez drugą z kilku ścieżek)
            $touch = in_array($kind, [Boundary::OUTSIDE, Boundary::STOPPED], true) ? ($limits->outside($e)[0] ?? null) : ($e->touches[0] ?? null);
            $target = OfficeState::target($touch !== null ? App::shortPath($touch->path, $cwd) : null, $e->summary);
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
            ];
        }

        return array_slice($out, -$limit);
    }

    /**
     * Mapa granicy sesji: katalogi dotknięte w oknie, pogrupowane po strefie (`Boundary::zoneOf`: `rw`, `r`, `out`; programy systemowe pominięte).
     * `dir` względny wobec korzenia strefy (`.` = sam korzeń), poza strefą — z `~`. Liczniki per rodzaj operacji; `stopped` = dotknięcia
     * z wywołań, które się nie wykonały. Najświeższe `MAP_LIMIT`.
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
     * @param list<array<string, mixed>> $sessions wyniki `session` (bez null)
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
            // strefy z konfiguracji do wyświetlenia (mapa granicy), ścieżki z `~`
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
     * Ostatnie wywołanie narzędzia wiadomości subagenta zablokowane (w oknie) — nic po nim tego rodzaju nie przeszło.
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
     * Wywołania w oknie, które przekroczyły granicę albo jej nie da się ustalić; najnowsze `BOUNDARY_LIMIT`.
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
