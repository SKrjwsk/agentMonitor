<?php

declare(strict_types=1);

namespace Viz\Permissions;

use Viz\Model\Event;
use Viz\Model\Session;
use Viz\Model\Touch;
use Viz\Summary\SummaryStore;

/**
 * "Approvals" tab of a session: calls where Claude Code asked the user for permission (or probably asked), with the cause.
 *
 * How we know it asked (`how`; the transcript does not record the question itself):
 * - `hook` — a `PermissionRequest` event or `Notification: permission_prompt` from the hook (`HookLog::state`) between the start and end
 *   of that agent's call; a request without a matching call = a separate row without `id`
 * - `rejected` — rejected by the user (`Event::denial` with `by` = `user`)
 * - `waited` — no block, lasted ≥ `LiveState::PERMISSION_GUESS` (`SummaryStore::waited`, also Bash — `waits` in the aggregate has a 10 min threshold for Bash);
 *   a long command without a question looks the same
 * A permission granted faster than the threshold, without the hook, is invisible.
 *
 * Cause (`causes`, level `rule` / `heuristic` / `unknown`): a matching `ask` rule from settings (`RuleMatcher`) — certain within the limits of
 * matching; a write to a protected path (`PROTECTED_DIRS`, `PROTECTED_FILES`) and patterns from Claude Code messages and documentation
 * recognized in the command text (`cd` + `git`, a variable, a redirect outside the session directory or to an unknown directory, `cd` outside the session directory)
 * and no allow rule — a guess; none of these — unknown.
 * Session directory = the only known working directory (additional ones from `--add-dir` / `additionalDirectories` are not known).
 *
 * Permission mode of the row (`mode`): `permission_mode` of the request from the hook, otherwise the mode from the user's last message before the call
 * (`Session::modes`, transcript); subagent — the main session's mode (documentation: the subagent's `permissionMode` is ignored, its calls
 * go through the same decision order as the session). `modeEffect` = effect of the mode on the cause, straight from the documentation only.
 */
final class Approvals
{
    public const HOOK = 'hook';
    public const WAITED = 'waited';
    public const REJECTED = 'rejected';
    /** Command removed from "most frequently asked" when a part holds more than a program (shell, assignments). */
    private const NOT_SHAPE = ['export', 'set', 'unset', 'source', '.', 'true', 'false', ':'];
    /**
     * Protected paths per code.claude.com/docs/en/permission-modes "Protected paths" (2026-11-09): a write is not approved automatically
     * by any mode except `bypassPermissions`, allow rules do not change that (`default`, `acceptEdits` — ask, `auto` — classifier,
     * `dontAsk` — deny). Applies to file-write tools and `>` redirect targets in Bash (permissions "Redirections").
     * Matching by path components at any level. Skipped: run-dependent entries (`--plugin-dir`, `--settings`,
     * settings symlink files) and the npm configuration from the documentation list (its name cannot be written in code — the secrets hook blocks it).
     */
    private const PROTECTED_DIRS = ['.git', '.config/git', '.vscode', '.idea', '.husky', '.cargo', '.devcontainer', '.yarn', '.mvn', '.claude'];
    private const PROTECTED_FILES = ['.gitconfig', '.gitmodules', '.bashrc', '.bash_profile', '.bash_login', '.bash_aliases', '.bash_logout', '.zshrc', '.zprofile',
        '.zshenv', '.zlogin', '.zlogout', '.profile', '.envrc', '.yarnrc', '.yarnrc.yml', '.pnp.cjs', '.pnp.loader.mjs', '.pnpmfile.cjs', 'bunfig.toml',
        '.bunfig.toml', '.bazelrc', '.bazelversion', '.bazeliskrc', '.pre-commit-config.yaml', 'lefthook.yml', 'lefthook.yaml', '.lefthook.yml', '.lefthook.yaml',
        'gradle-wrapper.properties', 'maven-wrapper.properties', '.devcontainer.json', '.ripgreprc', 'pyrightconfig.json', '.mcp.json', '.claude.json'];
    /**
     * Effect of the mode on the cause kind, straight from code.claude.com/docs/en/permission-modes (2026-11-09); other pairs = no entry
     * (ask or undetermined — the view adds nothing):
     * - `auto`: protected paths and calls without an allow rule go to the classifier (ask rules still ask)
     * - `dontAsk`: every call that would ask is rejected — including ask rules and protected paths
     * - `bypassPermissions`: a write in a protected path is allowed
     */
    private const MODE_EFFECTS = [
        'auto' => ['protected-path' => 'classifier', 'no-allow' => 'classifier'],
        'dontAsk' => ['ask-rule' => 'denied', 'protected-path' => 'denied', 'no-allow' => 'denied', 'cd-git' => 'denied', 'cd-outside' => 'denied',
            'variable' => 'denied', 'redirect' => 'denied'],
        'bypassPermissions' => ['protected-path' => 'allowed'],
    ];
    /** File-write tools whose path is compared with the protected ones. */
    private const WRITE_TOOLS = ['Write', 'Edit', 'MultiEdit', 'NotebookEdit'];

