<?php
/**
 * Zakładka Flow w szczegółach sesji (`view=flow`): przepływ pracy sesji w stylu diagramu terminalowego — z lewej kluczowe momenty,
 * w środku sesja główna, mix narzędzi, karty subagentów i powrót do sesji głównej, pod spodem log wywołań i wiadomości.
 * Dane: cała sesja w kształcie wiersza feedu (`LiveState::session(..., whole: true)`), osadzone jako JSON; odświeża je podmiana
 * fragmentu sesji (Hx). Rysuje public/assets/flow.js (DOM przez textContent). Filtry agenta i tury tej zakładki nie dotyczą.
 *
 * Sygnatura:
 * < viz-flow>
 *     < script type="application/json" viz-flow-data>   # wiersz sesji (agenci z wywołaniami, wiadomości) + `now`
 *     < .flow-legend viz-flow-legend>            # klucz kolorów: model (liczba agentów)
 *     < .flow-grid>
 *         < aside.flow-moments viz-flow-moments>  # kluczowe momenty (delegowanie, wynik, ten sam błąd ≥ 2×, blokada) + liczniki
 *         < .flow-diagram viz-flow-diagram>       # sesja główna → mix narzędzi → subagenci → powrót
 *     < .flow-log viz-flow-log>                  # ostatnie wywołania i wiadomości
 *     < .flow-statusline viz-flow-statusline>    # subagenci [działa/razem] i koszt sesji
 *     < p.live-foot>
 *     < s-init run="(el) => VizFlow.Init(el)">
 *
 * @var array $view
 */

$flow = $view['flow'] === null ? null : $view['flow'] + ['now' => time()];
?>
<section class="flow-board" viz-flow>
    <script type="application/json" viz-flow-data><?= json_encode($flow, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <div class="flow-legend" viz-flow-legend></div>
    <div class="flow-grid">
        <aside class="flow-moments" viz-flow-moments></aside>
        <div class="flow-diagram" viz-flow-diagram><p class="live-empty"><?= th('Loading…') ?></p></div>
    </div>
    <div class="flow-log" viz-flow-log></div>
    <div class="flow-statusline" viz-flow-statusline></div>
    <p class="live-foot muted"><?= th('Main session delegates to subagents and takes their results back. Bar on a card = context window used. Tool mix = share of tool groups in all calls. Whole session; agent and turn filters do not apply.') ?></p>
    <s-init run="(el) => VizFlow.Init(el)"></s-init>
</section>
