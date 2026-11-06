<?php
/**
 * Agenci (trasa /agents): sesje w zakresie projektów, w każdym wierszu kafelki agentów — model (kolor persony), nazwa agenta,
 * stan jednym słowem, tokeny z całej sesji i koszt; klik = zakładka Flow sesji. Aktywne sesje na górze, potem najnowsze.
 * Dane: wspólny feed GET /feed.json przez VizFeed co 1 s; rysuje public/assets/agents.js (DOM przez textContent).
 *
 * Sygnatura:
 * < viz-agents viz-agents-url>
 *     < header.live-top>  < .live-verdict viz-agents-verdict>  < button viz-density aria-pressed>  < nav.scope-switch>  < span viz-agents-status>
 *     < .agents-list viz-agents-list>            # wiersz = sesja (< a.agents-session>), kafelek = agent (< a.agents-tile data-persona data-state>)
 *     < p.live-foot>
 *     < s-init run="(el) => VizAgents.Init(el)">
 */
?>
<section class="live-board agents-board" viz-agents viz-agents-url="/feed.json">
    <header class="live-top">
        <strong><?= th('Agents') ?></strong>
        <span class="live-verdict" viz-agents-verdict></span>
        <button type="button" class="btn" viz-density title="<?= th('compact view: more sessions on one screen') ?>"><?= th('compact') ?></button>
        <?php require __DIR__ . '/scope-switch.php' ?>
        <span class="live-stale" viz-agents-status></span>
    </header>
    <div class="agents-list" viz-agents-list><p class="live-empty"><?= th('Loading…') ?></p></div>
    <p class="live-foot muted"><?= th('Sessions active in the last 30 min. Tile = agent: model, state, tokens of the whole session, cost. Click = session flow.') ?></p>
    <s-init run="(el) => VizAgents.Init(el)"></s-init>
</section>
