<?php

declare(strict_types=1);

namespace Viz\Office;

use Viz\Boundary;
use Viz\Path;
use Viz\Web\App;
use Viz\Web\Scope;

/**
 * Procesy do feedu (`processes`): z migawki `Processes::snapshot` zostają procesy `claude` z potomkami zgrupowane po projekcie
 * (klucz = `App::fileHash` katalogu roboczego procesu `claude`, jak `projectKey` sesji) i procesy osierocone (PPID = 1) z katalogiem
 * roboczym w strefach zapisu granicy (bez konfiguracji granicy: w katalogu domowym). Zakres projektów obowiązuje jak dla sesji.
 * Procesu nie da się przypisać do jednej sesji (sesje jednego projektu mają ten sam katalog roboczy), więc powiązanie jest z projektem.
 */
final class ProcessView
{
    public const GROUP_LIMIT = 40;
    public const ORPHAN_LIMIT = 30;

    /**
     * @param array{available: bool, at: int, procs: list<array<string, mixed>>} $snap
     * @return array{available: bool, at: int, groups: array<string, array<string, mixed>>, orphans: list<array<string, mixed>>}
     */
    public static function build(array $snap, Scope $scope, Boundary $limits): array
    {
        $out = ['available' => $snap['available'], 'at' => $snap['at'], 'groups' => [], 'orphans' => []];
        $procs = $snap['procs'];
        $byPid = [];
        foreach ($procs as $p) {
            $byPid[$p['pid']] = $p;
        }
        $zones = $limits->zones()['rw'] ?: [Path::home()];
        foreach ($procs as $p) {
            if ($p['root'] !== null) {
                $cwd = $byPid[$p['root']]['cwd'] ?? null;
                if ($cwd === null || !$scope->includes($cwd)) {
                    continue;
                }
                $key = App::fileHash($cwd);
                $g = &$out['groups'][$key];
                $g ??= ['claude' => 0, 'children' => 0, 'cpu' => 0.0, 'rss' => 0, 'procs' => []];
                $g['claude'] += $p['claude'] ? 1 : 0;
                $g['children'] += $p['claude'] ? 0 : 1;
                $g['cpu'] = round($g['cpu'] + $p['cpu'], 1);
                $g['rss'] += $p['rss'];
                if (count($g['procs']) < self::GROUP_LIMIT) {
                    $g['procs'][] = self::row($p);
                }
                unset($g);
            } elseif ($p['orphan'] && !$p['claude'] && count($out['orphans']) < self::ORPHAN_LIMIT) {
                $cwd = $p['cwd'] ?? null;
                if ($cwd !== null && $scope->includes($cwd) && self::under($cwd, $zones)) {
                    $out['orphans'][] = self::row($p) + ['cwd' => App::shortPath($cwd, null), 'project' => basename($cwd), 'projectKey' => App::fileHash($cwd)];
                }
            }
        }

        return $out;
    }

    /** Tylko nazwa programu, pid, ppid, czas życia, CPU, RAM, stan i porty — nigdy argumenty. */
    private static function row(array $p): array
    {
        return ['pid' => $p['pid'], 'ppid' => $p['ppid'], 'name' => $p['name'], 'uptime' => $p['uptime'], 'cpu' => $p['cpu'], 'rss' => $p['rss'], 'stat' => $p['stat'], 'ports' => $p['ports'], 'claude' => $p['claude']];
    }

    /** @param list<string> $zones */
    private static function under(string $cwd, array $zones): bool
    {
        foreach ($zones as $z) {
            $z = rtrim($z, '/');
            if ($cwd === $z || str_starts_with($cwd, $z . '/')) {
                return true;
            }
        }

        return false;
    }
}
