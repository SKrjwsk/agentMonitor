<?php

declare(strict_types=1);

namespace Viz\Web;

/**
 * Projects = session working directories, from the most recently active (session list order). Key = `App::fileHash(cwd)`, as in `Scope`. Name = the last directory; on a repeat (the same directory in different places) the last two.
 */
final class Projects
{
    /**
     * @param list<array{cwd: string}> $sessions
     * @return array<string, array{label: string, cwd: string, sessions: int}>
     */
    public static function of(array $sessions): array
    {
        $projects = [];
        foreach ($sessions as $s) {
            $key = App::fileHash($s['cwd']);
            $projects[$key] ??= ['label' => basename($s['cwd']) ?: '?', 'cwd' => $s['cwd'], 'sessions' => 0];
            $projects[$key]['sessions']++;
        }
        $names = array_count_values(array_column($projects, 'label'));
        foreach ($projects as &$pr) {
            if ($names[$pr['label']] > 1) {
                $pr['label'] = basename(dirname($pr['cwd'])) . '/' . $pr['label'];
            }
        }

        return $projects;
    }

    /**
     * Label of the left panel choice: "all", the name of the only project or the number chosen (keys outside the list skipped).
     *
     * @param array<string, array{label: string}> $projects
     */
    public static function scopeLabel(Scope $scope, array $projects): string
    {
        $chosen = array_values(array_filter($scope->keys, static fn (string $k): bool => isset($projects[$k])));

        return match (count($chosen)) {
            0 => $scope->all() ? t('all') : t('{n} selected', ['n' => count($scope->keys)]),
            1 => $projects[$chosen[0]]['label'],
            default => t('{n} selected', ['n' => count($chosen)]),
        };
    }
}
