<?php

declare(strict_types=1);

namespace Viz\Web;

/**
 * Widoki przeglądowe: przegląd wszystkich sesji w zakresie projektów (`Scope`). Każdy ma trasę strony, szablon i trasę danych JSON,
 * którą odpytuje co 1 s; zakres przychodzi z ciasteczka `viz-scope` (albo parametru `projects`).
 *
 * Nowy widok przeglądowy = wpis tutaj (`data` = `/feed.json`) + szablon w `templates/` + skrypt w `public/assets/`, który bierze dane
 * przez `VizFeed.Start` i tylko je rysuje — bez kodu serwera. Router, lewy panel (link przy `menu`), przełącznik widoków
 * (`templates/scope-switch.php`) i zakres biorą się z tej listy. Wszystkie widoki (także biuro 3D) czytają ten sam feed.
 * Teksty (`label`, `title`) tłumaczy się przy wypisaniu — muszą być w `lang/pl.php`.
 */
final class ScopeViews
{
    /**
     * @var array<string, array{name: string, icon: string, label: string, title: string, template: string, data: string, menu: string}>
     *      klucz = trasa strony; `menu` = do którego linku lewego panelu należy (link pokazuje pierwszy widok swojej grupy)
     */
    public const VIEWS = [
        '/lanes' => ['name' => 'lanes', 'icon' => '≡', 'label' => 'Work axis', 'title' => 'agent lanes of all sessions on one axis of the last 30 min: who works, since when, who waits',
            'template' => 'lanes.php', 'data' => '/feed.json', 'menu' => 'live'],
        '/office' => ['name' => 'office', 'icon' => '▦', 'label' => '3D office', 'title' => 'virtual office: what active agents of all sessions are doing right now',
            'template' => 'office.php', 'data' => '/feed.json', 'menu' => 'live'],
        '/agents' => ['name' => 'agents', 'icon' => '▤', 'label' => 'Agents', 'title' => 'sessions in scope with their agents: model, state, tokens and cost; click = session flow',
            'template' => 'agents.php', 'data' => '/feed.json', 'menu' => 'live'],
    ];

    /** Ciasteczko z ostatnio otwartym widokiem przeglądowym; ustawia je serwer przy każdym wejściu na taki widok. */
    public const COOKIE = 'viz-live';

    /** Jedna pozycja lewego panelu na grupę (zamiast pierwszego widoku grupy): nazwa i opis grupy. */
    private const GROUPS = [
        'live' => ['icon' => '◉', 'label' => 'Live preview', 'title' => 'what agents are doing now — opens the view you used last: work axis, 3D office or agents'],
    ];

    /** Trasa ostatnio otwartego widoku (z ciasteczek); gdy brak albo nieznana — pierwszy widok grupy `live`. */
    public static function last(array $cookies): string
    {
        $path = is_string($cookies[self::COOKIE] ?? null) ? $cookies[self::COOKIE] : '';
        $paths = array_column(self::group('live'), 'path');

        return in_array($path, $paths, true) ? $path : $paths[0];
    }

    /** @return ?array{name: string, icon: string, label: string, title: string, template: string, data: string, menu: string, path: string} */
    public static function page(string $path): ?array
    {
        return isset(self::VIEWS[$path]) ? self::VIEWS[$path] + ['path' => $path] : null;
    }

    /** Trasa danych widoków przeglądowych (dziś tylko `/feed.json`)? */
    public static function isData(string $path): bool
    {
        return in_array($path, array_column(self::VIEWS, 'data'), true);
    }

    /** @return list<array{name: string, icon: string, label: string, title: string, template: string, data: string, menu: string, path: string}> widoki z linkiem w lewym panelu (pierwszy z każdej grupy) */
    public static function menu(?string $last = null): array
    {
        $out = [];
        foreach (self::VIEWS as $path => $view) {
            $out[$view['menu']] ??= $view + ['path' => $path];
        }
        foreach (self::GROUPS as $menu => $group) {
            if (isset($out[$menu])) {
                $out[$menu] = $group + ['path' => $last !== null && ($sv = self::page($last)) !== null && $sv['menu'] === $menu ? $last : $out[$menu]['path']] + $out[$menu];
            }
        }

        return array_values($out);
    }

    /** @return list<array{name: string, icon: string, label: string, title: string, template: string, data: string, menu: string, path: string}> widoki tej samej grupy (przełącznik w nagłówku) */
    public static function group(string $menu): array
    {
        $out = [];
        foreach (self::VIEWS as $path => $view) {
            if ($view['menu'] === $menu) {
                $out[] = $view + ['path' => $path];
            }
        }

        return $out;
    }
}
