<?php

declare(strict_types=1);

namespace Viz\Web;

use Viz\Model\Event;
use Viz\Model\Session;
use Viz\Model\Touch;
use Viz\Path;

/**
 * Graf w stylu Obsidiana: agenci ↔ pliki, jeden poziom. Folder = bezpośredni rodzic pliku; pliki są przyciągane
 * do węzła folderu, a przeglądarka rysuje wokół skupiska obszar z nazwą folderu (grupowanie optyczne, bez zwijania).
 * Pochodzenie pliku: `context` (wstrzyknięty przez harness), `self` (doczytany przez agenta), `both`, `mod` (tylko zmieniony), `other` (listing/szukanie).
 */
final class Graph
{
    /**
     * @param list<Event> $events
     * @param array<string, int> $agentColors
     * @return array{nodes: list<array<string, mixed>>, links: list<array<string, mixed>>, hidden: int}
     */
    public static function build(Session $session, array $events, array $agentColors, bool $all, bool $dirs, ?string $origin): array
    {
        $files = [];
        $edges = [];
        foreach ($events as $e) {
            if (!$e->executed()) {
                continue;
            }
            foreach ($e->touches as $t) {
                $key = Path::key($t->path);
                $f = $files[$key] ?? ['path' => $t->path, 'ops' => [], 'agents' => [], 'isDir' => is_dir($t->path)];
                $f['ops'][$t->op] = ($f['ops'][$t->op] ?? 0) + 1;
                $f['agents'][$e->agentId] = true;
                $files[$key] = $f;
                $edges[$e->agentId][$key][$t->op] = ($edges[$e->agentId][$key][$t->op] ?? 0) + 1;
            }
        }

        $nodes = [];
        $links = [];
        $hidden = 0;
        $shown = [];
        foreach ($files as $key => $f) {
            $o = self::origin($f['ops']);
            $visible = ($all || in_array($o, ['context', 'self', 'both', 'mod'], true)) && !$f['isDir'] && ($origin === null || self::matches($o, $origin));
            if (!$visible) {
                $hidden++;
                continue;
            }
            $shown[$key] = true;
            $nodes[] = [
                'id' => 'f:' . App::fileHash($f['path']),
                'type' => 'file',
                'label' => basename($f['path']),
                'path' => App::shortPath($f['path'], $session->cwd),
                'origin' => $o,
                'modified' => isset($f['ops'][Touch::EDIT]) || isset($f['ops'][Touch::WRITE]),
                'reads' => ($f['ops'][Touch::READ] ?? 0) + ($f['ops'][Touch::CONTEXT] ?? 0),
                'ops' => $f['ops'],
                'agents' => array_values(array_map(static fn (string $id): string => isset($session->agents[$id]) ? App::agentLabel($session->agents[$id]) : $id, array_keys($f['agents']))),
                'hash' => App::fileHash($f['path']),
            ];
        }

        foreach ($session->agents as $agent) {
            if (!isset($edges[$agent->id])) {
                continue;
            }
            $nodes[] = [
                'id' => 'a:' . $agent->id,
                'type' => 'agent',
                'label' => App::agentLabel($agent),
                'color' => ($agentColors[$agent->id] ?? 0) % 8,
            ];
            foreach ($edges[$agent->id] as $key => $ops) {
                if (!isset($shown[$key])) {
                    continue;
                }
                $links[] = [
                    's' => 'a:' . $agent->id,
                    't' => 'f:' . App::fileHash($files[$key]['path']),
                    'kind' => self::origin($ops),
                    'w' => array_sum($ops),
                ];
            }
        }

        if ($dirs) {
            self::directories($files, $shown, $session->cwd, $nodes, $links);
        }

        return ['nodes' => $nodes, 'links' => $links, 'hidden' => $hidden];
    }

    /** @param array<string, int> $ops */
    public static function origin(array $ops): string
    {
        $context = isset($ops[Touch::CONTEXT]);
        $self = isset($ops[Touch::READ]);

        return match (true) {
            $context && $self => 'both',
            $context => 'context',
            $self => 'self',
            isset($ops[Touch::EDIT]) || isset($ops[Touch::WRITE]) => 'mod',
            default => 'other',
        };
    }

    private static function matches(string $o, string $filter): bool
    {
        return $filter === 'context' ? in_array($o, ['context', 'both'], true) : in_array($o, ['self', 'both', 'mod'], true);
    }

    /** Węzeł folderu (bezpośredni rodzic pliku) + połączenie z najbliższym folderem-przodkiem, który też jest węzłem. */
    private static function directories(array $files, array $shown, ?string $cwd, array &$nodes, array &$links): void
    {
        $dirs = [];
        foreach (array_keys($shown) as $key) {
            $dir = dirname($files[$key]['path']);
            $dirs[Path::key($dir)] ??= ['path' => $dir, 'files' => 0, 'ops' => []];
            $dirs[Path::key($dir)]['files']++;
            foreach ($files[$key]['ops'] as $op => $n) {
                $dirs[Path::key($dir)]['ops'][$op] = ($dirs[Path::key($dir)]['ops'][$op] ?? 0) + $n;
            }
            $links[] = ['s' => 'd:' . App::fileHash($dir), 't' => 'f:' . App::fileHash($files[$key]['path']), 'kind' => 'tree', 'w' => 1];
        }
        foreach ($dirs as $d) {
            $nodes[] = [
                'id' => 'd:' . App::fileHash($d['path']),
                'type' => 'dir',
                'label' => basename($d['path']) ?: '/',
                'path' => App::shortPath($d['path'], $cwd) . '/',
                'files' => $d['files'],
                'ops' => $d['ops'],
            ];
            for ($up = dirname($d['path']); $up !== '/' && $up !== '.'; $up = dirname($up)) {
                if (isset($dirs[Path::key($up)])) {
                    $links[] = ['s' => 'd:' . App::fileHash($up), 't' => 'd:' . App::fileHash($d['path']), 'kind' => 'tree', 'w' => 1];
                    break;
                }
            }
        }
    }
}
