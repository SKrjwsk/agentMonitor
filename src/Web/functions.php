<?php

declare(strict_types=1);

/** Escaping of every value coming from data (transcript, hook) — the data is untrusted. */
function h(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Interface text in the current language (Viz\Web\Lang). Key = English text; `{name}` replaced with values from `$vars`.
 * Returns plain text — in HTML still through h(), because `$vars` may be data.
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

/** h(t(…)) — the most common case in templates. */
function th(string $en, array $vars = []): string
{
    return h(t($en, $vars));
}
