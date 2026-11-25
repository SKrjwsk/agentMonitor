<?php

declare(strict_types=1);

namespace Viz\Web;

/**
 * Interface language: English texts in code are keys, `lang/<code>.php` returns an EN → language dictionary.
 * Chosen in the `viz-lang` cookie (whitelist), English by default. A key missing from the dictionary = the English text.
 * We translate the GUI only — exports (CSV, dump, summary.md) stay in English.
 */
final class Lang
{
    public const LANGS = ['pl' => 'PL', 'en' => 'EN'];
    public const DEFAULT = 'en';

    public static function current(): string
    {
        $lang = is_string($_COOKIE['viz-lang'] ?? null) ? $_COOKIE['viz-lang'] : '';

        return isset(self::LANGS[$lang]) ? $lang : self::DEFAULT;
    }

    /** @return array<string, string> */
    public static function dict(): array
    {
        $lang = self::current();
        if ($lang === 'en') {
            return [];
        }
        $file = dirname(__DIR__, 2) . '/lang/' . $lang . '.php';

        return is_file($file) ? (array) require $file : [];
    }
}
