<?php

declare(strict_types=1);

/*
 * Tests of the Approvals tab: permission rule matching (RuleMatcher), settings reading (Settings — only the `permissions` key),
 * rows and tooltips (Approvals), the hook allowlist for PermissionRequest. Settings from FIXTURES (files in a temporary directory),
 * not from the real `~/.claude` — matching checked only on examples from the documentation and from the user's sessions.
 * Loaded by tests/run.php; returns a list of [name, actual, expected]. `$root` = the tests' temporary directory.
 */

use Viz\Extract\ToolTouches;
use Viz\Model\Event;
use Viz\Permissions\Approvals;
use Viz\Permissions\RuleMatcher;
use Viz\Permissions\Settings;
use Viz\Source\HookLog;
use Viz\Source\HookRecorder;

/** @var string $root */
$fx = "$root/approvals";
mkdir("$fx/user/.claude", 0700, true);
mkdir("$fx/proj/.claude", 0700, true);
// fixture: `env` with a value that must not reach the app memory or the view
file_put_contents("$fx/user/.claude/settings.json", json_encode([
    'env' => ['SECRET_TOKEN' => 'CONTENT-env-{"permissions":1}'],
    'model' => 'opus',
    'permissions' => ['allow' => ['Bash(git add:*)', 'Bash(sed:*)', 'Bash(composer precommit)', 'Bash(git commit *)'], 'ask' => ['Bash(git commit:*)', 'Bash(git push:*)'],
        'deny' => ['Bash(rm -rf /*)'], 'defaultMode' => 'auto'],
    'hooks' => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'CONTENT-hook']]]]],
], JSON_PRETTY_PRINT));
file_put_contents("$fx/proj/.claude/settings.json", '{"permissions": {"allow": ["Bash(npm test *)"], "defaultMode": "default"}}');
file_put_contents("$fx/proj/.claude/settings.local.json", '{"permissions": {"defaultMode": "acceptEdits"}');
$settings = Settings::fromFiles([Settings::USER => "$fx/user/.claude/settings.json", Settings::PROJECT => "$fx/proj/.claude/settings.json", Settings::LOCAL => "$fx/proj/.claude/settings.local.json"]);
$only = static fn (array $perm): Settings => new Settings([['scope' => Settings::USER, 'file' => '~/.claude/settings.json', 'exists' => true,
    'allow' => $perm['allow'] ?? [], 'ask' => $perm['ask'] ?? [], 'deny' => $perm['deny'] ?? [], 'mode' => null]]);
$decide = static fn (string $cmd, Settings $s, string $tool = 'Bash'): string => (static function (array $d): string {
    return $d['decision'] . ($d['rule'] !== null ? ' ' . $d['rule'] : '') . ($d['segment'] !== null ? ' | ' . $d['segment'] : '') . ($d['missing'] !== [] ? ' | missing: ' . implode(' · ', $d['missing']) : '');
})(RuleMatcher::decide($tool, $tool === 'Bash' ? $cmd : null, $s));
$kinds = static fn (array $causes): string => implode(' ', array_map(static fn (array $c): string => $c['level'] . ':' . $c['kind'] . ($c['detail'] !== null ? '=' . $c['detail'] : ''), $causes));

// examples from the user's sessions (Claude Code messages: directory change before git, variable, "Ask rule Bash(git commit:*) overrides auto mode")
$example1 = "cd ~/workspace/project && sed -i '' 's/old/new/' file.md && git add file.md && git commit -q -m \"F12: description\"";
$example2 = "export PATH=\"/opt/homebrew/opt/php/bin:\$PATH\" PHP_INI_SCAN_DIR=\":\$TMPDIR/ini\"; composer precommit 2>&1 | tail -5; git add -A && git commit -m \"\$(cat <<'EOF'\ndescription\nEOF\n)\"";
$home = (string) getenv('HOME');

