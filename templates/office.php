<?php
/**
 * Wirtualne biuro (trasa /office): aktywni agenci wszystkich sesji jako postacie w stylu Minecrafta (three.js, public/assets/vendor, wersja przypięta).
 * Dane: GET /office.json (OfficeState::merge), pobierane co 1 s i przyciskiem „↻ refresh”.
 * Rząd biurek = projekt; kolor projektu (OfficeState::projectColor) = koszulki agentów i dywan rzędu, głowa = klasa modelu (OfficeState::persona);
 * każda sesja ma jeszcze własny kolor (kwadracik nad postaciami) i pozycję w legendzie „Projects” na lewym boku; pod nią statyczna legenda „Status”.
 *
 * Sygnatura:
 * < viz-office viz-office-url>
 *     < .office-top>                           # pasek i pod nim kolumna legend (lewy bok)
 *         < button onclick="VizOffice.Refresh(this)">  < viz-office-status>   # ręczne odświeżenie; status jest pusty, a przy błędzie odświeżania pokazuje „refresh not working”
 *         < viz-office-side>                   # kolumna legend; jej szerokość przesuwa scenę w prawo
 *             < viz-office-legend>             # Projects: linia „head = model · shirt = project”, potem < viz-office-project> kolor projektu i nazwa (= rząd biurek), pod nią < a viz-office-session href="/?s=…"> kwadracik, tytuł, liczba agentów
 *             < .office-legend>                # Status: kolory stanów, zachowania przy granicy, monety (wartość z OfficeState::COIN_USD), koperty
 *     < viz-office-canvas onpointer…="VizOffice.Down|Move|Up(this, event)" onwheel="VizOffice.Wheel(this, event)">   # renderer three.js;
 *                                              # lewy przycisk = obrót, prawy / Shift = przesuwanie; kamera w localStorage (`viz-office-camera`)
 *     < viz-office-labels>                     # podpisy nad postaciami < a viz-office-label> (href="/?s=…", textContent)
 *     < viz-office-empty hidden>               # brak aktywnych agentów
 *     < s-init run="(el) => import(…office.js).then(…)">
 */
?>
<section class="office" viz-office viz-office-url="/office.json">
    <div class="office-top">
    <div class="office-bar">
        <span class="muted"><?= th('only active agents · drag = rotate · right-drag / shift-drag = move · wheel = zoom') ?></span>
        <span class="office-bar-sep"></span>
        <span class="muted" viz-office-status></span>
        <button type="button" class="btn" onclick="VizOffice.Refresh(this)">↻ <?= th('refresh') ?></button>
    </div>
    <div class="office-side" viz-office-side>
        <section class="office-legend office-projects">
            <h3><?= th('Projects') ?></h3>
            <p class="muted" viz-office-legend-note><?= th('head = model · shirt = project') ?></p>
            <nav viz-office-legend></nav>
        </section>
        <section class="office-legend">
            <h3><?= th('Status') ?></h3>
            <span class="office-legend-item"><i class="office-dot" viz-office-state="working"></i><span><?= th('working') ?></span><span class="muted"><?= th('also waiting for your permission') ?></span></span>
            <span class="office-legend-item"><i class="office-dot" viz-office-state="delegated"></i><span><?= th('waiting for subagents') ?></span></span>
            <span class="office-legend-item"><i class="office-dot" viz-office-state="idle"></i><span><?= th('waiting for you') ?></span><span class="muted"><?= th('hand up') ?></span></span>
            <span class="office-legend-item"><i></i><span><?= th('climbing the wall') ?></span><span class="muted"><?= th('blocked outside the boundary') ?></span></span>
            <span class="office-legend-item"><i></i><span><?= th('digging on the grass') ?></span><span class="muted"><?= th('worked outside the boundary') ?></span></span>
            <span class="office-legend-item"><i class="office-dot" style="background: #f5c542"></i><span><?= th('coins') ?></span><span class="muted"><?= th('1 coin = ${usd} spent', ['usd' => rtrim(rtrim(number_format(\Viz\Office\OfficeState::COIN_USD, 4, '.', ''), '0'), '.')]) ?></span></span>
            <span class="office-legend-item"><i class="office-dot" style="background: #f4f1e6"></i><span><?= th('envelope') ?></span><span class="muted"><?= th('task, result or message between agents') ?></span></span>
        </section>
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
