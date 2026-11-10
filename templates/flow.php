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
 *     < details.flow-context viz-flow-context>  # „Kontekst wczytany (N)”: pliki instrukcji z hooka InstructionsLoaded (`HookLog::instructions`) —
 *                                                # czas, plik (względem cwd sesji, pełny w title), typ, powód (objaśnienie w title); zwinięta
 *                                                # (stan w localStorage `viz-flow-context`), bez zdarzeń — brak sekcji. Same ścieżki, bez treści plików
 *     < p.live-foot>
 *     < s-init run="(el) => VizFlow.Init(el)">
 *
 * @var array $view
 */

$flow = $view['flow'] === null ? null : $view['flow'] + ['now' => time()];
$loaded = $view['flowContext'] ?? [];
$cwd = $view['session']->cwd;
// powód wczytania (`load_reason` z dokumentacji hooków) → objaśnienie w podpowiedzi
$reasons = [
    'session_start' => t('loaded at session start'),
    'nested_traversal' => t('CLAUDE.md of a subdirectory, loaded when the agent worked on a file in it'),
    'path_glob_match' => t('rule with path globs, loaded when a matching file was touched'),
    'include' => t('imported by another instruction file (@path)'),
    'compact' => t('loaded again after context compaction'),
];
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
    <?php if ($loaded !== []): ?>
    <details class="flow-context" viz-flow-context>
        <summary><?= th('Context loaded ({n})', ['n' => count($loaded)]) ?></summary>
        <p class="muted"><?= th('Instruction files Claude Code put into the context at the start and during the session (agentMonitor hook InstructionsLoaded). The transcript shows only part of them.') ?></p>
        <table class="files flow-context-table">
            <thead><tr><th><?= th('time') ?></th><th><?= th('file') ?></th><th><?= th('type') ?></th><th><?= th('reason') ?></th></tr></thead>
            <tbody>
            <?php foreach ($loaded as $i): ?>
                <?php
                $why = array_filter([
                    $reasons[$i['reason']] ?? null,
                    $i['trigger'] !== null ? t('triggered by {p}', ['p' => $i['trigger']]) : null,
                    $i['parent'] !== null ? t('included from {p}', ['p' => $i['parent']]) : null,
                    $i['globs'] !== [] ? t('globs: {g}', ['g' => implode(', ', $i['globs'])]) : null,
                ]);
                ?>
                <tr<?= $i['agent'] !== \Viz\Model\Agent::MAIN ? ' title="' . th('subagent {a}', ['a' => $i['agent']]) . '"' : '' ?>>
                    <td class="nowrap"><?= h(\Viz\Clock::local($i['ts'], 'H:i:s')) ?></td>
                    <td title="<?= h($i['file']) ?>"><?= h(\Viz\Web\App::shortPath($i['file'], $cwd)) ?></td>
                    <td><?= h($i['memoryType'] ?? '?') ?></td>
                    <td title="<?= h(implode("\n", $why)) ?>"><?= h($i['reason'] ?? '?') ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </details>
    <?php endif ?>
    <p class="live-foot muted"><?= th('Main session delegates to subagents and takes their results back. Bar on a card = context window used. Tool mix = share of tool groups in all calls. Whole session; agent and turn filters do not apply.') ?></p>
    <s-init run="(el) => VizFlow.Init(el)"></s-init>
</section>
