<?php

declare(strict_types=1);

namespace Viz;

use Viz\Model\Event;
use Viz\Model\Touch;

/**
 * Granica pracy agentów z `.env`: `AGENT_ALLOWED_RW` (odczyt i zapis), `AGENT_ALLOWED_R` (tylko odczyt).
 * Dotknięcie ścieżki spoza obu list albo zapis w strefie tylko do odczytu = naruszenie.
 * Bez konfiguracji granica jest wyłączona — inaczej każda ścieżka byłaby naruszeniem.
 */
final class Boundary
{
    /** Wywołanie się wykonało, a dotknęło ścieżki poza granicą. */
    public const OUTSIDE = 'outside';
    /** Bash wykonany, ale parser nie ustalił (wszystkich) ścieżek — glob, zmienna, heredoc, nieznany program. */
    public const SUSPICIOUS = 'suspicious';
    /** Próba wyjścia poza granicę, która się nie wykonała: hook, reguła, tryb auto, sandbox, błąd narzędzia. */
    public const STOPPED = 'stopped';
    public const KINDS = [self::OUTSIDE, self::SUSPICIOUS, self::STOPPED];

    /** Plik wykonywalny programu systemowego to nie naruszenie — liczą się pliki przekazane mu w argumentach. */
    private const SYSTEM_PROGRAMS = ['/bin/', '/sbin/', '/usr/', '/opt/homebrew/', '/library/developer/'];

    private static ?self $env = null;

    /** @var list<string> */
    private array $rw;
    /** @var list<string> */
    private array $r;

    /**
     * @param list<string> $rw ścieżki absolutne: odczyt i zapis
     * @param list<string> $r ścieżki absolutne: tylko odczyt
     */
    public function __construct(array $rw, array $r)
    {
        $this->rw = array_map(self::canon(...), $rw);
        $this->r = array_map(self::canon(...), $r);
    }

    public static function fromEnv(): self
    {
        return self::$env ??= new self(Env::paths('AGENT_ALLOWED_RW'), Env::paths('AGENT_ALLOWED_R'));
    }

    public function enabled(): bool
    {
        return $this->rw !== [] || $this->r !== [];
    }

    /** Odcisk konfiguracji — w wersji agregatu, żeby zmiana `.env` przeliczyła sesje, które mają transkrypt. */
    public function fingerprint(): string
    {
        return $this->enabled() ? substr(md5((string) json_encode([$this->rw, $this->r])), 0, 8) : 'off';
    }

    /** @return list<Touch> dotknięcia poza granicą */
    public function outside(Event $e): array
    {
        if (!$this->enabled() || $e->tool === Event::TOOL_CONTEXT) {
            return [];
        }

        return array_values(array_filter($e->touches, fn (Touch $t): bool => !$this->allows($t)));
    }

    /** OUTSIDE / SUSPICIOUS / STOPPED albo null, gdy wywołanie zostało w granicy. */
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

    /** Kto zatrzymał próbę: mechanizm blokady, sandbox albo błąd narzędzia. */
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
        $path = self::canon($t->path);
        if ($t->op === Touch::EXEC && array_filter(self::SYSTEM_PROGRAMS, static fn (string $dir): bool => str_starts_with($path, $dir)) !== []) {
            return true;
        }
        foreach ($this->rw as $root) {
            if (self::under($path, $root)) {
                return true;
            }
        }
        if (in_array($t->op, [Touch::EDIT, Touch::WRITE], true)) {
            return false;
        }
        foreach ($this->r as $root) {
            if (self::under($path, $root)) {
                return true;
            }
        }

        return false;
    }

    /** Klucz porównań; na macOS `/tmp`, `/var`, `/etc` to dowiązania do `/private/…`. */
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
