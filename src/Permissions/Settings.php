<?php

declare(strict_types=1);

namespace Viz\Permissions;

use Viz\Env;
use Viz\Path;

/**
 * Claude Code permission rules from settings files: read-only, only the `permissions` key (`allow`, `ask`, `deny`, `defaultMode`).
 *
 * Only the value of the top-level `permissions` key is decoded (`permissionsJson` cuts it out of the text);
 * the rest of the file (`env`, `hooks`, …) never goes through `json_decode`, is not stored and not shown.
 * The app never writes settings.
 *
 * Sources (code.claude.com/docs/en/settings, 2026-11-09): user `~/.claude/settings.json`, project `<cwd>/.claude/settings.json`,
 * local `<cwd>/.claude/settings.local.json`; `allow`/`ask`/`deny` lists merge across files, `defaultMode` — the higher level wins
 * (local > project > user). Sessions below a directory listed in `AGENT_INHERITED_SETTINGS_DIRS` (empty by default) also inherit that
 * directory's `.claude/settings.json` (`inherited` scope), ranked above user and below project: inheritance from a parent directory
 * is not documented by Claude Code, so its precedence is an assumption; when cwd = the listed directory, the same file is already
 * the project file.
 * `defaultMode` = `auto` in a project or local file does not work (code.claude.com/docs/en/permission-modes, 2026-11-09) — `mode()` skips it.
 * Simplifications: project directory = session cwd (Claude Code keeps the local file in the git repository root), no managed
 * settings, no `--settings` / `--permission-mode` flags and no session rules (`/permissions` without saving to a file) — the actual session
 * mode is recorded by the transcript and the hook (`Approvals::modeAt`), not by settings.
 */
