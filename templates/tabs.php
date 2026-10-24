<?php
/**
 * Zakładki wspólne dla obu zakresów. W zakresie „all” widoki wymagające pojedynczych wywołań są wyłączone.
 *
 * @var array $view @var array $p @var bool $all @var array<string, string> $tabs @var callable $tab
 */

use Viz\Web\App;

$labels = ['timeline' => 'Timeline', 'graph' => 'Graph', 'log' => 'Log'];
$off = static fn (string $v): bool => $all && !in_array($v, App::ALL_VIEWS, true);
?>
<nav class="tabs">
    <?php foreach ($tabs as $v => $label): ?>
        <?php if ($off($v)): ?>
            <span class="tab-off" title="<?= th('only for a single session — pick one from the list') ?>"><?= th($label) ?></span>
        <?php else: ?>
            <a href="<?= h($tab($v)) ?>" <?= $p['view'] === $v ? 'viz-current' : '' ?>><?= th($label) ?> <span class="n"><?= h($view['counts'][$v]) ?></span></a>
        <?php endif ?>
    <?php endforeach ?>
    <span class="tabs-sep"></span>
    <?php foreach ($labels as $v => $label): ?>
        <?php if ($off($v)): ?>
            <span class="tab-off tab-extra" title="<?= th('only for a single session — pick one from the list') ?>"><?= th($label) ?></span>
        <?php else: ?>
            <a class="tab-extra" href="<?= h($tab($v)) ?>" <?= $p['view'] === $v ? 'viz-current' : '' ?>><?= th($label) ?></a>
        <?php endif ?>
    <?php endforeach ?>
</nav>
