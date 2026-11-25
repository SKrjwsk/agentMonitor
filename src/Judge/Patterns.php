<?php

declare(strict_types=1);

namespace Viz\Judge;

use Viz\Env;
use Viz\Path;
use Viz\Permissions\Settings;
use Viz\Source\HookLog;

/**
 * Recurring problems across many session judgments. Groups `findings` by the place of the fix (`owner` + normalized `owner_path`), not by title:
 * titles are free text from the judge, and "what to change" is a single file anyway. No LLM; computed on demand from `var/judge/` (the latest successful
 * judgment of each session). A judgment in the old schema (without `findings`) is counted separately and does not enter the ranking.
 */
final class Patterns
{
    private const SEVERITY = ['high' => 3, 'medium' => 2, 'low' => 1];

    /**
     * Latest successful judgment of each session.
     *
     * @return list<array{sid: string, title: string, stamp: string, judgment: array<string, mixed>}>
     */
    public static function load(): array
    {
        $out = [];
        foreach (glob(SessionJudge::root() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $sid = basename($dir);
            if (!HookLog::validSessionId($sid)) {
                continue;
            }
            foreach (SessionJudge::list($sid) as $r) {
                if (($r['ok'] ?? false) === true && is_array($r['judgment'] ?? null)) {
                    $out[] = ['sid' => $sid, 'title' => (string) ($r['title'] ?? ''), 'stamp' => (string) $r['stamp'], 'judgment' => $r['judgment']];
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<array{sid: string, title: string, stamp: string, judgment: array<string, mixed>}> $reports
     * @return array{sessions: int, legacy: int, groups: list<array<string, mixed>>, turns: list<array<string, mixed>>}
     */
    public static function build(array $reports): array
    {
        $groups = [];
        $turns = [];
        $legacy = 0;
        foreach ($reports as $r) {
            $j = $r['judgment'];
            if (!isset($j['findings'])) {
                $legacy++;
                continue;
            }
            foreach ((array) $j['findings'] as $f) {
                $path = self::path((string) ($f['owner_path'] ?? ''));
                $key = (string) ($f['owner'] ?? '') . '|' . $path;
                $g = $groups[$key] ?? ['owner' => (string) ($f['owner'] ?? ''), 'path' => $path, 'sessions' => [], 'items' => [], 'severity' => 0];
                $g['sessions'][$r['sid']] = $r['title'];
                $g['severity'] = max($g['severity'], self::SEVERITY[(string) ($f['severity'] ?? '')] ?? 0);
                $g['items'][] = ['sid' => $r['sid'], 'title' => (string) ($f['title'] ?? ''), 'severity' => (string) ($f['severity'] ?? ''), 'fix' => (string) ($f['fix'] ?? '')];
                $groups[$key] = $g;
            }
            foreach ((array) ($j['timeline'] ?? []) as $t) {
                $kind = (string) ($t['kind'] ?? '');
                if ($kind !== '' && $kind !== 'good') {
                    $turns[$kind]['sessions'][$r['sid']] = true;
                    $turns[$kind]['count'] = ($turns[$kind]['count'] ?? 0) + 1;
                }
            }
        }
        $groups = array_values(array_map(static fn (array $g): array => ['sessions' => count($g['sessions']), 'sessionIds' => array_keys($g['sessions'])] + $g, $groups));
        usort($groups, static fn (array $a, array $b): int => [$b['sessions'], $b['severity'], count($b['items'])] <=> [$a['sessions'], $a['severity'], count($a['items'])]);
        $turnRows = [];
        foreach ($turns as $kind => $t) {
            $turnRows[] = ['kind' => (string) $kind, 'count' => $t['count'], 'sessions' => count($t['sessions'])];
        }
        usort($turnRows, static fn (array $a, array $b): int => [$b['sessions'], $b['count']] <=> [$a['sessions'], $a['count']]);

        return ['sessions' => count($reports) - $legacy, 'legacy' => $legacy, 'groups' => $groups, 'turns' => $turnRows];
    }

    /**
     * The same path written `/Users/x/work/…`, `~/work/…` or `work/…` lands in one group; `work` is a directory
     * from `AGENT_INHERITED_SETTINGS_DIRS`.
     */
    private static function path(string $path): string
    {
        $relative = preg_replace('#^(?:/(?:Users|home)/[^/]+|~)?/?#', '', trim($path)) ?? $path;
        $home = Path::home();
        foreach (Env::paths(Settings::INHERITED_DIRS_KEY) as $dir) {
            $prefix = ltrim(str_starts_with($dir, $home . '/') ? substr($dir, strlen($home)) : $dir, '/') . '/';
            if (str_starts_with($relative, $prefix)) {
                return substr($relative, strlen($prefix));
            }
        }

        return $relative;
    }
}
