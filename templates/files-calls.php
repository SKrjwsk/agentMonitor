<?php
/**
 * Widoki „Read” i „Changed” jednej sesji: płaska lista dotknięć plików w kolejności wywołań (najnowsze na górze, jak Log).
 * Jeden wiersz = jedno dotknięcie: dokładna godzina, agent, operacja, plik, koszt wyniku w kontekście. Klik → panel wywołania.
 *
 * @var array $view
 */

use Viz\Boundary;
use Viz\Clock;
use Viz\Model\Event;
use Viz\Model\Touch;
use Viz\Web\App;

$p = $view['p'];
$changed = $p['view'] === 'changed';
$rows = $changed ? $view['changedCalls'] : $view['readCalls'];
// ścieżki względem wspólnego korzenia sesji (App::pathRoot), pokazanego raz w pasku listy; bez korzenia — względem katalogu sesji
$base = $view['pathRoot'] ?? $view['session']->cwd;
$multi = count($view['session']->agents) > 1;
$costs = !$changed && $view['session']->usage !== [];
$sessionUsd = $costs ? max($view['sessionTokens']['usd'], 1e-9) : 1.0;
$limits = Boundary::fromEnv();
$total = count($rows);
$rows = array_slice($rows, 0, $view['rowLimit']);
?>
<div class="list-bar">
    <?php if ($changed): ?>
        <span class="muted"><?= th('every edit or write, in call order · click a row →') ?> <?= th('the call') ?></span>
    <?php else: ?>
        <span class="muted"><?= th('every file access, in call order, with the cost of its result in context · click a row →') ?> <?= th('the call') ?></span>
        <a class="chip" href="/?<?= h(App::query($view, ['more' => $p['more'] ? '' : '1'])) ?>" <?= $p['more'] ? 'viz-current' : '' ?>
           title="<?= th('also files the agent only searched (grep), listed (ls, Glob) or ran — it saw matching lines or metadata, not the content') ?>">+ <?= th('searched / listed / run') ?></a>
    <?php endif ?>
    <?php if ($base !== null): ?><span class="muted path-root" title="<?= h($base) ?>"><?= th('paths relative to') ?> <code><?= h(App::shortPath($base, null)) ?>/</code></span><?php endif ?>
</div>
<?php if ($rows === []): ?>
    <p class="empty"><?= $changed ? th('No file was modified in this scope.') : th('No file was read in this scope.') ?></p>
<?php else: ?>
    <table class="files calls">
        <thead>
            <tr>
                <th><?= th('time') ?></th>
                <?= $multi ? '<th>' . h(t('agent')) . '</th>' : '' ?>
                <th class="c-how"><?= th('how') ?></th>
                <th><?= h(t('file')) ?></th>
                <?= $costs ? '<th title="' . h(t('what reading it cost: written to the context once, then read again on every later model call of the agent (estimate, API prices; a command reading several files splits its cost)')) . '">' . h(t('cost')) . '</th><th class="c-tok">' . h(t('tokens')) . '</th>' : '' ?>
                <th class="c-dur"><?= th('duration') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <?php
                $short = App::shortPath($r['path'], $base);
                $slash = strrpos($short, '/');
                $dir = $slash !== false ? substr($short, 0, $slash + 1) : '';
                $dir = mb_strlen($dir) > 60 ? mb_substr($dir, 0, 14) . '…' . mb_substr($dir, -40) : $dir;
                $op = $r['op'];
                $outside = $limits->enabled() && $limits->zoneOf(new Touch($r['path'], $op, $r['exists']))['zone'] === 'out';
                $label = match ($op) {
                    Touch::READ => t('agent read'),
                    Touch::WRITE => t('whole file written'),
                    Touch::EDIT => t('fragment edited'),
                    default => t(App::OP_LABELS[$op] ?? $op),
                };
                $ms = $r['endTs'] !== null ? (int) round(((float) (new DateTimeImmutable($r['endTs']))->format('U.u') - (float) (new DateTimeImmutable($r['ts']))->format('U.u')) * 1000) : null;
                ?>
                <tr viz-ev="<?= h($r['id']) ?>" onclick="Viz.Open(this, event)" <?= $p['pev'] === $r['id'] ? 'viz-picked' : '' ?> <?= $r['exists'] ? '' : 'viz-missing' ?> <?= $outside ? 'viz-outside' : '' ?>
                    title="<?= h($r['path']) ?><?= $outside ? ' — ' . h(t('outside the allowed boundary')) : '' ?>">
                    <td class="time" title="<?= h(Clock::local($r['ts'], 'Y-m-d H:i:s.v')) ?>"><?= h(Clock::local($r['ts'], 'H:i:s')) ?></td>
                    <?php if ($multi): ?>
                        <td class="agents"><i class="dot" viz-agent-color="<?= h(($view['agentColors'][$r['agentId']] ?? 0) % 8) ?>"
                            title="<?= h(isset($view['session']->agents[$r['agentId']]) ? App::agentLabel($view['session']->agents[$r['agentId']]) : $r['agentId']) ?>"></i></td>
                    <?php endif ?>
                    <td class="tags nowrap c-how">
                        <?php if ($op !== ($changed ? Touch::EDIT : Touch::READ)): ?><span class="tag" viz-tag="<?= h($op) ?>"<?= $op === Touch::WRITE ? ' title="' . h(t('created or overwritten')) . '"' : '' ?>><?= h($label) ?></span><?php endif ?>
                        <?php if ($r['partial']): ?><span class="note" title="<?= h(t('only part of the file (offset/limit, head, sed -n…)')) ?>"><?= th('fragment') ?><?= $r['range'] !== null ? ' ' . h($r['range']) : '' ?></span><?php endif ?>
                        <?php if ($r['blindEdit']): ?><span class="tag" viz-tag="warn" title="<?= h(t('edited without reading the file first')) ?>"><?= th('not read before') ?></span><?php endif ?>
                    </td>
                    <td class="path"><span class="dir"><?= h($dir) ?></span><span class="base"><?= h($slash !== false ? substr($short, $slash + 1) : $short) ?></span></td>
                    <?php if ($costs): ?>
                        <td class="num cost" <?= $r['usd'] >= 0.01 * $sessionUsd ? 'viz-high' : '' ?> title="<?= h(t('{pct}% of the session cost', ['pct' => round(100 * $r['usd'] / $sessionUsd, 1)])) ?>"><?= $r['usd'] >= 0.005 ? h(App::usd($r['usd'])) : '' ?></td>
                        <td class="num muted c-tok"><?= $r['tokens'] > 0 ? h(number_format($r['tokens'], 0, ',', ' ')) : '' ?></td>
                    <?php endif ?>
                    <td class="num muted c-dur"><?= $ms !== null && $ms >= 0 ? h($ms >= 1000 ? round($ms / 1000, 1) . ' s' : $ms . ' ms') : '' ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
    <?php if (count($rows) < $total): ?>
        <p class="muted list-more"><?= th('shown {n} of {total} ·', ['n' => count($rows), 'total' => $total]) ?>
            <a href="/?<?= h(App::query($view, ['limit' => 'all'])) ?>"><?= th('show all') ?></a></p>
    <?php endif ?>
<?php endif ?>
