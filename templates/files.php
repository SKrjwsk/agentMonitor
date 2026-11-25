<?php
/**
 * "Read" and "Changed" views: flat file table with server-side sorting (`sort` parameter).
 * Click a row opens the details panel with the calls that touched the file.
 *
 * @var array $view
 */

use Viz\Clock;
use Viz\Model\Touch;
use Viz\Web\App;

$p = $view['p'];
if (!$view['allSessions']) {
    require __DIR__ . '/files-calls.php';

    return;
}
// the same template for one session and for all (aggregates: session count instead of agents and first touch)
$all = $view['allSessions'];
$changed = $p['view'] === 'changed';
$rows = $changed ? $view['changed'] : $view['read'];
$sort = $p['sort'] !== '' ? $p['sort'] : ($changed && !$all ? 'path' : 'count');
$cwd = $all ? null : $view['session']->cwd;
$multi = !$all && count($view['session']->agents) > 1;
// content cost in context — only reads of one session from the transcript (the hook does not know usage)
$costs = !$all && !$changed && $view['session']->usage !== [];
$sessionUsd = $costs ? max($view['sessionTokens']['usd'], 1e-9) : 1.0;
$total = count($rows);
$rows = array_slice($rows, 0, $view['rowLimit']);
$th = static function (string $key, string $label, string $title = '') use ($view, $sort): string {
    $attrs = $title !== '' ? ' title="' . h($title) . '"' : '';

    return '<th' . $attrs . '><a href="/?' . h(App::query($view, ['sort' => $key])) . '"' . ($sort === $key ? ' viz-current' : '') . '>' . h($label) . '</a></th>';
};
?>
<div class="list-bar">
    <?php if ($changed): ?>
        <span class="muted"><?= th('files edited or written · click a row →') ?> <?= $all ? th('sessions where it appears') : th('calls that touched it') ?></span>
    <?php else: ?>
        <?php if (!$all && in_array($p['status'], ['blocked', 'failed'], true)): ?>
            <span class="muted"><?= th('files the agent tried to reach in {status} calls — their content did not reach it', ['status' => t($p['status'])]) ?></span>
        <?php else: ?>
            <span class="muted"><?= th('files whose content reached an agent (read with a tool unless marked) · click a row →') ?> <?= $all ? th('sessions where it appears') : th('calls that touched it') ?></span>
        <?php endif ?>
        <a class="chip" href="/?<?= h(App::query($view, ['more' => $p['more'] ? '' : '1'])) ?>" <?= $p['more'] ? 'viz-current' : '' ?>
           title="<?= th('also files the agent only searched (grep), listed (ls, Glob) or ran — it saw matching lines or metadata, not the content') ?>">+ <?= th('searched / listed / run') ?></a>
    <?php endif ?>
</div>
<?php if ($rows === []): ?>
    <p class="empty"><?= $changed ? th('No file was modified in this scope.') : th('No file was read in this scope.') ?></p>
