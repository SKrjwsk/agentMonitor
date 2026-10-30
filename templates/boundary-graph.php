<?php
/**
 * Graf granicy (trasa /boundary-graph): agenci sesji w zakresie projektów ↔ katalogi, które dotknęli w ostatnich 30 min.
 * Katalogi skupione w obszarach stref (odczyt i zapis, tylko odczyt, poza granicą), wywołania bez ustalonych ścieżek — osobny węzeł.
 * Dane: wspólny feed GET /feed.json (`zones`, `sessions[].map`, `sessions[].boundary`, `sessions[].agents`) przez VizFeed co 1 s;
 * rysuje public/assets/boundary-graph.js (force-graph, ten sam co w widoku Graph sesji). Podpisy w canvasie, panel przez textContent.
 *
 * Sygnatura:
 * < viz-bgraph viz-bgraph-url>
 *     < header.live-top>  < .live-verdict viz-bgraph-verdict data-sev>  < nav.scope-switch>  < span viz-bgraph-status>
 *     < .bgraph-canvas viz-bgraph-canvas>       # canvas force-graph; klik w węzeł = zaznaczenie i panel, klik w tło = bez zaznaczenia
 *     < .bgraph-info viz-bgraph-info hidden>    # zaznaczony węzeł: agent (sesja, link), katalog (strefa, operacje, sesje), strefa
 *     < .bgraph-legend>
 *     < s-init run="(el) => VizBGraph.Init(el)">
 */
?>
<section class="live-board bgraph-board" viz-bgraph viz-bgraph-url="/feed.json">
    <header class="live-top">
        <strong><?= th('Boundary graph') ?></strong>
        <span class="live-verdict" viz-bgraph-verdict></span>
        <?php require __DIR__ . '/scope-switch.php' ?>
        <span class="live-stale" viz-bgraph-status></span>
    </header>
    <div class="bgraph-stage">
        <div class="bgraph-canvas" viz-bgraph-canvas></div>
        <div class="bgraph-info" viz-bgraph-info hidden></div>
        <p class="bgraph-empty live-empty" viz-bgraph-empty hidden></p>
        <div class="bgraph-legend">
            <span><i data-zone="rw"></i><?= th('read and write') ?></span>
            <span><i data-zone="r"></i><?= th('read only') ?></span>
            <span><i data-zone="out"></i><?= th('outside the boundary — executed') ?></span>
            <span><i data-zone="stopped"></i><?= th('outside the boundary — stopped') ?></span>
            <span><i data-zone="unknown"></i><?= th('paths not resolved') ?></span>
            <span class="muted"><?= th('big dot = agent (project colour), small = directory, size = operations') ?></span>
        </div>
    </div>
    <s-init run="(el) => VizBGraph.Init(el)"></s-init>
</section>
