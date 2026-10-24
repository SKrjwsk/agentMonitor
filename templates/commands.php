<?php
/**
 * Widok „Commands”: wywołania Bash po kolei albo zgrupowane po programie (`group=1`).
 * Klik w program = filtr `pcmd` (lista wywołań, które go użyły); klik w wywołanie = panel szczegółów.
 *
 * @var array $view
 */

use Viz\Clock;
use Viz\Extract\ToolRoles;
use Viz\Model\Event;
use Viz\Web\App;

$p = $view['p'];
if ($view['allSessions']) {
    // wszystkie sesje: agregaty znają programy, nie pojedyncze wywołania — tylko „by program”, klik = panel z sesjami
    $sort = $p['sort'] !== '' ? $p['sort'] : 'count';
    $th = static fn (string $key, string $label): string => '<th><a href="/?' . h(App::query($view, ['sort' => $key])) . '"' . ($sort === $key ? ' viz-current' : '') . '>' . h($label) . '</a></th>';
    $top = static fn (array $m, int $n): string => implode(' · ', array_map(static fn ($k, $v): string => $k . ' ×' . $v, array_keys(array_slice($m, 0, $n, true)), array_slice($m, 0, $n, true)));
    ?>
    <div class="list-bar">
        <span class="chip tab-off" title="only for a single session — single calls are not kept in the archive">in order</span>
        <span class="chip" viz-current>by program</span>
        <span class="muted">Bash programs in all sessions in scope · ✗ exit ≠ 0 · ⊘ not run · click a row → details and sessions</span>
    </div>
    <?php if ($view['commands'] === []): ?>
        <p class="empty">No commands in this scope.</p>
    <?php else: ?>
        <table class="files cmds">
            <thead><tr><?= $th('path', 'program') ?><?= $th('count', 'uses') ?><th>errors</th><th>role</th><th>subcommands</th><?= $th('sessions', 'sessions') ?><th>last</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($view['commands'], 0, $view['rowLimit']) as $c): ?>
                    <tr viz-cmd="<?= h($c['program']) ?>" onclick="Viz.OpenCmd(this, event)" <?= $p['pcmd'] === $c['program'] ? 'viz-picked' : '' ?>>
                        <td class="path"><span class="base"><?= h($c['program']) ?></span></td>
                        <td class="num"><?= h($c['count']) ?></td>
                        <td class="num cmd-err" title="exit code ≠ 0 / not run (blocked, not approved)"><?= $c['failed'] > 0 ? '✗' . h($c['failed']) : '' ?><?= $c['blocked'] > 0 ? ' ⊘' . h($c['blocked']) : '' ?></td>
                        <td class="muted"><?= h($c['role']) ?></td>
                        <td class="muted"><?= h($top($c['subs'], 3)) ?></td>
                        <td class="num muted"><?= h(count($c['sessions'])) ?></td>
                        <td class="time"><?= h(Clock::local($c['last'], 'd.m H:i')) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
    <?php
    return;
}
$agents = $view['session']->agents;
$sessionUsd = max($view['sessionTokens']['usd'], 1e-9);
?>
<div class="list-bar">
    <a class="chip" href="/?<?= h(App::query($view, ['group' => '', 'pcmd' => ''])) ?>" <?= !$p['group'] ? 'viz-current' : '' ?>>in order</a>
    <a class="chip" href="/?<?= h(App::query($view, ['group' => '1', 'pcmd' => ''])) ?>" <?= $p['group'] ? 'viz-current' : '' ?>>by program</a>
    <?php if (!$p['group'] && $view['session']->usage !== []): ?>
        <a class="chip" href="/?<?= h(App::query($view, ['sort' => $p['sort'] === 'cost' ? '' : 'cost'])) ?>" <?= $p['sort'] === 'cost' ? 'viz-current' : '' ?>
           title="most expensive first: the result was written to the context and read again on every later model call (estimate, API prices)">by cost</a>
    <?php endif ?>
    <?php if ($p['pcmd'] !== ''): ?>
        <span class="chip" viz-current>program: <?= h($p['pcmd']) ?> <a href="/?<?= h(App::query($view, ['pcmd' => ''])) ?>" title="clear">×</a></span>
    <?php endif ?>
    <span class="muted"><?= in_array($p['status'], ['blocked', 'failed'], true) ? 'all tool calls' : 'Bash calls' ?> · ✓ ran · ✗ exit ≠ 0 · ⊘ blocked · ! tool error</span>
</div>
<?php if ($view['allBash'] === []): ?>
    <p class="empty"><?= $p['origin'] === 'context' ? 'Injected context has no commands.' : ($p['status'] === 'blocked' ? 'Nothing was blocked in this scope.' : 'No Bash commands in this scope.') ?></p>
