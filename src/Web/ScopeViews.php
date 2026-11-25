<?php

declare(strict_types=1);

namespace Viz\Web;

/**
 * Overview views: an overview of all sessions in the project scope (`Scope`). Each has a page route, a template and a JSON data route
 * polled every 1 s; the scope comes from the `viz-scope` cookie (or the `projects` parameter).
 *
 * A new overview view = an entry here (`data` = `/feed.json`) + a template in `templates/` + a script in `public/assets/` that takes the data
 * through `VizFeed.Start` and only draws it — no server code. The router, left panel (link at `menu`), view switch
 * (`templates/scope-switch.php`) and scope come from this list. All views (also the 3D office) read the same feed.
 * Texts (`label`, `title`) are translated on output — they must be in `lang/pl.php`.
 */
final class ScopeViews
{
    /**
     * @var array<string, array{name: string, icon: string, label: string, title: string, template: string, data: string, menu: string}>
     *      key = page route; `menu` = which left panel link it belongs to (the link shows the first view of its group)
     */
    public const VIEWS = [
        '/lanes' => ['name' => 'lanes', 'icon' => '≡', 'label' => 'Work axis', 'title' => 'agent lanes of all sessions on one axis of the last 30 min: who works, since when, who waits',
            'template' => 'lanes.php', 'data' => '/feed.json', 'menu' => 'live'],
        '/office' => ['name' => 'office', 'icon' => '▦', 'label' => '3D office', 'title' => 'virtual office: what active agents of all sessions are doing right now',
            'template' => 'office.php', 'data' => '/feed.json', 'menu' => 'live'],
        '/agents' => ['name' => 'agents', 'icon' => '▤', 'label' => 'Agents', 'title' => 'sessions in scope with their agents: model, state, tokens and cost; click = session flow',
            'template' => 'agents.php', 'data' => '/feed.json', 'menu' => 'live'],
        '/orbit' => ['name' => 'orbit', 'icon' => '◎', 'label' => 'Orbit', 'title' => 'equipment orbit: MCP servers, skills and subagents used by agents of all sessions in the last 30 min',
            'template' => 'orbit.php', 'data' => '/feed.json', 'menu' => 'live'],
    ];

    /** Cookie with the last opened overview view; the server sets it on every entry to such a view. */
    public const COOKIE = 'viz-live';

    /** One left panel item per group (instead of the group's first view): group name and description. */
    private const GROUPS = [
        'live' => ['icon' => '◉', 'label' => 'Live preview', 'title' => 'what agents are doing now — opens the view you used last: work axis, 3D office, agents or orbit'],
    ];

    /** Route of the last opened view (from cookies); when missing or unknown — the first view of the `live` group. */
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

    /** Overview views data route (today only `/feed.json`)? */
    public static function isData(string $path): bool
    {
        return in_array($path, array_column(self::VIEWS, 'data'), true);
    }

    /** @return list<array{name: string, icon: string, label: string, title: string, template: string, data: string, menu: string, path: string}> views with a link in the left panel (the first of each group) */
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

    /** @return list<array{name: string, icon: string, label: string, title: string, template: string, data: string, menu: string, path: string}> views of the same group (switch in the header) */
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
