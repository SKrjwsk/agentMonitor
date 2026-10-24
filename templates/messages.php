<?php
/**
 * Widok „Messages”: polecenia user → agent i agent → agent w kolejności (Session::messages).
 * Odpowiedzi subagentów są przycięte (TranscriptReader::RESULT_LIMIT) — zwykle cytują przeczytane pliki.
 *
 * @var array $view
 */

use Viz\Clock;
use Viz\Model\Agent;
use Viz\Source\TranscriptReader;
use Viz\Web\App;

$p = $view['p'];
$agents = $view['session']->agents;
// prompt rozpoczynający turę → zużycie tokenów tej tury (czas + treść, bo komenda usera może mieć ten sam czas)
$turnAt = [];
foreach ($view['turns'] ?? [] as $i => $t) {
    $turnAt[$t['ts'] . "\n" . $t['text']] = $i;
}
$kinds = ['user' => 'prompt', 'task' => 'task', 'result' => 'reply', 'send' => 'message', 'peer' => 'from other session'];
$who = static function (string $id) use ($agents, $view): string {
    if (isset($agents[$id])) {
        $label = $id === Agent::MAIN ? 'main' : mb_strimwidth($agents[$id]->label(), 0, 48, '…');

        return '<span class="who"><i class="dot" viz-agent-color="' . h(($view['agentColors'][$id] ?? 0) % 8) . '"></i>' . h($label) . '</span>';
    }

    return '<span class="who ext" title="' . h($id) . '">' . h(mb_strimwidth($id, 0, 32, '…')) . '</span>';
};
?>
<div class="list-bar">
    <?php foreach (['' => 'all', 'user' => 'from user', 'agents' => 'between agents'] as $mk => $label): ?>
        <a class="chip" href="/?<?= h(App::query($view, ['mk' => $mk])) ?>" <?= $p['mk'] === $mk ? 'viz-current' : '' ?>><?= h($label) ?></a>
    <?php endforeach ?>
    <span class="muted">tasks for subagents and SendMessage in full · subagent replies cut to <?= h(TranscriptReader::RESULT_LIMIT) ?> characters (they quote files)</span>
</div>
<?php if ($view['messages'] === []): ?>
    <p class="empty"><?= $p['src'] === 'hook' ? 'Messages are available only from the transcript source.' : 'No messages in this scope.' ?></p>
<?php else: ?>
    <ol class="messages">
        <?php foreach ($view['messages'] as $m): ?>
            <?php $long = mb_strlen($m['text']) > 300 || substr_count($m['text'], "\n") > 4 ?>
            <li viz-msg-kind="<?= h($m['kind']) ?>">
                <div class="msg-head">
                    <time title="<?= h($m['ts']) ?>"><?= h(Clock::local($m['ts'], 'd.m H:i:s')) ?></time>
                    <?= $m['from'] === 'user' ? '<span class="who user">user</span>' : $who($m['from']) ?>
                    <span class="arrow">→</span>
                    <?= $who($m['to']) ?>
                    <span class="badge"><?= h($kinds[$m['kind']] ?? $m['kind']) ?></span>
                    <?php if ($m['cut']): ?><span class="badge" title="subagent replies are cut — they usually quote file contents">truncated</span><?php endif ?>
                </div>
                <?php if ($long): ?>
                    <details class="msg-text">
                        <summary><?= h(mb_strimwidth(preg_replace('/\s+/', ' ', $m['text']), 0, 300, '…')) ?></summary>
                        <div><?= nl2br(h($m['text'])) ?></div>
                    </details>
                <?php else: ?>
                    <div class="msg-text"><?= nl2br(h($m['text'])) ?></div>
                <?php endif ?>
                <?php $ti = $m['kind'] === 'user' ? ($turnAt[$m['ts'] . "\n" . $m['text']] ?? null) : null ?>
                <?php if ($ti !== null && isset($view['turnTokens'][$ti])): ?>
                    <?php $tt = $view['turnTokens'][$ti] ?>
                    <div class="msg-tokens muted" title="tokens of all agents until the next prompt · output with ≥ = lower bound (<?= h($tt['outputPartial']) ?> responses saved without final usage) · new context = cache write · cache read = the same context read again on every call<?= $tt['models'] !== [] ? h(' · models: ' . implode(', ', array_map(static fn ($k, $n): string => "$k ×$n", array_keys($tt['models']), $tt['models']))) : '' ?>">
                        turn <?= h($ti + 1) ?> · <?= h(App::tokenText($tt)['long']) ?>
                    </div>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ol>
<?php endif ?>
