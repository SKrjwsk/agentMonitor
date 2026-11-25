<?php
/**
 * Work timeline (route /lanes): agent lanes of all sessions in scope on a common axis of the last 30 min ("now" at the right edge).
 * Row = session, below it a lane per agent (main session, then subagents by start); bar = tool call in the colour of its kind
 * (click = that call in the session log), outline = block / error / out of bounds; approval request from the hook = "waiting for approval" (hatched from the request),
 * without the approval hook a call > 90 s = "approval?" (hatched after the threshold); closed session (SessionEnd hook) = "session ended";
 * hatching = main session waits for you (grey = past); arrows = tasks and results of subagents, messages between sessions;
 * dashed line on the main session lane = context compaction (PreCompact / PostCompact hook).
 * At the end of the lane: state, time in state, files read / changed and Bash commands from the window, cost, current call. Row order
 * fixed (project, main session start). Data: shared feed GET /feed.json via VizFeed every 1 s; drawn by public/assets/lanes.js
 * (DOM via textContent). Click a session or a lane outside a bar = the session's Flow tab. Legend at the bottom, collapsed (state in localStorage `viz-lanes-legend`).
 *
 * Sygnatura:
 * < viz-lanes viz-lanes-url>
 *     < header.live-top>  < .live-verdict viz-lanes-verdict>   < nav.scope-switch>  < span viz-lanes-status>
 *     < .lanes-scale viz-lanes-scale>            # scale every 5 min
 *     < .lanes-list viz-lanes-list>              # < .lanes-session> + < a.lanes-lane data-persona data-state> z < .lanes-track data-agent> i < .lanes-end>
 *     < section.lanes-mcp viz-lanes-mcp>         # latest mcp__* calls from the window: server, tool, time, status (hidden without such calls)
 *     < details.lanes-legend-box viz-lanes-legend>  < summary.btn>  < .lanes-legend>   # groups: calls, You, communication, lane end
 *     < s-init run="(el) => VizLanes.Init(el)">
 */
// legend item: glyph (HTML from constants), name and description already translated by th()
$item = static fn (string $icon, string $name, string $text): string => '<li>' . $icon . '<b>' . $name . '</b><span>' . $text . '</span></li>';
$key = static fn (string $kind): string => '<span class="lanes-tally"><span data-kind="' . $kind . '">n</span></span>';
?>
<section class="live-board lanes-board" viz-lanes viz-lanes-url="/feed.json">
    <header class="live-top">
        <strong><?= th('Work axis') ?></strong>
        <span class="live-verdict" viz-lanes-verdict></span>
        <?php require __DIR__ . '/scope-switch.php' ?>
        <span class="live-stale" viz-lanes-status></span>
    </header>
    <div class="lanes-scale" viz-lanes-scale></div>
    <div class="lanes-list" viz-lanes-list><p class="live-empty"><?= th('Loading…') ?></p></div>
    <section class="lanes-mcp" viz-lanes-mcp hidden>
        <h3><?= th('MCP calls — latest in scope') ?></h3>
        <ul viz-lanes-mcp-list></ul>
    </section>
    <details class="lanes-legend-box" viz-lanes-legend>
    <summary class="btn"><?= th('legend') ?></summary>
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
                <?= $item('<i class="lanes-ask"></i>', th('waiting for permission'), th('with the agentMonitor hook: Claude Code asked for your approval (PermissionRequest or permission notification), hatched from the request')) ?>
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
                <?= $item('<i class="lanes-arrow-key" data-kind="peer"></i>', th('other session'), th('arrow in text colour: message to another session in view')) ?>
                <?= $item('<i class="lanes-msg" data-kind="send"></i>', th('message'), th('tick in text colour: message to or from a session outside the view')) ?>
                <?= $item('<i class="lanes-compact"></i>', th('compaction'), th('dashed line on the main session lane: context compacted, manual (/compact) or automatic; with the agentMonitor hook')) ?>
            </ul>
        </section>
        <section>
            <h3><?= th('End of the lane') ?></h3>
            <ul>
                <?= $item('', th('model'), th('start of the lane: ○ Haiku, ◐ Sonnet, ● Opus — a grey shade, not a colour (colour = tool kind)')) ?>
                <?= $item('', th('state · time'), th('what the agent does now and for how long')) ?>
                <?= $item('', th('session ended'), th('with the agentMonitor hook: the session was closed (exit, /clear, /resume); reason on hover')) ?>
                <?= $item($key('read'), th('files read'), th('distinct files read in the last 30 min')) ?>
                <?= $item($key('edit'), th('files changed'), th('distinct files edited or written in the last 30 min')) ?>
                <?= $item($key('exec'), 'Bash', th('shell commands run in the last 30 min')) ?>
                <?= $item('', '$', th('cost since the session start at API prices (with a subscription: for comparison); hover = last 30 min')) ?>
            </ul>
        </section>
        <p class="muted"><?= th('Last 30 min, now on the right. Up to 200 newest calls per agent. One lane per agent under the session header, with counters and cost of the whole session.') ?></p>
    </div>
    </details>
    <s-init run="(el) => VizLanes.Init(el)"></s-init>
</section>
