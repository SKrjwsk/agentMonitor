<?php

declare(strict_types=1);

namespace Viz\Web;

use Viz\Clock;
use Viz\Extract\ToolRoles;
use Viz\Model\Agent;
use Viz\Model\Event;
use Viz\Model\Session;
use Viz\Model\Touch;
use Viz\Path;
use Viz\Problems;

/**
 * Geometria widoku „Oś czasu”: tory agentów, prompty, rozmiar kontekstu i raster plik × czas na wspólnej osi X.
 * Przerwy dłuższe niż GAP_SECONDS są ściskane do GAP_PX — inaczej aktywność wielogodzinnej sesji zlewa się w kreski.
 */
final class Timeline
{
    private const GAP_SECONDS = 300;
    private const GAP_PX = 28;
    private const LANE_H = 22;
    private const CONTEXT_H = 70;
    private const ROW_H = 14;
    private const HEADER_H = 18;
    private const TICK_STEPS = [5, 10, 30, 60, 120, 300, 600, 900, 1800, 3600];

    /** @var list<array{t0: float, t1: float, x0: float}> */
    private array $segments = [];
    private float $pxPerSec = 1.0;

    /**
     * @param list<Event> $events zdarzenia po filtrze agenta
     * @param array<string, int> $agentColors
     * @param string $origin '' | 'context' (tylko wstrzyknięte przez harness) | 'self' (tylko wywołania narzędzi agenta)
     */
    /** @param array<string, list<string>> $problems toolUseId => powody (Problems::tag) */
    public function build(Session $session, array $events, array $agentColors, string $rows, string $origin = '', array $problems = [], ?string $from = null): array
    {
        if ($origin !== '') {
            $events = array_values(array_filter($events, static fn (Event $e): bool => ($e->tool === Event::TOOL_CONTEXT) === ($origin === 'context')));
        }
        $times = [];
        foreach ($events as $e) {
            $times[] = self::sec($e->ts);
            if ($e->endTs !== null) {
                $times[] = self::sec($e->endTs);
            }
        }
        $agentIds = array_unique(array_map(static fn (Event $e): string => $e->agentId, $events));
        // prompty tylko w zakresie pokazanych zdarzeń (filtr tury/agenta), z marginesem na prompt otwierający turę
        $first = $events === [] ? '' : $events[0]->ts;
        $last = $events === [] ? '' : max(array_map(static fn (Event $e): string => $e->endTs ?? $e->ts, $events));
        $margin = $from ?? ($first === '' ? '' : gmdate('Y-m-d\TH:i:s', (int) self::sec($first) - 600));
        $prompts = array_values(array_filter($session->prompts, static fn (array $p): bool => $first !== '' && $p['ts'] >= $margin && $p['ts'] <= $last));
        $turnNo = [];
        $n = 0;
        foreach ($session->prompts as $p) {
            if ($p['kind'] === 'prompt') {
                $turnNo[$p['ts']] = ++$n;
            }
        }
        $usage = array_values(array_filter($session->usage, static fn (array $u): bool => in_array($u['agent'], $agentIds, true)));
        foreach ($prompts as $p) {
            $times[] = self::sec($p['ts']);
        }
        if ($times === []) {
            return ['empty' => true];
        }
        $width = (int) min(9000, max(1000, count($events) * 9));
        $this->scale($times, $width);

        $y = 34;
        $promptsOut = [];
        foreach ($prompts as $p) {
            $promptsOut[] = [
                'x' => $this->x($p['ts']),
                'kind' => $p['kind'],
                'n' => $p['kind'] === 'prompt' ? $turnNo[$p['ts']] ?? null : null,
                'title' => Clock::local($p['ts']) . ' ' . $p['text'],
            ];
        }

        // tory agentów
        $lanes = [];
        $laneY = [];
        $byToolUse = [];
        foreach ($session->agents as $agent) {
            if (!in_array($agent->id, $agentIds, true)) {
                continue;
            }
            $laneY[$agent->id] = $y;
            $lanes[$agent->id] = [
                'id' => $agent->id, 'label' => App::agentLabel($agent), 'color' => ($agentColors[$agent->id] ?? 0) % 8,
                'y' => $y, 'bars' => [], 'parent' => $agent->parentToolUseId,
            ];
            $y += self::LANE_H;
        }
        foreach ($events as $e) {
            $x0 = $this->x($e->ts);
            $x1 = $e->endTs !== null ? $this->x($e->endTs) : $x0;
            $byToolUse[$e->toolUseId] = $x0;
            $lanes[$e->agentId]['bars'][] = [
                'x' => $x0,
                'w' => max(2.0, $x1 - $x0),
                'kind' => self::kind($e),
                'failed' => $e->failed,
                'blind' => $e->tool === 'Bash' && $e->isBlind(),
                'problem' => isset($problems[$e->toolUseId]),
                'title' => (isset($problems[$e->toolUseId]) ? '⚠ ' . implode(' · ', array_map([Problems::class, 'reasonText'], $problems[$e->toolUseId])) . "\n" : '') . Clock::local($e->ts) . ($e->endTs !== null ? '–' . Clock::local($e->endTs) : '') . ' · '
                    . ($e->tool === Event::TOOL_CONTEXT ? t('context') : $e->tool) . ' · ' . $e->summary
                    . ($e->touches !== [] ? "\n" . implode("\n", array_map(static fn (Touch $t): string => $t->op . ' ' . App::shortPath($t->path, $session->cwd), array_slice($e->touches, 0, 8))) : ''),
            ];
        }
        // połączenia: wywołanie Agent w torze rodzica → start toru subagenta
        $spawns = [];
        foreach ($lanes as $lane) {
            if ($lane['parent'] === null || !isset($byToolUse[$lane['parent']]) || $lane['bars'] === []) {
                continue;
            }
            foreach ($events as $e) {
                if ($e->toolUseId === $lane['parent']) {
                    $spawns[] = ['x0' => $byToolUse[$lane['parent']], 'y0' => $laneY[$e->agentId] + self::LANE_H / 2, 'x1' => $lane['bars'][0]['x'], 'y1' => $lane['y'] + self::LANE_H / 2, 'color' => $lane['color']];
                    break;
                }
            }
        }
        $lanesBottom = $y;

        // rozmiar kontekstu
        $y += 12;
        $context = null;
        if ($usage !== []) {
            $max = max(array_column($usage, 'tokens')) ?: 1;
            $series = [];
            usort($usage, static fn (array $a, array $b): int => strcmp($a['ts'], $b['ts']));
            foreach ($usage as $u) {
                $series[$u['agent']][] = round($this->x($u['ts']), 1) . ',' . round($y + self::CONTEXT_H - $u['tokens'] / $max * self::CONTEXT_H, 1);
            }
            $context = [
                'y' => $y,
                'h' => self::CONTEXT_H,
                'max' => $max,
                'series' => array_map(static fn (string $agent, array $pts): array => ['color' => ($agentColors[$agent] ?? 0) % 8, 'points' => implode(' ', $pts)], array_keys($series), $series),
            ];
            $y += self::CONTEXT_H + 16;
        }

        // raster plik × czas
        $y += 6;
        $raster = $this->raster($session, $events, $rows, $y);
        $y = $raster['bottom'];

        return [
            'empty' => false,
            'width' => $width + 20,
            'height' => $y + 10,
            'axis' => $this->axis(),
            'gaps' => $this->gaps(),
            'prompts' => $promptsOut,
            'lanes' => array_values($lanes),
            'lanesBottom' => $lanesBottom,
            'spawns' => $spawns,
            'context' => $context,
            'raster' => $raster,
            'laneH' => self::LANE_H,
            'rowH' => self::ROW_H,
        ];
    }

