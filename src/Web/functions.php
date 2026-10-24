<?php

declare(strict_types=1);

/** Escapowanie każdej wartości pochodzącej z danych (transkrypt, hook) — dane są niezaufane. */
function h(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Tekst interfejsu w bieżącym języku (Viz\Web\Lang). Klucz = tekst angielski; `{nazwa}` podmieniane wartościami z `$vars`.
 * Zwraca zwykły tekst — w HTML i tak przez h(), bo `$vars` bywają danymi.
 *
 * @param array<string, string|int|float> $vars
 */
function t(string $en, array $vars = []): string
{
    static $dict = null;
    $dict ??= Viz\Web\Lang::dict();
    $text = $dict[$en] ?? $en;
    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }

    return $text;
}

/** h(t(…)) — najczęstszy przypadek w szablonach. */
function th(string $en, array $vars = []): string
{
    return h(t($en, $vars));
}
