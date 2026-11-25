<?php

declare(strict_types=1);

namespace Viz;

/**
 * Configuration from the `.env` file in the application directory (template: `.env.example`).
 * A variable set in the process environment takes precedence over the file.
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

    /** @return list<string> paths from the key; empty list when the key is missing */
    public static function paths(string $key): array
    {
        return self::pathList(self::get($key) ?? '');
    }

    /**
     * "~/projects, /private/tmp/x" → absolute, normalized paths. A relative entry is skipped:
     * without a reference directory it is unknown what it means.
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
     * Lines `KEY=value` (optionally `export`, value in quotes). Comments `#` and other lines are skipped.
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
