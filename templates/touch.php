<?php
/**
 * @var Viz\Model\Touch $t
 * @var array<string, string> $opLabels
 * @var ?string $base  korzeń skracania ścieżek (log.php: App::pathRoot albo katalog sesji)
 */

use Viz\Web\App;

?>
<li viz-op="<?= h($t->op) ?>" <?= $t->partial ? 'viz-partial' : '' ?> <?= $t->exists ? '' : 'viz-missing' ?> title="<?= h($t->path) ?>">
    <span class="op-tag"><?= h(t($opLabels[$t->op] ?? $t->op)) ?></span>
    <?= h(App::shortPath($t->path, $base)) ?><?= $t->range !== null ? ' <span class="range">[' . h($t->range) . ']</span>' : '' ?>
</li>
