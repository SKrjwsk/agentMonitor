<?php
/**
 * Flow (trasa /flow): przepływ pracy jednej sesji w stylu diagramu terminalowego — sesja główna u góry, pod nią karty subagentów
 * (model, stan, bieżące wywołanie, kontekst, koszt), powrót wyników do sesji głównej i log ostatnich wywołań oraz wiadomości.
 * Sesję wybiera się z listy aktywnych w zakresie projektów (domyślnie najnowsza). Dane: wspólny feed GET /feed.json przez VizFeed
 * co 1 s; rysuje public/assets/flow.js (DOM przez textContent).
 *
 * Sygnatura:
 * < viz-flow viz-flow-url>
 *     < header.live-top>  < .live-verdict viz-flow-verdict data-sev>  < nav.scope-switch>  < span viz-flow-status>
 *     < nav.flow-tabs viz-flow-tabs>             # wybór sesji
 *     < .flow-diagram viz-flow-diagram>          # sesja główna → subagenci → powrót
 *     < .flow-log viz-flow-log>                  # ostatnie wywołania i wiadomości
 *     < p.live-foot>
 *     < s-init run="(el) => VizFlow.Init(el)">
 */
?>
<section class="live-board flow-board" viz-flow viz-flow-url="/feed.json">
    <header class="live-top">
        <strong><?= th('Flow') ?></strong>
        <span class="live-verdict" viz-flow-verdict></span>
        <?php require __DIR__ . '/scope-switch.php' ?>
        <span class="live-stale" viz-flow-status></span>
    </header>
    <nav class="flow-tabs" viz-flow-tabs></nav>
    <div class="flow-diagram" viz-flow-diagram><p class="live-empty"><?= th('Loading…') ?></p></div>
    <div class="flow-log" viz-flow-log></div>
    <p class="live-foot muted"><?= th('Main session delegates to subagents and takes their results back. Bar = context window used. Log: calls and messages from the last 30 min.') ?></p>
    <s-init run="(el) => VizFlow.Init(el)"></s-init>
</section>
