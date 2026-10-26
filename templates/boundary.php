<?php
/**
 * Zakładka „Boundary” — naruszenia granicy pracy agentów z `.env` (Boundary), ta sama dla jednej sesji i dla wszystkich.
 * Chipy rodzaju (`boundary`), ścieżki poza granicą zgrupowane, pod nimi wywołania. Sesja: wiersz otwiera panel wywołania;
 * wszystkie sesje: link do sesji z tym samym filtrem.
 *
 * @var array $view
 */

use Viz\Boundary;
use Viz\Clock;
use Viz\Model\Agent;
use Viz\Web\App;

$p = $view['p'];
$all = $view['allSessions'];
$rows = $view['boundary'];
$kinds = [
    Boundary::OUTSIDE => [t('executed'), t('the call ran and touched a path outside AGENT_ALLOWED_RW / AGENT_ALLOWED_R, or wrote into a read-only path')],
    Boundary::SUSPICIOUS => [t('suspicious'), t('Bash ran, but its paths could not be resolved (glob, variable, heredoc, unknown program)')],
    Boundary::STOPPED => [t('stopped'), t('an attempt outside the boundary that did not run: a hook, a rule, auto mode, the sandbox or a tool error')],
];
// ścieżki poza granicą: operacje, sesje, ostatnie dotknięcie
$paths = [];
foreach ($rows as $b) {
    foreach ($b['paths'] as $t) {
        $f = $paths[$t['path']] ?? ['ops' => [], 'sessions' => [], 'last' => '', 'kinds' => []];
        $f['ops'][$t['op']] = ($f['ops'][$t['op']] ?? 0) + 1;
        $f['sessions'][(string) $b['session_id']] = true;
        $f['last'] = max($f['last'], $b['ts']);
        $f['kinds'][$b['kind']] = true;
        $paths[$t['path']] = $f;
    }
}
uasort($paths, static fn (array $a, array $b): int => strcmp($b['last'], $a['last']));
$badge = static fn (string $kind): string => '<span class="badge" viz-boundary="' . h($kind) . '" title="' . h($kinds[$kind][1]) . '">' . h($kinds[$kind][0]) . '</span>';
?>
<div class="list-bar">
    <a class="chip" href="/?<?= h(App::query($view, ['boundary' => ''])) ?>" <?= $p['boundary'] === '' ? 'viz-current' : '' ?>><?= th('all') ?></a>
    <?php foreach ($kinds as $kind => [$label, $help]): ?>
        <a class="chip" href="/?<?= h(App::query($view, ['boundary' => $p['boundary'] === $kind ? '' : $kind])) ?>" <?= $p['boundary'] === $kind ? 'viz-current' : '' ?>
           title="<?= h($help) ?>"><?= h($label) ?><?= $all ? '' : ' <span class="n">' . h($view['boundaryCounts'][$kind]) . '</span>' ?></a>
    <?php endforeach ?>
    <span class="muted"><?= th('boundary from .env: AGENT_ALLOWED_RW (read + write), AGENT_ALLOWED_R (read only) · newest first') ?></span>
</div>
<?php if (!$view['boundaryOn']): ?>
    <p class="empty"><?= th('No boundary configured — set AGENT_ALLOWED_RW and AGENT_ALLOWED_R in .env (see .env.example).') ?></p>
<?php elseif ($rows === []): ?>
    <p class="empty"><?= th('Nothing crossed the boundary in this scope.') ?></p>
<?php else: ?>
    <?php if ($paths !== []): ?>
        <table class="files boundary-paths">
            <thead><tr><th><?= th('path outside the boundary') ?></th><th><?= th('operations') ?></th><th><?= th('kind') ?></th><?= $all ? '<th>' . th('sessions') . '</th>' : '' ?><th><?= th('last') ?></th></tr></thead>
            <tbody>
                <?php foreach (array_slice($paths, 0, $view['rowLimit'], true) as $path => $f): ?>
                    <tr>
                        <td class="path"><span class="base"><?= h(App::shortPath((string) $path, null)) ?></span></td>
                        <td class="muted"><?= h(implode(' · ', array_map(static fn ($op, $n): string => t($op) . ' ×' . $n, array_keys($f['ops']), $f['ops']))) ?></td>
                        <td><?= implode(' ', array_map($badge, array_keys($f['kinds']))) ?></td>
                        <?php if ($all): ?><td class="num muted"><?= h(count($f['sessions'])) ?></td><?php endif ?>
                        <td class="time"><?= h(Clock::local($f['last'], 'd.m H:i')) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
    <table class="files blocked">
        <thead><tr><th><?= th('time') ?></th><th><?= th('kind') ?></th><th><?= th('call') ?></th><th><?= $all ? th('session') : th('agent') ?></th></tr></thead>
        <tbody>
            <?php foreach (array_slice($rows, 0, $view['rowLimit']) as $b): ?>
                <?php $call = App::shortText($b['summary'], $b['cwd'] !== '' ? $b['cwd'] : null) ?>
                <tr <?= $b['id'] !== null ? 'viz-ev="' . h($b['id']) . '"' . ($p['pev'] === $b['id'] ? ' viz-picked' : '') : '' ?>>
                    <td class="time"><?= h(Clock::local($b['ts'], 'd.m H:i:s')) ?></td>
                    <td class="by"><?= $badge($b['kind']) ?> <?= $b['by'] !== null ? h(t($b['by'])) : '' ?></td>
                    <td class="call">
                        <details>
                            <summary title="<?= th('click to expand') ?>"><?= $b['tool'] !== 'Bash' ? '<strong>' . h($b['tool']) . '</strong> ' : '' ?><?= h((string) preg_replace('/\s+/', ' ', $call)) ?></summary>
                            <code><?= h($call) ?></code>
                        </details>
                    </td>
                    <?php if ($all): ?>
                        <td class="sess"><a href="/?<?= h(http_build_query(['s' => $b['session_id'], 'view' => 'boundary', 'boundary' => $b['kind']])) ?>" title="<?= h(basename($b['cwd'])) ?>"><?= h($b['title'] !== '' ? $b['title'] : substr((string) $b['session_id'], 0, 8)) ?></a></td>
                    <?php else: ?>
                        <?php $agents = $view['session']->agents ?>
                        <td class="sess"><i class="dot" viz-agent-color="<?= h(($view['agentColors'][$b['agent']] ?? 0) % 8) ?>"></i><?= h(isset($agents[$b['agent']]) ? ($b['agent'] === Agent::MAIN ? t('main') : mb_strimwidth($agents[$b['agent']]->label(), 0, 40, '…')) : $b['agent']) ?>
                            <button type="button" class="link" viz-ev="<?= h($b['id']) ?>" onclick="Viz.Open(this, event)" title="<?= th('details of this call') ?>"><?= th('details') ?></button></td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>
