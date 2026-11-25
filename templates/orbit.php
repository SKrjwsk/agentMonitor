<?php
/**
 * Orbit (route /orbit): agents of all sessions in scope as one node in the middle, around it the equipment used in the last 30 min —
 * MCP servers, skills, subagents and groups of built-in tools. Satellite size = number of uses in the window, red outline = calls that ended with an error, the line from the centre
 * brightens on use in the last 10 s, a new call flies as a pulse to the satellite. Click a satellite = list of its latest calls
 * (click a call = session log). Data: shared feed GET /feed.json via VizFeed every 1 s; drawn by public/assets/orbit.js
 * (SVG, DOM via textContent). Satellite layout is fixed (kind, name), so they do not jump on the 1 s refresh.
 *
 * Sygnatura:
 * < viz-orbit viz-orbit-url>
 *     < header.live-top>  < .live-verdict viz-orbit-verdict>   < nav.scope-switch>  < span viz-orbit-status>
 *     < .orbit-stage viz-orbit-stage>            # < svg> with the centre node and < g.orbit-node data-kind data-status data-slot> per satellite
 *     < .orbit-detail viz-orbit-detail>          # list of the selected satellite's latest calls (< li data-ev data-s>)
 *     < p.live-foot>
 *     < s-init run="(el) => VizOrbit.Init(el)">
 */
?>
<section class="live-board orbit-board" viz-orbit viz-orbit-url="/feed.json">
    <header class="live-top">
        <strong><?= th('Orbit') ?></strong>
        <span class="live-verdict" viz-orbit-verdict></span>
        <?php require __DIR__ . '/scope-switch.php' ?>
        <span class="live-stale" viz-orbit-status></span>
    </header>
    <div class="orbit-stage" viz-orbit-stage><p class="live-empty"><?= th('Loading…') ?></p></div>
    <div class="orbit-detail" viz-orbit-detail hidden></div>
    <p class="live-foot muted"><?= th('Tools and equipment used in the last 30 min: built-in tool groups, MCP servers, skills, subagents. Size = uses, red outline = errors. Click = latest calls.') ?></p>
    <s-init run="(el) => VizOrbit.Init(el)"></s-init>
</section>
