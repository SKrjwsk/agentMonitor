<?php
/**
 * Agents (route /agents): sessions within the project scope, with agent tiles in each row — model (persona colour), agent name,
 * state in one word and cost (tokens of the whole session in the tooltip); click = the session's Flow tab. Active sessions on top, then newest.
 * With the hook: state "waiting for approval" (`waiting-permission`, also on top), "session ended" (`ended`), API error type on `stuck`.
 * Data: shared feed GET /feed.json via VizFeed every 1 s; drawn by public/assets/agents.js (DOM via textContent).
 *
 * Sygnatura:
 * < viz-agents viz-agents-url>
 *     < header.live-top>  < .live-verdict viz-agents-verdict>   < nav.scope-switch>  < span viz-agents-status>
 *     < .agents-list viz-agents-list>            # row = session (< a.agents-session>), tile = agent (< a.agents-tile data-persona data-state>)
 *     < .agents-orphans viz-agents-orphans>      # < details.agents-procs> "Orphaned processes (N)" collapsed by default, or "processes unavailable" (feed `processes`); under the project < details.agents-procs>
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
