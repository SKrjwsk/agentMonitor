<?php
/**
 * Panel szczegółów po prawej — tylko gdy jest zaznaczony plik (`pick`) albo wywołanie (`pev`); Esc albo × zamyka.
 * Plik: wywołania z bieżącego zakresu filtrów, które go dotknęły. Wywołanie: szukane w całej sesji.
 * Każda sekcja ma zdanie objaśnienia — panel ma mówić, co pokazuje, a nie tylko podawać dane.
 *
 * @var array $view
 * @var array<string, string> $opLabels
 */

use Viz\Clock;
use Viz\Model\Event;
use Viz\Model\Touch;
use Viz\Web\App;

$p = $view['p'];
$session = $view['session'];
$cwd = $session->cwd;
$agentLabel = static fn (string $id): string => isset($session->agents[$id]) ? App::agentLabel($session->agents[$id]) : $id;
$dot = static fn (string $id): string => '<i class="dot" viz-agent-color="' . h(($view['agentColors'][$id] ?? 0) % 8) . '"></i>';
$seconds = static function (string $from, ?string $to): ?float {
    if ($to === null) {
        return null;
    }
    try {
        return (float) (new DateTimeImmutable($to))->format('U.u') - (float) (new DateTimeImmutable($from))->format('U.u');
    } catch (Exception) {
        return null;
    }
};
?>
<aside class="drawer" viz-pane="drawer">
    <button type="button" class="drawer-close" onclick="Viz.Close(this)" title="close (Esc)">×</button>
    <?php if ($p['pev'] !== ''): ?>
        <?php
        $e = null;
        foreach ($session->events as $candidate) {
            if ($candidate->toolUseId === $p['pev']) {
                $e = $candidate;
                break;
            }
        }
        ?>
        <?php if ($e === null): ?>
            <p class="empty">This call is not in the session.</p>
        <?php else: /** @var Event $e */ ?>
            <?php
            $context = $e->tool === Event::TOOL_CONTEXT;
            $took = $seconds($e->ts, $e->endTs);
            [$statusLabel, $statusHelp] = match (true) {
                $e->denial !== null => ['⊘ blocked by ' . $e->denial['by'] . ($e->denial['detail'] !== null ? ' · ' . $e->denial['detail'] : ''),
                    'Claude Code did not run it, so nothing from it reached the agent and its files are not counted as read or changed.'],
                !$e->failed => ['✓ ran', 'The call ran and its result went back to the agent.'],
                $e->executed() => ['✗ ran, exit code ≠ 0', 'The command ran but reported an error. Its output still reached the agent, so its files count.'],
                default => ['! tool error', 'The tool failed before doing anything (e.g. the file does not exist). Its files are not counted.'],
            };
            ?>
            <p class="drawer-kind">call from the session log</p>
            <h2><?= h($context ? 'loaded by Claude Code' : $e->tool) ?></h2>
            <p class="explain"><?= h(App::toolHelp($e->tool)) ?>.</p>

            <dl class="facts">
                <dt>when</dt>
                <dd><?= h(Clock::local($e->ts, 'd.m H:i:s')) ?><?php if ($took !== null && !$context): ?> → <?= h(Clock::local((string) $e->endTs)) ?>
                    <span class="muted" title="from the call to its result — includes waiting for your approval, if it was asked">(<?= h($took < 60 ? round($took, 1) . ' s' : round($took / 60, 1) . ' min') ?>)</span><?php endif ?></dd>
                <dt>agent</dt>
                <dd><?= $dot($e->agentId) ?><?= h($agentLabel($e->agentId)) ?></dd>
                <?php if (!$context): ?>
                    <dt>status</dt>
                    <dd><span class="<?= $e->failed ? 'cmd-err' : 'ok' ?>"><?= h($statusLabel) ?></span>
                        <div class="explain"><?= h($statusHelp) ?></div>
                        <?php if ($e->error !== null): ?><div class="muted">message (first line only): <?= h($e->error) ?></div><?php endif ?></dd>
                <?php endif ?>
                <?php if ($e->carry !== null && $e->carry['usd'] !== null): ?>
                    <dt>cost</dt>
                    <dd><strong><?= h(App::usd($e->carry['usd'])) ?></strong>
                        <span class="muted">(<?= h(round(100 * $e->carry['usd'] / max($view['sessionTokens']['usd'], 1e-9), 1)) ?>% of the session)</span>
                        <div class="explain">The result added ~<?= h(App::tokenCount($e->carry['tokens'])) ?> tokens to the agent's context.
                            They were written to the cache once and then read again by <?= h($e->carry['calls']) ?> later model <?= $e->carry['calls'] === 1 ? 'call' : 'calls' ?> of this agent<?= $e->carry['calls'] > 0 ? ' (until the context was compacted or the agent finished)' : '' ?>.
                            <?= $e->carry['tokens'] >= 5000 && $e->carry['calls'] >= 20 ? 'A narrower read (offset/limit, sed -n, grep) or a subagent would keep it out of the main context.' : '' ?></div>
                        <div class="muted">Estimate at API prices: the next cache write split between the tool results by their length.</div></dd>
                <?php endif ?>
            </dl>

            <h3><?= $context ? 'what was loaded' : ($e->tool === 'Bash' ? 'command' : 'input') ?></h3>
            <pre class="drawer-cmd"><?= h($e->summary) ?></pre>
            <?php if (mb_strlen($e->summary) >= 200): ?><p class="explain">Shortened to 200 characters.</p><?php endif ?>

            <?php if (($view['problems'][$e->toolUseId] ?? []) !== []): ?>
                <h3>worth a look</h3>
                <p class="explain">Signals computed by this app — hints, not verdicts.</p>
                <?php foreach ($view['problems'][$e->toolUseId] as $reason): ?>
                    <p class="warn">⚠ <?= h($reason) ?></p>
                <?php endforeach ?>
            <?php endif ?>

            <?php if ($e->commands !== []): ?>
                <h3>programs in the command</h3>
                <p class="explain">Each program the parser found, in order. <code>|</code> = it gets the output of the previous program; hover for where it runs.</p>
                <div class="ev-cmds">
                    <?php foreach ($e->commands as $c): ?>
                        <span title="<?= h(implode(', ', array_merge(array_map(static fn (string $x): string => App::CONTEXT_HELP[$x] ?? $x, $c->contexts), array_map(static fn (string $w): string => 'wrapped in ' . $w, $c->wrappers))) ?: 'top level') ?>"><?= $c->pipedFrom !== null ? '| ' : '' ?><?= h($c->program . ($c->sub !== null ? ' ' . $c->sub : '')) ?></span>
                    <?php endforeach ?>
                </div>
            <?php endif ?>

            <h3>files (<?= h(count($e->touches)) ?>)</h3>
            <?php if ($e->touches === []): ?>
                <p class="explain">No file was resolved from this call.</p>
            <?php else: ?>
                <p class="explain">Files this call touched and what happened to each. Click a file to see all calls that touched it.</p>
                <ul class="touches">
                    <?php foreach ($e->touches as $t): ?>
                        <li viz-op="<?= h($t->op) ?>" viz-file="<?= h(App::fileHash($t->path)) ?>" onclick="Viz.Open(this, event)" <?= $t->partial ? 'viz-partial' : '' ?> <?= $t->exists ? '' : 'viz-missing' ?>
                            title="<?= h((App::OP_HELP[$t->op] ?? $t->op) . ($t->partial ? ' · only a fragment' : '') . ($t->exists ? '' : ' · the file does not exist now')) ?>">
                            <span class="op-tag"><?= h($opLabels[$t->op] ?? $t->op) ?></span>
                            <?= h(App::shortPath($t->path, $cwd)) ?><?= $t->range !== null ? ' <span class="range">[' . h($t->range) . ']</span>' : '' ?>
                        </li>
                    <?php endforeach ?>
                </ul>
                <?php require __DIR__ . '/op-legend.php' ?>
            <?php endif ?>
            <?php if ($e->unresolved !== [] || $e->unknown !== []): ?>
                <h3>not resolved by the parser</h3>
                <p class="explain">Parts of the command this app could not map to files — the list above may be missing some.</p>
                <ul class="plain muted">
                    <?php foreach ($e->unresolved as $u): ?><li><?= h($u) ?></li><?php endforeach ?>
                    <?php foreach ($e->unknown as $u): ?><li>unknown command: <?= h($u) ?></li><?php endforeach ?>
                </ul>
            <?php endif ?>
        <?php endif ?>
    <?php else: ?>
        <?php
        $hits = [];
        $path = null;
        foreach (array_reverse($view['events']) as $e) {
            foreach ($e->touches as $t) {
                if (App::fileHash($t->path) === $p['pick']) {
                    $hits[] = [$e, $t];
                    $path ??= $t->path;
                }
            }
        }
        ?>
        <?php if ($path === null): ?>
            <p class="empty">No call touched this file in the current scope — check the filters (agent, turn, sources, status).</p>
        <?php else: ?>
            <?php
            $ops = [];
            $agentIds = [];
            foreach ($hits as [$e, $t]) {
                $ops[$t->op] = ($ops[$t->op] ?? 0) + 1;
                $agentIds[$e->agentId] = true;
            }
            $exists = $hits[0][1]->exists;
            ?>
            <p class="drawer-kind">file</p>
            <h2 title="<?= h($path) ?>"><?= h(basename($path)) ?></h2>
            <p class="muted drawer-path"><?= h(App::shortPath($path, null)) ?><?= $exists ? '' : ' · does not exist now' ?></p>
            <p class="explain">
                In the current scope <?= h(count($hits)) ?> <?= count($hits) === 1 ? 'call' : 'calls' ?> touched this file,
                by <?= h(count($agentIds)) ?> <?= count($agentIds) === 1 ? 'agent' : 'agents' ?>,
                between <?= h(Clock::local($hits[0][0]->ts, 'H:i:s')) ?> and <?= h(Clock::local($hits[count($hits) - 1][0]->ts, 'H:i:s')) ?>.
            </p>

            <h3>what happened to it</h3>
            <dl class="facts">
                <?php foreach ($ops as $op => $n): ?>
                    <dt viz-op="<?= h($op) ?>"><?= h(($opLabels[$op] ?? $op) . ' ×' . $n) ?></dt>
                    <dd class="explain"><?= h(App::OP_HELP[$op] ?? '') ?></dd>
                <?php endforeach ?>
            </dl>

            <h3>agents</h3>
            <p>
                <?php foreach (array_keys($agentIds) as $id): ?>
                    <span class="who"><?= $dot($id) ?><?= h(mb_strimwidth($agentLabel($id), 0, 60, '…')) ?></span>
                <?php endforeach ?>
            </p>

            <h3>calls (<?= h(count($hits)) ?>) — oldest first</h3>
            <p class="explain">Each row is one entry of the session log: time, agent, what the call did to this file, and the call itself. ┄ = only a fragment, [range] = which lines. Click a row for the whole call.</p>
            <ol class="rows">
                <?php foreach ($hits as [$e, $t]): /** @var Event $e @var Touch $t */ ?>
                    <li viz-ev="<?= h($e->toolUseId) ?>" onclick="Viz.Open(this, event)" <?= $e->failed ? 'viz-failed' : '' ?>
                        title="<?= h((App::OP_HELP[$t->op] ?? $t->op) . ($e->denial !== null ? ' · blocked, did not run' : '')) ?>">
                        <time><?= h(Clock::local($e->ts)) ?></time>
                        <?= $dot($e->agentId) ?>
                        <span class="op-tag" viz-op="<?= h($t->op) ?>"><?= h(($opLabels[$t->op] ?? $t->op) . ($t->partial ? ' ┄' : '')) ?></span>
                        <code class="cmd"><?= h($e->tool === Event::TOOL_CONTEXT ? $e->summary : $e->tool . ' ' . App::shortText($e->summary, $cwd)) ?></code>
                        <?php if ($t->range !== null): ?><span class="range">[<?= h($t->range) ?>]</span><?php endif ?>
                    </li>
                <?php endforeach ?>
            </ol>
            <p><a href="/?<?= h(App::query($view, ['view' => 'log', 'pick' => $p['pick']])) ?>">show these calls in the full log →</a></p>
        <?php endif ?>
    <?php endif ?>
</aside>
