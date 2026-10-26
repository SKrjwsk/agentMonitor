<?php
/**
 * @var list<array{id: string, path: string, mtime: int, title: string, subtitle: string, empty: bool, cwd: string, hook: bool}> $sessions
 * @var ?array $view
 * @var array<string, array{usd: ?float, output: int, partial: bool, upTo: ?string}> $sidebarTokens
 * @var ?array $overview strona startowa, gdy nie wybrano sesji
 * @var ?bool $office wirtualne biuro (wszystkie aktywne sesje), trasa /office
 */

use Viz\Clock;
use Viz\Web\App;

$currentId = $view['sid'] ?? '';
$office ??= false;
$follow = $view['p']['follow'] ?? false;
$projects = App::sidebarProjects($sessions);
?>
<!doctype html>
<html lang="<?= h(Viz\Web\Lang::current()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>agentMonitor</title>
    <link rel="icon" href="data:,">
    <script>try { if (localStorage.getItem('viz-theme') === 'light') document.documentElement.dataset.theme = 'light'; } catch (e) {}</script>
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
</head>
<body>
<span class="refresh-error" viz-refresh-error hidden><?= th('refresh not working') ?></span>
<div class="app">
    <aside class="sessions">
        <?php /* zakres „wszystkie sesje” to ten sam widok co sesja (s=all), z danymi z archiwum agregatów */ ?>
        <a class="summary-link" href="/" <?= $overview !== null ? 'viz-current' : '' ?>
           title="<?= th('what is fine and what needs attention: cost, security, agents') ?>">◎ <?= th('Overview') ?></a>
        <a class="summary-link" href="/office" <?= $office ? 'viz-current' : '' ?>
           title="<?= th('virtual office: what active agents of all sessions are doing right now') ?>">▦ <?= th('Office') ?></a>
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
                        </span>
                    </a>
                </li>
            <?php endforeach ?>
        </ol>
        <s-init run="(el) => Viz.ProjectInit(el)"></s-init>
    </aside>
    <main>
        <?php if ($office): ?>
            <?php require __DIR__ . '/office.php' ?>
        <?php elseif ($view === null): ?>
            <?php require __DIR__ . '/overview.php' ?>
        <?php else: ?>
            <?php require __DIR__ . '/session.php' ?>
        <?php endif ?>
    </main>
</div>
</body>
</html>
