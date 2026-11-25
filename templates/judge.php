<?php
/**
 * "Judge" tab of a session (`view=judge`): a button runs the LLM judge on demand (POST /judge → snapshot + `bin/judge.php` in the background,
 * SessionJudge), below it the history of judgements from `var/judge/<session_id>/`. The newest judgement expanded into cards: TL;DR, task and result,
 * course (where it got lost), problems from most important (evidence, fix, smallest owner, why not caught), prompts,
 * tools, what worked, missing evidence; on top a block to copy for Claude Code (session id and paths). Older judgements collapsed.
 * The tab has its own scrolling (the session view has the window height). A running judgement = status from GET /judge.json every 5 s,
 * page reload only after it finishes.
 *
 * @var array $view
 */

use Viz\Judge\SessionJudge;
use Viz\Source\HookLog;

$sid = $view['sid'];
/** @var Viz\Stats $stats */
$stats = $view['stats'];
$pct = $stats->bashCalls > 0 ? round(100 * $stats->blind / $stats->bashCalls) : 0;
$p = $view['p'];
$running = SessionJudge::running($sid, time());
$judgments = SessionJudge::list($sid);
$verdicts = ['good' => 'good', 'mixed' => 'mixed', 'poor' => 'poor', 'inconclusive' => 'inconclusive'];
$owners = ['agent' => 'agent', 'prompt' => 'your prompt', 'claude-md' => 'CLAUDE.md', 'rules' => 'rules', 'skill' => 'skill',
    'hooks-permissions' => 'hooks and permissions', 'tooling' => 'tooling'];
