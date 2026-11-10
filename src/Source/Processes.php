<?php

declare(strict_types=1);

namespace Viz\Source;

use Viz\Path;

/**
 * Migawka procesów użytkownika: `ps` (pid, ppid, czas życia, CPU, RAM, stan, nazwa programu), `lsof` (katalog roboczy
 * i porty nasłuchujące) — tylko dla procesów `claude`, ich potomków i procesów osieroconych (PPID = 1).
 *
 * Prywatność: żadne wywołanie nie pobiera argumentów procesu (kolumny `args` / `command`), bo mogą zawierać tokeny.
 * Z `comm` zostaje sama nazwa programu (`basename`). Komendy idą przez `proc_open` z tablicą argumentów (bez powłoki)
 * i limitem czasu; gdy komenda zawiedzie (brak programu, sandbox, limit), wynik to `available = false` i pusta lista.
 *
 * Pamięć podręczna `var/cache/processes.json` (`0600`) żyje `MAX_AGE` s — widoki odpytujące co 1 s dzielą jedno wywołanie.
 */
final class Processes
{
    public const MAX_AGE = 5;
    public const TIMEOUT_MS = 4000;
    /** Górna granica liczby pidów w jednym wywołaniu `lsof -p`. */
    public const MAX_PIDS = 600;

    /** Kolumny `ps`; nazwa programu jest ostatnia, bo ścieżka może zawierać spacje. Bez `args` i `command`. */
    public const PS_COLUMNS = 'pid,ppid,etime,%cpu,rss,stat,comm';

    /** @return list<string> */
    public static function psArgv(): array
    {
        return ['ps', '-xo', self::PS_COLUMNS];
    }

    /** @return list<string> */
    public static function cwdArgv(array $pids): array
    {
        return ['lsof', '-a', '-d', 'cwd', '-p', implode(',', array_map('intval', $pids)), '-Fpn'];
    }

    /** @return list<string> */
    public static function listenArgv(): array
    {
        return ['lsof', '-iTCP', '-sTCP:LISTEN', '-P', '-n'];
    }

    /**
     * @param (callable(list<string>): ?string)|null $run wykonanie komendy (null = `proc_open`); zwraca wyjście albo null przy błędzie
     * @return array{available: bool, at: int, procs: list<array<string, mixed>>}
     */
    public static function snapshot(int $now, ?callable $run = null): array
    {
        $run ??= static fn (array $argv): ?string => self::exec($argv, self::TIMEOUT_MS);
        $ps = $run(self::psArgv());
        $all = $ps === null ? [] : self::parsePs($ps);
        if ($all === []) {
            return ['available' => false, 'at' => $now, 'procs' => []];
        }
        $byPid = [];
        foreach ($all as $p) {
            $byPid[$p['pid']] = $p;
        }
        $relevant = self::relevant($byPid);
        // przy obcięciu do MAX_PIDS najpierw procesy `claude` i ich potomkowie, potem osierocone
        uasort($relevant, static fn (array $a, array $b): int => ($b['root'] !== null) <=> ($a['root'] !== null));
        $pids = array_slice(array_keys($relevant), 0, self::MAX_PIDS);
        $cwd = [];
        if ($pids !== []) {
            $out = $run(self::cwdArgv($pids));
            $cwd = $out === null ? [] : self::parseCwd($out);
        }
        $out = $run(self::listenArgv());
        $ports = $out === null ? [] : self::parseListen($out);
        $procs = [];
        foreach ($pids as $pid) {
            $p = $relevant[$pid];
            $p['cwd'] = $cwd[$pid] ?? null;
            $p['ports'] = $ports[$pid] ?? [];
            $procs[] = $p;
        }

        return ['available' => true, 'at' => $now, 'procs' => $procs];
    }

    /** Migawka z pamięcią podręczną; błąd zapisu nie psuje wyniku. */
    public static function cached(int $now): array
    {
        $file = dirname(__DIR__, 2) . '/var/cache/processes.json';
        $c = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (is_array($c) && isset($c['at'], $c['procs']) && $now - (int) $c['at'] <= self::MAX_AGE && $now >= (int) $c['at']) {
            return $c;
        }
        $snap = self::snapshot($now);
        $dir = dirname($file);
        if (is_dir($dir) || @mkdir($dir, 0700, true)) {
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, (string) json_encode($snap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
                @chmod($tmp, 0600);
                @rename($tmp, $file);
            }
        }

        return $snap;
    }

