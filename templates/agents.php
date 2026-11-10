<?php
/**
 * Agenci (trasa /agents): sesje w zakresie projektów, w każdym wierszu kafelki agentów — model (kolor persony), nazwa agenta,
 * stan jednym słowem i koszt (tokeny z całej sesji w podpowiedzi); klik = zakładka Flow sesji. Aktywne sesje na górze, potem najnowsze.
 * Z hookiem: stan „czeka na zgodę” (`waiting-permission`, też na górze), „sesja zakończona” (`ended`), typ błędu API przy `stuck`.
 * Dane: wspólny feed GET /feed.json przez VizFeed co 1 s; rysuje public/assets/agents.js (DOM przez textContent).
 *
 * Sygnatura:
 * < viz-agents viz-agents-url>
 *     < header.live-top>  < .live-verdict viz-agents-verdict>   < nav.scope-switch>  < span viz-agents-status>
 *     < .agents-list viz-agents-list>            # wiersz = sesja (< a.agents-session>), kafelek = agent (< a.agents-tile data-persona data-state>)
 *     < .agents-orphans viz-agents-orphans>      # < details.agents-procs> „Osierocone procesy (N)” zwinięte domyślnie albo „procesy niedostępne” (feed `processes`); pod projektem < details.agents-procs>
 *     < p.live-foot>
 *     < s-init run="(el) => VizAgents.Init(el)">
 */
?>
<section class="live-board agents-board" viz-agents viz-agents-url="/feed.json">
    <header class="live-top">
        <strong><?= th('Agents') ?></strong>
        <span class="live-verdict" viz-agents-verdict></span>
        <?php require __DIR__ . '/scope-switch.php' ?>
        <span class="live-stale" viz-agents-status></span>
    </header>
    <div class="agents-list" viz-agents-list><p class="live-empty"><?= th('Loading…') ?></p></div>
    <div class="agents-orphans" viz-agents-orphans></div>
    <p class="live-foot muted"><?= th('Sessions active in the last 30 min. Tile = agent: model, state, cost; tokens of the whole session on hover. Click = session flow.') ?></p>
    <s-init run="(el) => VizAgents.Init(el)"></s-init>
</section>
