<?php
/**
 * Oś pracy (trasa /lanes): tory agentów wszystkich sesji w zakresie na wspólnej osi ostatnich 30 min („teraz” przy prawej krawędzi).
 * Wiersz = sesja, pod nim tor na agenta (sesja główna, potem subagenci według startu); pasek = wywołanie narzędzia w kolorze rodzaju
 * (klik = to wywołanie w logu sesji), obrys = blokada / błąd / poza granicą; wywołanie > 90 s = „zgoda?” (po progu zakreskowane);
 * zakreskowanie = sesja główna czeka na Ciebie (szare = minione); strzałki = zadania i wyniki subagentów, wiadomości między sesjami.
 * Na końcu toru stan, czas w stanie, pliki odczytane / zmienione i komendy Bash z okna, koszt, bieżące wywołanie. Kolejność wierszy
 * stała (projekt, start sesji głównej). Dane: wspólny feed GET /feed.json przez VizFeed co 1 s; rysuje public/assets/lanes.js
 * (DOM przez textContent). Klik w sesję albo tor poza paskiem = zakładka Flow sesji. Legenda na dole.
 *
 * Sygnatura:
 * < viz-lanes viz-lanes-url>
 *     < header.live-top>  < .live-verdict viz-lanes-verdict>  < button viz-density aria-pressed>  < nav.scope-switch>  < span viz-lanes-status>
 *     < .lanes-scale viz-lanes-scale>            # podziałka co 5 min
 *     < .lanes-list viz-lanes-list>              # < .lanes-session> + < a.lanes-lane data-persona data-state> z < .lanes-track data-agent> i < .lanes-end>
 *     < .lanes-legend>                          # grupy: wywołania, Ty, komunikacja, koniec toru
 *     < s-init run="(el) => VizLanes.Init(el)">
 */
// pozycja legendy: znak (HTML ze stałych), nazwa i opis już przetłumaczone przez th()
$item = static fn (string $icon, string $name, string $text): string => '<li>' . $icon . '<b>' . $name . '</b><span>' . $text . '</span></li>';
$key = static fn (string $kind): string => '<span class="lanes-tally"><span data-kind="' . $kind . '">n</span></span>';
?>
<section class="live-board lanes-board" viz-lanes viz-lanes-url="/feed.json">
    <header class="live-top">
        <strong><?= th('Work axis') ?></strong>
        <span class="live-verdict" viz-lanes-verdict></span>
        <button type="button" class="btn" viz-density title="<?= th('compact view: more sessions on one screen') ?>"><?= th('compact') ?></button>
        <?php require __DIR__ . '/scope-switch.php' ?>
        <span class="live-stale" viz-lanes-status></span>
    </header>
    <div class="lanes-scale" viz-lanes-scale></div>
    <div class="lanes-list" viz-lanes-list><p class="live-empty"><?= th('Loading…') ?></p></div>
    <div class="lanes-legend">
        <section>
            <h3><?= th('Tool calls — bar colour, click = the call in the session log') ?></h3>
            <ul>
                <?= $item('<i class="lanes-call" data-kind="read"></i>', th('read'), th('Read — file read')) ?>
                <?= $item('<i class="lanes-call" data-kind="search"></i>', th('search'), th('Grep, Glob, WebSearch, WebFetch')) ?>
                <?= $item('<i class="lanes-call" data-kind="edit"></i>', th('edit'), th('Edit, Write — file changed')) ?>
                <?= $item('<i class="lanes-call" data-kind="exec"></i>', 'Bash', th('shell command')) ?>
                <?= $item('<i class="lanes-call" data-kind="agent"></i>', th('delegate'), th('Agent, SendMessage — work handed to another agent')) ?>
                <?= $item('<i class="lanes-call" data-kind="other"></i>', th('other'), th('MCP, browser and the rest')) ?>
                <?= $item('<i class="lanes-call" data-kind="other" data-status="blocked"></i>', th('blocked'), th('red outline: blocked by a hook or rule, or outside the boundary')) ?>
                <?= $item('<i class="lanes-call" data-kind="other" data-status="failed"></i>', th('failed'), th('orange outline: the call did not run or ended with an error')) ?>
            </ul>
        </section>
        <section>
            <h3><?= th('You — yellow') ?></h3>
            <ul>
                <?= $item('<i class="lanes-wait"></i>', th('waiting for you'), th('the main session finished and waits for your prompt')) ?>
                <?= $item('<i class="lanes-ask"></i>', th('permission?'), th('a call running over 90 s — usually your approval, the transcript cannot tell it from a long command')) ?>
                <?= $item('<i class="lanes-msg" data-kind="prompt"></i>', th('prompt'), th('your message to the session')) ?>
                <?= $item('<i class="lanes-waited"></i>', th('past wait'), th('grey: the session waited for you here earlier')) ?>
            </ul>
        </section>
        <section>
            <h3><?= th('Communication') ?></h3>
            <ul>
                <?= $item('<i class="lanes-arrow-key" data-kind="sub"></i>', th('subagent'), th('pink arrow: task from the main session to a subagent and its result back (detailed view)')) ?>
                <?= $item('<i class="lanes-msg" data-kind="task"></i>', th('task / result'), th('pink tick on the subagent lane')) ?>
                <?= $item('<i class="lanes-arrow-key" data-kind="peer"></i>', th('other session'), th('white arrow: message to another session in view')) ?>
                <?= $item('<i class="lanes-msg" data-kind="send"></i>', th('message'), th('white tick: message to or from a session outside the view')) ?>
            </ul>
        </section>
        <section>
            <h3><?= th('End of the lane') ?></h3>
            <ul>
                <?= $item('', th('state · time'), th('what the agent does now and for how long')) ?>
                <?= $item($key('read'), th('files read'), th('distinct files read in the last 30 min')) ?>
                <?= $item($key('edit'), th('files changed'), th('distinct files edited or written in the last 30 min')) ?>
                <?= $item($key('exec'), 'Bash', th('shell commands run in the last 30 min')) ?>
                <?= $item('', '$', th('cost since the session start at API prices (with a subscription: for comparison); hover = last 30 min')) ?>
            </ul>
        </section>
        <p class="muted"><?= th('Last 30 min, now on the right. Up to 200 newest calls per agent. Compact view: one row per session, subagents as thin lines under it, counters and cost of the whole session.') ?></p>
    </div>
    <s-init run="(el) => VizLanes.Init(el)"></s-init>
</section>