$steps = ['detour' => 'detour', 'loop' => 'loop', 'blocked' => 'blocked', 'waiting' => 'waiting', 'correction' => 'your correction', 'good' => 'good move'];
$levels = ['high' => 'high', 'medium' => 'medium', 'low' => 'low'];
$rank = ['high' => 0, 'medium' => 1, 'low' => 2];
// list from elements already built safely (h() in $f)
$list = static fn (array $items, callable $f): string => $items === [] ? '<p class="muted">—</p>' : '<ul>' . implode('', array_map(static fn ($i): string => '<li>' . $f($i) . '</li>', $items)) . '</ul>';
?>
<div class="judge">
    <div class="judge-bar">
        <form method="post" action="/judge?<?= h(http_build_query(['s' => $sid])) ?>" class="inline">
            <button class="btn" <?= $running || SessionJudge::disabledInDemo() ? 'disabled' : '' ?> title="<?= SessionJudge::disabledInDemo() ? th('disabled in the demo') : th('runs Claude Code ({m}) in the background on a dump of the whole session; read-only tools, instructions from prompts/session-review.md', ['m' => SessionJudge::MODEL]) ?>">⚖ <?= th('Assess the session') ?></button>
        </form>
        <span class="muted"><?= th('Saved to') ?> <code>var/judge/<?= h($sid) ?>/</code> — <?= th('a hint, not a verdict: the judge is a model and can be wrong') ?></span>
    </div>
    <?php if ($running !== null): ?>
        <p class="notice"><?= th('Assessment running for {s} s…', ['s' => time() - $running['started']]) ?></p>
        <!-- status only (/judge.json), no session render; reload once, after finishing; one timer despite fragment refresh -->
        <s-init run="() => { if (window._judgePoll) return; window._judgePoll = setInterval(async () => {
            const r = await fetch('/judge.json?s=<?= h(rawurlencode($sid)) ?>').then(x => x.json()).catch(() => null);
            if (r && !r.running) { clearInterval(window._judgePoll); location.reload(); }
        }, 5000); }"></s-init>
    <?php elseif ($p['busy']): ?>
        <p class="notice"><?= th('An assessment of this session is already running.') ?></p>
    <?php endif ?>
    <?php if ($judgments === [] && $running === null): ?>
        <p class="empty"><?= th('No assessments yet.') ?></p>
    <?php endif ?>
    <?php foreach ($judgments as $n => $r): ?>
        <?php $j = $r['judgment'] ?? null ?>
        <?php $full = is_array($j) && isset($j['findings']) ?>
        <details class="judge-item" data-verdict="<?= h(is_array($j) ? $j['verdict'] : 'error') ?>" <?= $n === 0 ? 'open' : '' ?>>
            <summary class="judge-head">
                <strong class="judge-verdict"><?= is_array($j) ? th($verdicts[$j['verdict']] ?? 'inconclusive') : th('error') ?></strong>
                <?php if ($full): ?><span class="judge-chip"><?= th('ended: {t}', ['t' => $j['termination']]) ?></span><?php endif ?>
                <span class="muted"><?= h(date('Y-m-d H:i', (int) ($r['started'] ?? 0))) ?>
                    · <?= h($r['model'] ?? '?') ?>
                    <?= isset($r['durationMs']) ? ' · ' . h(round($r['durationMs'] / 1000)) . ' s' : '' ?>
                    <?= isset($r['turns']) ? ' · ' . th('turns {n}', ['n' => $r['turns']]) : '' ?>
                    <?= isset($r['costUsd']) ? ' · $' . h(number_format((float) $r['costUsd'], 2)) : '' ?>
                    · <code><?= h($r['stamp']) ?>.<?= is_array($j) ? 'md' : 'json' ?></code></span>
                <?php if (is_string($r['judgeSession'] ?? null) && HookLog::validSessionId($r['judgeSession'])): ?>
                    <a href="/?<?= h(http_build_query(['s' => $r['judgeSession'], 'view' => 'log'])) ?>" title="<?= th('the judge session: what it read before the verdict') ?>"><?= th('judge session') ?> →</a>
                <?php endif ?>
            </summary>
            <?php if (!is_array($j)): ?>
                <p class="judge-error"><?= h((string) ($r['error'] ?? '')) ?></p>
            <?php else: ?>
                <?php /* order: TL;DR right after the verdict; copy for Claude Code with one button (paths collapsed), rationale collapsed */ ?>
                <?php ob_start() ?>
                <div class="judge-handoff">
                    <button type="button" class="btn" onclick="navigator.clipboard.writeText(this.parentElement.querySelector('pre').textContent).then(() => { this.textContent = '✓ <?= th('copied') ?>'; })">⧉ <?= th('Copy for Claude Code') ?></button>
                    <details><summary class="muted"><?= th('what gets copied') ?></summary><pre><?= h(SessionJudge::handoff($sid, $r)) ?></pre></details>
                    <details class="judge-reason"><summary class="muted"><?= th('why this verdict') ?></summary><p><?= h($j['reasoning']) ?></p></details>
                </div>
                <?php $handoff = (string) ob_get_clean() ?>
                <?php if (!$full): ?>
                    <section class="judge-card"><h3>TL;DR</h3><?= $list($j['tldr'] ?? [], static fn (string $t): string => h($t)) ?>
                        <p class="muted"><?= th('older report format — run a new assessment for the full report') ?></p></section>
                    <?= $handoff ?>
                <?php else: ?>
                    <?php usort($j['findings'], static fn (array $a, array $b): int => ($rank[$a['severity']] ?? 3) <=> ($rank[$b['severity']] ?? 3)) ?>
                    <div class="judge-grid">
                        <section class="judge-card judge-tldr"><h3>TL;DR</h3><?= $list($j['tldr'], static fn (string $t): string => h($t)) ?></section>
                        <section class="judge-card"><h3><?= th('Task and outcome') ?></h3>
                            <dl><dt><?= th('task') ?></dt><dd><?= h($j['task']) ?></dd><dt><?= th('outcome') ?></dt><dd><?= h($j['outcome']) ?></dd></dl></section>
                        <div class="judge-wide"><?= $handoff ?></div>
                        <section class="judge-card judge-wide"><h3><?= th('Where the agent got lost — key moments') ?></h3>
                            <?php if ($j['timeline'] === []): ?><p class="muted">—</p><?php endif ?>
                            <ol class="judge-timeline">
                                <?php foreach ($j['timeline'] as $t): ?>
                                    <li data-kind="<?= h($t['kind']) ?>"><span class="judge-time"><?= h($t['time']) ?></span>
                                        <span class="judge-chip"><?= th($steps[$t['kind']] ?? 'detour') ?></span>
                                        <span><?= h($t['what']) ?> <span class="muted">— <?= h($t['evidence']) ?></span></span></li>
                                <?php endforeach ?>
                            </ol></section>
                        <section class="judge-card judge-wide"><h3><?= th('Problems — most important first') ?></h3>
                            <?php if ($j['findings'] === []): ?><p class="muted">—</p><?php endif ?>
                            <?php foreach ($j['findings'] as $f): ?>
                                <div class="judge-finding" data-severity="<?= h($f['severity']) ?>">
                                    <div class="judge-finding-head"><span class="judge-chip judge-sev"><?= th($levels[$f['severity']] ?? 'low') ?></span>
                                        <strong><?= h($f['title']) ?></strong>
                                        <span class="judge-chip"><?= th($owners[$f['owner']] ?? 'agent') ?><?= $f['owner_path'] !== '' ? ' · ' . h($f['owner_path']) : '' ?></span></div>
                                    <dl>
                                        <dt><?= th('evidence') ?></dt><dd><?= h($f['evidence']) ?></dd>
                                        <dt><?= th('fix') ?></dt><dd class="judge-fix"><?= h($f['fix']) ?></dd>
                                        <dt><?= th('why not caught') ?></dt><dd><?= h($f['why_missed']) ?></dd>
                                        <dt><?= th('confidence') ?></dt><dd><?= th($levels[$f['confidence']] ?? 'low') ?></dd>
                                    </dl>
                                </div>
                            <?php endforeach ?></section>
                        <section class="judge-card"><h3><?= th('Prompts — how to write them better') ?></h3>
                            <?= $list($j['prompt_tips'], static fn (array $t): string => '<span class="muted">' . th('instead') . ':</span> ' . h($t['instead'])
                                . '<br><span class="muted">' . th('better') . ':</span> <span class="judge-fix">' . h($t['better']) . '</span><br><span class="muted">' . h($t['why']) . '</span>') ?></section>
                        <section class="judge-card"><h3><?= th('Tools that would help') ?></h3>
                            <?= $list($j['tools'], static fn (array $t): string => '<span class="judge-chip">' . h($t['kind']) . '</span> ' . h($t['idea']) . ' <span class="muted">— ' . h($t['why']) . '</span>') ?></section>
                        <section class="judge-card"><h3><?= th('What worked') ?></h3>
                            <?= $list($j['strengths'], static fn (array $t): string => h($t['text']) . ' <span class="muted">— ' . h($t['evidence']) . '</span>') ?></section>
                        <section class="judge-card"><h3><?= th('Missing evidence') ?></h3>
                            <?= $list($j['missing_evidence'], static fn (string $t): string => h($t)) ?></section>
                    </div>
                <?php endif ?>
            <?php endif ?>
        </details>
    <?php endforeach ?>
    <details class="parser-diag">
        <summary title="<?= th('how well the parser resolved this session') ?>"><?= th('parser: {pct}% blind', ['pct' => $pct]) ?></summary>
        <div class="parser-body">
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
