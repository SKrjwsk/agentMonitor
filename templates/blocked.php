<?php
/**
 * Zakładka „Blocked” — ta sama tabela dla jednej sesji i dla wszystkich: kto zablokował (hook ze skryptem i regułą, permissions.deny,
 * tryb auto, user, kontrola bezpieczeństwa) i jakie wywołanie. Dane z decyzji zapisanych przez Claude Code — nowe reguły pojawiają się same.
 * Sesja: wiersz otwiera panel wywołania (pev), kolumna agenta. Wszystkie sesje: kolumna sesji z linkiem.
 *
 * @var array $view
 */

use Viz\Clock;
use Viz\Model\Agent;
use Viz\Web\App;

$p = $view['p'];
$all = $view['allSessions'];
$blocked = $view['blocked'];
// w sesji nie ma pola szukania — przyciski grup filtrują tutaj (w „all” filtruje serwer)
if (!$all && $p['find'] !== '') {
    $blocked = array_values(array_filter($blocked, static fn (array $b): bool => mb_stripos($b['tool'] . ' ' . $b['summary'] . ' ' . $b['by'] . ' ' . ($b['detail'] ?? ''), $p['find']) !== false));
}
// grupy mechanizm · reguła; klik = szukanie po regule (albo mechanizmie, gdy reguły brak)
$groups = [];
foreach ($view['blocked'] as $b) {
    $key = $b['by'] . ($b['detail'] !== null ? ' · ' . $b['detail'] : '');
    $groups[$key] ??= ['n' => 0, 'find' => $b['detail'] ?? $b['by'], 'label' => t($b['by']) . ($b['detail'] !== null ? ' · ' . $b['detail'] : '')];
    $groups[$key]['n']++;
}
uasort($groups, static fn (array $a, array $b): int => $b['n'] <=> $a['n']);
?>
<div class="list-bar">
    <span class="muted"><?= th('calls Claude Code did not run — stopped by a hook, a permissions.deny rule, auto mode, a safety check or you · newest first · click a call to expand') ?></span>
</div>
<?php if ($view['blocked'] === []): ?>
    <p class="empty"><?= th('Nothing was blocked in this scope.') ?></p>
<?php else: ?>
    <div class="blocked-groups">
        <?php foreach ($groups as $key => $g): ?>
            <a class="chip" href="/?<?= h(App::query($view, ['find' => $p['find'] === $g['find'] ? '' : $g['find']])) ?>" <?= $p['find'] === $g['find'] ? 'viz-current' : '' ?>
               title="<?= th('show only this') ?>"><?= h($g['label']) ?> <span class="n"><?= h($g['n']) ?></span></a>
        <?php endforeach ?>
    </div>
    <table class="files blocked">
        <thead><tr><th><?= th('time') ?></th><th><?= th('blocked by') ?></th><th><?= th('call') ?></th><th><?= $all ? th('session') : th('agent') ?></th></tr></thead>
        <tbody>
            <?php foreach ($blocked as $b): ?>
                <?php $call = App::shortText($b['summary'], $b['cwd'] !== '' ? $b['cwd'] : null) ?>
                <tr <?= $b['id'] !== null ? 'viz-ev="' . h($b['id']) . '"' . ($p['pev'] === $b['id'] ? ' viz-picked' : '') : '' ?>>
                    <td class="time"><?= h(Clock::local($b['ts'], 'd.m H:i:s')) ?></td>
                    <td class="by"><span class="badge denied"><?= h(t($b['by'])) ?></span> <?= $b['detail'] !== null ? h($b['detail']) : '' ?></td>
                    <td class="call">
                        <!-- komenda zwinięta do jednej linii; klik rozwija -->
                        <details>
                            <summary title="<?= th('click to expand') ?>"><?= $b['tool'] !== 'Bash' ? '<strong>' . h($b['tool']) . '</strong> ' : '' ?><?= h((string) preg_replace('/\s+/', ' ', $call)) ?></summary>
                            <code><?= h($call) ?></code>
                        </details>
                    </td>
                    <?php if ($all): ?>
                        <td class="sess"><a href="/?s=<?= h($b['session_id']) ?>&view=blocked" title="<?= h(basename($b['cwd'])) ?>"><?= h($b['title'] !== '' ? $b['title'] : substr((string) $b['session_id'], 0, 8)) ?></a></td>
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
