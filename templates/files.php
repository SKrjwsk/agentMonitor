<?php
/**
 * Widoki „Read” i „Changed”: płaska tabela plików z sortowaniem po stronie serwera (parametr `sort`).
 * Klik w wiersz otwiera panel szczegółów z wywołaniami, które dotknęły pliku.
 *
 * @var array $view
 */

use Viz\Clock;
use Viz\Model\Touch;
use Viz\Web\App;

$p = $view['p'];
// ten sam szablon dla jednej sesji i dla wszystkich (agregaty: zamiast agentów i pierwszego dotknięcia — liczba sesji)
$all = $view['allSessions'];
$changed = $p['view'] === 'changed';
$rows = $changed ? $view['changed'] : $view['read'];
$sort = $p['sort'] !== '' ? $p['sort'] : ($changed && !$all ? 'path' : 'count');
$cwd = $all ? null : $view['session']->cwd;
$multi = !$all && count($view['session']->agents) > 1;
// koszt treści w kontekście — tylko odczyty jednej sesji z transkryptu (hook nie zna usage)
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
        <span class="muted">files edited or written · click a row → <?= $all ? "sessions where it appears" : "calls that touched it" ?></span>
    <?php else: ?>
        <?php if (!$all && in_array($p['status'], ['blocked', 'failed'], true)): ?>
            <span class="muted">files the agent <strong>tried</strong> to reach in <?= h($p['status']) ?> calls — their content did not reach it</span>
        <?php else: ?>
            <span class="muted">files whose content reached an agent (read with a tool unless marked) · click a row → <?= $all ? "sessions where it appears" : "calls that touched it" ?></span>
        <?php endif ?>
        <a class="chip" href="/?<?= h(App::query($view, ['more' => $p['more'] ? '' : '1'])) ?>" <?= $p['more'] ? 'viz-current' : '' ?>
           title="also files the agent only searched (grep), listed (ls, Glob) or ran — it saw matching lines or metadata, not the content">+ searched / listed / run</a>
    <?php endif ?>
</div>
<?php if ($rows === []): ?>
    <p class="empty"><?= $changed ? 'No file was modified in this scope.' : 'No file was read in this scope.' ?></p>
<?php else: ?>
    <table class="files">
        <thead>
            <tr>
                <?= $th('path', 'file') ?>
                <?= $changed ? $th('count', 'changes', 'edits + writes') : $th('count', 'reads', 'reads by tools + injections by the harness') ?>
                <?= $costs ? $th('cost', 'cost', 'what reading it cost: written to the context once, then read again on every later model call of the agent (estimate, API prices; a command reading several files splits its cost)') : '' ?>
                <th>how</th>
                <?= $multi ? $th('agents', 'agents') : '' ?>
                <?= $all ? $th('sessions', 'sessions') : $th('first', 'first') ?>
                <?= $th('last', 'last') ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $f): ?>
                <?php
                $short = App::shortPath($f['path'], $cwd);
                $slash = strrpos($short, '/');
                // długie foldery (np. katalogi tymczasowe sesji) skracane w środku; pełna ścieżka w title i w panelu
                $dir = $slash !== false ? substr($short, 0, $slash + 1) : '';
                $dir = mb_strlen($dir) > 60 ? mb_substr($dir, 0, 14) . '…' . mb_substr($dir, -40) : $dir;
                $tags = [];
                if ($changed) {
                    if (isset($f['ops'][Touch::WRITE])) {
                        $tags[] = ['write', 'write ×' . $f['ops'][Touch::WRITE], 'created or overwritten'];
                    }
                    if (isset($f['ops'][Touch::EDIT])) {
                        $tags[] = ['edit', 'edit ×' . $f['ops'][Touch::EDIT], ''];
                    }
                    if ($f['blindEdit']) {
                        $tags[] = ['warn', 'not read before', 'edited without reading the file first'];
                    }
                } else {
                    // odczyt narzędziem to przypadek domyślny — oznaczamy tylko wstrzyknięcie przez harness
                    if (isset($f['ops'][Touch::CONTEXT])) {
                        $tags[] = ['context', isset($f['ops'][Touch::READ]) ? 'injected ×' . $f['ops'][Touch::CONTEXT] . ' + read again' : 'injected',
                            'loaded into context by Claude Code (CLAUDE.md, rules, @file)' . (isset($f['ops'][Touch::READ]) ? '; an agent also read it with a tool ×' . $f['ops'][Touch::READ] : '')];
                    }
                    if ($f['reads'] > 0 && $f['partialOnly']) {
                        $tags[] = ['partial', 'fragment only', 'only part of the file (offset/limit, head, sed -n…)'];
                    }
                    foreach ([Touch::SEARCH => 'search', Touch::LIST => 'listing', Touch::EXEC => 'run'] as $op => $label) {
                        if ($p['more'] && isset($f['ops'][$op])) {
                            $tags[] = [$op, $label . ' ×' . $f['ops'][$op], ''];
                        }
                    }
                    if (isset($f['ops'][Touch::EDIT]) || isset($f['ops'][Touch::WRITE])) {
                        $tags[] = ['edit', 'modified', ''];
                    }
                }
                ?>
                <tr viz-file="<?= h($f['hash']) ?>" onclick="Viz.Open(this, event)" <?= $p['pick'] === $f['hash'] ? 'viz-picked' : '' ?> <?= $f['exists'] ? '' : 'viz-missing' ?>
                    title="<?= h($f['path']) ?>">
                    <td class="path"><span class="dir"><?= h($dir) ?></span><span class="base"><?= h($slash !== false ? substr($short, $slash + 1) : $short) ?></span></td>
                    <td class="num"><?= h($changed ? $f['mods'] : $f['reads']) ?></td>
                    <?php if ($costs): ?>
                        <td class="num cost" <?= $f['usd'] >= 0.01 * $sessionUsd ? 'viz-high' : '' ?> title="<?= h(round(100 * $f['usd'] / $sessionUsd, 1) . '% of the session cost') ?>"><?= $f['usd'] >= 0.005 ? h(App::usd($f['usd'])) : '' ?></td>
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
        <p class="muted list-more">shown <?= h(count($rows)) ?> of <?= h($total) ?> ·
            <a href="/?<?= h(App::query($view, ['limit' => 'all'])) ?>">show all</a> · or narrow with search, period or project</p>
    <?php endif ?>
<?php endif ?>
