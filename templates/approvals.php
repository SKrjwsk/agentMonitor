<?php
/**
 * Zakładka „Zgody” jednej sesji (`view=approvals`): o co Claude Code pytał usera o zgodę (albo prawdopodobnie pytał) i dlaczego.
 * Dane: `Permissions\Approvals` (wiersze, „najczęściej pytane”), reguły z `Permissions\Settings`.
 * Nad tabelami linia trybu uprawnień: w sesji tryby z transkryptu (`approvalModes`, zmiany przy wiadomościach usera), bez nich tryby wierszy
 * (hook) i `defaultMode` z ustawień; w „all” tryby wierszy z liczbą.
 * Obie tabele mają dwie kolumny: czas i dlaczego; pełna komenda w panelu wywołania (klik w wiersz), skrócona w dymku wiersza.
 * Na górze najczęściej pytane kształty komend (czas = ostatnie wystąpienie, liczba przy „dlaczego”, kształt w dymku; klik = ostatnie wywołanie),
 * niżej lista chronologiczna (najnowsze na górze); klik w wiersz = panel wywołania (viz-ev, jak w Log).
 * Przyczyna ma poziom pewności: z ustawień (reguła) / domysł z treści komendy / nieznana; przy niej skutek trybu wiersza (`Approvals::modeEffect`),
 * tylko gdy wynika wprost z dokumentacji. Aplikacja nie podpowiada zmian ustawień.
 * Zakres „all” (`viz-all`): wiersze z agregatów wszystkich sesji, kolumna sesji zamiast agenta, klik = wywołanie w zakładce Zgody jego sesji; do `rowLimit` wierszy.
 *
 * @var array $view
 */

use Viz\Clock;
use Viz\Model\Agent;
use Viz\Office\LiveState;
use Viz\Permissions\Approvals;
use Viz\Web\App;

$p = $view['p'];
$all = $view['allSessions'];
$rows = $view['approvals'];
$total = count($rows);
if ($all) {
    $rows = array_slice($rows, 0, $view['rowLimit']);
}
// ścieżki w przyczynach skracane względem katalogu sesji wiersza (w „all” każdy wiersz ma swój)
$sessionCwd = $all ? null : $view['session']->cwd;
$agents = $all ? [] : $view['session']->agents;
$multi = count($agents) > 1;

