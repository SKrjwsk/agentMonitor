<?php
/**
 * @var list<array{id: string, path: string, mtime: int, title: string, subtitle: string, empty: bool, cwd: string, hook: bool}> $sessions
 * @var ?array $view
 */

use Viz\Clock;
use Viz\Web\App;

$currentId = $view['sid'] ?? '';
$follow = $view['p']['follow'] ?? false;
$projects = App::sidebarProjects($sessions);
?>
<!doctype html>
<html lang="<?= h(Viz\Web\Lang::current()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agent viz</title>
    <link rel="icon" href="data:,">
    <script>try { if (localStorage.getItem('viz-theme') === 'light') document.documentElement.dataset.theme = 'light'; } catch (e) {}</script>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/toolkit/Util.js"></script>
    <script src="/assets/toolkit/Hx.js"></script>
    <script src="/assets/toolkit/SInit.js"></script>
    <script src="/assets/toolkit/Filter.js"></script>
    <script src="/assets/app.js"></script>
    <script src="/assets/vendor/force-graph-1.51.4.min.js"></script>
    <script src="/assets/graph.js"></script>
    <script src="/assets/timeline.js"></script>
</head>
<body>
<div class="app">
    <aside class="sessions">
        <?php /* zakres „wszystkie sesje” to ten sam widok co sesja (s=all), z danymi z archiwum agregatów */ ?>
        <a class="summary-link" href="/?s=all" <?= $currentId === App::ALL ? 'viz-current' : '' ?>
           title="<?= th('files, commands and blocked calls across all sessions — the same tabs as a single session') ?>">Σ <?= th('All sessions') ?></a>
        <div class="sessions-head">
            <strong><?= th('Sessions') ?></strong>
            <a href="/?follow=1" <?= $follow ? 'viz-current' : '' ?>>● <?= th('follow latest') ?></a>
            <button type="button" class="theme-toggle" onclick="Viz.Lang()" title="<?= th('language') ?>"><?= h(Viz\Web\Lang::LANGS[Viz\Web\Lang::current() === 'pl' ? 'en' : 'pl']) ?></button>
            <button type="button" class="theme-toggle" onclick="Viz.Theme()" title="<?= th('light / dark theme') ?>">◐</button>
        </div>
        <?php /* wybór projektu filtruje listę w przeglądarce (Viz.Project), linki sesji zostają bez zmian */ ?>
        <div class="sessions-project">
            <select onchange="Viz.Project(this)" title="<?= th('show sessions of one project (working directory of the session)') ?>">
                <option value=""><?= th('all projects ({n})', ['n' => count($sessions)]) ?></option>
                <?php foreach ($projects as $key => $pr): ?>
                    <option value="<?= h($key) ?>" title="<?= h(App::shortPath($pr['cwd'], null)) ?>"><?= h($pr['label'] . ' · ' . $pr['sessions']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <ol>
            <?php foreach ($sessions as $s): ?>
                <li viz-project="<?= h(App::fileHash($s['cwd'])) ?>" <?= !$follow && $s['id'] === $currentId ? 'viz-current' : '' ?> <?= $s['empty'] ? 'viz-empty' : '' ?>>
                    <a href="/?s=<?= h($s['id']) ?>" <?= $s['empty'] ? 'title="' . th('no conversation — only commands like /model or /exit') . '"' : '' ?>>
                        <span class="s-title"><?= $s['empty'] ? '<span class="muted">' . th('no conversation') . '</span> ' : '' ?><?= h($s['title'] !== '' ? $s['title'] : substr($s['id'], 0, 8)) ?></span>
                        <?php if ($s['subtitle'] !== ''): ?><span class="s-sub muted"><?= h($s['subtitle']) ?></span><?php endif ?>
                        <span class="s-meta">
                            <?= h(basename($s['cwd'])) ?> ·
                            <?= h(Clock::local(date('c', $s['mtime']), 'd.m H:i')) ?>
                            <?php if (time() - $s['mtime'] < 120): ?><span class="badge live"><?= th('active') ?></span><?php endif ?>
                            <?php if ($s['hook']): ?><span class="badge">hook</span><?php endif ?>
                        </span>
                    </a>
                </li>
            <?php endforeach ?>
        </ol>
        <s-init run="(el) => Viz.ProjectInit(el)"></s-init>
    </aside>
    <main>
        <?php if ($view === null): ?>
            <p class="empty"><?= th('Pick a session from the list or turn on “follow latest”.') ?></p>
        <?php else: ?>
            <?php require __DIR__ . '/session.php' ?>
        <?php endif ?>
    </main>
</div>
</body>
</html>