    /** Klasa paska: najważniejsza operacja wywołania. */
    private static function kind(Event $e): string
    {
        if ($e->tool === Event::TOOL_CONTEXT) {
            return 'context';
        }
        if ($e->tool === 'Agent' || $e->tool === 'Task') {
            return 'agent';
        }
        $ops = array_map(static fn (Touch $t): string => $t->op, $e->touches);
        foreach ([Touch::EDIT, Touch::WRITE, Touch::READ, Touch::SEARCH, Touch::LIST] as $op) {
            if (in_array($op, $ops, true)) {
                return $op === Touch::WRITE ? Touch::EDIT : $op;
            }
        }

        return $e->indirect !== [] || $e->tool === 'Bash' ? 'exec' : 'other';
    }

    /**
     * Wiersze rastra: foldery (domyślnie) albo pliki z nagłówkami folderów.
     * Tylko pliki z odczytem, kontekstem albo zmianą — listing i szukanie samodzielnie zalałyby widok.
     *
     * @param list<Event> $events
     */
    private function raster(Session $session, array $events, string $rows, float $y): array
    {
        if ($rows === 'commands') {
            return $this->commandRows($events, $y);
        }
        $files = [];
        foreach ($events as $e) {
            if (!$e->executed()) {
                continue;
            }
            foreach ($e->touches as $t) {
                if (is_dir($t->path) && $t->exists) {
                    continue;
                }
                $files[Path::key($t->path)]['path'] = $t->path;
                $files[Path::key($t->path)]['dots'][] = ['x' => $this->x($e->ts), 'op' => $t->op, 'title' => Clock::local($e->ts) . ' · ' . $t->op . ' · ' . App::shortPath($t->path, $session->cwd)];
            }
        }
        $files = array_filter($files, static fn (array $f): bool => array_intersect(array_column($f['dots'], 'op'), [Touch::READ, Touch::CONTEXT, Touch::EDIT, Touch::WRITE]) !== []);
        $cwd = $session->cwd;
        uasort($files, static function (array $a, array $b) use ($cwd): int {
            $inA = $cwd !== null && str_starts_with($a['path'], $cwd . '/') ? 0 : 1;
            $inB = $cwd !== null && str_starts_with($b['path'], $cwd . '/') ? 0 : 1;

            return [$inA, dirname($a['path']), basename($a['path'])] <=> [$inB, dirname($b['path']), basename($b['path'])];
        });

        $out = [];
        $lastDir = null;
        if ($rows === 'files') {
            foreach ($files as $f) {
                $dir = dirname($f['path']);
                if ($dir !== $lastDir) {
                    $out[] = ['y' => $y, 'header' => true, 'label' => App::shortPath($dir, $cwd) . '/', 'title' => App::shortPath($dir, $cwd), 'dots' => [], 'hash' => null];
                    $y += self::HEADER_H;
                    $lastDir = $dir;
                }
                $out[] = ['y' => $y, 'header' => false, 'label' => basename($f['path']), 'title' => App::shortPath($f['path'], $cwd), 'dots' => $f['dots'], 'hash' => App::fileHash($f['path'])];
                $y += self::ROW_H;
            }
        } else {
            $dirs = [];
            foreach ($files as $f) {
                $dir = dirname($f['path']);
                $dirs[$dir]['dots'] = array_merge($dirs[$dir]['dots'] ?? [], $f['dots']);
                $dirs[$dir]['files'] = ($dirs[$dir]['files'] ?? 0) + 1;
            }
            foreach ($dirs as $dir => $d) {
                $out[] = ['y' => $y, 'header' => false, 'label' => App::shortPath($dir, $cwd) . '/ (' . $d['files'] . ')', 'title' => App::shortPath($dir, $cwd), 'dots' => $d['dots'], 'hash' => null];
                $y += self::ROW_H;
            }
        }

        return ['top' => $out[0]['y'] ?? $y, 'rows' => $out, 'bottom' => $y, 'count' => count($files)];
    }

