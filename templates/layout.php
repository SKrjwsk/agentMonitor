<?php
/**
 * @var list<array{id: string, path: string, mtime: int, title: string, subtitle: string, empty: bool, cwd: string, hook: bool}> $sessions
 * @var ?array $view
 * @var array<string, array{usd: ?float, output: int, partial: bool, upTo: ?string}> $sidebarTokens
 * @var ?array $overview strona startowa, gdy nie wybrano sesji
 * @var ?array $scopeView widok przeglądowy (Viz\Web\ScopeViews: /live, /office) — wszystkie sesje w zakresie projektów
 * @var Viz\Web\Scope $scope zakres projektów (wybór w lewym panelu, ciasteczko viz-scope)
 */

use Viz\Clock;
use Viz\Web\App;
use Viz\Web\Projects;
use Viz\Web\ScopeViews;

$currentId = $view['sid'] ?? '';
$scopeView ??= null;
$follow = $view['p']['follow'] ?? false;
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
    <script src="/assets/vendor/force-graph-1.51.4.min.js"></script>
    <script src="/assets/graph.js"></script>
    <script src="/assets/timeline.js"></script>
    <script src="/assets/feed.js"></script>
    <script src="/assets/live.js"></script>
    <script src="/assets/views.js"></script>
    <script src="/assets/flow.js"></script>
    <script src="/assets/agents.js"></script>
    <script src="/assets/lanes.js"></script>
    <script src="/assets/boundary-graph.js"></script>
    <script src="/assets/boundary-flow.js"></script>
