<?php
/**
 * Graf w stylu Obsidiana: force-graph (public/assets/vendor, wersja przypięta). Dane: GET /graph.json z filtrami widoku.
 *
 * Sygnatura:
 * < viz-graph viz-graph-url viz-graph-key viz-graph-logs>
 *     < viz-graph-hidden>                      # liczba ukrytych węzłów (listing/szukanie)
 *     < viz-graph-canvas>                      # tu montuje się force-graph (canvas)
 *     < viz-graph-info hidden>                 # panel zaznaczonego węzła
 *         < viz-graph-info-title> < viz-graph-info-path> < viz-graph-info-ops> < a viz-graph-info-link>
 *     < s-init run="(el) => VizGraph.Init(el)">
 *
 * @var array $view
 */

use Viz\Web\App;

$p = $view['p'];
?>
<div class="graph" viz-graph
     viz-graph-url="/graph.json?<?= h(App::query($view)) ?>"
     viz-graph-key="<?= h(implode('|', [$view['sid'], $view['agentFilter'], $p['origin'], (int) $p['all'], (int) $p['dirs']])) ?>"
     viz-graph-logs="/?<?= h(App::query($view, ['view' => ''])) ?>">
    <div class="graph-bar">
        <span><i class="gdot" viz-origin="context"></i><?= th('auto-injected context') ?></span>
        <span><i class="gdot" viz-origin="self"></i><?= th('read by agent') ?></span>
        <span><i class="gdot" viz-origin="both"></i><?= th('both') ?></span>
        <span><i class="gdot" viz-origin="mod"></i><?= th('modified') ?></span>
        <?php if ($p['all']): ?><span><i class="gdot" viz-origin="other"></i><?= th('listing/search') ?></span><?php endif ?>
        <span><i class="gdot" viz-origin="agent"></i><?= th('agent') ?></span>
        <?php if ($p['dirs']): ?><span><i class="gdot" viz-origin="dir"></i><?= th('folder (area)') ?></span><?php endif ?>
        <span class="sep"></span>
        <a href="/?<?= h(App::query($view, ['dirs' => $p['dirs'] ? '0' : ''])) ?>"><?= $p['dirs'] ? '☑' : '☐' ?> <?= th('folders') ?></a>

        <a href="/?<?= h(App::query($view, ['all' => $p['all'] ? '' : '1'])) ?>"><?= $p['all'] ? '☑' : '☐' ?> <?= th('listing and search') ?></a>
        <span class="muted"><?= th('hidden') ?>: <span viz-graph-hidden>…</span></span>
        <span class="muted"><?= th('click = details and link to file calls · wheel = zoom (file labels when zoomed in) · drag') ?></span>
    </div>
    <div class="graph-canvas" viz-graph-canvas></div>
    <aside class="graph-info" viz-graph-info hidden>
        <strong viz-graph-info-title></strong>
        <div class="muted" viz-graph-info-path></div>
        <div viz-graph-info-ops></div>
        <div class="muted" viz-graph-info-agents></div>
        <a viz-graph-info-link hidden><?= th('show file calls →') ?></a>
    </aside>
    <s-init run="(el) => VizGraph.Init(el)"></s-init>
</div>