    /**
     * Wiersz = program, kropka = wystąpienie; kolor = status całego wywołania (ok / błąd po wykonaniu / niewykonane).
     *
     * @param list<Event> $events
     */
    private function commandRows(array $events, float $y): array
    {
        $programs = [];
        foreach ($events as $e) {
            foreach ($e->commands as $c) {
                $status = !$e->failed ? 'ok' : ($e->executed() ? 'failed' : 'blocked');
                $programs[$c->program][] = [
                    'x' => $this->x($e->ts),
                    'op' => 'cmd-' . $status,
                    'title' => Clock::local($e->ts) . ' · ' . $c->program . ($c->sub !== null ? ' ' . $c->sub : '')
                        . ($c->contexts !== [] ? ' [' . implode(', ', $c->contexts) . ']' : '')
                        . ($c->pipedFrom !== null ? ' ← ' . $c->pipedFrom : '')
                        . ($c->wrappers !== [] ? ' (' . implode(', ', $c->wrappers) . ')' : '')
                        . ' · ' . t(['ok' => 'ok', 'failed' => 'error', 'blocked' => 'not executed'][$status])
                        . "\n" . mb_strimwidth($e->summary, 0, 160, '…'),
                ];
            }
        }
        uksort($programs, static fn (string $a, string $b): int => [count($programs[$b]), $a] <=> [count($programs[$a]), $b]);
        $out = [];
        foreach ($programs as $program => $dots) {
            $failed = count(array_filter($dots, static fn (array $d): bool => $d['op'] !== 'cmd-ok'));
            $out[] = [
                'y' => $y, 'header' => false, 'hash' => null, 'dots' => $dots,
                'label' => $program . ' (' . count($dots) . ($failed > 0 ? ', ' . t('errors {n}', ['n' => $failed]) : '') . ')',
                'title' => $program . ' · ' . t('role: {r}', ['r' => t(ToolRoles::role($program))]),
            ];
            $y += self::ROW_H;
        }

        return ['top' => $out[0]['y'] ?? $y, 'rows' => $out, 'bottom' => $y, 'count' => count($programs), 'kind' => 'commands'];
    }