$level = static fn (array $c): string => match ($c['level']) {
    'rule' => '<span class="tag" title="' . th('a rule from your settings matches this call (the settings file is named in the reason)') . '">' . th('from settings') . '</span>',
    'heuristic' => '<span class="tag" viz-tag="partial" title="' . th('a guess from the command text — patterns Claude Code reports in its prompts; not confirmed by Claude Code') . '">' . th('guess') . '</span>',
    default => '<span class="tag" title="' . th('no rule and no known pattern matched — the prompt text itself is not in the transcript') . '">' . th('unknown') . '</span>',
};
$causeText = static fn (array $c, ?string $cwd): string => match ($c['kind']) {
    'ask-rule' => t('ask rule {rule} in {file}', ['rule' => $c['rule'], 'file' => $c['file']]),
    'deny-rule' => t('deny rule {rule} in {file}', ['rule' => $c['rule'], 'file' => $c['file']]),
    'cd-git' => t('cd to another directory before git ({dir})', ['dir' => App::shortText((string) $c['detail'], $cwd)]),
    'cd-outside' => t('cd outside the session directory ({dir})', ['dir' => (string) $c['detail']]),
    'variable' => t('a variable that cannot be checked before it runs ({vars})', ['vars' => (string) $c['detail']]),
    'redirect' => t('redirect to a file outside the session directory or not known before it runs ({target})', ['target' => App::shortText((string) $c['detail'], $cwd)]),
    'protected-path' => t('write to a protected path ({path})', ['path' => App::shortText((string) $c['detail'], $cwd)]),
    'no-allow' => t('no allow rule for: {cmd}', ['cmd' => (string) $c['detail']]),
    default => t('cause unknown'),
};
$causeHelp = static fn (array $c): string => match ($c['kind']) {
    'ask-rule' => t('Claude Code checks rules in the order deny → ask → allow; a matching ask rule prompts even when an allow rule also matches, also in auto mode. Matched part: {part}', ['part' => (string) $c['detail']]),
    'cd-git' => t('Claude Code asks when cd changes the directory before git: git in another directory can run that directory’s hooks or repository configuration.'),
    'cd-outside' => t('cd counts as read-only only inside the working directories; elsewhere Claude Code asks.'),
    'variable' => t('Claude Code cannot know the value of a variable ($VAR, ${…}) or of an assignment to PATH / IFS before the command runs, so it asks.'),
    'redirect' => t('Claude Code checks the target of a redirect like a file write; outside the working directories, or when it cannot tell the directory (after cd, a variable), it asks.'),
    'protected-path' => t('Claude Code never auto-approves writes to protected paths (.git, .claude, .vscode, .idea, shell and tool config files…) outside bypassPermissions mode, and allow rules do not change that: default and acceptEdits modes ask, auto mode sends them to the classifier, dontAsk denies them.'),
    'no-allow' => t('Commands without an allow rule prompt in default mode; in auto mode a classifier decides instead. Read-only commands (ls, cat, grep…) do not need a rule.'),
    default => '',
};
$howLabel = static fn (string $how): string => match ($how) {
    Approvals::HOOK => '<span class="tag" viz-tag="read" title="' . th('the hook recorded the permission request (PermissionRequest or a permission_prompt notification) during this call') . '">' . th('from hook') . '</span>',
    Approvals::REJECTED => '<span class="tag" viz-tag="warn" title="' . th('you answered No — Claude Code did not run it') . '">' . th('rejected by you') . '</span>',
    default => '<span class="tag" title="' . th('no hook data: the call took at least {s} s without a block — it may have waited for you or just run long', ['s' => LiveState::PERMISSION_GUESS]) . '">' . th('waited ≥ {s} s', ['s' => LiveState::PERMISSION_GUESS]) . '</span>',
};
$effectLabel = static fn (?string $mode, array $c): string => match (Approvals::modeEffect($mode, $c['kind'])) {
    'classifier' => '<span class="note" title="' . th('Claude Code documentation, permission modes: after 3 blocks in a row or 20 in total auto mode pauses and Claude Code asks again') . '">' . th('auto mode: the classifier decides instead of a prompt') . '</span>',
    'denied' => '<span class="note" title="' . th('Claude Code documentation, permission modes') . '">' . th('dontAsk mode: denied instead of a prompt') . '</span>',
    'allowed' => '<span class="note" title="' . th('Claude Code documentation, permission modes') . '">' . th('bypassPermissions mode: allowed without a prompt') . '</span>',
    default => '',
};
$why = static function (array $causes, ?string $mode, ?string $cwd) use ($level, $causeText, $causeHelp, $effectLabel): string {
    $out = '';
    foreach ($causes as $c) {
        $out .= '<div>' . $level($c) . '<span title="' . h($causeHelp($c)) . '">' . h($causeText($c, $cwd)) . '</span>' . $effectLabel($mode, $c) . '</div>';
    }

    return $out;
};
// wiersz tabeli: <tr> z kliknięciem i komórka czasu (w „all” link do wywołania w jego sesji); $tip = dymek wiersza
$openRow = static function (array $r, string $tip) use ($all, $p, $multi, $agents, $view): string {
    $dot = $multi ? '<i class="dot" viz-agent-color="' . h(($view['agentColors'][$r['agent']] ?? 0) % 8) . '" title="' . h(isset($agents[$r['agent']]) ? App::agentLabel($agents[$r['agent']]) : $r['agent']) . '"></i>' : '';
    if ($all) {
        $href = '/?' . http_build_query(array_filter(['s' => $r['session_id'], 'view' => 'approvals', 'pev' => (string) $r['id']], static fn (string $v): bool => $v !== ''));

        return '<tr onclick="if (!event.target.closest(\'a\')) location.href = this.dataset.href" data-href="' . h($href) . '" title="' . h($tip) . '">'
            . '<td class="time nowrap"><a href="' . h($href) . '">' . h(Clock::local($r['ts'], 'd.m H:i:s')) . '</a>' . $dot . '</td>';
    }

    return '<tr ' . ($r['id'] !== null ? 'viz-ev="' . h($r['id']) . '" onclick="Viz.Open(this, event)"' . ($p['pev'] === $r['id'] ? ' viz-picked' : '') : '') . ' title="' . h($tip) . '">'
        . '<td class="time nowrap">' . h(Clock::local($r['ts'], 'd.m H:i:s')) . $dot . '</td>';
};
$rowCwd = static fn (array $r): ?string => $all ? ($r['cwd'] !== '' ? $r['cwd'] : null) : $sessionCwd;
// linia trybu uprawnień
$modeTag = static fn (string $mode): string => '<span class="tag">' . ($mode !== '' ? h($mode) : th('unknown')) . '</span>';
$sessionModes = $all ? [] : array_column($view['approvalModes'], 'mode');
$rowModes = Approvals::modeCounts($view['approvals']);
$settingsMode = $all ? null : $view['approvalSettings']->mode();
?>
<div class="list-bar approvals-bar">
    <?php if ($sessionModes !== []): ?>
        <span title="<?= th('from the transcript: the mode recorded with your messages; each call gets the mode of your last message before it (a subagent — the mode of the session)') ?>"><?= th('permission mode:') ?>
            <?= implode(' → ', array_map($modeTag, array_slice($sessionModes, -6))) ?></span>
    <?php elseif ($rowModes !== [] && array_keys($rowModes) !== ['']): ?>
        <span title="<?= th('mode of each call: from the hook (permission request) or from the transcript') ?>"><?= th('permission mode:') ?>
            <?= implode(' ', array_map(static fn (string $m, int $n): string => $modeTag($m) . '<span class="muted">×' . $n . '</span>', array_keys($rowModes), $rowModes)) ?></span>
    <?php elseif ($settingsMode !== null): ?>
        <span title="<?= th('no mode in the transcript or the hook; the --permission-mode flag or Shift+Tab can change the mode of a session') ?>"><?= th('defaultMode in settings:') ?>
            <?= $modeTag($settingsMode) ?> <span class="muted"><?= h((string) $view['approvalSettings']->modeFile()) ?></span></span>
    <?php else: ?>
        <span><?= th('permission mode:') ?> <?= $modeTag('') ?></span>
    <?php endif ?>
    <span class="muted"><?= th('calls Claude Code asked you to approve (or probably asked) · newest first · click a row →') ?> <?= $all ? th('the call in its session') : th('the call') ?></span>
