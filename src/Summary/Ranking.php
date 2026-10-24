<?php

declare(strict_types=1);

namespace Viz\Summary;

use Viz\Model\Touch;
use Viz\Path;
use Viz\Web\App;

/**
 * Ranking plików ze wszystkich sesji. Domyślny wynik = odczyty + kontekst + zmiany;
 * listing i szukanie są liczone osobno, bo `grep -r` i globy zawyżyłyby „liczbę akcji”.
 */
final class Ranking
{
    public const PAGE = 300;

    public const SORTS = ['main', 'path', Touch::READ, Touch::EDIT, Touch::CONTEXT, Touch::SEARCH, Touch::EXEC, 'sessions'];

    /**
     * @param list<array<string, mixed>> $sessions agregaty z SummaryStore
     * @return array{files: list<array<string, mixed>>, sessions: list<array<string, mixed>>, projects: array<string, array{cwd: string, sessions: int}>}
     */
    public static function build(array $sessions, int $days, string $project, string $sort, bool $onlyProject): array
    {
        $cutoff = $days > 0 ? gmdate('Y-m-d\TH:i:s', time() - $days * 86400) : '';
        $projects = [];
        foreach ($sessions as $s) {
            $cwd = (string) ($s['cwd'] ?? '');
            $key = App::fileHash($cwd);
            $projects[$key] ??= ['cwd' => $cwd, 'sessions' => 0];
            $projects[$key]['sessions']++;
        }
        uasort($projects, static fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions']);

        $files = [];
        $inScope = [];
        foreach ($sessions as $s) {
            $cwd = (string) ($s['cwd'] ?? '');
            if ($project !== '' && App::fileHash($cwd) !== $project) {
                continue;
            }
            if ($cutoff !== '' && (string) ($s['last'] ?? '') < $cutoff) {
                continue;
            }
            $inScope[] = $s;
            foreach ($s['files'] as $f) {
                if ($cutoff !== '' && $f['last'] < $cutoff) {
                    continue;
                }
                if ($onlyProject && $cwd !== '' && !str_starts_with($f['path'], $cwd . '/')) {
                    continue;
                }
                $key = Path::key($f['path']);
                $row = $files[$key] ?? ['path' => $f['path'], 'ops' => [], 'sessions' => [], 'last' => ''];
                foreach ($f['ops'] as $op => $n) {
                    $row['ops'][$op] = ($row['ops'][$op] ?? 0) + $n;
                }
                $row['sessions'][$s['session_id']] = $f['ops'];
                $row['last'] = max($row['last'], $f['last']);
                $files[$key] = $row;
            }
        }

        foreach ($files as $key => $row) {
            $o = $row['ops'];
            $files[$key]['main'] = ($o[Touch::READ] ?? 0) + ($o[Touch::CONTEXT] ?? 0) + ($o[Touch::EDIT] ?? 0) + ($o[Touch::WRITE] ?? 0);
            $files[$key]['score'] = match ($sort) {
                'main' => $files[$key]['main'],
                // po ścieżce: ta sama lista co „liczba wejść”, tylko w kolejności A→Z
                'path' => $files[$key]['main'],
                Touch::EDIT => ($o[Touch::EDIT] ?? 0) + ($o[Touch::WRITE] ?? 0),
                'sessions' => count($row['sessions']),
                default => $o[$sort] ?? 0,
            };
            $files[$key]['hash'] = App::fileHash($row['path']);
            $files[$key]['exists'] = file_exists($row['path']);
        }
        $files = array_filter($files, static fn (array $r): bool => $r['score'] > 0);
        $sort === 'path'
            ? uasort($files, static fn (array $a, array $b): int => strnatcasecmp(App::shortPath($a['path'], null), App::shortPath($b['path'], null)))
            : uasort($files, static fn (array $a, array $b): int => [$b['score'], $b['main']] <=> [$a['score'], $a['main']]);
        usort($inScope, static fn (array $a, array $b): int => strcmp((string) $b['last'], (string) $a['last']));

        return ['files' => array_values($files), 'sessions' => $inScope, 'projects' => $projects];
    }
}
