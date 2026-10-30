<?php
/**
 * Live (trasa /live): tablica decyzji — wiersz = sesja w zakresie projektów, kolumna = pytanie, na które user może zareagować
 * („Ty”, „Granica”, „Kontekst”, „Tempo”). Dane: wspólny feed GET /feed.json (Viz\Office\Feed, LiveState) przez VizFeed co 1 s; zakres z ciasteczka viz-scope.
 * Klasy z prefiksem live-. Treść budowana w public/assets/live.js przez textContent.
 *
 * Sygnatura:
 * < viz-live viz-live-url>
 *     < header.live-top>  < .live-verdict viz-live-verdict data-sev>  < nav.scope-switch>  < span viz-live-status>   # status pusty; przy błędzie „refresh not working”
 *     < .live-sum>  < section>  < h2.live-label>  < .live-counts viz-live-you>     # liczniki = filtr tablicy
 *                   < section>  < h2.live-label>  < .live-counts viz-live-bound>
 *     < .live-table>  < .live-thead>  < div viz-live-rows>                          # wiersze, szczegóły pod wierszem, grupa „bez uwag”
 *     < p.live-foot>
 *     < s-init run="(el) => VizLive.Init(el)">
 */
?>
<section class="live-board" viz-live viz-live-url="/feed.json">
    <header class="live-top">
        <strong><?= th('Live') ?></strong>
        <span class="live-verdict" viz-live-verdict></span>
        <?php require __DIR__ . '/scope-switch.php' ?>
        <span class="live-stale" viz-live-status></span>
    </header>
    <div class="live-sum">
        <section>
            <h2 class="live-label"><?= th('You — where to step in') ?></h2>
            <div class="live-counts" viz-live-you></div>
        </section>
        <section>
            <h2 class="live-label"><?= th('Boundary — last 30 min, all sessions in scope') ?></h2>
            <div class="live-counts" viz-live-bound></div>
        </section>
    </div>
    <div class="live-table">
        <div class="live-thead"><div><?= th('Session') ?></div><div><?= th('You') ?></div><div><?= th('Boundary') ?></div><div><?= th('Context') ?></div><div><?= th('Pace') ?></div></div>
        <div viz-live-rows><p class="live-empty"><?= th('Loading…') ?></p></div>
    </div>
    <p class="live-foot muted"><?= th('Sessions active in the last 30 min. “permission?” = a call running longer than 90 s — usually a permission prompt, but the transcript cannot tell it from a long command. “Reviewed” is remembered only in this browser.') ?></p>
    <s-init run="(el) => VizLive.Init(el)"></s-init>
</section>
