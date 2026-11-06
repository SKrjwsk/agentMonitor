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
        '/live' => ['name' => 'live', 'icon' => '◉', 'label' => 'Live', 'title' => 'where to step in: sessions waiting for you, boundary, context and pace of all sessions in scope',
            'template' => 'live.php', 'data' => '/feed.json', 'menu' => 'live'],
        '/office' => ['name' => 'office', 'icon' => '▦', 'label' => '3D office', 'title' => 'virtual office: what active agents of all sessions are doing right now',
            'template' => 'office.php', 'data' => '/feed.json', 'menu' => 'live'],
        '/flow' => ['name' => 'flow', 'icon' => '⇣', 'label' => 'Flow', 'title' => 'work flow of one session: main session, its subagents and what they are doing, with the log of recent calls and messages',
            'template' => 'flow.php', 'data' => '/feed.json', 'menu' => 'live'],
        '/boundary-graph' => ['name' => 'bgraph', 'icon' => '◈', 'label' => 'Boundary graph', 'title' => 'agents and the directories they touched, grouped by boundary zone; red = outside the boundary',
            'template' => 'boundary-graph.php', 'data' => '/feed.json', 'menu' => 'live'],
        '/boundary-flow' => ['name' => 'bflow', 'icon' => '⇶', 'label' => 'Boundary flow', 'title' => 'how much of the work of each session goes into each zone, outside the boundary and to unknown paths',
            'template' => 'boundary-flow.php', 'data' => '/feed.json', 'menu' => 'live'],
    ];

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
    public static function menu(): array
    {
        $out = [];
        foreach (self::VIEWS as $path => $view) {
            $out[$view['menu']] ??= $view + ['path' => $path];
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