    /**
     * Procesy `claude`, ich potomkowie (przez PPID) i osierocone (PPID = 1); wiersz ma `root` = pid przodka `claude`
     * (dla procesów `claude` — własny pid, dla osieroconych — null) i `orphan`.
     *
     * @param array<int, array<string, mixed>> $byPid
     * @return array<int, array<string, mixed>>
     */
    public static function relevant(array $byPid): array
    {
        $out = [];
        foreach ($byPid as $pid => $p) {
            $root = null;
            $cur = $p;
            for ($i = 0; $i < 64 && $cur !== null; $i++) {
                if ($cur['claude']) {
                    $root = $cur['pid'];
                    break;
                }
                $cur = $byPid[$cur['ppid']] ?? null;
            }
            $orphan = $p['ppid'] === 1;
            if ($root !== null || $orphan) {
                $out[$pid] = $p + ['root' => $root, 'orphan' => $orphan];
            }
        }

        return $out;
    }

    /**
     * Wyjście `ps -xo pid,ppid,etime,%cpu,rss,stat,comm`: nagłówek i linie w złym formacie są pomijane.
     *
     * @return list<array{pid: int, ppid: int, uptime: int, cpu: float, rss: int, stat: string, name: string, claude: bool}>
     */
    public static function parsePs(string $output): array
    {
        $out = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (!preg_match('/^\s*(\d+)\s+(\d+)\s+(\S+)\s+(\d+(?:[.,]\d+)?)\s+(\d+)\s+(\S+)\s+(.+?)\s*$/', $line, $m)) {
                continue;
            }
            $uptime = self::etime($m[3]);
            if ($uptime === null) {
                continue;
            }
            $name = basename($m[7]);
            $out[] = [
                'pid' => (int) $m[1],
                'ppid' => (int) $m[2],
                'uptime' => $uptime,
                'cpu' => (float) str_replace(',', '.', $m[4]),
                'rss' => (int) $m[5] * 1024,
                'stat' => $m[6],
                'name' => $name,
                'claude' => $name === 'claude',
            ];
        }

        return $out;
    }

    /** `[[dd-]hh:]mm:ss` → sekundy; null przy innym formacie. */
    public static function etime(string $etime): ?int
    {
        if (!preg_match('/^(?:(\d+)-)?(?:(\d+):)?(\d+):(\d+)$/', $etime, $m)) {
            return null;
        }

        return (int) $m[1] * 86400 + (int) $m[2] * 3600 + (int) $m[3] * 60 + (int) $m[4];
    }

    /**
     * Wyjście `lsof -a -d cwd -p … -Fpn`: linie `p<pid>`, `fcwd`, `n<ścieżka>`.
     *
     * @return array<int, string> pid → katalog roboczy
     */
    public static function parseCwd(string $output): array
    {
        $out = [];
        $pid = null;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^p(\d+)$/', $line, $m)) {
                $pid = (int) $m[1];
            } elseif ($pid !== null && str_starts_with($line, 'n/') && !isset($out[$pid])) {
                $out[$pid] = Path::normalize(substr($line, 1));
            }
        }

        return $out;
    }

    /**
     * Wyjście `lsof -iTCP -sTCP:LISTEN -P -n`: `COMMAND PID USER FD TYPE DEVICE SIZE/OFF NODE NAME`, NAME = `*:3000 (LISTEN)`.
     *
     * @return array<int, list<int>> pid → porty (rosnąco, bez powtórzeń)
     */
    public static function parseListen(string $output): array
    {
        $out = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (!preg_match('/^\S+\s+(\d+)\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+TCP\s+(\S+):(\d+)\s+\(LISTEN\)\s*$/', $line, $m)) {
                continue;
            }
            $out[(int) $m[1]][(int) $m[3]] = (int) $m[3];
        }
        foreach ($out as $pid => $ports) {
            sort($ports);
            $out[$pid] = $ports;
        }

        return $out;
    }

    /**
     * Wykonanie komendy bez powłoki z limitem czasu; po przekroczeniu limitu własny proces potomny jest kończony.
     *
     * @param list<string> $argv
     * @return ?string stdout; null przy braku programu, kodzie ≥ 2 albo przekroczeniu limitu (`lsof` zwraca 1 przy braku wyników)
     */
    public static function exec(array $argv, int $timeoutMs): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        $proc = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        $out = '';
        $deadline = microtime(true) + $timeoutMs / 1000;
        $timedOut = false;
        while (!feof($pipes[1])) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                $timedOut = true;
                break;
            }
            $read = [$pipes[1]];
            $w = $e = null;
            if (@stream_select($read, $w, $e, 0, (int) (min($left, 0.2) * 1000000)) > 0) {
                $out .= (string) fread($pipes[1], 65536);
            }
            if (strlen($out) > 8_000_000) {
                $timedOut = true;
                break;
            }
        }
        fclose($pipes[1]);
        if ($timedOut) {
            proc_terminate($proc);
            proc_close($proc);

            return null;
        }
        $code = proc_close($proc);

        return $code >= 2 || $code < 0 ? null : $out;
    }
}
