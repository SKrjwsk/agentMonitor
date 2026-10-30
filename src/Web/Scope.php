<?php

declare(strict_types=1);

namespace Viz\Web;

/**
 * Zakres projektów widoków przeglądowych (`ScopeViews`): wszystkie projekty albo wybrane. Projekt = katalog roboczy sesji,
 * klucz = `App::fileHash(cwd)` (12 hex, jak filtr projektu w Overview i „all”).
 *
 * Jeden wybór dla całej aplikacji: parametr `projects` (lista po przecinku; pusty = wszystkie) ma pierwszeństwo przed ciasteczkiem
 * `viz-scope`, które ustawia wybór w lewym panelu (`Viz.Scope`). Ciasteczko idzie też z odpytywaniem `/feed.json` (wszystkie widoki przeglądowe),
 * więc zmiana wyboru działa bez przeładowania strony.
 */
final class Scope
{
    public const PARAM = 'projects';
    public const COOKIE = 'viz-scope';
    private const MAX = 50;

    /** @param list<string> $keys pusta lista = wszystkie projekty */
    private function __construct(public readonly array $keys)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $cookies
     */
    public static function fromRequest(array $query, array $cookies): self
    {
        if (is_string($query[self::PARAM] ?? null)) {
            return self::parse($query[self::PARAM]);
        }

        return self::parse(is_string($cookies[self::COOKIE] ?? null) ? $cookies[self::COOKIE] : '');
    }

    /** Biała lista: tylko klucze 12 hex, bez powtórzeń, maks. `MAX`; reszta pominięta. */
    public static function parse(string $raw): self
    {
        $keys = [];
        foreach (explode(',', $raw) as $key) {
            $key = trim($key);
            if (preg_match('/^[a-f0-9]{12}$/', $key) && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return new self(array_slice($keys, 0, self::MAX));
    }

    public function all(): bool
    {
        return $this->keys === [];
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys, true);
    }

    /** Sesja o tym katalogu roboczym należy do zakresu; sesja bez katalogu tylko przy „wszystkie”. */
    public function includes(?string $cwd): bool
    {
        if ($this->all()) {
            return true;
        }

        return $cwd !== null && $cwd !== '' && $this->has(App::fileHash($cwd));
    }

    public function value(): string
    {
        return implode(',', $this->keys);
    }
}