final readonly class Settings
{
    public const USER = 'user';
    public const PROJECT = 'project';
    public const LOCAL = 'local';
    public const INHERITED = 'inherited';
    public const BEHAVIORS = ['deny', 'ask', 'allow'];
    /** Permission modes (`permissions.defaultMode`, transcript `permissionMode`, hook `permission_mode`) according to the docs. */
    public const MODES = ['default', 'acceptEdits', 'plan', 'auto', 'dontAsk', 'bypassPermissions'];
    public const INHERITED_DIRS_KEY = 'AGENT_INHERITED_SETTINGS_DIRS';

    /**
     * @param list<array{scope: string, file: string, exists: bool, allow: list<string>, ask: list<string>, deny: list<string>, mode: ?string}> $sources
     *        from the lowest level (user) to the highest (local)
     */
    public function __construct(public array $sources)
    {
    }

    /** @param ?list<string> $inheritedDirs absolute directories whose settings are inherited by sessions below them; null = from configuration */
    public static function forSession(?string $cwd, ?array $inheritedDirs = null): self
    {
        $home = Path::home();
        $files = [self::USER => $home . '/.claude/settings.json'];
        foreach ($inheritedDirs ?? Env::paths(self::INHERITED_DIRS_KEY) as $i => $dir) {
            if ($cwd !== null && str_starts_with($cwd, $dir . '/')) {
                $files[self::INHERITED . ($i === 0 ? '' : '#' . ($i + 1))] = $dir . '/.claude/settings.json';
            }
        }
        if ($cwd !== null && $cwd !== '' && $cwd !== $home) {
            $files[self::PROJECT] = $cwd . '/.claude/settings.json';
            $files[self::LOCAL] = $cwd . '/.claude/settings.local.json';
        }

        return self::fromFiles($files);
    }

    /** @param array<string, string> $files scope => path, from the lowest level */
    public static function fromFiles(array $files): self
    {
        $sources = [];
        foreach ($files as $scope => $file) {
            $raw = is_file($file) && is_readable($file) && filesize($file) <= 1_048_576 ? @file_get_contents($file) : false;
            $perm = $raw === false ? null : self::permissionsJson($raw);
            unset($raw);
            $list = static fn (string $k): array => is_array($perm[$k] ?? null)
                ? array_values(array_filter($perm[$k], static fn (mixed $r): bool => is_string($r) && strlen($r) <= 500)) : [];
            $sources[] = ['scope' => $scope, 'file' => self::display($file), 'exists' => $perm !== null || is_file($file),
                'allow' => $list('allow'), 'ask' => $list('ask'), 'deny' => $list('deny'),
                'mode' => in_array($perm['defaultMode'] ?? null, self::MODES, true) ? $perm['defaultMode'] : null];
        }

        return new self($sources);
    }

    /**
     * Value of the top-level `permissions` key of the JSON document, decoded; the rest of the document is not decoded.
     * Missing key, invalid JSON (the whole document) or a non-object = null.
     *
     * @return ?array<string, mixed>
     */
    public static function permissionsJson(string $json): ?array
    {
        // json_validate checks the syntax without building structures; a corrupted Claude Code file is skipped too
        if (!json_validate($json)) {
            return null;
        }
        $n = strlen($json);
        $i = self::space($json, 0);
        if (($json[$i] ?? '') !== '{') {
            return null;
        }
        $i = self::space($json, $i + 1);
        while ($i < $n && $json[$i] === '"') {
            $keyEnd = self::stringEnd($json, $i);
            if ($keyEnd === null) {
                return null;
            }
            $key = json_decode(substr($json, $i, $keyEnd - $i + 1));
            $i = self::space($json, $keyEnd + 1);
            if (($json[$i] ?? '') !== ':') {
                return null;
            }
            $start = self::space($json, $i + 1);
            $end = self::valueEnd($json, $start);
            if ($end === null) {
                return null;
            }
            if ($key === 'permissions') {
                $v = json_decode(substr($json, $start, $end - $start), true);

                return is_array($v) && !array_is_list($v) ? $v : null;
            }
            $i = self::space($json, $end);
            if (($json[$i] ?? '') !== ',') {
                return null;
            }
            $i = self::space($json, $i + 1);
        }

        return null;
    }

    /** `defaultMode` from the highest level that sets it (without `auto` from project and local files); null = no file. */
    public function mode(): ?string
    {
        return $this->modeSource()['mode'] ?? null;
    }

    /** File that `mode()` comes from; null = none. */
    public function modeFile(): ?string
    {
        return $this->modeSource()['file'] ?? null;
    }

    /** @return ?array{mode: string, file: string} */
    private function modeSource(): ?array
    {
        $out = null;
        foreach ($this->sources as $s) {
            if ($s['mode'] !== null && !($s['mode'] === 'auto' && in_array($s['scope'], [self::PROJECT, self::LOCAL], true))) {
                $out = ['mode' => $s['mode'], 'file' => $s['file']];
            }
        }

        return $out;
    }

    /**
     * Rules of one kind from all files (the lists are merged).
     *
     * @return list<array{rule: string, scope: string, file: string}>
     */
    public function rules(string $behavior): array
    {
        $out = [];
        foreach ($this->sources as $s) {
            foreach ($s[$behavior] ?? [] as $rule) {
                $out[] = ['rule' => $rule, 'scope' => $s['scope'], 'file' => $s['file']];
            }
        }

        return $out;
    }

    /** File of the given scope or null. */
    public function source(string $scope): ?array
    {
        foreach ($this->sources as $s) {
            if ($s['scope'] === $scope) {
                return $s;
            }
        }

        return null;
    }

    private static function display(string $file): string
    {
        $home = Path::home();

        return $home !== '' && str_starts_with($file, $home . '/') ? '~' . substr($file, strlen($home)) : $file;
    }

    private static function space(string $s, int $i): int
    {
        $n = strlen($s);
        while ($i < $n && ($s[$i] === ' ' || $s[$i] === "\n" || $s[$i] === "\r" || $s[$i] === "\t")) {
            $i++;
        }

        return $i;
    }

    /** Index of the closing `"` of a string starting at `$i`; null = unterminated. */
    private static function stringEnd(string $s, int $i): ?int
    {
        $n = strlen($s);
        for ($j = $i + 1; $j < $n; $j++) {
            if ($s[$j] === '\\') {
                $j++;
            } elseif ($s[$j] === '"') {
                return $j;
            }
        }

        return null;
    }

    /** Index of the first character after a value starting at `$i` (object, array, string or literal); null = invalid. */
    private static function valueEnd(string $s, int $i): ?int
    {
        $n = strlen($s);
        if ($i >= $n) {
            return null;
        }
        if ($s[$i] === '"') {
            $end = self::stringEnd($s, $i);

            return $end === null ? null : $end + 1;
        }
        if ($s[$i] !== '{' && $s[$i] !== '[') {
            $j = $i;
            while ($j < $n && !str_contains(",}] \n\r\t", $s[$j])) {
                $j++;
            }

            return $j > $i ? $j : null;
        }
        $depth = 0;
        for ($j = $i; $j < $n; $j++) {
            $c = $s[$j];
            if ($c === '"') {
                $end = self::stringEnd($s, $j);
                if ($end === null) {
                    return null;
                }
                $j = $end;
            } elseif ($c === '{' || $c === '[') {
                $depth++;
            } elseif ($c === '}' || $c === ']') {
                $depth--;
                if ($depth === 0) {
                    return $j + 1;
                }
            }
        }

        return null;
    }
}
