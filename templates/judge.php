<?php
/**
 * Zakładka „Ocena” sesji (`view=judge`): przycisk uruchamia sędziego LLM na żądanie (POST /judge → zrzut + `bin/judge.php` w tle,
 * SessionJudge), pod nim historia ocen z `var/judge/<session_id>/`. Najnowsza ocena rozwinięta w karty: TL;DR, zadanie i wynik,
 * przebieg (gdzie się gubił), problemy od najważniejszych (dowód, poprawka, najmniejszy właściciel, czemu nie złapane), prompty,
 * narzędzia, co działało, brakujące dowody; na górze blok do skopiowania dla Claude Code (id sesji i ścieżki). Starsze oceny zwinięte.
 * Zakładka ma własne przewijanie (widok sesji ma wysokość okna). Trwająca ocena = status z GET /judge.json co 5 s,
 * przeładowanie strony dopiero po zakończeniu.
 *
 * @var array $view
 */

use Viz\Judge\SessionJudge;
use Viz\Source\HookLog;

$sid = $view['sid'];
$p = $view['p'];
$running = SessionJudge::running($sid, time());
$judgments = SessionJudge::list($sid);
$verdicts = ['good' => 'good', 'mixed' => 'mixed', 'poor' => 'poor', 'inconclusive' => 'inconclusive'];
$owners = ['agent' => 'agent', 'prompt' => 'your prompt', 'claude-md' => 'CLAUDE.md', 'rules' => 'rules', 'skill' => 'skill',
    'hooks-permissions' => 'hooks and permissions', 'tooling' => 'tooling'];
$steps = ['detour' => 'detour', 'loop' => 'loop', 'blocked' => 'blocked', 'waiting' => 'waiting', 'correction' => 'your correction', 'good' => 'good move'];
$levels = ['high' => 'high', 'medium' => 'medium', 'low' => 'low'];
$rank = ['high' => 0, 'medium' => 1, 'low' => 2];
// lista z elementów już zbudowanych bezpiecznie (h() w $f)
$list = static fn (array $items, callable $f): string => $items === [] ? '<p class="muted">—</p>' : '<ul>' . implode('', array_map(static fn ($i): string => '<li>' . $f($i) . '</li>', $items)) . '</ul>';
?>
<div class="judge">
    <div class="judge-bar">
        <form method="post" action="/judge?<?= h(http_build_query(['s' => $sid])) ?>" class="inline">
            <button class="btn" <?= $running ? 'disabled' : '' ?> title="<?= th('runs Claude Code ({m}) in the background on a dump of the whole session; read-only tools, instructions from the sk-analiza-sesji skill', ['m' => SessionJudge::MODEL]) ?>">⚖ <?= th('Assess the session') ?></button>
        </form>
        <span class="muted"><?= th('Saved to') ?> <code>var/judge/<?= h($sid) ?>/</code> — <?= th('a hint, not a verdict: the judge is a model and can be wrong') ?></span>
    </div>
    <?php if ($running !== null): ?>
        <p class="notice"><?= th('Assessment running for {s} s…', ['s' => time() - $running['started']]) ?></p>
        <!-- tylko status (/judge.json), bez renderu sesji; przeładowanie raz, po zakończeniu; jeden timer mimo odświeżania fragmentu -->
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
                <div class="judge-handoff">
                    <button type="button" class="btn" onclick="navigator.clipboard.writeText(this.nextElementSibling.textContent).then(() => { this.textContent = '✓ <?= th('copied') ?>'; })">⧉ <?= th('Copy for Claude Code') ?></button>
                    <pre><?= h(SessionJudge::handoff($sid, $r)) ?></pre>
                </div>
                <p class="judge-reason"><?= h($j['reasoning']) ?></p>
                <?php if (!$full): ?>
                    <section class="judge-card"><h3>TL;DR</h3><?= $list($j['tldr'] ?? [], static fn (string $t): string => h($t)) ?>
                        <p class="muted"><?= th('older report format — run a new assessment for the full report') ?></p></section>
                <?php else: ?>
                    <?php usort($j['findings'], static fn (array $a, array $b): int => ($rank[$a['severity']] ?? 3) <=> ($rank[$b['severity']] ?? 3)) ?>
                    <div class="judge-grid">
                        <section class="judge-card judge-tldr"><h3>TL;DR</h3><?= $list($j['tldr'], static fn (string $t): string => h($t)) ?></section>
                        <section class="judge-card"><h3><?= th('Task and outcome') ?></h3>
                            <dl><dt><?= th('task') ?></dt><dd><?= h($j['task']) ?></dd><dt><?= th('outcome') ?></dt><dd><?= h($j['outcome']) ?></dd></dl></section>
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
</div>
