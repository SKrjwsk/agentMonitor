<?php

declare(strict_types=1);

namespace Viz\Permissions;

use Viz\Path;

/**
 * Reguły uprawnień Claude Code z plików ustawień — tylko do odczytu, tylko klucz `permissions` (`allow`, `ask`, `deny`, `defaultMode`).
 *
 * Z pliku dekodowana jest wyłącznie wartość klucza `permissions` najwyższego poziomu (`permissionsJson` wycina ją z tekstu);
 * reszta pliku (`env`, `hooks`, …) nie przechodzi przez `json_decode`, nie jest zapamiętywana ani pokazywana.
 * Aplikacja nigdy nie zapisuje ustawień.
 *
 * Źródła (code.claude.com/docs/en/settings, 2026-11-09): user `~/.claude/settings.json`, projekt `<cwd>/.claude/settings.json`,
 * lokalne `<cwd>/.claude/settings.local.json`; listy `allow`/`ask`/`deny` łączą się między plikami, `defaultMode` — wyższy poziom wygrywa
 * (argumenty wiersza poleceń > lokalne > projekt > user). Plik `--settings` (`CLI`) = `OFFICE_SETTINGS` dla sesji w `~/OFFICE/workbox9`
 * i jego podkatalogach: tak uruchamia je funkcja `claudewrap` środowiska usera (opis w `workbox9/CLAUDE.md`, nie w kodzie Claude Code);
 * gdy cwd = `~/OFFICE/workbox9`, ten sam plik jest już plikiem projektu.
 * `defaultMode` = `auto` w pliku projektu albo lokalnym nie działa (code.claude.com/docs/en/permission-modes, 2026-11-09) — `mode()` go pomija.
 * Uproszczenia: katalog projektu = cwd sesji (Claude Code trzyma plik lokalny w korzeniu repozytorium git), bez ustawień zarządzanych
 * (managed), flagi `--permission-mode` i reguł sesji (`/permissions` bez zapisu do pliku) — faktyczny tryb sesji zapisują transkrypt
 * i hook (`Approvals::modeAt`), nie ustawienia.
 */
final readonly class Settings
{
    public const USER = 'user';
    public const PROJECT = 'project';
    public const LOCAL = 'local';
    public const CLI = 'cli';
    public const BEHAVIORS = ['deny', 'ask', 'allow'];
    /** Tryby uprawnień (`permissions.defaultMode`, `permissionMode` transkryptu, `permission_mode` hooka) według dokumentacji. */
    public const MODES = ['default', 'acceptEdits', 'plan', 'auto', 'dontAsk', 'bypassPermissions'];
    /** Plik `--settings` sesji w `~/OFFICE/workbox9` (względem katalogu domowego). */
    public const OFFICE_SETTINGS = '/OFFICE/workbox9/.claude/settings.json';

    /**
     * @param list<array{scope: string, file: string, exists: bool, allow: list<string>, ask: list<string>, deny: list<string>, mode: ?string}> $sources
     *        od najniższego poziomu (user) do najwyższego (local)
     */
    public function __construct(public array $sources)
    {
    }

    public static function forSession(?string $cwd): self
    {
        $home = Path::home();
        $files = [self::USER => $home . '/.claude/settings.json'];
        if ($cwd !== null && $cwd !== '' && $cwd !== $home) {
            $files[self::PROJECT] = $cwd . '/.claude/settings.json';
            $files[self::LOCAL] = $cwd . '/.claude/settings.local.json';
        }
        $workbox = dirname($home . self::OFFICE_SETTINGS, 2);
        if ($home !== '' && $cwd !== null && str_starts_with($cwd, $workbox . '/')) {
            $files[self::CLI] = $home . self::OFFICE_SETTINGS;
        }

        return self::fromFiles($files);
    }

    /** @param array<string, string> $files zakres => ścieżka, od najniższego poziomu */
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
     * Wartość klucza `permissions` najwyższego poziomu dokumentu JSON, zdekodowana; reszta dokumentu nie jest dekodowana.
     * Brak klucza, niepoprawny JSON (cały dokument) albo nie-obiekt = null.
     *
     * @return ?array<string, mixed>
     */
    public static function permissionsJson(string $json): ?array
    {
        // json_validate sprawdza składnię bez budowania struktur; uszkodzony plik Claude Code też pomija
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

    /** `defaultMode` z najwyższego poziomu, który go ustawia (bez `auto` z plików projektu i lokalnego); null = żaden plik. */
    public function mode(): ?string
    {
        return $this->modeSource()['mode'] ?? null;
    }

    /** Plik, z którego pochodzi `mode()`; null = żaden. */
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
     * Reguły jednego rodzaju ze wszystkich plików (listy się łączą).
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

    /** Plik danego zakresu albo null. */
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

    /** Indeks zamykającego `"` napisu zaczynającego się w `$i`; null = niedomknięty. */
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

    /** Indeks pierwszego znaku za wartością zaczynającą się w `$i` (obiekt, tablica, napis albo literał); null = niepoprawna. */
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