    /**
     * @param list<Event> $events calls within the view's filters
     * @param list<array{ts: string, event: string, agent: string, detail: ?string, commands?: list<string>, rules?: list<string>, mode?: ?string}> $hooks `HookLog::state`
     * @param list<array{ts: string, mode: string}> $modes `Session::modes`
     * @return list<array<string, mixed>> rows, newest first
     */
    public static function rows(array $events, array $hooks, Settings $settings, ?string $cwd, int $now, array $modes = []): array
    {
        $asks = array_values(array_filter($hooks, static fn (array $h): bool => $h['event'] === 'PermissionRequest'
            || ($h['event'] === 'Notification' && $h['detail'] === 'permission_prompt')));
        $used = [];
        $rows = [];
        foreach ($events as $e) {
            if ($e->tool === Event::TOOL_CONTEXT) {
                continue;
            }
            $how = [];
            $request = null;
            $from = self::time($e->ts);
            $to = $e->endTs !== null ? self::time($e->endTs) : (float) $now;
            foreach ($asks as $k => $h) {
                $at = self::time($h['ts']);
                if ($h['agent'] !== $e->agentId || $at < $from - 1 || $at > $to + 1
                    || ($h['event'] === 'PermissionRequest' && $h['detail'] !== null && $h['detail'] !== $e->tool)) {
                    continue;
                }
                $used[$k] = true;
                $how[self::HOOK] = true;
                if ($h['event'] === 'PermissionRequest') {
                    $request ??= $h;
                }
            }
            if (($e->denial['by'] ?? null) === 'user') {
                $how[self::REJECTED] = true;
            }
            if (SummaryStore::waited($e)) {
                $how[self::WAITED] = true;
            }
            if ($how === []) {
                continue;
            }
            $writes = in_array($e->tool, self::WRITE_TOOLS, true)
                ? array_values(array_map(static fn (Touch $t): string => $t->path, array_filter($e->touches, static fn (Touch $t): bool => $t->op === Touch::EDIT || $t->op === Touch::WRITE)))
                : [];
            $rows[] = self::row($e->ts, $e->agentId, $e->tool, $e->toolUseId, $e->summary, $e->command, $e->cwd ?? $cwd, $e->commands, array_keys($how),
                SummaryStore::durationMs($e), $request, $settings, $request['mode'] ?? self::modeAt($modes, $e->ts), $writes);
        }
        foreach ($asks as $k => $h) {
            if (!isset($used[$k]) && $h['event'] === 'PermissionRequest') {
                $rows[] = self::row($h['ts'], $h['agent'], (string) ($h['detail'] ?? '?'), null, implode(' · ', $h['commands'] ?? []), null, $cwd, [], [self::HOOK], null, $h, $settings,
                    $h['mode'] ?? self::modeAt($modes, $h['ts']));
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($b['ts'], $a['ts']));

        return $rows;
    }

    /**
     * "Most frequently asked": command shape (program + subcommand) or tool → count, causes of the most frequent variant
     * (by first cause and rule) and the last occurrence (`last` — a row from `rows`: time, call, session).
     *
     * @param list<array<string, mixed>> $rows newest first
     * @return list<array{shape: string, count: int, causes: list<array<string, mixed>>, last: array<string, mixed>}>
     */
    public static function top(array $rows, int $limit = 8): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $g = $groups[$r['shape']] ?? ['shape' => $r['shape'], 'count' => 0, 'causes' => [], 'last' => $r];
            $g['count']++;
            $key = $r['causes'][0]['kind'] . ':' . ($r['causes'][0]['rule'] ?? '');
            $g['causes'][$key] ??= ['n' => 0, 'causes' => $r['causes']];
            $g['causes'][$key]['n']++;
            $groups[$r['shape']] = $g;
        }
        $out = [];
        foreach ($groups as $g) {
            uasort($g['causes'], static fn (array $a, array $b): int => $b['n'] <=> $a['n']);
            $best = reset($g['causes']);
            $out[] = ['shape' => $g['shape'], 'count' => $g['count'], 'causes' => $best['causes'], 'last' => $g['last']];
        }
        usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['shape'], $b['shape']));

        return array_slice($out, 0, $limit);
    }

    /**
     * Causes of the call, most certain first. Bash without command text (a row from the hook only) — only rules by tool name.
     * A write in a protected path takes precedence over "no allow rule" (allow does not approve it).
     *
     * @param list<string> $writes paths written by `Write` / `Edit` / `NotebookEdit`
     * @return list<array{level: string, kind: string, detail: ?string, rule?: string, file?: string, scope?: string}>
     */
    public static function causes(string $tool, ?string $command, ?string $cwd, Settings $settings, array $writes = []): array
    {
        $out = [];
        $d = RuleMatcher::decide($tool, $command, $settings);
        if ($d['decision'] === 'ask' || $d['decision'] === 'deny') {
            $out[] = ['level' => 'rule', 'kind' => $d['decision'] . '-rule', 'detail' => $d['segment'], 'rule' => (string) $d['rule'], 'file' => (string) $d['file'], 'scope' => (string) $d['scope']];
        }
        foreach ($writes as $w) {
            if (self::protectedPath($w)) {
                $out['protected-path'] = ['level' => 'heuristic', 'kind' => 'protected-path', 'detail' => $w];
                break;
            }
        }
        if ($tool === 'Bash' && $command !== null) {
            $parts = RuleMatcher::segments($command);
            $cdTo = null;
            $vars = [];
            $assigned = [];
            $redirect = null;
            foreach ($parts as $p) {
                foreach ($p['words'] as $w) {
                    if (preg_match('/^([A-Za-z_]\w*)=/', $w, $m)) {
                        $assigned[] = '$' . $m[1];
                        // write to special shell variables — Claude Code always asks (documentation: PATH, IFS)
                        if ($m[1] === 'PATH' || $m[1] === 'IFS') {
                            $vars[] = $m[1];
                        }
                    }
                }
                $words = RuleMatcher::strip($p['words'], true);
                if (($words[0] ?? '') === 'for' && isset($words[1])) {
                    $assigned[] = '$' . $words[1];
                }
                if (($words[0] ?? '') === 'cd' && isset($words[1]) && self::otherDir($words[1], $cwd)) {
                    $cdTo = $words[1];
                }
                if ($cdTo !== null && ($words[0] ?? '') === 'git' && !isset($out['cd-git'])) {
                    $out['cd-git'] = ['level' => 'heuristic', 'kind' => 'cd-git', 'detail' => $cdTo];
                }
                // `$(cat <<'EOF' …)` — a typical commit message, not a variable
                if (!($p['subst'] && ($words[0] ?? '') === 'cat' && $p['heredoc'])) {
                    $vars = [...$vars, ...$p['dyn']];
                }
                foreach ($p['redirects'] as $r) {
                    $t = $r['target'];
                    if ($r['op'] !== '<' && !isset($out['protected-path']) && self::protectedPath($t)) {
                        $out['protected-path'] = ['level' => 'heuristic', 'kind' => 'protected-path', 'detail' => $t];
                    }
                    // relative target after `cd`, target with a variable, or absolute outside the session directory
                    if ($r['op'] !== '<' && !str_starts_with($t, '/dev/')
                        && (str_contains($t, '$') || ($cdTo !== null && !str_starts_with($t, '/')) || self::outside($t, $cwd))) {
                        $redirect ??= $t;
                    }
                }
            }
            // a variable assigned in the same command (`X=…`, `for X in`) has a known value
            $vars = array_values(array_diff(array_unique($vars), array_diff($assigned, ['$PATH', '$IFS'])));
            if ($vars !== []) {
                $out[] = ['level' => 'heuristic', 'kind' => 'variable', 'detail' => implode(' ', array_slice($vars, 0, 4))];
            }
            if ($redirect !== null) {
                $out[] = ['level' => 'heuristic', 'kind' => 'redirect', 'detail' => $redirect];
            }
            // `cd` outside the session directory without `git` (documentation: `cd` is read-only only inside working directories)
            if ($cdTo !== null && !isset($out['cd-git']) && self::outside($cdTo, $cwd)) {
                $out[] = ['level' => 'heuristic', 'kind' => 'cd-outside', 'detail' => $cdTo];
            }
        }
        if ($out === [] && $d['decision'] === 'none') {
            $out[] = ['level' => 'heuristic', 'kind' => 'no-allow', 'detail' => implode(' · ', array_slice($d['missing'], 0, 3))];
        }

        return $out === [] ? [['level' => 'unknown', 'kind' => 'unknown', 'detail' => null]] : array_values($out);
    }

    /**
     * @param list<\Viz\Model\Command> $commands
     * @param list<string> $how
     * @param ?array{commands?: list<string>, rules?: list<string>} $request
     * @param list<string> $writes
     * @return array<string, mixed>
     */
    private static function row(string $ts, string $agent, string $tool, ?string $id, string $summary, ?string $command, ?string $cwd, array $commands,
        array $how, ?int $ms, ?array $request, Settings $settings, ?string $mode, array $writes = []): array
    {
        $causes = self::causes($tool, $command, $cwd, $settings, $writes);
        $shape = self::shape($tool, $causes[0], $commands, $request['commands'] ?? []);
        $suggested = $request['rules'] ?? [];

        return ['ts' => $ts, 'agent' => $agent, 'tool' => $tool, 'id' => $id, 'summary' => $summary, 'how' => $how, 'ms' => $ms,
            'shape' => $shape, 'causes' => $causes, 'suggested' => $suggested, 'mode' => $mode];
    }

    /**
     * Mode at moment `$ts`: the last `Session::modes` entry not later than `$ts`; before the first or without entries = null.
     *
     * @param list<array{ts: string, mode: string}> $modes oldest first
     */
    public static function modeAt(array $modes, string $ts): ?string
    {
        $at = self::time($ts);
        $mode = null;
        foreach ($modes as $m) {
            if (self::time($m['ts']) > $at) {
                break;
            }
            $mode = $m['mode'];
        }

        return $mode;
    }

    /** Effect of the mode on the cause kind (`MODE_EFFECTS`): `classifier` / `denied` / `allowed`; null = ask or undetermined. */
    public static function modeEffect(?string $mode, string $kind): ?string
    {
        return $mode === null ? null : self::MODE_EFFECTS[$mode][$kind] ?? null;
    }

    /**
     * Row modes with a count, most frequent first; a row without a mode (missing in transcript and hook, aggregate from before `PARSER` 19) = key ''.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    public static function modeCounts(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = (string) ($r['mode'] ?? '');
            $out[$k] = ($out[$k] ?? 0) + 1;
        }
        arsort($out);

        return $out;
    }

    /**
     * Command shape for grouping: fixed beginning of the matching rule (`git commit`), otherwise program + subcommand of the first program
     * outside read-only commands, otherwise the shape from the hook, otherwise the first program other than `cd`; other tools — the tool name.
     *
     * @param array<string, mixed> $cause
     * @param list<\Viz\Model\Command> $commands
     * @param list<string> $hookCommands
     */
    private static function shape(string $tool, array $cause, array $commands, array $hookCommands): string
    {
        if ($tool !== 'Bash') {
            return $tool;
        }
        // rule from settings: its fixed beginning (`Bash(git commit:*)` → `git commit`)
        $spec = isset($cause['rule']) ? RuleMatcher::parse((string) $cause['rule'])['spec'] ?? null : null;
        if ($spec !== null) {
            $prefix = trim((string) preg_replace('/(:\*|\*).*$/s', '', $spec));
            if ($prefix !== '') {
                return $prefix;
            }
        }
        $fallback = null;
        foreach ($commands as $c) {
            if (!in_array($c->program, RuleMatcher::READ_ONLY, true) && !in_array($c->program, self::NOT_SHAPE, true)) {
                return $c->program . ($c->sub !== null ? ' ' . $c->sub : '');
            }
            $fallback ??= $c->program !== 'cd' ? $c->program : null;
        }

        return $hookCommands[0] ?? $fallback ?? 'Bash';
    }

    /**
     * Protected path (`PROTECTED_DIRS` as a path component, `PROTECTED_FILES` as a file name). `.claude` exceptions from the documentation:
     * `worktrees/`, `plans/`, `jobs/<id>/tmp/`, `.md` files in `projects/<project>/memory/` and `agent-memory/`.
     */
    public static function protectedPath(string $path): bool
    {
        $parts = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== '' && $s !== '.'));
        if (in_array(end($parts) ?: '', self::PROTECTED_FILES, true)) {
            return true;
        }
        $md = str_ends_with($path, '.md');
        foreach ($parts as $i => $part) {
            $rest = array_slice($parts, $i + 1);
            if ($rest === []) {
                break;
            }
            if ($part === '.claude') {
                $exception = in_array($rest[0], ['worktrees', 'plans'], true)
                    || ($rest[0] === 'jobs' && ($rest[2] ?? null) === 'tmp')
                    || ($md && $rest[0] === 'projects' && ($rest[2] ?? null) === 'memory')
                    || ($md && $rest[0] === 'agent-memory');
                if (!$exception) {
                    return true;
                }
                continue;
            }
            if (in_array($part, self::PROTECTED_DIRS, true) || ($part === '.config' && $rest[0] === 'git' && count($rest) > 1)) {
                return true;
            }
        }

        return false;
    }

    /** `cd` to a directory other than the session directory (relative `cd` = always different; `cd .` is not). */
    private static function otherDir(string $target, ?string $cwd): bool
    {
        if ($target === '.' || $target === '') {
            return false;
        }
        if ($cwd === null || !str_starts_with($target, '/')) {
            return true;
        }

        return rtrim($target, '/') !== rtrim($cwd, '/');
    }

    /** Redirect target outside the session directory (relative = inside it). */
    private static function outside(string $target, ?string $cwd): bool
    {
        if (!str_starts_with($target, '/')) {
            return false;
        }
        if ($cwd === null) {
            return true;
        }
        $cwd = rtrim($cwd, '/');

        return $target !== $cwd && !str_starts_with($target, $cwd . '/');
    }

    private static function time(string $ts): float
    {
        try {
            return (float) (new \DateTimeImmutable($ts))->format('U.u');
        } catch (\Exception) {
            return 0.0;
        }
    }
}