$tools = new ToolTouches();
$ev = static function (string $id, string $ts, ?string $end, string $tool, array $input, ?array $denial = null, string $agent = 'main') use ($tools): Event {
    $x = $tools->extract($tool, $input, '/w/repo');

    return new Event("2026-01-01T{$ts}Z", 's', $agent, $tool, $id, (string) ($input['command'] ?? $input['file_path'] ?? ''), '/w/repo', $denial !== null, $x->touches,
        endTs: $end !== null ? "2026-01-01T{$end}Z" : null, error: $denial !== null ? 'x' : null, commands: $x->commands, denial: $denial,
        command: $tool === 'Bash' ? (string) $input['command'] : null);
};
$events = [
    $ev('tu_wait', '10:00:00', '10:02:00', 'Bash', ['command' => 'git commit -m "a"']),
    $ev('tu_rej', '10:03:00', '10:03:20', 'Bash', ['command' => 'git commit -m "b"'], ['by' => 'user', 'detail' => null]),
    $ev('tu_fast', '10:04:00', '10:04:01', 'Bash', ['command' => 'ls']),
    $ev('tu_hook', '10:05:00', '10:05:30', 'Bash', ['command' => 'npm run build']),
    $ev('tu_edit', '10:06:00', '10:08:00', 'Edit', ['file_path' => '/w/repo/a.txt']),
];
$hooks = [
    ['ts' => '2026-01-01T10:05:02Z', 'event' => 'PermissionRequest', 'agent' => 'main', 'detail' => 'Bash', 'commands' => ['npm run'], 'rules' => ['Bash(npm run build)']],
    ['ts' => '2026-01-01T10:09:00Z', 'event' => 'PermissionRequest', 'agent' => 'main', 'detail' => 'WebFetch', 'commands' => [], 'rules' => []],
    ['ts' => '2026-01-01T10:04:00.500Z', 'event' => 'Notification', 'agent' => 'main', 'detail' => 'idle_prompt', 'commands' => [], 'rules' => []],
];
$rows = Approvals::rows($events, $hooks, $only(['ask' => ['Bash(git commit:*)'], 'allow' => ['Bash(git commit *)']]), '/w/repo', strtotime('2026-01-01T11:00:00Z'));
$top = Approvals::top($rows);

// hook: PermissionRequest — command shape instead of content, from the suggestions only toolName + ruleContent
$hookDir = "$root/hook-approvals";
$sid = '0b5c2c1e-1111-4222-8333-444455556668';
(new HookRecorder($hookDir))->record(['session_id' => $sid, 'hook_event_name' => 'PermissionRequest', 'cwd' => '/w', 'tool_name' => 'Bash', 'permission_mode' => 'acceptEdits',
    'tool_input' => ['command' => 'cd /w/other && git commit -m "CONTENT-message" && curl -d @CONTENT-file https://x', 'description' => 'CONTENT-desc'],
    'permission_suggestions' => [
        ['type' => 'addRules', 'rules' => [['toolName' => 'Bash', 'ruleContent' => 'git commit *'], ['toolName' => 'Bash', 'ruleContent' => "x\nCONTENT-ctrl"]], 'behavior' => 'allow', 'destination' => 'localSettings'],
        ['type' => 'setMode', 'mode' => 'acceptEdits', 'destination' => 'session'],
        ['type' => 'addDirectories', 'directories' => ['/CONTENT-dir'], 'destination' => 'session'],
    ]]);
(new HookRecorder($hookDir))->record(['session_id' => $sid, 'hook_event_name' => 'PermissionRequest', 'cwd' => '/w', 'tool_name' => 'Edit', 'tool_input' => ['file_path' => '/w/CONTENT-path', 'new_string' => 'CONTENT']]);
$hookRaw = (string) @file_get_contents("$hookDir/$sid.state.jsonl");
$hookState = HookLog::readState("$hookDir/$sid.state.jsonl");

