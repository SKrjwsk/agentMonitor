<?php
/**
 * @var list<array{id: string, path: string, mtime: int, title: string, subtitle: string, empty: bool, cwd: string, hook: bool}> $sessions
 * @var ?array $view
 * @var array<string, array{usd: ?float, output: int, partial: bool, upTo: ?string}> $sidebarTokens
 * @var ?array $overview strona startowa, gdy nie wybrano sesji
 * @var ?array $scopeView widok przeglądowy (Viz\Web\ScopeViews: /lanes, /office, /agents) — wszystkie sesje w zakresie projektów
 * @var Viz\Web\Scope $scope zakres projektów (wybór w lewym panelu, ciasteczko viz-scope)
 */

use Viz\Clock;
use Viz\Web\App;
use Viz\Web\Projects;
use Viz\Web\ScopeViews;

$currentId = $view['sid'] ?? '';
$scopeView ??= null;
$projects = App::sidebarProjects($sessions);
// znacznik oceny sędziego przy sesji (zakładka „Ocena”)
$judged = Viz\Judge\SessionJudge::badges(array_column($sessions, 'id'), time());
$verdictLabels = ['good' => 'good', 'mixed' => 'mixed', 'poor' => 'poor', 'inconclusive' => 'inconclusive', 'error' => 'error'];
?>
<!doctype html>
<html lang="<?= h(Viz\Web\Lang::current()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>agentMonitor</title>
    <link rel="icon" href="data:,">
    <script>try { if (localStorage.getItem('viz-theme') === 'light') document.documentElement.dataset.theme = 'light'; } catch (e) {}</script>
    <?php /* szerokość lewego panelu (Viz.SideDown…) przed pierwszym rysowaniem, żeby układ nie skakał */ ?>
    <script>try { const w = localStorage.getItem('viz-side-w'); if (w !== null && w !== '') document.documentElement.style.setProperty('--side-w', (+w) + 'px'); } catch (e) {}</script>
    <link rel="stylesheet" href="/assets/app.css">
    <?php /* słownik dla JS (Viz.T): blok danych, nie skrypt; JSON_HEX_* chroni przed </script> i &; pusty obiekt dla EN */ ?>
    <script type="application/json" id="viz-i18n"><?= json_encode((object) Viz\Web\Lang::dict(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
    <script src="/assets/toolkit/Util.js"></script>
    <script src="/assets/toolkit/Hx.js"></script>
    <script src="/assets/toolkit/SInit.js"></script>
    <script src="/assets/toolkit/Filter.js"></script>
    <script src="/assets/app.js"></script>
    <script src="/assets/charts.js"></script>
    <script src="/assets/feed.js"></script>
    <script src="/assets/views.js"></script>
    <script src="/assets/flow.js"></script>
    <script src="/assets/agents.js"></script>
    <script src="/assets/lanes.js"></script>
</head>
<body>
<span class="refresh-error" viz-refresh-error hidden><?= th('refresh not working') ?></span>
<header class="topbar">
    <button type="button" class="theme-toggle" onclick="Viz.Lang()" title="<?= th('language') ?>"><?= h(Viz\Web\Lang::LANGS[Viz\Web\Lang::current() === 'pl' ? 'en' : 'pl']) ?></button>
    <button type="button" class="theme-toggle" onclick="Viz.Theme()" title="<?= th('light / dark theme') ?>">◐</button>
</header>
<div class="app">
    <?php /* uchwyt szerokości lewego panelu: przeciągnij; do lewej krawędzi = schowany; dwuklik = domyślna szerokość */ ?>
    <div class="side-resize" viz-side-resize title="<?= th('drag to resize the panel · drag to the edge = hide · double-click = default width') ?>"
         onpointerdown="Viz.SideDown(this, event)" onpointermove="Viz.SideMove(this, event)" onpointerup="Viz.SideUp(this)" onpointercancel="Viz.SideUp(this)" ondblclick="Viz.SideReset()"></div>
    <aside class="sessions">
        <?php /* zakres „wszystkie sesje” to ten sam widok co sesja (s=all), z danymi z archiwum agregatów */ ?>
        <a class="summary-link" href="/?overview=1" <?= $overview !== null ? 'viz-current' : '' ?>
           title="<?= th('what is fine and what needs attention: cost, security, agents') ?>">◎ <?= th('Overview') ?></a>
        <?php foreach (ScopeViews::menu($_COOKIE[ScopeViews::COOKIE] ?? null) as $menuView): ?>
            <a class="summary-link" href="<?= h($menuView['path']) ?>" <?= $scopeView !== null && $scopeView['menu'] === $menuView['menu'] ? 'viz-current' : '' ?>
               title="<?= h(t($menuView['title'])) ?>"><?= h($menuView['icon']) ?> <?= h(t($menuView['label'])) ?></a>
        <?php endforeach ?>
        <a class="summary-link" href="/?s=all" <?= $currentId === App::ALL ? 'viz-current' : '' ?>
           title="<?= th('files, commands, blocked calls, approvals and log across all sessions — the same tabs as a single session') ?>">Σ <?= th('All sessions') ?></a>
        <?php /* zakres projektów (Viz\Web\Scope): wspólny dla Przeglądu, „Wszystkich sesji”, widoków przeglądowych i listy sesji; Viz.Scope zapisuje ciasteczko viz-scope */ ?>
        <details class="sessions-project scope-pick" viz-scope>
            <summary title="<?= th('projects counted in the overview, all sessions, the live preview and the session list (working directory of the session)') ?>">
                <?= th('Projects') ?>: <b viz-scope-label><?= h(Projects::scopeLabel($scope, $projects)) ?></b>
            </summary>
            <label class="scope-all"><input type="checkbox" value="" onchange="Viz.Scope(this)" <?= $scope->all() ? 'checked' : '' ?>> <?= th('all projects ({n})', ['n' => count($sessions)]) ?></label>
            <?php foreach ($projects as $key => $pr): ?>
                <label title="<?= h(App::shortPath($pr['cwd'], null)) ?>"><input type="checkbox" value="<?= h($key) ?>" viz-scope-name="<?= h($pr['label']) ?>" onchange="Viz.Scope(this)" <?= $scope->has($key) ? 'checked' : '' ?>> <?= h($pr['label'] . ' · ' . $pr['sessions']) ?></label>
            <?php endforeach ?>
        </details>
        <?php
        // lista: nazwa + godzina, nagłówki dni, szczegóły w dymku; puste sesje i sesje techniczne (var/ tej aplikacji) w zwiniętej grupie
        $isNoise = static fn (array $s): bool => $s['empty'] || str_contains($s['cwd'], '/REPO_agentMonitor/var/');
        $today = Clock::local(date('c'), 'Y-m-d');
        $yesterday = Clock::local(date('c', time() - 86400), 'Y-m-d');
        $dayOf = static fn (int $mtime): string => ($d = Clock::local(date('c', $mtime), 'Y-m-d')) === $today ? t('today') : ($d === $yesterday ? t('yesterday') : Clock::local(date('c', $mtime), 'd.m'));
        $sessionRow = static function (array $s) use ($currentId, $sidebarTokens, $judged, $verdictLabels): void {
            $st = $sidebarTokens[$s['id']] ?? null;
            $jb = $judged[$s['id']] ?? null;
            $name = $s['title'] !== '' ? $s['title'] : substr($s['id'], 0, 8);
            $tip = implode("\n", array_filter([
                $s['empty'] ? t('no conversation — only commands like /model or /exit') : $name,
                $s['subtitle'],
                basename($s['cwd']) . ' · ' . Clock::local(date('c', $s['mtime']), 'd.m.Y H:i'),
                $st !== null && $st['usd'] !== null ? App::usd($st['usd']) : '',
                $jb !== null && $jb['verdict'] !== null ? t('assessment: {v} ({t})', ['v' => t($verdictLabels[$jb['verdict']] ?? 'error'), 't' => Clock::local(date('c', $jb['started']), 'd.m H:i')]) : '',
            ]));
            ?>
            <li viz-project="<?= h(App::fileHash($s['cwd'])) ?>" <?= $s['id'] === $currentId ? 'viz-current' : '' ?> <?= $s['empty'] ? 'viz-empty' : '' ?>>
                <a href="/?s=<?= h($s['id']) ?>" onclick="Viz.SessionLink(this)" title="<?= h($tip) ?>">
                    <i class="s-dot" <?= time() - $s['mtime'] < 120 ? 'viz-live title="' . th('active') . '"' : '' ?>></i>
                    <span class="s-title"><?= $s['empty'] ? '<span class="muted">' . th('no conversation') . '</span> ' : '' ?><?= h($name) ?></span>
                    <?php if ($jb !== null): ?>
                        <span class="s-judged" data-verdict="<?= h($jb['running'] ? 'running' : $jb['verdict']) ?>"
                              onclick="event.preventDefault(); event.stopPropagation(); location.href = '/?s=<?= h(rawurlencode($s['id'])) ?>&view=judge'">⚖</span>
                    <?php endif ?>
                    <time class="s-time"><?= h(Clock::local(date('c', $s['mtime']), 'H:i')) ?></time>
                </a>
            </li>
            <?php
        };
        $main = array_filter($sessions, static fn (array $s): bool => !$isNoise($s));
        $noise = array_filter($sessions, $isNoise);
        ?>
        <ol>
            <?php $lastDay = null ?>
            <?php foreach ($main as $s): ?>
                <?php if (($day = $dayOf($s['mtime'])) !== $lastDay): ?>
                    <li class="s-day"><?= h($lastDay = $day) ?></li>
                <?php endif ?>
                <?php $sessionRow($s) ?>
            <?php endforeach ?>
        </ol>
        <?php if ($noise !== []): ?>
            <details class="s-noise">
                <summary><?= th('other') ?> (<?= count($noise) ?>)</summary>
                <ol><?php foreach ($noise as $s) { $sessionRow($s); } ?></ol>
            </details>
        <?php endif ?>
        <s-init run="(el) => Viz.ScopeInit(el)"></s-init>
    </aside>
    <main>
        <?php if ($scopeView !== null): ?>
            <?php require __DIR__ . '/' . $scopeView['template'] ?>
        <?php elseif ($view === null): ?>
            <?php require __DIR__ . '/overview.php' ?>
        <?php else: ?>
            <?php require __DIR__ . '/session.php' ?>
        <?php endif ?>
    </main>
</div>
<footer class="footbar">agentMonitor</footer>
</body>
</html>