<?php else: ?>
    <table class="files">
        <thead>
            <tr>
                <?= $th('path', t('file')) ?>
                <?= $changed ? $th('count', t('changes'), t('edits + writes')) : $th('count', t('reads'), t('reads by tools + injections by the harness')) ?>
                <?= $costs ? $th('cost', t('cost'), t('what reading it cost: written to the context once, then read again on every later model call of the agent (estimate, API prices; a command reading several files splits its cost)')) : '' ?>
                <th><?= th('how') ?></th>
                <?= $multi ? $th('agents', t('agents')) : '' ?>
                <?= $all ? $th('sessions', t('sessions')) : $th('first', t('first')) ?>
                <?= $th('last', t('last')) ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $f): ?>
                <?php
                $short = App::shortPath($f['path'], $cwd);
                $slash = strrpos($short, '/');
                // long folders (e.g. session temp directories) shortened in the middle; full path in title and in the panel
                $dir = $slash !== false ? substr($short, 0, $slash + 1) : '';
                $dir = mb_strlen($dir) > 60 ? mb_substr($dir, 0, 14) . '…' . mb_substr($dir, -40) : $dir;
                $tags = [];
                if ($changed) {
                    if (isset($f['ops'][Touch::WRITE])) {
                        $tags[] = ['write', t('write ×{n}', ['n' => $f['ops'][Touch::WRITE]]), t('created or overwritten')];
                    }
                    if (isset($f['ops'][Touch::EDIT])) {
                        $tags[] = ['edit', t('edit ×{n}', ['n' => $f['ops'][Touch::EDIT]]), ''];
                    }
                    if ($f['blindEdit']) {
                        $tags[] = ['warn', t('not read before'), t('edited without reading the file first')];
                    }
                } else {
                    // a tool read is the default case — we mark only injection by the harness
                    if (isset($f['ops'][Touch::CONTEXT])) {
                        $tags[] = ['context', isset($f['ops'][Touch::READ]) ? t('injected ×{n} + read again', ['n' => $f['ops'][Touch::CONTEXT]]) : t('injected'),
                            t('loaded into context by Claude Code (CLAUDE.md, rules, @file)') . (isset($f['ops'][Touch::READ]) ? t('; an agent also read it with a tool ×{n}', ['n' => $f['ops'][Touch::READ]]) : '')];
                    }
                    if ($f['reads'] > 0 && $f['partialOnly']) {
                        $tags[] = ['partial', t('fragment only'), t('only part of the file (offset/limit, head, sed -n…)')];
                    }
                    foreach ([Touch::SEARCH => t('search ×{n}'), Touch::LIST => t('listing ×{n}'), Touch::EXEC => t('run ×{n}')] as $op => $label) {
                        if ($p['more'] && isset($f['ops'][$op])) {
                            $tags[] = [$op, str_replace('{n}', (string) $f['ops'][$op], $label), ''];
                        }
                    }
                    if (isset($f['ops'][Touch::EDIT]) || isset($f['ops'][Touch::WRITE])) {
                        $tags[] = ['edit', t('modified'), ''];
                    }
                }
                ?>
                <tr viz-file="<?= h($f['hash']) ?>" onclick="Viz.Open(this, event)" <?= $p['pick'] === $f['hash'] ? 'viz-picked' : '' ?> <?= $f['exists'] ? '' : 'viz-missing' ?>
                    title="<?= h($f['path']) ?>">
                    <td class="path"><span class="dir"><?= h($dir) ?></span><span class="base"><?= h($slash !== false ? substr($short, $slash + 1) : $short) ?></span></td>
                    <td class="num"><?= h($changed ? $f['mods'] : $f['reads']) ?></td>
                    <?php if ($costs): ?>
                        <td class="num cost" <?= $f['usd'] >= 0.01 * $sessionUsd ? 'viz-high' : '' ?> title="<?= h(t('{pct}% of the session cost', ['pct' => round(100 * $f['usd'] / $sessionUsd, 1)])) ?>"><?= $f['usd'] >= 0.005 ? h(App::usd($f['usd'])) : '' ?></td>
                    <?php endif ?>
                    <td class="tags">
                        <?php foreach ($tags as [$kind, $label, $title]): ?>
                            <span class="tag" viz-tag="<?= h($kind) ?>" <?= $title !== '' ? 'title="' . h($title) . '"' : '' ?>><?= h($label) ?></span>
                        <?php endforeach ?>
                    </td>
                    <?php if ($multi): ?>
                        <td class="agents">
                            <?php foreach (array_keys($changed ? $f['modAgents'] : $f['agents']) as $agentId): ?>
                                <i class="dot" viz-agent-color="<?= h(($view['agentColors'][$agentId] ?? 0) % 8) ?>"
                                   title="<?= h(isset($view['session']->agents[$agentId]) ? App::agentLabel($view['session']->agents[$agentId]) : $agentId) ?>"></i>
                            <?php endforeach ?>
                        </td>
                    <?php endif ?>
                    <?php if ($all): ?>
                        <td class="num muted"><?= h(count($f['sessions'])) ?></td>
                        <td class="time"><?= h(Clock::local($f['last'], 'd.m H:i')) ?></td>
                    <?php else: ?>
                        <td class="time"><?= h(Clock::local($changed ? (string) $f['firstMod'] : $f['first'])) ?></td>
                        <td class="time"><?= h(Clock::local($f['last'])) ?></td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
    <?php if (count($rows) < $total): ?>
        <p class="muted list-more"><?= th('shown {n} of {total} ·', ['n' => count($rows), 'total' => $total]) ?>
            <a href="/?<?= h(App::query($view, ['limit' => 'all'])) ?>"><?= th('show all') ?></a> · <?= th('or narrow with search, period or project') ?></p>
    <?php endif ?>
<?php endif ?>
