<?php

declare(strict_types=1);

namespace Viz;

use Viz\Model\Event;
use Viz\Model\Touch;

/**
 * Boundary of agent work from `.env`: `AGENT_ALLOWED_RW` (read and write), `AGENT_ALLOWED_R` (read only).
 * Touching a path outside both lists, or writing in a read-only zone, is a violation.
 * Without configuration the boundary is disabled — otherwise every path would be a violation.
 */
final class Boundary
{
    /** The call executed and touched a path outside the boundary. */
    public const OUTSIDE = 'outside';
    /** Bash executed, but the parser did not determine (all) paths — glob, variable, heredoc, unknown program. */
    public const SUSPICIOUS = 'suspicious';
    /** An attempt to leave the boundary that did not execute: hook, rule, auto mode, sandbox, tool error. */
    public const STOPPED = 'stopped';
    public const KINDS = [self::OUTSIDE, self::SUSPICIOUS, self::STOPPED];

    /** The executable of a system program is not a violation — what counts are the files passed to it as arguments. */
    private const SYSTEM_PROGRAMS = ['/bin/', '/sbin/', '/usr/', '/opt/homebrew/', '/library/developer/'];

    private static ?self $env = null;

    /** @var list<string> */
    private array $rw;
    /** @var list<string> */
    private array $r;
    /** @var array{rw: list<string>, r: list<string>} paths from the configuration, for display */
    private array $roots;

    /**
     * @param list<string> $rw absolute paths: read and write
     * @param list<string> $r absolute paths: read only
     */
    public function __construct(array $rw, array $r)
    {
        $this->rw = array_map(self::canon(...), $rw);
        $this->r = array_map(self::canon(...), $r);
        $trim = static fn (string $p): string => rtrim($p, '/');
        $this->roots = ['rw' => array_values(array_map($trim, $rw)), 'r' => array_values(array_map($trim, $r))];
    }

    /** @return array{rw: list<string>, r: list<string>} zones from the configuration (paths as written, without trailing `/`) */
    public function zones(): array
    {
        return $this->roots;
    }

    /**
     * Touch zone: `rw` / `r` (with the zone root), `system` (executable of a system program) or `out`
     * (outside the boundary; also a write in a read-only zone — then `root` = that zone). Same rule as `classify`.
     *
     * @return array{zone: string, root: ?string}
     */
    public function zoneOf(Touch $t): array
    {
        $path = self::canon($t->path);
        if ($t->op === Touch::EXEC && array_filter(self::SYSTEM_PROGRAMS, static fn (string $dir): bool => str_starts_with($path, $dir)) !== []) {
            return ['zone' => 'system', 'root' => null];
        }
        foreach ($this->rw as $i => $root) {
            if (self::under($path, $root)) {
                return ['zone' => 'rw', 'root' => $this->roots['rw'][$i]];
            }
        }
        foreach ($this->r as $i => $root) {
            if (self::under($path, $root)) {
                return ['zone' => in_array($t->op, [Touch::EDIT, Touch::WRITE], true) ? 'out' : 'r', 'root' => $this->roots['r'][$i]];
            }
        }

        return ['zone' => 'out', 'root' => null];
    }

    public static function fromEnv(): self
    {
        return self::$env ??= new self(Env::paths('AGENT_ALLOWED_RW'), Env::paths('AGENT_ALLOWED_R'));
    }

    public function enabled(): bool
    {
        return $this->rw !== [] || $this->r !== [];
    }

    /** Configuration fingerprint — part of the aggregate version, so a `.env` change recomputes sessions that have a transcript. */
    public function fingerprint(): string
    {
        return $this->enabled() ? substr(md5((string) json_encode([$this->rw, $this->r])), 0, 8) : 'off';
    }

    /** @return list<Touch> touches outside the boundary */
    public function outside(Event $e): array
    {
        if (!$this->enabled() || $e->tool === Event::TOOL_CONTEXT) {
            return [];
        }

        return array_values(array_filter($e->touches, fn (Touch $t): bool => !$this->allows($t)));
    }

    /** OUTSIDE / SUSPICIOUS / STOPPED, or null when the call stayed within the boundary. */
    public function classify(Event $e): ?string
    {
        if (!$this->enabled() || $e->tool === Event::TOOL_CONTEXT) {
            return null;
        }
        $ran = $e->executed() && !$e->osDenied;
        if ($this->outside($e) !== []) {
            return $ran ? self::OUTSIDE : self::STOPPED;
        }

        return $ran && $e->tool === 'Bash' && ($e->isBlind() || $e->unresolved !== []) ? self::SUSPICIOUS : null;
    }

    /** Who stopped the attempt: a blocking mechanism, the sandbox, or a tool error. */
    public static function stoppedBy(Event $e): string
    {
        return match (true) {
            $e->denial !== null => $e->denial['by'] . ($e->denial['detail'] !== null ? ' · ' . $e->denial['detail'] : ''),
            $e->osDenied => 'sandbox',
            default => 'tool error',
        };
    }

    private function allows(Touch $t): bool
    {
        return $this->zoneOf($t)['zone'] !== 'out';
    }

    /** Comparison key; on macOS `/tmp`, `/var`, `/etc` are symlinks to `/private/…`. */
    private static function canon(string $path): string
    {
        if (PHP_OS_FAMILY === 'Darwin' && preg_match('~^/(tmp|var|etc)(/|$)~', $path)) {
            $path = '/private' . $path;
        }

        return Path::key(rtrim($path, '/'));
    }

    private static function under(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . '/');
    }
}