// permission mode: settings inherited from `~/workspace` (fake HOME), `auto` in the project file ignored, modes from the transcript (`permissionMode` on user messages)
$fxHome = "$fx/home";
$office = "$fxHome/workspace";
$project = "$office/team/app";
mkdir("$fxHome/.claude", 0700, true);
mkdir("$office/.claude", 0700, true);
mkdir("$project/.claude", 0700, true);
file_put_contents("$fxHome/.claude/settings.json", '{"permissions": {"defaultMode": "default"}}');
file_put_contents("$office/.claude/settings.json", json_encode(['env' => ['SECRET_TOKEN' => 'CONTENT-office'], 'permissions' => ['allow' => ['Bash(make *)'], 'defaultMode' => 'dontAsk']]));
file_put_contents("$project/.claude/settings.json", '{"permissions": {"defaultMode": "auto"}}');
$realHome = getenv('HOME');
putenv("HOME=$fxHome");
$inOffice = Settings::forSession($project, [$office]);
$atOffice = Settings::forSession($office, [$office]);
$elsewhere = Settings::forSession('/w/repo', [$office]);
putenv('HOME=' . $realHome);
$projectAuto = Settings::fromFiles([Settings::USER => "$fxHome/.claude/settings.json", Settings::PROJECT => "$project/.claude/settings.json"]);
$tdir = "$fx/transcript";
mkdir($tdir, 0700, true);
$tline = static fn (string $ts, array $extra): string => json_encode(['type' => 'user', 'timestamp' => "2026-01-01T{$ts}Z", 'cwd' => '/w/repo'] + $extra) . "\n";
file_put_contents("$tdir/0b5c2c1e-1111-4222-8333-444455556669.jsonl", implode('', [
    $tline('09:00:00', ['message' => ['role' => 'user', 'content' => 'pierwsze'], 'permissionMode' => 'auto']),
    $tline('09:30:00', ['message' => ['role' => 'user', 'content' => 'drugie'], 'permissionMode' => 'auto']),
    $tline('10:00:30', ['message' => ['role' => 'user', 'content' => 'trzecie'], 'permissionMode' => 'default']),
    $tline('10:20:00', ['message' => ['role' => 'user', 'content' => 'fourth'], 'permissionMode' => 'CONTENT-unknown']),
]));
$transcriptModes = (new \Viz\Source\TranscriptReader())->read("$tdir/0b5c2c1e-1111-4222-8333-444455556669.jsonl")->modes;
$modeRows = Approvals::rows($events, [['mode' => 'plan'] + $hooks[0], ...array_slice($hooks, 1)],
    $only(['ask' => ['Bash(git commit:*)']]), '/w/repo', strtotime('2026-01-01T11:00:00Z'), $transcriptModes);

