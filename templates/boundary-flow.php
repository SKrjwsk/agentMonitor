<?php
/**
 * Przepływ granicy (trasa /boundary-flow): wykres Sankeya — z lewej sesje w zakresie projektów, z prawej strefy granicy
 * (odczyt i zapis, tylko odczyt), wykonane poza granicą, zatrzymane i nieustalone ścieżki; grubość wstęgi = operacje na plikach
 * z ostatnich 30 min (nieustalone = wywołania Bash). Dane: wspólny feed GET /feed.json przez VizFeed co 1 s;
 * rysuje public/assets/boundary-flow.js (SVG, bez bibliotek; podpisy i podpowiedzi przez textContent).
 *
 * Sygnatura:
 * < viz-bflow viz-bflow-url>
 *     < header.live-top>  < .live-verdict viz-bflow-verdict data-sev>  < nav.scope-switch>  < span viz-bflow-status>
 *     < .bflow-chart viz-bflow-chart>           # SVG; najechanie na wstęgę albo węzeł = podświetlenie i podpowiedź
 *     < .bflow-tip viz-bflow-tip hidden>
 *     < p.live-foot>
 *     < s-init run="(el) => VizBFlow.Init(el)">
 */
?>
<section class="live-board bflow-board" viz-bflow viz-bflow-url="/feed.json">
    <header class="live-top">
        <strong><?= th('Boundary flow') ?></strong>
        <span class="live-verdict" viz-bflow-verdict></span>
        <?php require __DIR__ . '/scope-switch.php' ?>
        <span class="live-stale" viz-bflow-status></span>
    </header>
    <div class="bflow-chart" viz-bflow-chart><p class="live-empty"><?= th('Loading…') ?></p></div>
    <div class="bflow-tip" viz-bflow-tip hidden></div>
    <p class="live-foot muted"><?= th('Left: sessions in scope. Right: where their work went in the last 30 min. Band width = file operations (unknown paths = Bash calls).') ?></p>
    <s-init run="(el) => VizBFlow.Init(el)"></s-init>
</section>
