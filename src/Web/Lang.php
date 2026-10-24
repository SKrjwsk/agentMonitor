<?php

declare(strict_types=1);

namespace Viz\Web;

/**
 * Język interfejsu: angielskie teksty w kodzie są kluczami, `lang/<kod>.php` zwraca słownik EN → język.
 * Wybór w ciasteczku `viz-lang` (biała lista), domyślnie polski. Brak klucza w słowniku = tekst angielski.
 * Tłumaczymy tylko GUI — eksporty (CSV, zrzut, summary.md) zostają po angielsku.
 */
final class Lang
{
    public const LANGS = ['pl' => 'PL', 'en' => 'EN'];
    public const DEFAULT = 'pl';

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
