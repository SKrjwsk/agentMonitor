<?php

declare(strict_types=1);

namespace Viz\Export;

/**
 * Komórki CSV z danych niezaufanych (komendy, ścieżki, nazwy narzędzi i agentów z transkryptów): arkusze wykonują komórki
 * zaczynające się od `=`, `+`, `-`, `@` jak formuły, więc taka komórka dostaje prefiks `'`. Używaj dla każdej komórki tekstowej każdego CSV.
 */
final class Csv
{
    public static function safe(string $value): string
    {
        return $value !== '' && str_contains('=+-@', $value[0]) ? "'" . $value : $value;
    }

    /**
     * Wiersz do `fputcsv`: komórki tekstowe przez `safe`, liczby bez zmian.
     *
     * @param list<string|int|float|bool|null> $cells
     * @return list<string|int|float|bool|null>
     */
    public static function cells(array $cells): array
    {
        return array_map(static fn (string|int|float|bool|null $v): string|int|float|bool|null => is_string($v) ? self::safe($v) : $v, $cells);
    }
}