return [
    // rule syntax per the documentation: `*` after a space also matches the bare command, without a space — a longer word, `:*` = ` *`
    ['permissions: Bash rule patterns', [
        RuleMatcher::bashMatches('ls *', 'ls'), RuleMatcher::bashMatches('ls *', 'ls -la'), RuleMatcher::bashMatches('ls *', 'lsof'), RuleMatcher::bashMatches('ls*', 'lsof'),
        RuleMatcher::bashMatches('git commit:*', 'git commit -q -m x'), RuleMatcher::bashMatches('git commit:*', 'git commit'), RuleMatcher::bashMatches('git commit:*', 'git commitx'),
        RuleMatcher::bashMatches('git * main', 'git push origin main'), RuleMatcher::bashMatches('git * main', 'git log'), RuleMatcher::bashMatches('npm run build', 'npm run build --watch'),
        RuleMatcher::bashMatches('* --help *', 'npm --help'), RuleMatcher::bashMatches('git:* push', 'git push'),
    ], [true, true, false, true, true, true, false, true, false, false, false, false]],
    // example 1: the ask rule wins over allow `Bash(git commit *)` (order deny → ask → allow); the part of the command that matched
    ['permissions: example 1 — ask rule over allow, cd before git', [$decide($example1, $settings), $kinds(Approvals::causes('Bash', $example1, '/w/repo', $settings))],
        ['ask Bash(git commit:*) | git commit -q -m F12: description', "rule:ask-rule=git commit -q -m F12: description heuristic:cd-git=$home/workspace/project"]],
    // example 2: variables ($PATH, $TMPDIR, PATH assignment); `$(cat <<EOF …)` in a commit description is not a variable
    ['permissions: example 2 — ask rule, variables', [$decide($example2, $settings), $kinds(Approvals::causes('Bash', $example2, '/w/repo', $settings))],
        ['ask Bash(git commit:*) | git commit -m ', 'rule:ask-rule=git commit -m  heuristic:variable=PATH $PATH $TMPDIR']],
    // loop: a variable assigned in the command (`for r in`, `X=…`) has a known value; `do` before the command stripped; redirection after cd and to a variable;
    // `cd .` and `/dev/null` are not a cause; `cd` outside the session directory without git; read-only commands alone in the session directory = unknown
    ['permissions: heuristics — assigned variables, loop body, redirects', [
        $kinds(Approvals::causes('Bash', 'for r in a b; do cd /w/$r && git status > out.txt; done', '/w/repo', $only([]))),
        $kinds(Approvals::causes('Bash', 'D=/w/x; ls $D 2>/dev/null > "$LOGDIR/l.txt"', '/w/repo', $only([]))),
        $kinds(Approvals::causes('Bash', 'cd . && git status 2>/dev/null; echo ok >> /tmp/o.txt', '/w/repo', $only([]))),
        $kinds(Approvals::causes('Bash', 'npm run build', '/w/repo', $only([]))),
        $kinds(Approvals::causes('Bash', 'cd /elsewhere; grep -rl x .', '/w/repo', $only([]))),
        $kinds(Approvals::causes('Bash', 'cd /w/repo/sub && ls', '/w/repo', $only([]))),
    ], ['heuristic:cd-git=/w/$r heuristic:redirect=out.txt', 'heuristic:variable=$LOGDIR heuristic:redirect=$LOGDIR/l.txt', 'heuristic:redirect=/tmp/o.txt',
        'heuristic:no-allow=npm run build', 'heuristic:cd-outside=/elsewhere', 'unknown:unknown']],
    // compound command: allow requires every part (read-only commands without a rule), deny/ask — any; deny before ask.
    // Simplification: `$(…)` in quotes is not split (Claude Code splits it and would ask) — here it comes out `allow`
    ['permissions: compound commands, deny before ask, wrappers and assignments', [
        $decide('npm test && git status', $only(['allow' => ['Bash(npm test *)', 'Bash(git status)']])),
        $decide('cd sub && npm test | grep ok', $only(['allow' => ['Bash(npm test *)']])),
        $decide('npm test && git push origin main', $only(['allow' => ['Bash(npm test *)']])),
        $decide('git push origin main', $only(['allow' => ['Bash(git push *)'], 'ask' => ['Bash(git push:*)'], 'deny' => ['Bash(git push origin *)']])),
        $decide('timeout 30 npm test', $only(['allow' => ['Bash(npm test *)']])),
        $decide('FOO=bar rm -rf tmp', $only(['deny' => ['Bash(rm *)']])),
        $decide('FOO=bar rm -rf tmp', $only(['allow' => ['Bash(rm *)']])),
        $decide('echo "$(git clean -f)"', $only(['ask' => ['Bash(git clean *)']])),
        $decide('ls; (cd /tmp && git clean -f)', $only(['ask' => ['Bash(git clean *)']])),
    ], ['allow', 'allow', 'none | missing: git push origin main', 'deny Bash(git push origin *) | git push origin main', 'allow',
        'deny Bash(rm *) | rm -rf tmp', 'none | missing: FOO=bar rm -rf tmp', 'allow', 'ask Bash(git clean *) | git clean -f']],
    // other tools: name only (bare, `Tool(*)`, MCP glob); allow with a glob without a server prefix allows nothing
    ['permissions: tool-name rules', [
        $decide('', $only(['ask' => ['Edit']]), 'Edit'), $decide('', $only(['allow' => ['mcp__srv__*']]), 'mcp__srv__get'),
        $decide('', $only(['allow' => ['*']]), 'WebFetch'), $decide('', $only(['ask' => ['mcp__*']]), 'mcp__srv__get'), $decide('', $only(['allow' => ['Read(./src/**)']]), 'Read'),
    ], ['ask Edit', 'allow mcp__srv__*', 'none | missing: WebFetch', 'ask mcp__*', 'none | missing: Read']],
    // settings: only the `permissions` key decoded; env/hooks do not reach the result; lists merged, defaultMode from the top level;
    // invalid JSON (a local file without a closing brace) = empty
    ['permissions: settings — permissions key only, lists merged, mode precedence', [
        str_contains(json_encode($settings->sources), 'CONTENT'), array_column($settings->rules('ask'), 'rule'), count($settings->rules('allow')), $settings->mode(),
        array_column($settings->sources, 'scope'), Settings::permissionsJson('{"a": "}\"{", "b": [1, {"c": "]"}], "permissions": {"allow": ["Bash(x)"]}, "z": 1}'),
        Settings::permissionsJson('{"env": {"permissions": {"allow": ["evil"]}}}'), Settings::permissionsJson('[1]'), Settings::permissionsJson('{"permissions": [1]}'),
    ], [false, ['Bash(git commit:*)', 'Bash(git push:*)'], 5, 'default', ['user', 'project', 'local'], ['allow' => ['Bash(x)']], null, null, null]],
    // rows: rejected by the user, waited ≥ 90 s (also Edit), request from the hook during a fast call, request without a call;
    // a fast call without the hook and an idle_prompt notification — skipped
    ['approvals: rows — hook, rejected, waited; unmatched hook request', array_map(static fn (array $r): string => ($r['id'] ?? '-') . ':' . implode('+', $r['how']) . ':' . $r['shape']
        . ':' . $r['causes'][0]['kind'], $rows),
        ['-:hook:WebFetch:no-allow', 'tu_edit:waited:Edit:no-allow', 'tu_hook:hook:npm run:no-allow', 'tu_rej:rejected:git commit:ask-rule', 'tu_wait:waited:git commit:ask-rule']],
    // most frequently asked: shape → count, last occurrence (rows from newest)
    ['approvals: top shapes', array_map(static fn (array $g): string => $g['shape'] . '=' . $g['count'] . '@' . ($g['last']['id'] ?? '-') . ' ' . $g['last']['ts'], $top),
        ['git commit=2@tu_rej 2026-01-01T10:03:00Z', 'Edit=1@tu_edit 2026-01-01T10:06:00Z', 'WebFetch=1@- 2026-01-01T10:09:00Z', 'npm run=1@tu_hook 2026-01-01T10:05:00Z']],
    // settings: `~/workspace/.claude/settings.json` inherited only by sessions below `~/workspace`, between user and project;
    // `auto` in the project file does not work (permission modes docs); only `permissions` read from the inherited file
    ['permissions: settings — inherited inherited file, auto in project file ignored', [
        array_column($inOffice->sources, 'scope'), $inOffice->source(Settings::INHERITED)['file'] ?? null, $inOffice->mode(), $inOffice->modeFile(),
        array_column($inOffice->rules('allow'), 'rule'), str_contains(json_encode($inOffice->sources), 'CONTENT'),
        array_column($atOffice->sources, 'scope'), array_column($elsewhere->sources, 'scope'), $projectAuto->mode(),
    ], [['user', 'inherited', 'project', 'local'], '~/workspace/.claude/settings.json', 'dontAsk', '~/workspace/.claude/settings.json',
        ['Bash(make *)'], false, ['user', 'project', 'local'], ['user', 'project', 'local'], 'default']],
    // mode from the transcript: an entry at a change, outside the mode list skipped; call mode = the last message before it, a request from the hook takes precedence
    ['approvals: permission mode — transcript changes, mode at call, hook mode first', [
        array_map(static fn (array $m): string => substr($m['ts'], 11, 8) . '=' . $m['mode'], $transcriptModes),
        [Approvals::modeAt($transcriptModes, '2026-01-01T08:59:59Z'), Approvals::modeAt($transcriptModes, '2026-01-01T10:00:00Z'), Approvals::modeAt($transcriptModes, '2026-01-01T10:00:30Z')],
        array_map(static fn (array $r): string => ($r['id'] ?? '-') . ':' . ($r['mode'] ?? 'null'), $modeRows),
        array_column($hookState, 'mode'), Approvals::modeCounts($modeRows),
    ], [['09:00:00=auto', '10:00:30=default'], [null, 'auto', 'default'],
        ['-:default', 'tu_edit:default', 'tu_hook:plan', 'tu_rej:default', 'tu_wait:auto'], ['acceptEdits', null], ['default' => 3, 'plan' => 1, 'auto' => 1]]],
    // effect of the mode only from the mode documentation; other pairs (e.g. an ask rule in auto, default) = null
    ['approvals: permission mode effect on causes (docs only)', array_map(static fn (array $c): ?string => Approvals::modeEffect($c[0], $c[1]), [
        ['auto', 'no-allow'], ['auto', 'protected-path'], ['auto', 'ask-rule'], ['dontAsk', 'ask-rule'], ['dontAsk', 'variable'], ['dontAsk', 'unknown'],
        ['bypassPermissions', 'protected-path'], ['bypassPermissions', 'no-allow'], ['default', 'no-allow'], ['acceptEdits', 'protected-path'], [null, 'no-allow'],
    ]), ['classifier', 'classifier', null, 'denied', 'denied', null, 'allowed', null, null, null, null]],
    // hook: stored command shape (program + subcommand) and rules from the suggestions (toolName + ruleContent), without command content,
    // description, paths or other suggestion types; a rule with a control character rejected
    ['hook: PermissionRequest — command shape and suggested rules only', [str_contains($hookRaw, 'CONTENT'),
        array_map(static fn (array $h): string => $h['detail'] . ':' . implode(',', $h['commands']) . ':' . implode(',', $h['rules']), $hookState)],
        [false, ['Bash:cd,git commit,curl:Bash(git commit *)', 'Edit::']]],
    // protected paths (documentation "Protected paths"): directories as a path component, files by name, `.claude` exceptions
    ['approvals: protected paths', array_map([Approvals::class, 'protectedPath'], [
        '/w/repo/.claude/skills/sk-x/SKILL.md', '/w/repo/.git/config', '/home/u/.config/git/ignore', '/home/u/.zshrc', '/w/repo/.vscode/settings.json',
        '/w/repo/.claude/worktrees/a/b.php', '/home/u/.claude/projects/p/memory/note.md', '/home/u/.claude/projects/p/s.jsonl', '/w/repo/.github/workflows/ci.yml',
        '/w/repo/src/a.php', '/home/u/.config/gitx/a',
    ]), [true, true, true, true, true, false, false, true, false, false, false]],
    // cause: Edit in `.claude/skills` waited ≥ 90 s → protected path instead of "no allow rule"; Bash redirect to `.git`;
    // the ask rule stays first; a regular file — unchanged
    ['approvals: protected path cause before no-allow', [
        array_map(static fn (array $r): string => $r['id'] . ':' . $kinds($r['causes']), Approvals::rows([
            $ev('tu_skill', '11:00:00', '11:07:00', 'Edit', ['file_path' => '/w/repo/.claude/skills/sk-x/SKILL.md']),
            $ev('tu_plain', '11:10:00', '11:12:00', 'Write', ['file_path' => '/w/repo/a.txt']),
        ], [], $only([]), '/w/repo', strtotime('2026-01-01T12:00:00Z'))),
        $kinds(Approvals::causes('Bash', 'echo x >> .git/info/exclude', '/w/repo', $only([]))),
        $kinds(Approvals::causes('Edit', null, '/w/repo', $only(['ask' => ['Edit']]), ['/w/repo/.git/hooks/pre-commit'])),
    ], [['tu_plain:heuristic:no-allow=Write', 'tu_skill:heuristic:protected-path=/w/repo/.claude/skills/sk-x/SKILL.md'], 'heuristic:protected-path=.git/info/exclude',
        'rule:ask-rule heuristic:protected-path=/w/repo/.git/hooks/pre-commit']],
];
