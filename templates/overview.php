<?php
/**
 * Strona startowa „Overview”: trzy osie pracy z agentami (kontekst, komunikacja, granice), w każdej osobno to, co może zmienić
 * user, i to, co może zmienić agent. Każdy rodzaj sygnału ma „dlaczego” i „co zrobić”, kwoty sumowane per rodzaj (bez sumy
 * między rodzajami — nakładają się). Dane z agregatów var/summary (Signals). Każdy przypadek to link do widoku sesji z przyczyną.
 *
 * @var array $overview
 */

use Viz\Clock;
use Viz\Signals;
use Viz\Web\App;

$o = $overview;
$p = $o['p'];
$query = static fn (array $over): string => '/?' . http_build_query(array_filter(array_merge(
    ['days' => $o['days'] === 7 ? '' : ($o['days'] > 0 ? (string) $o['days'] : 'all'), 'project' => $p['project']],
    $over,
), static fn (string $v): bool => $v !== ''));
// rodzaj → [nazwa, dlaczego to ważne, co zrobić, zmienne]
$kinds = [
    'expensive-turn' => ['Expensive turns', 'a prompt and its work cost more than {v} at API prices', '', ['v' => App::usd(Signals::TURN_USD)]],
    'cold-cache' => ['Cold cache after a pause', 'the cache lives 5 minutes — after a longer pause the whole context is written again (over {v} per rebuild)',
        'Before a break, let the agent finish; after it, a new task means a new session. A pause inside a turn is often a permission prompt waiting for you — allow routine commands in settings.', ['v' => App::usd(Signals::COLD_USD)]],
    'quick-question' => ['Quick question in a big context', 'an answer of up to {n} model calls that still paid for a context over {v} tokens',
        'Ask side questions in a new session or a second terminal — the answer does not need everything this session has read.', ['n' => Signals::QUICK_CALLS, 'v' => round(Signals::QUICK_CONTEXT / 1000) . 'k']],
    'stale-session' => ['Coming back to an old session', 'you returned after more than {h} h to a context over {v} tokens; the amount is what carrying it along cost',
        'New task → new session. Same task → /compact with what to keep, then continue.', ['h' => Signals::STALE_GAP / 3600, 'v' => round(Signals::STALE_CONTEXT / 1000) . 'k']],
    'huge-context' => ['Huge context', 'the context grew past {v} tokens — every call re-reads it',
        'Run /compact at a natural break (task done, new topic), or split the work into sessions.', ['v' => round(Signals::CONTEXT_TOKENS / 1000) . 'k']],
    'expensive-result' => ['Expensive tool results', 'output kept in the context and re-read by every later call cost more than {v}',
        'Read files in fragments, search before reading, delegate wide exploration to a subagent — worth a rule in CLAUDE.md.', ['v' => App::usd(Signals::RESULT_USD)]],
    'error-rate' => ['Many tool errors', 'more than {v} of tool calls failed in a session — every failure and retry stays in the context',
        'Open the failed calls: missing files and edit conflicts usually mean guessed paths — point the agent at the right directories.', ['v' => round(100 * Signals::ERROR_RATE) . '%']],
    'interrupt' => ['Interrupted by you', 'you stopped the agent — it was going somewhere you did not want',
        'Look at the prompt before the stop: a goal, a scope (which files, what not to touch) and a done criterion prevent most stops.', []],
    'report-blocked' => ['Agent messages blocked', 'the hook stopped a subagent report or message because its text matched a rule — it never arrived',
        'Tell agents to describe protected things by role, not by name, or narrow the hook rule.', []],
    'subagent-failed' => ['Subagent failures', 'a subagent call ended with an error',
        'Open the call: a too broad brief, a missing path or a limit — give the subagent a concrete goal and where to look.', []],
    'hook-text' => ['Hook false alarms', 'the hook blocked a call only because the rule pattern appeared in its text (a comment, a name, a message) — the agent had to retry',
        'If these are false alarms, narrow the rule so it matches paths, not any text.', []],
    'protected-path' => ['Access to protected paths', 'your hook blocked a call that touched a path matching its rule',
        'The hook did its job. If the agent keeps trying, write in CLAUDE.md which paths are off-limits and why.', []],
    'auto-mode' => ['Blocked by auto mode', 'the auto mode classifier or a safety check stopped the call',
        'Check the command: a real risk → keep it blocked; routine work → allow it in permissions.', []],
    'outside-executed' => ['Outside the boundary — executed', 'a call ran and touched a path outside AGENT_ALLOWED_RW / AGENT_ALLOWED_R in .env, or wrote into a read-only path — your guards let it through',
        'Find which guard missed it (hook, sandbox, permissions) and close the gap; if the path is legitimate, add it to .env.', []],
    'unknown-paths' => ['Unknown paths — suspicious', 'Bash ran, but its paths could not be resolved (glob, variable, heredoc, unknown program) — it may have left the boundary',
        'Open the call and check what it touched; the sandbox is what really stops shell commands.', []],
    'outside-stopped' => ['Outside the boundary — stopped', 'an attempt outside the boundary that did not run: a hook, a rule, auto mode, the sandbox or a tool error',
        'The guards did their job. If the agent keeps trying, its task needs something outside — hand it over or widen the boundary.', []],
    'limit' => ['Usage limits hit', 'the session hit a usage limit and had to wait', '', []],
    'api-error' => ['API errors', 'network, server, login or the computer went to sleep', '', []],
];
$actors = ['you' => 'What you can change', 'agent' => 'What the agent can change', '' => ''];
$status = ['high' => 'check now', 'warn' => 'needs attention', 'ok' => 'ok'];
$value = static fn (array $it): string => match (true) {
    in_array($it['kind'], Signals::USD_KINDS, true) => App::usd((float) $it['value']),
    $it['kind'] === 'huge-context' => round($it['value'] / 1000) . 'k',
    $it['kind'] === 'error-rate' => round(100 * $it['value'], 1) . '%',
    default => '',
};
$info = $o['info'];
$infoText = [
    'context' => t('{usd} in {n} sessions · {c} compactions', ['usd' => App::usd($o['usd']), 'n' => $o['sessionCount'], 'c' => $info['compactions'] ?? 0]),
    'communication' => t('retries after an error {r} · tool errors {e} of {c} calls', ['r' => $info['retry_after_error'] ?? 0, 'e' => $info['tool_errors'] ?? 0, 'c' => $info['calls'] ?? 0]),
    'boundaries' => t('not alarming: permissions.deny {d} · rejected by you {u}', ['d' => $info['deny_rule'] ?? 0, 'u' => $info['user_rejected'] ?? 0]),
];
$item = static function (array $it) use ($value, $kinds): string {
    $v = $value($it);
    $text = Signals::localize($it['text']);
    $detail = Signals::localize($it['detail']);
    $who = '<span class="ov-where">' . h(($it['title'] !== '' ? mb_strimwidth($it['title'], 0, 40, '…') : substr($it['session_id'], 0, 8)) . ' · ' . Clock::local($it['ts'], 'd.m H:i')) . '</span>';

    return '<li viz-sev="' . h($it['sev']) . '"><a href="' . h(App::signalLink($it)) . '" title="' . h(t($kinds[$it['kind']][0]) . ': ' . $text . ($detail !== '' ? "\n" . $detail : '')) . '">'
        . ($v !== '' ? '<span class="ov-val">' . h($v) . '</span>' : '')
        . '<span class="ov-text">' . h(App::shortText($text, $it['cwd'] !== '' ? $it['cwd'] : null)) . '</span>'
        . ($detail !== '' ? '<span class="ov-detail">' . h($detail) . '</span>' : '')
        . $who . '</a></li>';
};
// rodzaj sygnału z liczbą, kwotą, „dlaczego”, „co zrobić” i przypadkami do rozwinięcia
$kindRow = static function (string $kind, array $k) use ($kinds, $item): string {
    [$label, $help, $do, $vars] = $kinds[$kind];
    $n = count($k['items']);

    return '<li' . ($n === 0 ? ' viz-zero' : '') . '><details><summary title="' . th($help, $vars) . '">'
        . '<span class="ov-kind">' . th($label) . '</span>'
        . ($n > 0 && $k['usd'] !== null ? '<span class="ov-usd">' . h(App::usd($k['usd'])) . '</span>' : '')
        . '<span class="ov-n">' . ($n === 0 ? '✓' : h($n)) . '</span>'
        . ($k['sessions'] > 1 ? '<span class="muted">' . th('in {n} sessions', ['n' => $k['sessions']]) . '</span>' : '')
        . '</summary><p class="ov-help">' . th($help, $vars) . '</p>'
        . ($do !== '' ? '<p class="ov-do">' . th($do) . '</p>' : '')
        . ($n > 0 ? '<ol class="ov-items">' . implode('', array_map($item, array_slice($k['items'], 0, 30))) . '</ol>' : '')
        . '</details></li>';
};
?>
<section class="overview">
    <div class="title-row">
        <h1><?= th('Overview') ?></h1>
        <span class="meta"><?= th('what you and the agents can do better · {n} sessions · {usd}', ['n' => $o['sessionCount'], 'usd' => App::usd($o['usd'])]) ?></span>
    </div>
    <div class="filters">
        <select onchange="Viz.Go(this)" title="<?= th('period') ?>">
            <?php foreach (['1' => 'last 24 hours', '' => 'last 7 days', '30' => 'last 30 days', 'all' => 'all time'] as $days => $label): ?>
                <?php $days = (string) $days ?>
                <option value="<?= h($query(['days' => $days])) ?>" <?= ($days === '' ? $o['days'] === 7 : ($days === 'all' ? $o['days'] === 0 : (string) $o['days'] === $days)) ? 'selected' : '' ?>><?= th($label) ?></option>
            <?php endforeach ?>
        </select>
        <select onchange="Viz.Go(this)" title="<?= th('project = working directory of the session') ?>">
            <option value="<?= h($query(['project' => ''])) ?>"><?= th('all projects ({n})', ['n' => count($o['projects'])]) ?></option>
            <?php foreach ($o['projects'] as $key => $pr): ?>
                <option value="<?= h($query(['project' => (string) $key])) ?>" <?= $p['project'] === (string) $key ? 'selected' : '' ?> title="<?= h(App::shortPath($pr['cwd'], null)) ?>"><?= h($pr['label'] . ' · ' . $pr['sessions']) ?></option>
            <?php endforeach ?>
        </select>
        <span class="muted"><?= th('cost = Anthropic API prices, a yardstick for comparing — not your bill. Amounts of different kinds overlap, so they are not added up.') ?></span>
    </div>

    <div class="ov-grid">
        <?php foreach (App::OVERVIEW_AXES as $axis => $_): ?>
            <?php $a = $o['axes'][$axis] ?>
            <article class="ov-card" viz-status="<?= h($a['status']) ?>">
                <header>
                    <h2><?= th($a['label']) ?></h2>
                    <span class="ov-status"><?= th($status[$a['status']]) ?><?= $a['count'] > 0 ? ' · ' . h($a['count']) : '' ?></span>
                </header>
                <?php foreach ($a['groups'] as $actor => $group): ?>
                    <h3><?= th($actors[$actor]) ?></h3>
                    <ul class="ov-kinds"><?= implode('', array_map($kindRow, array_keys($group), $group)) ?></ul>
                <?php endforeach ?>
                <?php if ($a['top'] !== []): ?>
                    <h3><?= th('most important') ?></h3>
                    <ol class="ov-items"><?= implode('', array_map($item, $a['top'])) ?></ol>
                <?php else: ?>
                    <p class="ov-ok"><?= th('nothing to look at in this period') ?></p>
                <?php endif ?>
                <p class="ov-info"><?= h($infoText[$axis]) ?></p>
            </article>
        <?php endforeach ?>
    </div>

    <?php $env = $o['axes']['env'] ?>
    <div class="ov-env">
        <span class="muted"><?= th($env['label']) ?>:</span>
        <ul class="ov-kinds"><?= implode('', array_map($kindRow, array_keys($env['groups']['']), $env['groups'][''])) ?></ul>
    </div>

    <?php $turns = $o['turns'] ?>
    <h2 class="ov-sessions-title"><?= th('Most expensive turns') ?> <span class="muted"><?= th('{n} turns over {v} in {s} sessions', ['n' => count($turns['items']), 'v' => App::usd(Signals::TURN_USD), 's' => $turns['sessions']]) ?></span></h2>
    <?php if ($turns['top'] === []): ?>
        <p class="ov-ok"><?= th('No turn cost more than {v} in this period.', ['v' => App::usd(Signals::TURN_USD)]) ?></p>
    <?php else: ?>
        <ol class="ov-items ov-turns"><?= implode('', array_map($item, $turns['top'])) ?></ol>
    <?php endif ?>

    <h2 class="ov-sessions-title"><?= th('Sessions to look at') ?> <span class="muted"><?= th('{n} of {t} sessions have signals', ['n' => $o['flaggedCount'], 't' => $o['sessionCount']]) ?></span></h2>
    <?php if ($o['flagged'] === []): ?>
        <p class="ov-ok"><?= th('No session has signals in this period.') ?></p>
    <?php else: ?>
        <table class="files ov-table">
            <thead><tr><th><?= th('session') ?></th><th><?= th('project') ?></th><th class="num" title="<?= th('cost of the session at API prices') ?>">$</th>
                <th><?= th('signals') ?></th><th><?= th('last') ?></th></tr></thead>
            <tbody>
                <?php foreach ($o['flagged'] as $s): ?>
                    <?php arsort($s['kinds']) ?>
                    <tr onclick="this.one('a').click()">
                        <td class="path"><a href="/?s=<?= h($s['id']) ?>"><span class="base"><?= h($s['title'] !== '' ? $s['title'] : substr($s['id'], 0, 8)) ?></span></a></td>
                        <td class="muted"><?= h(basename($s['cwd'])) ?></td>
                        <td class="num"><?= h(App::usd($s['usd'])) ?></td>
                        <td class="ov-sig">
                            <?php foreach (array_slice($s['kinds'], 0, 3, true) as $kind => $n): ?>
                                <span viz-sev="<?= h(Signals::KINDS[$kind][2]) ?>"><?= th($kinds[$kind][0]) ?><?= $n > 1 ? ' ×' . h($n) : '' ?></span>
                            <?php endforeach ?>
                            <?php if (count($s['kinds']) > 3): ?><span class="muted">+<?= h(count($s['kinds']) - 3) ?></span><?php endif ?>
                        </td>
                        <td class="time"><?= h($s['last'] !== '' ? Clock::local($s['last'], 'd.m H:i') : '') ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>
