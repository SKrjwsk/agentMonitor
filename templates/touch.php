<?php
/**
 * @var Viz\Model\Touch $t
 * @var array<string, string> $opLabels
 * @var ?string $cwd
 */

use Viz\Web\App;

?>
<li viz-op="<?= h($t->op) ?>" <?= $t->partial ? 'viz-partial' : '' ?> <?= $t->exists ? '' : 'viz-missing' ?>>
    <span class="op-tag"><?= h(t($opLabels[$t->op] ?? $t->op)) ?></span>
    <?= h(App::shortPath($t->path, $cwd)) ?><?= $t->range !== null ? ' <span class="range">[' . h($t->range) . ']</span>' : '' ?>
</li>
