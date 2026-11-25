<?php
/**
 * Switch of overview views of one group (Viz\Web\ScopeViews::group): Live | 3D Office. Shared by the headers of these views.
 *
 * @var array{name: string, menu: string, path: string} $scopeView current view
 *
 * Sygnatura:
 * < nav.scope-switch>  < a href="/live" viz-current?> …
 */

use Viz\Web\ScopeViews;

?>
<nav class="scope-switch" aria-label="<?= th('view') ?>">
    <?php foreach (ScopeViews::group($scopeView['menu']) as $sv): ?>
        <a href="<?= h($sv['path']) ?>" <?= $sv['path'] === $scopeView['path'] ? 'viz-current aria-current="page"' : '' ?> title="<?= h(t($sv['title'])) ?>"><?= h($sv['icon']) ?> <?= h(t($sv['label'])) ?></a>
    <?php endforeach ?>
</nav>
