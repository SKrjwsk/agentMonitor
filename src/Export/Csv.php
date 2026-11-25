<?php

declare(strict_types=1);

namespace Viz\Export;

/**
 * CSV cells from untrusted data (commands, paths, tool and agent names from transcripts): spreadsheets execute cells
 * starting with `=`, `+`, `-`, `@`, a tab or a carriage return as formulas, so such a cell gets a `'` prefix. Use for every text cell of every CSV.
 */
final class Csv
{
    public static function safe(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }

    /**
     * Row for `fputcsv`: text cells through `safe`, numbers unchanged.
     *
     * @param list<string|int|float|bool|null> $cells
     * @return list<string|int|float|bool|null>
     */
    public static function cells(array $cells): array
    {
        return array_map(static fn (string|int|float|bool|null $v): string|int|float|bool|null => is_string($v) ? self::safe($v) : $v, $cells);
    }
}
