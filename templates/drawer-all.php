<?php
/**
 * Panel szczegółów w zakresie „wszystkie sesje”: plik (`pick`) → sesje, w których się pojawił, z operacjami;
 * program (`pcmd`) → podkomendy, potoki, konteksty i sesje. Linki prowadzą do sesji z tym samym plikiem/programem.
 * Sesja bez transkryptu (usunięty przez Claude Code) zostaje jako archiwum — bez linku.
 *
 * @var array $view
 * @var array<string, string> $opLabels
 */

use Viz\Clock;
use Viz\Source\TranscriptReader;
use Viz\Web\App;

$p = $view['p'];
$sessionsById = $view['sessionsById'];
$sessionLink = static function (string $sid, string $query) use ($sessionsById): string {
    $s = $sessionsById[$sid] ?? ['title' => '', 'cwd' => '', 'last' => null];
    $title = ($s['title'] ?? '') !== '' ? $s['title'] : substr($sid, 0, 8);
    $meta = '<span class="muted"> · ' . h(basename((string) ($s['cwd'] ?? ''))) . ($s['last'] ?? null ? ' · ' . h(Clock::local((string) $s['last'], 'd.m H:i')) : '') . '</span>';

    return TranscriptReader::locate($sid) !== null
        ? '<a href="/?s=' . h($sid) . '&' . h($query) . '">' . h($title) . '</a>' . $meta
        : '<span title="transcript deleted by Claude Code — only counters remain">' . h($title) . ' (archive)</span>' . $meta;
};
$file = null;
if ($p['pick'] !== '') {
    foreach ([...$view['read'], ...$view['changed']] as $f) {
        if ($f['hash'] === $p['pick']) {
            $file = $f;
            break;
        }
    }
}
$cmd = null;
if ($file === null && $p['pcmd'] !== '') {
    foreach ($view['commands'] as $c) {
        if ($c['program'] === $p['pcmd']) {
            $cmd = $c;
            break;
        }
    }
}
$top = static fn (array $m, int $n): string => implode(' · ', array_map(static fn ($k, $v): string => $k . ' ×' . $v, array_keys(array_slice($m, 0, $n, true)), array_slice($m, 0, $n, true)));
?>
<aside class="drawer" viz-pane="drawer">
    <button type="button" class="drawer-close" onclick="Viz.Close(this)" title="close (Esc)">×</button>
    <?php if ($file !== null): ?>
        <?php uasort($file['sessions'], static fn (array $a, array $b): int => array_sum($b) <=> array_sum($a)) ?>
        <p class="drawer-kind">file · all sessions</p>
        <h2 title="<?= h($file['path']) ?>"><?= h(basename($file['path'])) ?></h2>
        <p class="muted drawer-path"><?= h(App::shortPath($file['path'], null)) ?><?= $file['exists'] ? '' : ' · does not exist now' ?></p>
        <p class="explain">Counted over <?= h(count($file['sessions'])) ?> <?= count($file['sessions']) === 1 ? 'session' : 'sessions' ?> in the current scope (period, project, search).
            Only counters are kept across sessions — single calls are visible after opening a session.</p>

        <h3>what happened to it — totals</h3>
        <dl class="facts">
            <?php foreach ($file['ops'] as $op => $n): ?>
                <dt viz-op="<?= h($op) ?>"><?= h(($opLabels[$op] ?? $op) . ' ×' . $n) ?></dt>
                <dd class="explain"><?= h(App::OP_HELP[$op] ?? '') ?></dd>
            <?php endforeach ?>
        </dl>

        <h3>sessions (<?= h(count($file['sessions'])) ?>) — most activity first</h3>
        <p class="explain">Per session: its name, project and last activity, then what happened to this file there. Click a name to open the session with this file selected; „archive” = Claude Code already deleted the transcript, only the counters remain.</p>
        <ul class="drawer-sessions">
            <?php foreach ($file['sessions'] as $sid => $ops): ?>
                <li>
                    <?= $sessionLink((string) $sid, 'pick=' . $file['hash']) ?>
                    <div class="muted"><?= h(implode(' · ', array_map(static fn ($op, $n): string => ($opLabels[$op] ?? $op) . ' ' . $n, array_keys($ops), $ops))) ?></div>
                </li>
            <?php endforeach ?>
        </ul>
    <?php elseif ($cmd !== null): ?>
        <?php arsort($cmd['sessions']) ?>
        <p class="drawer-kind">program · all sessions</p>
        <h2><?= h($cmd['program']) ?></h2>
        <p class="explain">A program agents ran inside Bash commands, counted over <?= h(count($cmd['sessions'])) ?> <?= count($cmd['sessions']) === 1 ? 'session' : 'sessions' ?> in the current scope.</p>
        <dl class="facts">
            <dt>role</dt><dd><?= h($cmd['role']) ?> <span class="explain">— what this app thinks the program is for</span></dd>
            <dt>uses</dt><dd><?= h($cmd['count']) ?> <span class="explain">— each appearance in a command, also inside pipes and $(…)</span></dd>
            <dt>exit ≠ 0</dt><dd><?= h($cmd['failed']) ?> <span class="explain">— the whole command reported an error (one exit code per command, so a program in a chain inherits it)</span></dd>
            <dt>not run</dt><dd><?= h($cmd['blocked']) ?> <span class="explain">— blocked by a hook, a deny rule, auto mode or you</span></dd>
        </dl>
        <?php if ($cmd['subs'] !== []): ?>
            <h3>subcommands</h3>
            <p class="explain">The first argument, e.g. <code>git status</code> → status.</p>
            <p><?= h($top($cmd['subs'], 12)) ?></p>
        <?php endif ?>
        <?php if ($cmd['from'] !== []): ?>
            <h3>gets input from (left side of |)</h3>
            <p class="explain">Which programs feed it through a pipe, e.g. <code>ls | grep</code> → ls.</p>
            <p><?= h($top($cmd['from'], 12)) ?></p>
        <?php endif ?>
        <?php if ($cmd['contexts'] !== []): ?>
            <h3>where it runs</h3>
            <p class="explain">
                <?php foreach (array_slice($cmd['contexts'], 0, 10, true) as $ctx => $n): ?>
                    <span class="ctx"><?= h($ctx . ' ×' . $n) ?> <span class="muted">(<?= h(App::CONTEXT_HELP[$ctx] ?? 'wrapped in ' . $ctx) ?>)</span></span>
                <?php endforeach ?>
            </p>
        <?php endif ?>
        <h3>sessions (<?= h(count($cmd['sessions'])) ?>) — most uses first</h3>
        <p class="explain">Click a name to open the session's commands filtered to this program.</p>
        <ul class="drawer-sessions">
            <?php foreach ($cmd['sessions'] as $sid => $n): ?>
                <li><?= $sessionLink((string) $sid, 'view=commands&pcmd=' . rawurlencode($cmd['program'])) ?> <span class="muted">· <?= h($n) ?>×</span></li>
            <?php endforeach ?>
        </ul>
    <?php else: ?>
        <p class="empty">Not in the current scope — change period, project or search.</p>
    <?php endif ?>
</aside>
