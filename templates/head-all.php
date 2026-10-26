<?php
/**
 * Nagłówek zakresu „wszystkie sesje”: tytuł, CSV, live (okna 1 h / 5 min) + rząd filtrów (okres, projekt, szukanie, wykluczenia).
 * Szukanie i wykluczenia to formularz GET na `/` z ukrytymi polami bieżącego widoku (Enter).
 *
 * @var array $view @var array $p
 */

use Viz\Web\App;

$live = $p['recent'] !== '';
?>
<div class="title-row">
    <h1><?= th('All sessions') ?><?= $live ? ' — ' . th($p['recent'] === '5m' ? 'last 5 minutes' : 'last hour') : '' ?></h1>
    <span class="meta" title="<?= th('archive: {a} sessions, {t} still with a transcript (the rest deleted by Claude Code, only counters remain)', ['a' => $view['archived'], 't' => $view['withTranscript']]) ?><?= $view['refreshed'] > 0 ? ' · ' . th('recomputed now: {n}', ['n' => $view['refreshed']]) : '' ?>">
        <?= th('{n} sessions in scope', ['n' => count($view['sessionsById'])]) ?>
    </span>
    <a class="btn" href="/export.csv?<?= h(App::query($view, ['pick' => '', 'pcmd' => ''])) ?>" title="<?= th('the whole filtered list of this tab (including rows not shown)') ?>">⇩ CSV</a>
    <?php if ($live): ?>
        <label class="live-toggle"><input type="checkbox" <?= $view['live'] ? 'checked' : '' ?> onchange="Viz.LiveToggle(this)"> <?= th('live') ?></label>
    <?php endif ?>
</div>

<?php require __DIR__ . '/tabs.php' ?>

<form method="get" action="/" class="filters">
    <?php foreach (['s' => App::ALL, 'view' => $p['view'] === 'read' ? '' : $p['view'], 'days' => $p['days'] > 0 ? (string) $p['days'] : '', 'recent' => $p['recent'], 'project' => $p['project'], 'scope' => $p['scope'], 'sort' => $p['sort'], 'more' => $p['more'] ? '1' : '', 'limit' => $p['limit'] ? 'all' : ''] as $name => $value): ?>
        <?php if ($value !== ''): ?><input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>"><?php endif ?>
    <?php endforeach ?>
    <select onchange="Viz.Go(this)" title="<?= th('period · last hour and last 5 minutes are computed from events of active sessions and refresh live') ?>">
        <?php foreach (['' => 'all time', '30' => 'last 30 days', '14' => 'last 14 days', '7' => 'last 7 days', '1h' => 'last hour (live)', '5m' => 'last 5 minutes (live)'] as $period => $label): ?>
            <?php $period = (string) $period; // PHP zamienia klucze '30', '7' na int ?>
            <?php $isWindow = in_array($period, ['1h', '5m'], true) ?>
            <option value="/?<?= h(App::query($view, ['days' => $isWindow ? '' : $period, 'recent' => $isWindow ? $period : '', 'pick' => '', 'pcmd' => ''])) ?>"
                <?= ($isWindow ? $p['recent'] === $period : !$live && ($p['days'] > 0 ? (string) $p['days'] : '') === $period) ? 'selected' : '' ?>><?= th($label) ?></option>
        <?php endforeach ?>
    </select>
    <select onchange="Viz.Go(this)" title="<?= th('project = working directory of the session') ?>">
        <option value="/?<?= h(App::query($view, ['project' => '', 'scope' => '', 'pick' => '', 'pcmd' => ''])) ?>"><?= th('all projects ({n})', ['n' => count($view['projects'])]) ?></option>
        <?php foreach ($view['projects'] as $key => $pr): ?>
            <option value="/?<?= h(App::query($view, ['project' => (string) $key, 'pick' => '', 'pcmd' => ''])) ?>" <?= $p['project'] === (string) $key ? 'selected' : '' ?>><?= h((basename($pr['cwd']) ?: '?') . ' · ' . $pr['sessions'] . ' · ' . App::shortPath($pr['cwd'], null)) ?></option>
        <?php endforeach ?>
    </select>
    <?php if ($p['project'] !== '' && in_array($p['view'], ['read', 'changed'], true)): ?>
        <a class="chip" href="/?<?= h(App::query($view, ['scope' => $p['scope'] === 'project' ? '' : 'project'])) ?>" <?= $p['scope'] === 'project' ? 'viz-current' : '' ?>
           title="<?= th('skip files outside the project directory') ?>"><?= th('project only') ?></a>
    <?php endif ?>
    <input class="rank-search" type="search" name="find" value="<?= h($p['find']) ?>"
           placeholder="<?= th(match ($p['view']) { 'commands' => 'search program, subcommand or role… (Enter)', 'blocked' => 'search command, rule or mechanism… (Enter)', default => 'search paths… (Enter)' }) ?>">
    <details class="exclude" <?= $p['exclude'] !== '' ? 'open' : '' ?>>
        <summary title="<?= th('hide entries: starting with / or ~ = path prefix, anything else = fragment') ?>"><?= th('exclude') ?><?= $p['exclude'] !== '' ? ' (' . h(count(array_filter(array_map('trim', explode(',', $p['exclude']))))) . ')' : '' ?></summary>
        <input class="rank-search" type="search" name="exclude" value="<?= h($p['exclude']) ?>"
               placeholder="<?= h(match ($p['view']) { 'commands' => 'cd, echo, shell', 'blocked' => t('user, auto mode, deny rule'), default => '/private, ~/projects/archive, scratchpad' }) ?>  (<?= th('comma-separated, Enter') ?>)">
    </details>
    <?php if ($p['find'] !== '' || $p['exclude'] !== ''): ?><a class="chip" href="/?<?= h(App::query($view, ['find' => '', 'exclude' => ''])) ?>"><?= th('clear search') ?></a><?php endif ?>
</form>
