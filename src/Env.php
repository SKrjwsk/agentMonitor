<?php

declare(strict_types=1);

namespace Viz;

/**
 * Konfiguracja z pliku `.env` w katalogu aplikacji (wzór: `.env.example`).
 * Zmienna ustawiona w środowisku procesu ma pierwszeństwo przed plikiem.
 */
final class Env
{
    /** @var array<string, string>|null */
    private static ?array $file = null;

    public static function get(string $key): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        self::$file ??= self::parse(dirname(__DIR__) . '/.env');

        return self::$file[$key] ?? null;
    }

    /** @return list<string> ścieżki z klucza; pusta lista, gdy klucza brak */
    public static function paths(string $key): array
    {
        return self::pathList(self::get($key) ?? '');
    }

    /**
     * „~/SK, /private/tmp/x” → absolutne, znormalizowane ścieżki. Wpis względny jest pomijany:
     * bez katalogu odniesienia nie wiadomo, co oznacza.
     *
     * @return list<string>
     */
    public static function pathList(string $value): array
    {
        $out = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            $path = $item === '' ? null : Path::resolve($item, null);
            if ($path !== null) {
                $out[] = $path;
            }
        }

        return $out;
    }

    /**
     * Linie `KLUCZ=wartość` (opcjonalnie `export`, wartość w cudzysłowach). Komentarze `#` i inne linie są pomijane.
     *
     * @return array<string, string>
     */
    public static function parse(string $file): array
    {
        $out = [];
        $lines = is_readable($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $m)) {
                $out[$m[1]] = preg_match('/^([\'"])(.*)\1$/', $m[2], $q) ? $q[2] : $m[2];
            }
        }

        return $out;
    }
}
