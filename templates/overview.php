<?php
/**
 * Strona startowa „Overview”: trzy osie pracy z agentami (kontekst, komunikacja, granice), w każdej osobno to, co może zmienić
 * user, i to, co może zmienić agent. Każdy rodzaj sygnału ma „dlaczego” i „co zrobić”, kwoty sumowane per rodzaj (bez sumy
 * między rodzajami — nakładają się). Dane z agregatów var/summary (Signals). Każdy przypadek to link do widoku sesji z przyczyną.
 *
 * @var array $overview
 */

use Viz\Clock;
use Viz\Office\LiveState;
use Viz\Signals;
use Viz\Summary\SummaryStore;
use Viz\Web\App;

$o = $overview;
// zakres projektów = wybór w lewym panelu (Scope, ciasteczko); linki go nie niosą, poza „koszt wg projektu” (`projects` = nowy wybór)
$query = static fn (array $over): string => '/?' . http_build_query(array_filter(array_merge(
    ['overview' => '1', 'days' => $o['days'] === 7 ? '' : ($o['days'] > 0 ? (string) $o['days'] : 'all')],
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
// ---- wykresy: HTML/SVG renderowane na serwerze, podpowiedź przy najechaniu z `data-tip` (public/assets/charts.js) ----
$sevIcon = ['high' => '▲', 'warn' => '●'];
$niceMax = static function (float $v): float {
    if ($v <= 0) {
        return 1.0;
    }
    $e = 10 ** floor(log10($v));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($v <= $m * $e) {
            return $m * $e;
        }
    }

    return 10 * $e;
};
$tick = static fn (float $v): string => '$' . (str_contains($f = number_format($v, $v < 10 ? 1 : 0, '.', ''), '.') ? rtrim(rtrim($f, '0'), '.') : $f);
/** Słupki poziome: wiersz = [label, wartość, tekst wartości, href|null, podpowiedź, sev|null]; długość względem największej wartości. */
$hbars = static function (array $rows) : string {
    $max = max(1e-9, ...array_column($rows, 1));
    $out = '<ol class="hbars">';
    foreach ($rows as [$label, $v, $text, $href, $tip, $sev]) {
        $tag = $href !== null ? 'a href="' . h($href) . '"' : 'span';
        $end = $href !== null ? 'a' : 'span';
        $out .= '<li data-tip="' . h($tip) . '"' . ($sev !== null ? ' viz-sev="' . h($sev) . '"' : '') . '><' . $tag . ' class="hb-label">' . ($sev !== null ? '<i class="hb-sev">' . h($GLOBALS['sevIconMap'][$sev] ?? '') . '</i>' : '') . h($label) . '</' . $end . '>'
            . '<span class="hb-track"><i style="width: ' . h(round(100 * $v / $max, 1)) . '%"></i></span><span class="hb-val">' . h($text) . '</span></li>';
    }

    return $out . '</ol>';
};
$GLOBALS['sevIconMap'] = $sevIcon;
$calls = (int) ($o['info']['calls'] ?? 0);
$errors = (int) ($o['info']['tool_errors'] ?? 0);
$series = $o['series'];
?>
<section class="overview" viz-scope-reload>
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
        <span class="muted"><?= th('cost = Anthropic API prices, a yardstick for comparing — not your bill. Amounts of different kinds overlap, so they are not added up.') ?></span>
    </div>

    <div class="ov-kpis">
        <div class="ov-kpi"><span class="ov-kpi-l"><?= th('Cost (API prices)') ?></span><strong class="ov-kpi-v"><?= h(App::usd($o['usd'])) ?></strong><span class="ov-kpi-s"><?= th('in {n} sessions', ['n' => $o['sessionCount']]) ?></span></div>
        <div class="ov-kpi"><span class="ov-kpi-l"><?= th('Tool calls') ?></span><strong class="ov-kpi-v"><?= h(number_format($calls, 0, ',', ' ')) ?></strong><span class="ov-kpi-s"><?= th('{n} compactions', ['n' => $o['info']['compactions'] ?? 0]) ?></span></div>
        <div class="ov-kpi"><span class="ov-kpi-l"><?= th('Tool errors') ?></span><strong class="ov-kpi-v"><?= h($errors) ?></strong><span class="ov-kpi-s"><?= th('{pct}% of calls', ['pct' => $calls > 0 ? round(100 * $errors / $calls, 1) : 0]) ?></span></div>
    </div>

    <div class="ov-axes">
        <?php foreach (App::OVERVIEW_AXES as $axis => $_): ?>
            <?php $a = $o['axes'][$axis] ?>
            <div class="ov-axis" viz-status="<?= h($a['status']) ?>"><span class="ov-axis-name"><?= th($a['label']) ?></span>
                <span class="ov-status"><?= ['high' => '▲', 'warn' => '●', 'ok' => '✓'][$a['status']] ?> <?= th($status[$a['status']]) ?><?= $a['count'] > 0 ? ' · ' . h($a['count']) : '' ?></span></div>
        <?php endforeach ?>
    </div>

    <div class="ov-charts">
        <section class="ov-chart ov-wide">
            <h2><?= $series['hourly'] ? th('Cost per hour') : th('Cost per day') ?> <span class="muted"><?= th('session cost counted at its last activity') ?></span></h2>
            <?php
            $rows = $series['rows'];
            $n = count($rows);
            $top = $niceMax((float) max(array_column($rows, 'usd')));
            [$W, $H, $pl, $pr, $pt, $pb] = [760, 190, 44, 8, 8, 22];
            $iw = $W - $pl - $pr;
            $ih = $H - $pt - $pb;
            $slot = $iw / $n;
            $bw = max(2, min(28, $slot * 0.62));
            $step = (int) ceil($n / 8);
            $y = static fn (float $v): float => $pt + $ih - $ih * $v / $top;
            ?>
            <svg class="ov-svg" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="<?= th('Cost per day') ?>">
                <?php foreach ([0, $top / 2, $top] as $g): ?>
                    <line class="grid" x1="<?= $pl ?>" x2="<?= $W - $pr ?>" y1="<?= round($y($g), 1) ?>" y2="<?= round($y($g), 1) ?>"/>
                    <text class="ax" x="<?= $pl - 6 ?>" y="<?= round($y($g) + 4, 1) ?>" text-anchor="end"><?= h($tick($g)) ?></text>
                <?php endforeach ?>
                <?php foreach ($rows as $i => $r): ?>
                    <?php $h = $ih * $r['usd'] / $top; $x = $pl + $slot * $i ?>
                    <g data-tip="<?= h($r['label'] . ' · ' . App::usd($r['usd']) . ' · ' . t('{n} sessions', ['n' => $r['n']])) ?>">
                        <rect class="hit" x="<?= round($x, 1) ?>" y="<?= $pt ?>" width="<?= round($slot, 1) ?>" height="<?= $ih ?>"/>
                        <?php if ($r['usd'] > 0): ?><rect class="bar" x="<?= round($x + ($slot - $bw) / 2, 1) ?>" y="<?= round($pt + $ih - max(1.5, $h), 1) ?>" width="<?= round($bw, 1) ?>" height="<?= round(max(1.5, $h), 1) ?>" rx="2"/><?php endif ?>
                    </g>
                    <?php if ($i % $step === 0): ?><text class="ax" x="<?= round($x + $slot / 2, 1) ?>" y="<?= $H - 6 ?>" text-anchor="middle"><?= h($r['label']) ?></text><?php endif ?>
                <?php endforeach ?>
            </svg>
            <details class="ov-tableview"><summary><?= th('table') ?></summary>
                <table class="files"><thead><tr><th><?= th('period') ?></th><th class="num">$</th><th class="num"><?= th('sessions') ?></th></tr></thead><tbody>
                    <?php foreach (array_reverse($rows) as $r): ?><tr><td><?= h($r['label']) ?></td><td class="num"><?= h(App::usd($r['usd'])) ?></td><td class="num"><?= h($r['n']) ?></td></tr><?php endforeach ?>
                </tbody></table>
            </details>
        </section>

        <section class="ov-chart">
            <h2><?= th('Equipment: MCP / skills / subagents') ?> <span class="muted"><?= th('uses in sessions active in this period') ?></span></h2>
            <?php
            // trzy grupy z osobną skalą: liczba użyć serwera MCP i skilla to różne rzędy wielkości, porównujemy w obrębie grupy
            $equipGroups = ['mcp' => t('MCP servers'), 'skills' => t('Skills'), 'subagents' => t('Subagents')];
            $equipAny = false;
            foreach ($equipGroups as $kind => $groupLabel):
                $names = $o['equipment'][$kind];
                if ($names === []) {
                    continue;
                }
                $equipAny = true;
                $equipRows = [];
                foreach (array_slice($names, 0, 6, true) as $e) {
                    $equipRows[] = [$e['name'], (float) $e['count'], '×' . $e['count'], null,
                        $e['name'] . ' · ×' . $e['count'] . ' · ' . t('in {n} sessions', ['n' => $e['sessions']]) . ' · ' . t('last use {time}', ['time' => Clock::local($e['last'], 'd.m H:i')]), null];
                }
                $rest = array_slice($names, 6, null, true);
                if ($rest !== []) {
                    $restN = array_sum(array_column($rest, 'count'));
                    $equipRows[] = [t('other ({n})', ['n' => count($rest)]), (float) $restN, '×' . $restN, null, implode(', ', array_column($rest, 'name')), null];
                }
                ?>
                <h3 class="ov-sub"><?= h($groupLabel) ?> <span class="muted"><?= th('{n} uses', ['n' => array_sum(array_column($names, 'count'))]) ?></span></h3>
                <?= $hbars($equipRows) ?>
            <?php endforeach ?>
            <?= $equipAny ? '' : '<p class="empty">' . th('No MCP, skill or subagent use in this period.') . '</p>' ?>
        </section>

        <section class="ov-chart">
            <h2><?= th('Cost by project') ?></h2>
            <?php
            $projectRows = [];
            foreach (array_slice($o['byProject'], 0, 8) as $pr) {
                $projectRows[] = [$pr['label'], $pr['usd'], App::usd($pr['usd']), $query(['projects' => $pr['key']]), $pr['label'] . ' · ' . App::usd($pr['usd']) . ' · ' . t('{n} sessions', ['n' => $pr['n']]), null];
            }
            $rest = array_slice($o['byProject'], 8);
            if ($rest !== []) {
                $restUsd = array_sum(array_column($rest, 'usd'));
                $projectRows[] = [t('other projects ({n})', ['n' => count($rest)]), $restUsd, App::usd($restUsd), null, App::usd($restUsd), null];
            }
            ?>
            <?= $projectRows === [] ? '<p class="empty">' . th('No sessions in this period.') . '</p>' : $hbars($projectRows) ?>
        </section>

        <section class="ov-chart">
            <h2><?= th('Signals by kind') ?> <span class="muted ov-legend">▲ <?= th('check now') ?> · ● <?= th('needs attention') ?></span></h2>
            <?php
            $kindRows = [];
            foreach (array_slice($o['kindCounts'], 0, 10) as $k) {
                $kindRows[] = [t($kinds[$k['kind']][0]), $k['n'], (string) $k['n'], null, t($kinds[$k['kind']][0]) . ' · ' . $k['n'] . ' · ' . t('in {n} sessions', ['n' => $k['sessions']]), $k['sev']];
            }
            ?>
            <?= $kindRows === [] ? '<p class="ov-ok">' . th('nothing to look at in this period') . '</p>' : $hbars($kindRows) ?>
        </section>

        <section class="ov-chart ov-wide">
            <h2><?= th('Most expensive turns') ?> <span class="muted"><?= th('{n} turns over {v} in {s} sessions', ['n' => count($o['turns']['items']), 'v' => App::usd(Signals::TURN_USD), 's' => $o['turns']['sessions']]) ?></span></h2>
            <?php
            $turnRows = [];
            foreach ($o['turns']['top'] as $it) {
                $name = ($it['title'] !== '' ? mb_strimwidth($it['title'], 0, 48, '…') : substr($it['session_id'], 0, 8)) . ' · ' . Clock::local($it['ts'], 'd.m H:i');
                $turnRows[] = [$name, (float) $it['value'], App::usd((float) $it['value']), App::signalLink($it), $name . ' · ' . App::usd((float) $it['value']), null];
            }
            ?>
            <?= $turnRows === [] ? '<p class="ov-ok">' . th('No turn cost more than {v} in this period.', ['v' => App::usd(Signals::TURN_USD)]) . '</p>' : $hbars($turnRows) ?>
        </section>

        <?php $pat = $o['patterns'] ?>
        <section class="ov-chart ov-wide">
            <h2><?= th('Recurring problems in assessments') ?> <span class="muted"><?= th('{n} assessed sessions, grouped by the place to fix', ['n' => $pat['sessions']]) ?><?= $pat['legacy'] > 0 ? ' · ' . th('{n} in the old format skipped', ['n' => $pat['legacy']]) : '' ?></span></h2>
            <?php
            $patRows = [];
            foreach (array_slice($pat['groups'], 0, 8) as $g) {
                $name = ($g['path'] !== '' ? $g['path'] : $g['owner']) . ' · ' . $g['owner'];
                $titles = implode("\n", array_unique(array_column($g['items'], 'title')));
                $patRows[] = [$name, (float) $g['sessions'], t('{n} sessions · {m} findings', ['n' => $g['sessions'], 'm' => count($g['items'])]),
                    '/?' . http_build_query(['s' => $g['sessionIds'][0], 'view' => 'judge']), $titles, null];
            }
            ?>
            <?= $patRows === [] ? '<p class="ov-ok">' . th('No assessed sessions yet (tab Assessment).') . '</p>' : $hbars($patRows) ?>
            <?php if ($pat['turns'] !== []): ?>
                <p class="muted ov-kinds"><?= th('Detours in the timelines') ?>:
                    <?php foreach ($pat['turns'] as $t): ?><span class="ov-pill"><?= h($t['kind']) ?> <?= h((string) $t['count']) ?> (<?= th('{n} sessions', ['n' => $t['sessions']]) ?>)</span> <?php endforeach ?>
                </p>
            <?php endif ?>
        </section>

        <section class="ov-chart">
            <?php $bashMin = intdiv(SummaryStore::BASH_WAIT_FROM, 60) ?>
            <h2><?= th('Time in tools') ?> <span class="muted"><?= th('total per tool, calls under {s} s (Bash under {b} min)', ['s' => LiveState::PERMISSION_GUESS, 'b' => $bashMin]) ?></span></h2>
            <?php
            $sessionName = static fn (array $r): string => $r['title'] !== '' ? mb_strimwidth($r['title'], 0, 40, '…') : substr($r['session_id'], 0, 8);
            $callName = static fn (array $c): string => $c['tool'] . ' · ' . mb_strimwidth(App::shortText($c['summary'], null), 0, 48, '…');
            $callLink = static fn (array $c): string => '/?' . http_build_query(['s' => $c['session_id'], 'view' => 'log', 'pev' => $c['ev']]);
            // suma mówi, gdzie ucieka czas; średnia w podpowiedzi zdradza narzędzia, które powinny być natychmiastowe (np. Edit czekający na zgodę)
            $toolRows = array_map(static fn (array $tt): array => [$tt['tool'], (float) $tt['ms'], App::duration($tt['ms']), null,
                $tt['tool'] . ' · ' . App::duration($tt['ms']) . ' · ' . t('{n} calls, average {avg}, longest {max}', ['n' => $tt['count'], 'avg' => App::duration(intdiv($tt['ms'], max(1, $tt['count']))), 'max' => App::duration($tt['max'])]), null], array_slice($o['toolTime'], 0, 8));
            $waits = $o['waits'];
            ?>
            <?= $toolRows === [] ? '<p class="empty">' . th('No timed tool calls in this period.') . '</p>' : $hbars($toolRows) ?>
            <?php if ($waits['top'] !== []): ?>
                <h3 class="ov-sub" title="<?= th('other tools: the same threshold as the live “approval?” state; Bash: from its default maximum timeout plus a 1 min margin ({b} min), because a shorter command may just run long — a transcript cannot tell waiting for approval from long work', ['b' => $bashMin]) ?>">● <?= th('Maybe waiting for your approval') ?>
                    <span class="muted"><?= th('{s} s or longer (Bash {b} min or longer), not blocked · calls: {n} · {t} in total', ['s' => LiveState::PERMISSION_GUESS, 'b' => $bashMin, 'n' => $waits['count'], 't' => App::duration($waits['ms'])]) ?></span></h3>
                <?= $hbars(array_map(static fn (array $c): array => [$callName($c), (float) $c['ms'], App::duration($c['ms']), $callLink($c),
                    $callName($c) . ' · ' . App::duration($c['ms']) . ' · ' . $sessionName($c) . ' · ' . Clock::local($c['ts'], 'd.m H:i'), 'warn'], $waits['top'])) ?>
            <?php endif ?>
            <?php if ($o['slow'] !== []): ?>
                <details class="ov-tableview"><summary><?= th('slowest calls under {s} s (Bash under {b} min)', ['s' => LiveState::PERMISSION_GUESS, 'b' => $bashMin]) ?></summary>
                    <table class="files"><thead><tr><th><?= th('call') ?></th><th><?= th('session') ?></th><th class="num"><?= th('duration') ?></th></tr></thead><tbody>
                        <?php foreach ($o['slow'] as $c): ?>
                            <tr><td><a href="<?= h($callLink($c)) ?>" title="<?= h($c['summary']) ?>"><?= h($callName($c)) ?></a></td><td><?= h($sessionName($c)) ?></td><td class="num"><?= h(App::duration($c['ms'])) ?></td></tr>
                        <?php endforeach ?>
                    </tbody></table>
                </details>
            <?php endif ?>
            <?php if ($o['results']['files'] > 0): ?>
                <details class="ov-tableview"><summary><?= th('tool results saved on disk — files: {n}, {size}', ['n' => $o['results']['files'], 'size' => $o['results']['bytes'] < 1048576 ? max(1, round($o['results']['bytes'] / 1024)) . ' KB' : round($o['results']['bytes'] / 1048576, 1) . ' MB']) ?></summary>
                    <table class="files"><thead><tr><th><?= th('tool') ?></th><th><?= th('session') ?></th><th class="num">KB</th></tr></thead><tbody>
                        <?php foreach ($o['results']['top'] as $r): ?>
                            <tr><td><?= $r['tool'] !== '' ? '<a href="' . h('/?' . http_build_query(['s' => $r['session_id'], 'view' => 'log', 'pev' => $r['id']])) . '">' . h($r['tool']) . '</a>' : '?' ?></td>
                                <td><a href="/?s=<?= h($r['session_id']) ?>"><?= h($sessionName($r)) ?></a></td><td class="num"><?= h(round($r['bytes'] / 1024)) ?></td></tr>
                        <?php endforeach ?>
                    </tbody></table>
                </details>
            <?php endif ?>
        </section>

    </div>

    <details class="ov-details">
        <summary><?= th('Why it matters and what to do') ?></summary>
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

    </details>
</section>
