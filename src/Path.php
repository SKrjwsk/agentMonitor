<?php

declare(strict_types=1);

namespace Viz;

final class Path
{
    public static function home(): string
    {
        return rtrim((string) (getenv('HOME') ?: ''), '/');
    }

    /** Absolute, normalized path, or null when relative with an unknown cwd. */
    public static function resolve(string $path, ?string $cwd): ?string
    {
        if ($path === '~' || str_starts_with($path, '~/')) {
            $path = self::home() . substr($path, 1);
        }
        if (!str_starts_with($path, '/')) {
            if ($cwd === null) {
                return null;
            }
            $path = $cwd . '/' . $path;
        }

        return self::normalize($path);
    }

    public static function normalize(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $part;
        }

        return '/' . implode('/', $out);
    }

    /** Comparison key: APFS on macOS is case-insensitive by default. */
    public static function key(string $path): string
    {
        return PHP_OS_FAMILY === 'Darwin' ? mb_strtolower($path) : $path;
    }
}
