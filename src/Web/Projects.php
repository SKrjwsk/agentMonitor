<?php

declare(strict_types=1);

namespace Viz\Web;

/**
 * Projekty = katalogi robocze sesji, od ostatnio aktywnego (kolejność listy sesji). Klucz = `App::fileHash(cwd)`, jak w `Scope`. Nazwa = ostatni katalog; przy powtórzeniu (ten sam katalog w różnych miejscach) dwa ostatnie.
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
     * Podpis wyboru w lewym panelu: „wszystkie”, nazwa jedynego projektu albo liczba wybranych (klucze spoza listy pominięte).
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