    /** @param list<float> $times */
    private function scale(array $times, int $width): void
    {
        sort($times);
        $segments = [];
        $start = $prev = $times[0];
        foreach ($times as $t) {
            if ($t - $prev > self::GAP_SECONDS) {
                $segments[] = [$start, $prev];
                $start = $t;
            }
            $prev = $t;
        }
        $segments[] = [$start, $prev];
        $active = array_sum(array_map(static fn (array $s): float => max(1.0, $s[1] - $s[0]), $segments));
        $this->pxPerSec = max(0.001, ($width - (count($segments) - 1) * self::GAP_PX) / $active);
        $x = 10.0;
        $this->segments = [];
        foreach ($segments as [$t0, $t1]) {
            $this->segments[] = ['t0' => $t0, 't1' => $t1, 'x0' => $x];
            $x += max(1.0, $t1 - $t0) * $this->pxPerSec + self::GAP_PX;
        }
    }

    private function x(string $iso): float
    {
        $t = self::sec($iso);
        $seg = $this->segments[0];
        foreach ($this->segments as $s) {
            if ($t >= $s['t0']) {
                $seg = $s;
            }
        }

        return round($seg['x0'] + max(0.0, min($t, $seg['t1']) - $seg['t0']) * $this->pxPerSec, 1);
    }

    /** @return list<array{x: float, label: string}> */
    private function axis(): array
    {
        $step = self::TICK_STEPS[count(self::TICK_STEPS) - 1];
        foreach (self::TICK_STEPS as $s) {
            if ($s * $this->pxPerSec >= 90) {
                $step = $s;
                break;
            }
        }
        $ticks = [];
        $zone = Clock::zone();
        foreach ($this->segments as $seg) {
            for ($t = ceil($seg['t0'] / $step) * $step; $t <= $seg['t1']; $t += $step) {
                $ticks[] = [
                    'x' => round($seg['x0'] + ($t - $seg['t0']) * $this->pxPerSec, 1),
                    'label' => (new \DateTimeImmutable('@' . (int) $t))->setTimezone($zone)->format($step < 60 ? 'H:i:s' : 'H:i'),
                ];
            }
        }

        return $ticks;
    }

    /** @return list<array{x: float, label: string}> */
    private function gaps(): array
    {
        $out = [];
        for ($i = 1, $n = count($this->segments); $i < $n; $i++) {
            $gap = $this->segments[$i]['t0'] - $this->segments[$i - 1]['t1'];
            $h = intdiv((int) $gap, 3600);
            $m = intdiv((int) $gap % 3600, 60);
            $out[] = ['x' => $this->segments[$i]['x0'] - self::GAP_PX / 2, 'label' => t($h > 0 ? '{h} h {m} min break' : '{m} min break', ['h' => $h, 'm' => $m])];
        }

        return $out;
    }

    private static function sec(string $iso): float
    {
        try {
            $d = new \DateTimeImmutable($iso);

            return (float) $d->format('U.u');
        } catch (\Exception) {
            return 0.0;
        }
    }
}
