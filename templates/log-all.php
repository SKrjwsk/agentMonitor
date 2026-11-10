<?php
/**
 * Zakładka „Log” w zakresie „all” (`App::allLog`): najnowsze wywołania narzędzi ze wszystkich sesji z transkryptem, najnowsze na górze,
 * ten sam wiersz co log.php (czas, narzędzie, wywołanie, blokada / błąd, dotknięte pliki) plus sesja. Klik w wiersz = wywołanie w Log jego sesji, w nazwę sesji = jej Log.
 * Strona = `logPage` (300); starsze przez `before` (czas najstarszego wiersza), bez „pokaż wszystkie” — opis przy `App::allLog`.
 *
 * @var array $view
 * @var array<string, string> $opLabels
 */

use Viz\Clock;
use Viz\Model\Event;
use Viz\Web\App;

$p = $view['p'];
$rows = $view['log'];
$link = static fn (array $extra): string => '/?' . App::query($view) . ($extra !== [] ? '&' . http_build_query($extra) : '');
?>
<div class="list-bar">
    <span class="muted"><?= th('newest tool calls of all sessions that still have a transcript · click a row →') ?> <?= th('the call in its session') ?></span>
</div>
<?php if ($rows === []): ?>
    <p class="empty"><?= th('No tool calls in this scope.') ?></p>
<?php else: ?>
    <ol class="timeline">
        <?php foreach ($rows as $row): /** @var Event $e */ ?>
            <?php
            $e = $row['event'];
            $base = $row['cwd'] !== '' ? $row['cwd'] : null;
            ?>
            <?php $href = '/?' . http_build_query(['s' => $row['session_id'], 'view' => 'log', 'pev' => $e->toolUseId]) ?>
            <li viz-event <?= $e->failed ? 'viz-failed' : '' ?> data-href="<?= h($href) ?>" onclick="if (!event.target.closest('a')) location.href = this.dataset.href">
                <div class="ev-head">
                    <time title="<?= h($e->ts) ?>"><a href="<?= h($href) ?>"><?= h(Clock::local($e->ts, 'd.m H:i:s')) ?></a></time>
                    <span class="tool"><?= h($e->tool) ?></span>
                    <code class="summary" title="<?= h($e->summary) ?>"><?= h(App::shortText($e->summary, $base)) ?></code>
                    <?php if ($e->denial !== null): ?>
                        <span class="badge denied" title="<?= h(t('blocked') . ($e->error !== null ? ' · ' . $e->error : '')) ?>">⊘<?= h(t($e->denial['by']) . ($e->denial['detail'] !== null ? ' · ' . $e->denial['detail'] : '')) ?></span>
                    <?php elseif ($e->failed): ?><span class="badge err" title="<?= h((string) $e->error) ?>"><?= th('error') ?></span><?php endif ?>
                    <a class="sess muted" href="/?s=<?= h($row['session_id']) ?>&view=log" title="<?= h(App::shortPath($row['cwd'], null)) ?>"><?= h(mb_strimwidth($row['title'] !== '' ? $row['title'] : substr($row['session_id'], 0, 8), 0, 50, '…')) ?></a>
                </div>
                <?php if ($e->touches !== []): ?>
                    <?php $visible = count($e->touches) > 8 ? array_slice($e->touches, 0, 6) : $e->touches ?>
                    <ul class="touches">
                        <?php foreach ($visible as $t): ?>
                            <?php require __DIR__ . '/touch.php' ?>
                        <?php endforeach ?>
                    </ul>
                    <?php if (count($visible) < count($e->touches)): ?>
                        <p class="more muted"><?= th('+{n} more files', ['n' => count($e->touches) - count($visible)]) ?></p>
                    <?php endif ?>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ol>
<?php endif ?>
<?php if ($p['before'] !== '' || count($rows) >= $view['logPage']): ?>
    <p class="muted list-more">
        <?php if ($p['before'] !== ''): ?><a href="<?= h($link([])) ?>"><?= th('← newest') ?></a><?php endif ?>
        <?php if (count($rows) >= $view['logPage']): ?>
            <?= $p['before'] !== '' ? '·' : '' ?> <?= th('{n} calls per page ·', ['n' => count($rows)]) ?>
            <a href="<?= h($link(['before' => end($rows)['event']->ts])) ?>"><?= th('older →') ?></a> · <?= th('or narrow with search, period or project') ?>
        <?php endif ?>
    </p>
<?php endif ?>
