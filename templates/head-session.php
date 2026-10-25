<?php
/**
 * Nagłówek zakresu „jedna sesja”: tytuł, źródło, zrzut, live + rząd filtrów (agent, tura, pochodzenie, projekt, status, diagnostyka parsera).
 *
 * @var array $view
 */

use Viz\Clock;
use Viz\Web\App;

/** @var Viz\Model\Session $session */
$session = $view['session'];
/** @var Viz\Stats $stats */
$stats = $view['stats'];
$p = $view['p'];
$cwd = $session->cwd;
$pct = $stats->bashCalls > 0 ? round(100 * $stats->blind / $stats->bashCalls) : 0;
?>
        <div class="title-row">
            <h1 title="<?= h($view['title']) ?>"><?= h($view['title']) ?></h1>
            <span class="meta" title="<?= h($view['sid']) ?>">
                <?= h(App::shortPath((string) $cwd, null)) ?>
                · <?php foreach (['transcript', 'hook'] as $src): ?>
                    <?php $available = $src === 'hook' ? $view['hookAvailable'] : $view['transcriptAvailable'] ?>
                    <?php if ($p['src'] === $src): ?>
                        <strong><?= th($src) ?></strong>
                    <?php elseif ($available): ?>
                        <a href="/?<?= h(App::query($view, ['src' => $src])) ?>"><?= th($src) ?></a>
                    <?php endif ?>
                <?php endforeach ?>
            </span>
            <a class="btn" href="/export.csv?<?= h(App::query($view, ['pick' => '', 'pev' => ''])) ?>" title="<?= th('this tab as CSV, with the current filters (all rows)') ?>">⇩ CSV</a>
            <form method="post" action="/export?<?= h(App::query($view)) ?>" class="inline">
                <button class="btn" title="<?= th('writes var/session_dump/: summary.md, metrics.json, prompts.json, events.jsonl, files.csv, commands.csv — current source and filters') ?>">⇩ <?= th('dump') ?></button>
            </form>
            <label class="live-toggle">
                <input type="checkbox" <?= $view['live'] ? 'checked' : '' ?> onchange="Viz.LiveToggle(this)">
                <?= th('live') ?><?= $view['active'] ? ' <span class="badge live">' . th('active') . '</span>' : '' ?>
            </label>
        </div>
        <?php require __DIR__ . '/tabs.php' ?>

        <div class="filters">
            <label>
                <i class="dot" viz-agent-color="<?= h(($view['agentColors'][$view['agentFilter']] ?? 0) % 8) ?>" <?= $view['agentFilter'] === '' ? 'hidden' : '' ?>></i>
                <select onchange="Viz.Go(this)" title="<?= th('agent') ?>">
                    <option value="/?<?= h(App::query($view, ['agent' => ''])) ?>"><?= th('all agents ({n})', ['n' => count($session->agents)]) ?></option>
                    <?php foreach ($session->agents as $agent): ?>
                        <option value="/?<?= h(App::query($view, ['agent' => $agent->id])) ?>" <?= $view['agentFilter'] === $agent->id ? 'selected' : '' ?>><?= h(mb_strimwidth(App::agentLabel($agent), 0, 70, '…') . ($view['agentTokensAll'][$agent->id]['calls'] > 0 ? ' · ' . App::tokenText($view['agentTokensAll'][$agent->id])['short'] : '')) ?></option>
                    <?php endforeach ?>
                </select>
            </label>
            <?php if ($view['turns'] !== []): ?>
                <span class="turn-pick">
                    <?php if ($view['turn'] > 1): ?><a href="/?<?= h(App::query($view, ['turn' => (string) ($view['turn'] - 1)])) ?>" title="<?= th('previous turn') ?>">←</a><?php endif ?>
                    <?php $turnToken = $view['turn'] > 0 ? App::tokenText($view['turnTokens'][$view['turn'] - 1])['long'] : '' ?>
                    <select onchange="Viz.Go(this)" title="<?= th('turn = from a user prompt to the next one · $ = cost of all agents in the turn at API prices') ?><?= $turnToken !== '' ? h(' · ' . t('this turn') . ': ' . $turnToken) : '' ?>">
                        <option value="/?<?= h(App::query($view, ['turn' => ''])) ?>"><?= th('all turns ({n})', ['n' => count($view['turns'])]) ?></option>
                        <?php foreach ($view['turns'] as $i => $t): ?>
                            <option value="/?<?= h(App::query($view, ['turn' => (string) ($i + 1)])) ?>" <?= $view['turn'] === $i + 1 ? 'selected' : '' ?>><?= h(($i + 1) . ' · ' . Clock::local($t['ts'], 'H:i') . ' · ' . mb_strimwidth($t['text'], 0, 50, '…') . ' · ' . App::tokenText($view['turnTokens'][$i])['short']) ?></option>
                        <?php endforeach ?>
                    </select>
                    <?php if ($view['turn'] > 0 && $view['turn'] < count($view['turns'])): ?><a href="/?<?= h(App::query($view, ['turn' => (string) ($view['turn'] + 1)])) ?>" title="<?= th('next turn') ?>">→</a><?php endif ?>
                </span>
            <?php endif ?>
            <select onchange="Viz.Go(this)" title="<?= th('where the file came from') ?>">
                <?php foreach (['' => 'all sources', 'self' => 'agent tools only', 'context' => 'injected context only'] as $origin => $label): ?>
                    <option value="/?<?= h(App::query($view, ['origin' => $origin])) ?>" <?= $p['origin'] === $origin ? 'selected' : '' ?>><?= th($label) ?></option>
                <?php endforeach ?>
            </select>
            <a class="chip" href="/?<?= h(App::query($view, ['scope' => $p['scope'] === 'project' ? '' : 'project'])) ?>" <?= $p['scope'] === 'project' ? 'viz-current' : '' ?>
               title="<?= th('only files in {dir}', ['dir' => App::shortPath((string) $cwd, null)]) ?>"><?= th('project only') ?></a>
            <select onchange="Viz.Go(this)" title="<?= th('executed = ran (also Bash with exit code ≠ 0) · blocked = stopped by a hook, a permissions.deny rule, auto mode or you · failed = tool error, e.g. file does not exist') ?>">
                <?php $sc = $view['statusCounts'] ?>
                <?php foreach (['' => t('all calls ({n})', ['n' => array_sum($sc)]), 'executed' => t('executed ({n})', ['n' => $sc['executed']]), 'blocked' => t('blocked ({n})', ['n' => $sc['blocked']]), 'failed' => t('failed ({n})', ['n' => $sc['failed']])] as $status => $label): ?>
                    <option value="/?<?= h(App::query($view, ['status' => $status])) ?>" <?= $p['status'] === $status ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach ?>
            </select>
            <?php if ($view['boundaryOn']): ?>
                <select onchange="Viz.Go(this)" title="<?= th('boundary from .env · outside = ran and touched a path outside it · suspicious = Bash whose paths could not be resolved · stopped = an attempt outside that did not run') ?>">
                    <?php $bc = $view['boundaryCounts'] ?>
                    <?php foreach (['' => t('boundary: all'), 'outside' => t('outside ({n})', ['n' => $bc['outside']]), 'suspicious' => t('suspicious ({n})', ['n' => $bc['suspicious']]), 'stopped' => t('stopped ({n})', ['n' => $bc['stopped']])] as $kind => $label): ?>
                        <option value="/?<?= h(App::query($view, ['boundary' => $kind])) ?>" <?= $p['boundary'] === $kind ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach ?>
                </select>
            <?php endif ?>
            <?php if ($p['problems']): ?>
                <a class="chip" viz-current href="/?<?= h(App::query($view, ['problems' => ''])) ?>" title="<?= th('filter from an older link — click to clear') ?>"><?= th('flagged only') ?> ×</a>
            <?php endif ?>
            <?php if ($session->usage !== []): ?>
                <?php
                $tok = $view['tokens'];
                $scopeUsd = $tok['usd'] > 0 ? $tok['usd'] : 1.0;
                $pctOf = static fn (float $usd): string => round(100 * $usd / $scopeUsd) . '%';
                $cells = static fn (array $t): string => '<td class="num">' . h(App::usd($t['usd'])) . '</td><td class="num muted">' . h($pctOf($t['usd'])) . '</td><td class="num">' . h($t['calls']) . '</td>'
                    . '<td class="num">' . h(($t['outputPartial'] > 0 ? '≥' : '') . App::tokenCount($t['output'])) . '</td><td class="num">' . h(App::tokenCount($t['cacheWrite'])) . '</td><td class="num">' . h(App::tokenCount($t['cacheRead'] + $t['input'])) . '</td>';
                $parts = [
                    'read' => ['cache read', 'the whole context read again on every model call'],
                    'write' => ['new context', 'cache write: tool results, prompts, agent starts, context rebuilds'],
                    'output' => ['output', 'text, tool inputs and thinking generated by the model'],
                    'input' => ['uncached input', 'input outside the cache'],
                ];
                ?>
                <details class="diag">
                    <summary title="<?= th('cost of all agents at API prices — click: where it went') ?>"><?= $view['turn'] > 0 ? th('turn {n} cost: {usd}', ['n' => $view['turn'], 'usd' => App::tokenText($tok)['short']]) : th('cost: {usd}', ['usd' => App::tokenText($tok)['short']]) ?></summary>
                    <div class="diag-body tok-body">
                        <p><strong><?= $view['turn'] > 0 ? th('turn {n}', ['n' => $view['turn']]) : th('whole session') ?></strong> · <?= th('all agents, regardless of the agent and status filters') ?><?= $view['turn'] > 0 ? ' · ' . th('subagents counted by time') . ' · ' . th('{pct} of the session', ['pct' => round(100 * $tok['usd'] / max($view['sessionTokens']['usd'], 1e-9)) . '%']) : '' ?></p>
                        <?php if ($tok['calls'] === 0): ?>
                            <p class="muted"><?= th('no model calls in this turn') ?></p>
                        <?php else: ?>
                            <h4><?= th('what the money went to') ?></h4>
                            <div class="tok-parts">
                                <?php foreach ($parts as $k => [$label, $help]): ?>
                                    <?php if ($tok['cost'][$k] >= 0.005): ?>
                                        <span class="tok-part" viz-part="<?= h($k) ?>" style="flex-grow: <?= h(round(1000 * $tok['cost'][$k] / $scopeUsd)) ?>" title="<?= th($label) ?> — <?= th($help) ?>: <?= h(App::usd($tok['cost'][$k])) ?>"><?= th($label) ?> <?= h($pctOf($tok['cost'][$k])) ?></span>
                                    <?php endif ?>
                                <?php endforeach ?>
                            </div>
                            <p class="muted"><?= th('Cache read grows with context size × number of calls: every call re-reads everything the agent has seen so far. A big tool result therefore costs again on every later call.') ?></p>
                            <?php if ($view['expensive'] !== []): ?>
                                <h4><?= th('most expensive tool results') ?> <span class="muted"><?= th('— all tool results: {usd} ({pct})', ['usd' => App::usd($view['carried']), 'pct' => $pctOf($view['carried'])]) ?></span></h4>
                                <ol class="tok-top">
                                    <?php foreach ($view['expensive'] as $e): ?>
                                        <li viz-ev="<?= h($e->toolUseId) ?>" onclick="Viz.Open(this, event)" title="<?= th('+{tokens} tokens in the context, read again by {calls} later model calls of this agent — click for details', ['tokens' => App::tokenCount($e->carry['tokens']), 'calls' => $e->carry['calls']]) ?>">
                                            <span class="num"><?= h(App::usd((float) $e->carry['usd'])) ?></span>
                                            <span class="num muted"><?= h($pctOf((float) $e->carry['usd'])) ?></span>
                                            <i class="dot" viz-agent-color="<?= h(($view['agentColors'][$e->agentId] ?? 0) % 8) ?>"></i>
                                            <code><?= $e->tool !== 'Bash' ? '<span class="tool">' . h($e->tool) . '</span> ' : '' ?><?= h(mb_strimwidth(App::shortText($e->summary, $session->cwd), 0, 90, '…')) ?></code>
                                            <span class="muted n"><?= h(App::tokenCount($e->carry['tokens'])) ?> × <?= h($e->carry['calls']) ?></span>
                                        </li>
                                    <?php endforeach ?>
                                </ol>
                            <?php endif ?>
                            <h4><?= th('agents') ?></h4>
                            <table class="tok-table">
                                <thead><tr><th><?= th('agent') ?></th><th class="num"><?= th('cost') ?></th><th class="num"></th><th class="num" title="<?= th('model responses') ?>"><?= th('calls') ?></th><th class="num" title="<?= th('tokens generated by the model') ?>"><?= th('output') ?></th><th class="num" title="<?= th('cache write — context sent for the first time') ?>"><?= th('new context') ?></th><th class="num" title="<?= th('the same context read again on every call (+ uncached input)') ?>"><?= th('cache read') ?></th></tr></thead>
                                <tbody>
                                    <?php foreach ($view['agentTokens'] as $id => $t): ?>
                                        <tr>
                                            <td class="tok-agent"><a href="/?<?= h(App::query($view, ['agent' => (string) $id])) ?>" title="<?= th('filter this agent') ?>"><i class="dot" viz-agent-color="<?= h(($view['agentColors'][$id] ?? 0) % 8) ?>"></i><?= h(mb_strimwidth(App::agentLabel($session->agents[$id]), 0, 44, '…')) ?></a></td>
                                            <?= $cells($t) ?>
                                        </tr>
                                    <?php endforeach ?>
                                </tbody>
                                <?php if (count($view['agentTokens']) > 1): ?>
                                    <tfoot><tr><td><?= th('total') ?></td><?= $cells($tok) ?></tr></tfoot>
                                <?php endif ?>
                            </table>
                            <p><?= th('models') ?>: <?= h(implode(' · ', array_map(static fn ($k, $n): string => "$k ×$n", array_keys($tok['models']), $tok['models']))) ?></p>
                            <?php if ($tok['outputPartial'] > 0): ?>
                                <p class="muted"><?= th('≥ = lower bound: {n} responses were saved without final usage (subagents in Claude Code ≤ 2.1.300)', ['n' => $tok['outputPartial']]) ?></p>
                            <?php endif ?>
                            <?php if ($tok['unpriced'] > 0): ?>
                                <p class="muted"><?= th('? = {n} responses of a model without a price in the table are not in the cost', ['n' => $tok['unpriced']]) ?></p>
                            <?php endif ?>
                            <p class="muted"><?= th('Cost = Anthropic API prices per model (cache read, 5 min / 1 h cache write, output) — a yardstick for comparing, not your bill. Tool result cost is an estimate: the next cache write split by result length.') ?></p>
                        <?php endif ?>
                    </div>
                </details>
            <?php endif ?>
            <details class="diag">
                <summary title="<?= th('how well the parser resolved this session') ?>"><?= th('parser: {pct}% blind', ['pct' => $pct]) ?></summary>
                <div class="diag-body">
                    <p><strong><?= th('{n} calls', ['n' => $stats->calls]) ?></strong> · <?= h(implode(' · ', array_map(static fn ($t, $n): string => "$t ×$n", array_keys($stats->perTool), $stats->perTool))) ?></p>
                    <p><?= th('files: {n} · injected context {c} · read by agent {r} · re-read from context {rr}', ['n' => count($stats->files), 'c' => $stats->contextFiles, 'r' => $stats->selfReadFiles, 'rr' => $stats->contextReread]) ?></p>
                    <p><?= th('blind spots {b} ({pct}% of Bash) · partial parse {i} · errors {e}', ['b' => $stats->blind, 'pct' => $pct, 'i' => $stats->incomplete, 'e' => $stats->failed]) ?></p>
                    <?php if ($stats->indirect !== []): ?>
                        <p><?= th('indirect (program reads files itself)') ?>: <?= h(implode(', ', array_map(static fn ($k, $n): string => "$k ×$n", array_keys($stats->indirect), $stats->indirect))) ?></p>
                    <?php endif ?>
                    <?php if ($stats->unknown !== []): ?>
                        <p><?= th('unknown commands') ?>: <?= h(implode(', ', array_map(static fn ($k, $n): string => "$k ×$n", array_keys($stats->unknown), $stats->unknown))) ?></p>
                    <?php endif ?>
                </div>
            </details>
        </div>
        <?php if ($p['dumped'] !== ''): ?>
            <p class="notice"><?= th('Saved') ?> <code>var/session_dump/<?= h($p['dumped']) ?>/</code> — <?= th('analysis') ?>: <code>/analiza-sesji var/session_dump/<?= h($p['dumped']) ?></code></p>
        <?php endif ?>
        <?php if ($view['emptyWarning']): ?>
            <p class="warn"><?= th('The transcript is large but the parser found no tool calls — the transcript format may have changed.') ?></p>
        <?php endif ?>