<?php elseif ($p['group']): ?>
    <?php
    $programs = [];
    foreach ($view['allBash'] as $e) {
        /** @var Event $e */
        foreach ($e->commands as $c) {
            $row = $programs[$c->program] ?? ['count' => 0, 'failed' => 0, 'subs' => []];
            $row['count']++;
            $row['failed'] += $e->failed ? 1 : 0;
            if ($c->sub !== null) {
                $row['subs'][$c->sub] = ($row['subs'][$c->sub] ?? 0) + 1;
            }
            $programs[$c->program] = $row;
        }
    }
    uasort($programs, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
    ?>
    <table class="files cmds">
        <thead><tr><th>program</th><th>uses</th><th>role</th><th>subcommands</th></tr></thead>
        <tbody>
            <?php foreach ($programs as $program => $row): ?>
                <?php arsort($row['subs']) ?>
                <tr onclick="this.one('a').click()">
                    <td class="path"><a href="/?<?= h(App::query($view, ['group' => '', 'pcmd' => (string) $program])) ?>"><span class="base"><?= h($program) ?></span></a></td>
                    <td class="num"><?= h($row['count']) ?><?= $row['failed'] > 0 ? ' <span class="cmd-err" title="calls with an error">✗' . h($row['failed']) . '</span>' : '' ?></td>
                    <td class="muted"><?= h(ToolRoles::role((string) $program)) ?></td>
                    <td class="muted"><?= h(implode(' · ', array_map(static fn ($s, $n): string => "$s ×$n", array_keys(array_slice($row['subs'], 0, 4)), array_slice($row['subs'], 0, 4)))) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
<?php else: ?>
    <ol class="rows">
        <?php foreach ($view['bash'] as $e): ?>
            <?php
            /** @var Event $e */
            $status = match (true) {
                $e->denial !== null => ['blocked', '⊘', 'blocked by ' . $e->denial['by'] . ($e->denial['detail'] !== null ? ' · ' . $e->denial['detail'] : '')],
                !$e->failed => ['ok', '✓', 'ran'],
                $e->executed() => ['exit', '✗', (string) $e->error],
                default => ['failed', '!', (string) $e->error],
            };
            $problems = $view['problems'][$e->toolUseId] ?? [];
            ?>
            <li viz-ev="<?= h($e->toolUseId) ?>" onclick="Viz.Open(this, event)" <?= $p['pev'] === $e->toolUseId ? 'viz-picked' : '' ?>>
                <time><?= h(Clock::local($e->ts)) ?></time>
                <i class="dot" viz-agent-color="<?= h(($view['agentColors'][$e->agentId] ?? 0) % 8) ?>" title="<?= h(isset($agents[$e->agentId]) ? App::agentLabel($agents[$e->agentId]) : $e->agentId) ?>"></i>
                <span class="status" viz-status="<?= h($status[0]) ?>" title="<?= h($status[2]) ?>"><?= h($status[1]) ?></span>
                <code class="cmd" title="<?= h($e->summary) ?>"><?= $e->tool !== 'Bash' ? '<span class="tool">' . h($e->tool) . '</span> ' : '' ?><?= h(App::shortText($e->summary, $view['session']->cwd)) ?></code>
                <?php if ($e->denial !== null): ?>
                    <span class="badge denied" title="<?= h((string) $e->error) ?>"><?= h($e->denial['by'] . ($e->denial['detail'] !== null ? ' · ' . $e->denial['detail'] : '')) ?></span>
                <?php endif ?>
                <?php if (($e->carry['usd'] ?? 0) >= 0.005): ?>
                    <span class="cost" <?= $e->carry['usd'] >= 0.01 * $sessionUsd ? 'viz-high' : '' ?> title="<?= h('+' . App::tokenCount($e->carry['tokens']) . ' tokens in the context × ' . $e->carry['calls'] . ' later model calls = ' . round(100 * $e->carry['usd'] / $sessionUsd, 1) . '% of the session cost') ?>"><?= h(App::usd($e->carry['usd'])) ?></span>
                <?php endif ?>
                <?php if ($e->touches !== []): ?><span class="muted n" title="files resolved from the command"><?= h(count($e->touches)) ?> <?= count($e->touches) === 1 ? 'file' : 'files' ?></span><?php endif ?>
                <?php if ($problems !== []): ?><span class="badge problem" title="<?= h(implode("\n", $problems)) ?>">⚠</span><?php endif ?>
            </li>
        <?php endforeach ?>
    </ol>
<?php endif ?>
