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
                        <option value="/?<?= h(App::query($view, ['agent' => $agent->id])) ?>" <?= $view['agentFilter'] === $agent->id ? 'selected' : '' ?>><?= h(mb_strimwidth(App::agentLabel($agent), 0, 70, '…')) ?></option>
                    <?php endforeach ?>
                </select>
            </label>
            <?php if ($view['turns'] !== []): ?>
                <span class="turn-pick">
                    <?php if ($view['turn'] > 1): ?><a href="/?<?= h(App::query($view, ['turn' => (string) ($view['turn'] - 1)])) ?>" title="<?= th('previous turn') ?>">←</a><?php endif ?>
                    <?php $turnToken = $view['turn'] > 0 ? App::tokenText($view['turnTokens'][$view['turn'] - 1])['long'] : '' ?>
                    <select onchange="Viz.Go(this)" title="<?= th('turn = from a user prompt to the next one · out = output tokens of all agents in the turn') ?><?= $turnToken !== '' ? h(' · ' . t('this turn') . ': ' . $turnToken) : '' ?>">
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
            <?php if ($p['problems']): ?>
                <a class="chip" viz-current href="/?<?= h(App::query($view, ['problems' => ''])) ?>" title="<?= th('filter from an older link — click to clear') ?>"><?= th('flagged only') ?> ×</a>
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