</div>
<?php if ($rows === []): ?>
    <p class="empty"><?= th('No approval prompts found in this scope. Without the hook only calls you rejected or that took at least {s} s are visible.', ['s' => LiveState::PERMISSION_GUESS]) ?></p>
<?php else: ?>
    <h3 class="approvals-h"><?= th('Most often asked') ?></h3>
    <table class="files calls approvals approvals-top">
        <thead><tr><th title="<?= th('last time it was asked') ?>"><?= th('time') ?></th><th><?= th('why') ?></th></tr></thead>
        <tbody>
            <?php foreach ($view['approvalsTop'] as $g): ?>
                <?= $openRow($g['last'], $g['shape'] . ' ×' . $g['count'] . ' · ' . $g['last']['summary']) ?>
                    <td><span class="note" title="<?= h($g['shape']) ?>">×<?= h($g['count']) ?></span><?= $why($g['causes'], $g['last']['mode'] ?? null, $rowCwd($g['last'])) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>

    <table class="files calls approvals">
        <thead><tr><th><?= th('time') ?></th><th><?= th('why') ?></th><?php if ($all): ?><th><?= th('session') ?></th><?php endif ?></tr></thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <?= $openRow($r, $r['summary']) ?>
                    <td>
                        <?php foreach ($r['how'] as $how): ?><?= $howLabel($how) ?><?php endforeach ?><?= $r['ms'] !== null ? '<span class="note">' . h(App::duration($r['ms'])) . '</span>' : '' ?>
                        <?= $why($r['causes'], $r['mode'] ?? null, $rowCwd($r)) ?>
                    </td>
                    <?php if ($all): ?>
                        <td class="sess"><a href="/?s=<?= h($r['session_id']) ?>&view=approvals" title="<?= h(basename($r['cwd'])) ?>"><?= h($r['title'] !== '' ? $r['title'] : substr($r['session_id'], 0, 8)) ?></a></td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
    <?php if (count($rows) < $total): ?>
        <p class="muted list-more"><?= th('shown {n} of {total} ·', ['n' => count($rows), 'total' => $total]) ?>
            <a href="/?<?= h(App::query($view, ['limit' => 'all'])) ?>"><?= th('show all') ?></a> · <?= th('or narrow with search, period or project') ?></p>
    <?php endif ?>
<?php endif ?>