</head>
<body>
<span class="refresh-error" viz-refresh-error hidden><?= th('refresh not working') ?></span>
<div class="app">
    <?php /* uchwyt szerokości lewego panelu: przeciągnij; do lewej krawędzi = schowany; dwuklik = domyślna szerokość */ ?>
    <div class="side-resize" viz-side-resize title="<?= th('drag to resize the panel · drag to the edge = hide · double-click = default width') ?>"
         onpointerdown="Viz.SideDown(this, event)" onpointermove="Viz.SideMove(this, event)" onpointerup="Viz.SideUp(this)" onpointercancel="Viz.SideUp(this)" ondblclick="Viz.SideReset()"></div>
    <aside class="sessions">
        <?php /* zakres „wszystkie sesje” to ten sam widok co sesja (s=all), z danymi z archiwum agregatów */ ?>
        <a class="summary-link" href="/" <?= $overview !== null ? 'viz-current' : '' ?>
           title="<?= th('what is fine and what needs attention: cost, security, agents') ?>">◎ <?= th('Overview') ?></a>
        <?php foreach (ScopeViews::menu() as $menuView): ?>
            <a class="summary-link" href="<?= h($menuView['path']) ?>" <?= $scopeView !== null && $scopeView['menu'] === $menuView['menu'] ? 'viz-current' : '' ?>
               title="<?= h(t($menuView['title'])) ?>"><?= h($menuView['icon']) ?> <?= h(t($menuView['label'])) ?></a>
        <?php endforeach ?>
        <a class="summary-link" href="/?s=all" <?= $currentId === App::ALL ? 'viz-current' : '' ?>
           title="<?= th('files, commands and blocked calls across all sessions — the same tabs as a single session') ?>">Σ <?= th('All sessions') ?></a>
        <div class="sessions-head">
            <strong><?= th('Sessions') ?></strong>
            <a href="/?follow=1" <?= $follow ? 'viz-current' : '' ?>>● <?= th('follow latest') ?></a>
            <button type="button" class="theme-toggle" onclick="Viz.Lang()" title="<?= th('language') ?>"><?= h(Viz\Web\Lang::LANGS[Viz\Web\Lang::current() === 'pl' ? 'en' : 'pl']) ?></button>
            <button type="button" class="theme-toggle" onclick="Viz.Theme()" title="<?= th('light / dark theme') ?>">◐</button>
        </div>
        <?php /* zakres projektów (Viz\Web\Scope): wspólny dla widoków przeglądowych i listy sesji; Viz.Scope zapisuje ciasteczko viz-scope */ ?>
        <details class="sessions-project scope-pick" viz-scope>
            <summary title="<?= th('projects shown in Live, the 3D office and the session list (working directory of the session)') ?>">
                <?= th('Projects') ?>: <b viz-scope-label><?= h(Projects::scopeLabel($scope, $projects)) ?></b>
            </summary>
            <label class="scope-all"><input type="checkbox" value="" onchange="Viz.Scope(this)" <?= $scope->all() ? 'checked' : '' ?>> <?= th('all projects ({n})', ['n' => count($sessions)]) ?></label>
            <?php foreach ($projects as $key => $pr): ?>
                <label title="<?= h(App::shortPath($pr['cwd'], null)) ?>"><input type="checkbox" value="<?= h($key) ?>" viz-scope-name="<?= h($pr['label']) ?>" onchange="Viz.Scope(this)" <?= $scope->has($key) ? 'checked' : '' ?>> <?= h($pr['label'] . ' · ' . $pr['sessions']) ?></label>
            <?php endforeach ?>
        </details>
        <ol>
            <?php foreach ($sessions as $s): ?>
                <li viz-project="<?= h(App::fileHash($s['cwd'])) ?>" <?= !$follow && $s['id'] === $currentId ? 'viz-current' : '' ?> <?= $s['empty'] ? 'viz-empty' : '' ?>>
                    <a href="/?s=<?= h($s['id']) ?>" onclick="Viz.SessionLink(this)" <?= $s['empty'] ? 'title="' . th('no conversation — only commands like /model or /exit') . '"' : '' ?>>
                        <span class="s-title"><?= $s['empty'] ? '<span class="muted">' . th('no conversation') . '</span> ' : '' ?><?= h($s['title'] !== '' ? $s['title'] : substr($s['id'], 0, 8)) ?></span>
                        <?php if ($s['subtitle'] !== ''): ?><span class="s-sub muted"><?= h($s['subtitle']) ?></span><?php endif ?>
                        <span class="s-meta">
                            <?= h(basename($s['cwd'])) ?> ·
                            <?= h(Clock::local(date('c', $s['mtime']), 'd.m H:i')) ?>
                            <?php if (isset($sidebarTokens[$s['id']])): ?>
                                <?php $st = $sidebarTokens[$s['id']] ?>
                                · <?php if ($st['usd'] !== null): ?>
                                    <span class="s-tok" title="<?= th('cost of all agents at API prices') ?><?= $st['upTo'] !== null ? h(' · ' . t('counted up to {time}', ['time' => Clock::local($st['upTo'], 'd.m H:i')])) : '' ?>"><?= h(App::usd($st['usd'])) ?></span>
                                <?php else: ?>
                                    <span class="s-tok" title="<?= th('output tokens of all agents — no cost in this older summary') ?><?= $st['upTo'] !== null ? h(' · ' . t('counted up to {time}', ['time' => Clock::local($st['upTo'], 'd.m H:i')])) : '' ?>"><?= h(($st['partial'] ? '≥' : '') . t('{n} out', ['n' => App::tokenCount($st['output'])])) ?></span>
                                <?php endif ?>
                            <?php endif ?>
                            <?php if (time() - $s['mtime'] < 120): ?><span class="badge live"><?= th('active') ?></span><?php endif ?>
                            <?php if ($s['hook']): ?><span class="badge">hook</span><?php endif ?>
                            <?php if (isset($judged[$s['id']])): ?>
                                <?php $jb = $judged[$s['id']] ?>
                                <span class="badge judged" data-verdict="<?= h($jb['running'] ? 'running' : $jb['verdict']) ?>"
                                      title="<?= $jb['verdict'] !== null ? th('assessment: {v} ({t})', ['v' => t($verdictLabels[$jb['verdict']] ?? 'error'), 't' => Clock::local(date('c', $jb['started']), 'd.m H:i')]) . ' — ' : '' ?><?= th('click = Assessment tab') ?>"
                                      onclick="event.preventDefault(); event.stopPropagation(); location.href = '/?s=<?= h(rawurlencode($s['id'])) ?>&view=judge'">⚖ <?= $jb['running'] ? th('assessing…') : th('assessed') ?></span>
                            <?php endif ?>
                        </span>
                    </a>
                </li>
            <?php endforeach ?>
        </ol>
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
</body>
</html>
