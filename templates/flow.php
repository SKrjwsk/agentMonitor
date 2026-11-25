<?php
/**
 * Flow tab in session details (`view=flow`): the session's workflow in the style of a terminal diagram — key moments on the left,
 * in the middle the main session, tool mix, subagent cards and return to the main session, below the call log and messages.
 * Data: the whole session in the shape of a feed row (`LiveState::session(..., whole: true)`), embedded as JSON; refreshed by swapping
 * the session fragment (Hx). Drawn by public/assets/flow.js (DOM via textContent). The agent and turn filters do not apply to this tab.
 *
 * Sygnatura:
 * < viz-flow>
 *     < script type="application/json" viz-flow-data>   # session row (agents with calls, messages) + `now`
 *     < .flow-legend viz-flow-legend>            # colour key: model (agent count)
 *     < .flow-grid>
 *         < aside.flow-moments viz-flow-moments>  # key moments (delegation, result, the same error ≥ 2×, block) + counters
 *         < .flow-diagram viz-flow-diagram>       # main session → tool mix → subagents → return
 *     < .flow-log viz-flow-log>                  # latest calls and messages
 *     < .flow-statusline viz-flow-statusline>    # subagents [running/total] and session cost
 *     < details.flow-context viz-flow-context>  # "Context loaded (N)": instruction files from the InstructionsLoaded hook (`HookLog::instructions`) —
 *                                                # time, file (relative to the session cwd, full in title), type, reason (explanation in title); collapsed
 *                                                # (state in localStorage `viz-flow-context`), without events — no section. Paths only, no file contents
 *     < p.live-foot>
 *     < s-init run="(el) => VizFlow.Init(el)">
 *
 * @var array $view
 */

$flow = $view['flow'] === null ? null : $view['flow'] + ['now' => time()];
$loaded = $view['flowContext'] ?? [];
$cwd = $view['session']->cwd;
// load reason (`load_reason` from the hook documentation) → explanation in the tooltip
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
