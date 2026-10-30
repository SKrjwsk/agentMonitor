<?php
/**
 * Przełącznik widoków przeglądowych jednej grupy (Viz\Web\ScopeViews::group): Live | Biuro 3D. Wspólny dla nagłówków tych widoków.
 *
 * @var array{name: string, menu: string, path: string} $scopeView bieżący widok
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
