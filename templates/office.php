<?php
/**
 * Virtual office (route /office): active agents of all sessions as Minecraft-style characters (three.js, public/assets/vendor, pinned version).
 * Data: shared feed GET /feed.json (Viz\Office\Feed) via VizFeed every 1 s, assembled in the browser into office state (fromFeed) (also right after a project scope change, event viz-scope).
 * A desk row = project; project colour (OfficeState::projectColor) = agents' shirts and the row carpet, head = model class (OfficeState::persona);
 * each session also has its own colour (a small square above the characters) and a place in the "Projects" legend on the left side; below it the static "Status" legend.
 *
 * Sygnatura:
 * < viz-office viz-office-url>
 *     < .office-top>                           # pasek i pod nim kolumna legend (lewy bok)
 *         < nav.scope-switch>                  # Live | 3D Office switch (templates/scope-switch.php)
 *         < viz-office-status>                 # status is empty, and on a refresh error shows "refresh not working"
 *         < viz-office-side>                   # legend column; its width shifts the scene to the right
 *             < details viz-office-projects>   # collapsed by default (localStorage `viz-office-projects`)
 *             < viz-office-legend>             # Projects: line "head = model · shirt = project", then < viz-office-project> project colour and name (= desk row), below it < a viz-office-session href="/?s=…"> square, title, agent count
 *             < details viz-office-status-legend>   # Status, collapsed by default (localStorage `viz-office-status`): state colours, behaviours at the boundary, coins (value from OfficeState::COIN_USD), envelopes
 *     < viz-office-canvas onpointer…="VizOffice.Down|Move|Up(this, event)" onwheel="VizOffice.Wheel(this, event)">   # renderer three.js;
 *                                              # left button = rotate, right / Shift = pan; camera in localStorage (`viz-office-camera`)
 *     < viz-office-labels>                     # podpisy nad postaciami < a viz-office-label> (href="/?s=…", textContent)
 *     < viz-office-empty hidden>               # no active agents
 *     < s-init run="(el) => import(…office.js).then(…)">
 */
?>
<section class="office" viz-office viz-office-url="/feed.json">
    <div class="office-top">
    <div class="office-bar">
        <span class="muted"><?= th('only active agents · drag = rotate · right-drag / shift-drag = move · wheel = zoom') ?></span>
        <span class="office-bar-sep"></span>
        <?php require __DIR__ . '/scope-switch.php' ?>
        <span class="muted" viz-office-status></span>
    </div>
    <div class="office-side" viz-office-side>
        <details class="office-legend office-projects" viz-office-projects>
            <summary><h3><?= th('Projects') ?></h3></summary>
            <p class="muted" viz-office-legend-note><?= th('head = model · shirt = project') ?></p>
            <nav viz-office-legend></nav>
        </details>
        <details class="office-legend" viz-office-status-legend>
            <summary><h3><?= th('Status') ?></h3></summary>
            <span class="office-legend-item"><i class="office-dot" viz-office-state="working"></i><span><?= th('working') ?></span><span class="muted"><?= th('also waiting for your permission') ?></span></span>
            <span class="office-legend-item"><i class="office-dot" viz-office-state="delegated"></i><span><?= th('waiting for subagents') ?></span></span>
            <span class="office-legend-item"><i class="office-dot" viz-office-state="idle"></i><span><?= th('waiting for you') ?></span><span class="muted"><?= th('hand up') ?></span></span>
            <span class="office-legend-item"><i></i><span><?= th('climbing the wall') ?></span><span class="muted"><?= th('blocked outside the boundary') ?></span></span>
            <span class="office-legend-item"><i></i><span><?= th('digging on the grass') ?></span><span class="muted"><?= th('worked outside the boundary') ?></span></span>
            <span class="office-legend-item"><i class="office-dot" style="background: #f5c542"></i><span><?= th('coins') ?></span><span class="muted"><?= th('1 coin = ${usd} spent', ['usd' => rtrim(rtrim(number_format(\Viz\Office\OfficeState::COIN_USD, 4, '.', ''), '0'), '.')]) ?></span></span>
            <span class="office-legend-item"><i class="office-dot" style="background: #f4f1e6"></i><span><?= th('envelope') ?></span><span class="muted"><?= th('task, result or message between agents') ?></span></span>
        </details>
    </div>
    </div>
    <div class="office-canvas" viz-office-canvas
         onpointerdown="VizOffice.Down(this, event)" onpointermove="VizOffice.Move(this, event)"
         onpointerup="VizOffice.Up(this)" onpointercancel="VizOffice.Up(this)" onwheel="VizOffice.Wheel(this, event)"
         oncontextmenu="return false"></div>
    <div class="office-labels" viz-office-labels></div>
    <p class="office-empty" viz-office-empty hidden><?= th('No active agents right now.') ?></p>
    <s-init run="(el) => import('/assets/office.js').then(() => VizOffice.Init(el))"></s-init>
</section>
