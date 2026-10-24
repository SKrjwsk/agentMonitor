<?php

declare(strict_types=1);

namespace Viz\Verify;

use Viz\Clock;
use Viz\Model\Event;
use Viz\Model\Touch;
use Viz\Path;

/**
 * Porównanie odczytów rozpoznanych przez aplikację z logiem systemowym w oknie czasowym sesji.
 * Log nie zna agenta, więc weryfikacja ma sens dla jednego agenta pracującego naraz.
 */
final class Verifier
{
    /** Procesy spoza agenta, które czytają pliki w tle. */
    private const NOISE = ['mds', 'mds_stores', 'mdworker', 'mdworker_shared', 'mdwrite', 'fseventsd', 'MEGAclient', 'MEGAsync',
        'MEGAsync Helper', 'Finder', 'phpstorm', 'idea', 'java', 'Code Helper', 'Code Helper (Plugin)', 'Electron', 'spotlightknowledged',
        'corespotlightd', 'revisiond', 'backupd', 'bird', 'cloudd', 'syspolicyd', 'XprotectService', 'fs_usage', 'grep'];

    private const MARGIN_SECONDS = 5;

    /**
     * @param list<Event> $events
     * @param list<array{time: string, op: string, path: string, proc: string}> $log
     * @return array{window: array{0: string, 1: string}, ok: list<string>, appOnly: list<string>, logOnly: array<string, list<string>>, noise: int}
     */
    public function compare(array $events, array $log, string $root): array
    {
        $root = rtrim(Path::normalize($root), '/');
        $times = array_map(static fn (Event $e): string => $e->ts, array_filter($events, static fn (Event $e): bool => $e->tool !== Event::TOOL_CONTEXT));
        if ($times === []) {
            throw new \RuntimeException('The session has no tool calls.');
        }
        sort($times);
        $from = self::shift(Clock::local($times[0], 'H:i:s'), -self::MARGIN_SECONDS);
        $to = self::shift(Clock::local(end($times), 'H:i:s'), self::MARGIN_SECONDS);

        // aplikacja: pliki przeczytane/zmienione + katalogi przeszukane/wylistowane, tylko pod korzeniem
        $appFiles = [];
        $appDirs = [];
        // tylko przeszukanie (grep -r, rg) otwiera pliki pod katalogiem; listowanie nie czyta treści
        $searchDirs = [];
        foreach ($events as $e) {
            if (!$e->executed() || $e->tool === Event::TOOL_CONTEXT) {
                continue;
            }
            foreach ($e->touches as $t) {
                if (!self::under($t->path, $root)) {
                    continue;
                }
                if (is_dir($t->path)) {
                    $appDirs[Path::key($t->path)] = $t->path;
                    if ($t->op === Touch::SEARCH) {
                        $searchDirs[Path::key($t->path)] = true;
                    }
                } elseif ($t->op !== Touch::WRITE || $t->exists) {
                    $appFiles[Path::key($t->path)] = $t->path;
                }
            }
        }

        $seen = [];
        $seenProc = [];
        $noise = 0;
        foreach ($log as $row) {
            $time = substr($row['time'], 0, 8);
            if ($time < $from || $time > $to || !self::under($row['path'], $root) || str_contains($row['path'], '/.git/')) {
                continue;
            }
            if (in_array($row['proc'], self::NOISE, true) || str_starts_with($row['proc'], 'mdworker')) {
                $noise++;
                continue;
            }
            $key = Path::key($row['path']);
            $seen[$key] = $row['path'];
            if ($row['op'] === 'open') {
                $seenProc[$key][$row['proc']] = true;
            }
        }

        $ok = [];
        $appOnly = [];
        foreach ($appFiles as $key => $path) {
            isset($seen[$key]) ? $ok[] = $path : $appOnly[] = $path;
        }
        foreach ($appDirs as $key => $path) {
            $hit = isset($seen[$key]);
            foreach (array_keys($seen) as $s) {
                if ($hit || str_starts_with($s, $key . '/')) {
                    $hit = true;
                    break;
                }
            }
            $hit ? $ok[] = $path . '/' : $appOnly[] = $path . '/';
        }

        $logOnly = [];
        foreach ($seen as $key => $path) {
            if (!isset($seenProc[$key]) || isset($appFiles[$key]) || isset($appDirs[$key]) || is_dir($path)) {
                continue;
            }
            foreach (array_keys($searchDirs) as $dir) {
                if (str_starts_with($key, $dir . '/')) {
                    continue 2;
                }
            }
            foreach (array_keys($seenProc[$key]) as $proc) {
                $logOnly[$proc][] = $path;
            }
        }
        sort($ok);
        sort($appOnly);
        ksort($logOnly);

        return ['window' => [$from, $to], 'ok' => $ok, 'appOnly' => $appOnly, 'logOnly' => $logOnly, 'noise' => $noise];
    }

    private static function under(string $path, string $root): bool
    {
        return Path::key($path) === Path::key($root) || str_starts_with(Path::key($path), Path::key($root) . '/');
    }

    private static function shift(string $hms, int $seconds): string
    {
        $t = \DateTimeImmutable::createFromFormat('H:i:s', $hms);

        return $t === false ? $hms : $t->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')->format('H:i:s');
    }
}
