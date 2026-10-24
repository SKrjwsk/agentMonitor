<?php
/**
 * Widok „Log”: wszystkie wywołania (najnowsze na górze) z programami i dotkniętymi plikami.
 * Gdy w panelu szczegółów jest plik (`pick`), wywołania, które go dotknęły, są podświetlone.
 *
 * @var array $view
 * @var array<string, string> $opLabels
 * @var ?string $cwd
 */

use Viz\Clock;
use Viz\Model\Event;
use Viz\Model\Touch;
use Viz\Web\App;

$pick = $view['p']['pick'];
$session = $view['session'];
?>
<ol class="timeline">
    <?php foreach ($view['events'] as $e): /** @var Event $e */ ?>
        <?php
        $hit = $pick !== '' && in_array($pick, array_map(static fn (Touch $t): string => App::fileHash($t->path), $e->touches), true);
        $blind = $e->tool === 'Bash' && $e->isBlind();
        ?>
        <li viz-event viz-ev="<?= h($e->toolUseId) ?>" onclick="Viz.Open(this, event)"
            viz-agent-color="<?= h(($view['agentColors'][$e->agentId] ?? 0) % 8) ?>"
            <?= $hit ? 'viz-hit' : '' ?> <?= $view['p']['pev'] === $e->toolUseId ? 'viz-picked' : '' ?> <?= $e->failed ? 'viz-failed' : '' ?>
            <?= $e->tool === Event::TOOL_CONTEXT ? 'viz-context' : '' ?>>
            <div class="ev-head">
                <time title="<?= h($e->ts) ?>"><?= h(Clock::local($e->ts)) ?></time>
                <i class="dot" title="<?= h(isset($session->agents[$e->agentId]) ? App::agentLabel($session->agents[$e->agentId]) : $e->agentId) ?>"></i>
                <span class="tool"><?= h($e->tool === Event::TOOL_CONTEXT ? 'context' : $e->tool) ?></span>
                <code class="summary" title="<?= h($e->summary) ?>"><?= h(App::shortText($e->summary, $session->cwd)) ?></code>
                <?php if ($e->denial !== null): ?>
                    <span class="badge denied" title="<?= h((string) $e->error) ?>">⊘ <?= h($e->denial['by'] . ($e->denial['detail'] !== null ? ' · ' . $e->denial['detail'] : '')) ?></span>
                <?php elseif ($e->failed): ?><span class="badge err" title="<?= h((string) $e->error) ?>">error</span><?php endif ?>
                <?php if (isset($view['problems'][$e->toolUseId]) && !$e->failed): ?>
                    <span class="badge problem" title="<?= h(implode("\n", $view['problems'][$e->toolUseId])) ?>">⚠ <?= h(mb_strimwidth($view['problems'][$e->toolUseId][0], 0, 40, '…')) ?></span>
                <?php endif ?>
                <?php if ($blind): ?>
                    <span class="badge blind" title="<?= h(implode("\n", array_merge($e->unresolved, array_map(static fn ($u): string => 'unknown command: ' . $u, $e->unknown)))) ?>">? blind spot</span>
                <?php elseif ($e->unresolved !== []): ?>
                    <span class="badge partial-blind" title="<?= h(implode("\n", $e->unresolved)) ?>">? partial</span>
                <?php endif ?>
            </div>
            <?php if ($e->touches !== []): ?>
                <?php $visible = count($e->touches) > 8 ? array_slice($e->touches, 0, 6) : $e->touches ?>
                <ul class="touches">
                    <?php foreach ($visible as $t): ?>
                        <?php require __DIR__ . '/touch.php' ?>
                    <?php endforeach ?>
                </ul>
                <?php if (count($visible) < count($e->touches)): ?>
                    <p class="more muted">+<?= h(count($e->touches) - count($visible)) ?> more — click for details</p>
                <?php endif ?>
            <?php endif ?>
        </li>
    <?php endforeach ?>
</ol>
