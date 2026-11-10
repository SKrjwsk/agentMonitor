<?php

declare(strict_types=1);

/*
 * Testy zakładki „Zgody”: dopasowanie reguł uprawnień (RuleMatcher), odczyt ustawień (Settings — tylko klucz `permissions`),
 * wiersze i podpowiedzi (Approvals), biała lista hooka dla PermissionRequest. Ustawienia z FIXTURE (pliki w katalogu tymczasowym),
 * nie z prawdziwego `~/.claude` — dopasowanie sprawdzone tylko na przykładach z dokumentacji i z sesji usera.
 * Wczytywane przez tests/run.php; zwraca listę [nazwa, otrzymane, oczekiwane]. `$root` = katalog tymczasowy testów.
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
// fixture: `env` z wartością, która nie może trafić do pamięci aplikacji ani do widoku
file_put_contents("$fx/user/.claude/settings.json", json_encode([
    'env' => ['SECRET_TOKEN' => 'TRESC-env-{"permissions":1}'],
    'model' => 'opus',
    'permissions' => ['allow' => ['Bash(git add:*)', 'Bash(sed:*)', 'Bash(composer precommit)', 'Bash(git commit *)'], 'ask' => ['Bash(git commit:*)', 'Bash(git push:*)'],
        'deny' => ['Bash(rm -rf /*)'], 'defaultMode' => 'auto'],
    'hooks' => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'TRESC-hook']]]]],
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

// przykłady z sesji usera (komunikaty Claude Code: zmiana katalogu przed git, zmienna, „Ask rule Bash(git commit:*) overrides auto mode”)
$example1 = "cd ~/OFFICE/workbox9/CONTEXT_APP_SYSTEM && sed -i '' 's/stary/nowy/' plik.md && git add plik.md && git commit -q -m \"F12: opis\"";
$example2 = "export PATH=\"/opt/homebrew/opt/php/bin:\$PATH\" PHP_INI_SCAN_DIR=\":\$TMPDIR/ini\"; composer precommit 2>&1 | tail -5; git add -A && git commit -m \"\$(cat <<'EOF'\nopis\nEOF\n)\"";
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

// hook: PermissionRequest — kształt komendy zamiast treści, z propozycji tylko toolName + ruleContent
$hookDir = "$root/hook-approvals";
$sid = '0b5c2c1e-1111-4222-8333-444455556668';
(new HookRecorder($hookDir))->record(['session_id' => $sid, 'hook_event_name' => 'PermissionRequest', 'cwd' => '/w', 'tool_name' => 'Bash', 'permission_mode' => 'acceptEdits',
    'tool_input' => ['command' => 'cd /w/other && git commit -m "TRESC-message" && curl -d @TRESC-file https://x', 'description' => 'TRESC-desc'],
    'permission_suggestions' => [
        ['type' => 'addRules', 'rules' => [['toolName' => 'Bash', 'ruleContent' => 'git commit *'], ['toolName' => 'Bash', 'ruleContent' => "x\nTRESC-ctrl"]], 'behavior' => 'allow', 'destination' => 'localSettings'],
        ['type' => 'setMode', 'mode' => 'acceptEdits', 'destination' => 'session'],
        ['type' => 'addDirectories', 'directories' => ['/TRESC-dir'], 'destination' => 'session'],
    ]]);
(new HookRecorder($hookDir))->record(['session_id' => $sid, 'hook_event_name' => 'PermissionRequest', 'cwd' => '/w', 'tool_name' => 'Edit', 'tool_input' => ['file_path' => '/w/TRESC-path', 'new_string' => 'TRESC']]);
$hookRaw = (string) @file_get_contents("$hookDir/$sid.state.jsonl");
$hookState = HookLog::readState("$hookDir/$sid.state.jsonl");

// tryb uprawnień: plik `--settings` workbox9 (sztuczny HOME), `auto` w pliku projektu pomijany, tryby z transkryptu (`permissionMode` przy wiadomości usera)
$fxHome = "$fx/home";
$workbox = "$fxHome/OFFICE/workbox9";
mkdir("$fxHome/.claude", 0700, true);
mkdir("$workbox/.claude", 0700, true);
mkdir("$workbox/CONTEXT_X/.claude", 0700, true);
file_put_contents("$fxHome/.claude/settings.json", '{"permissions": {"defaultMode": "default"}}');
file_put_contents("$workbox/.claude/settings.json", json_encode(['env' => ['SECRET_TOKEN' => 'TRESC-workbox'], 'permissions' => ['allow' => ['Bash(make *)'], 'defaultMode' => 'dontAsk']]));
file_put_contents("$workbox/CONTEXT_X/.claude/settings.json", '{"permissions": {"defaultMode": "auto"}}');
$realHome = getenv('HOME');
putenv("HOME=$fxHome");
$inWorkbox = Settings::forSession("$workbox/CONTEXT_X");
$atWorkbox = Settings::forSession($workbox);
$elsewhere = Settings::forSession('/w/repo');
putenv('HOME=' . $realHome);
$projectAuto = Settings::fromFiles([Settings::USER => "$fxHome/.claude/settings.json", Settings::PROJECT => "$workbox/CONTEXT_X/.claude/settings.json"]);
$tdir = "$fx/transcript";
mkdir($tdir, 0700, true);
$tline = static fn (string $ts, array $extra): string => json_encode(['type' => 'user', 'timestamp' => "2026-01-01T{$ts}Z", 'cwd' => '/w/repo'] + $extra) . "\n";
file_put_contents("$tdir/0b5c2c1e-1111-4222-8333-444455556669.jsonl", implode('', [
    $tline('09:00:00', ['message' => ['role' => 'user', 'content' => 'pierwsze'], 'permissionMode' => 'auto']),
    $tline('09:30:00', ['message' => ['role' => 'user', 'content' => 'drugie'], 'permissionMode' => 'auto']),
    $tline('10:00:30', ['message' => ['role' => 'user', 'content' => 'trzecie'], 'permissionMode' => 'default']),
    $tline('10:20:00', ['message' => ['role' => 'user', 'content' => 'czwarte'], 'permissionMode' => 'TRESC-unknown']),
]));
$transcriptModes = (new \Viz\Source\TranscriptReader())->read("$tdir/0b5c2c1e-1111-4222-8333-444455556669.jsonl")->modes;
$modeRows = Approvals::rows($events, [['mode' => 'plan'] + $hooks[0], ...array_slice($hooks, 1)],
    $only(['ask' => ['Bash(git commit:*)']]), '/w/repo', strtotime('2026-01-01T11:00:00Z'), $transcriptModes);

return [
    // składnia reguł według dokumentacji: `*` po spacji pasuje też do samej komendy, bez spacji — do dłuższego słowa, `:*` = ` *`
    ['permissions: Bash rule patterns', [
        RuleMatcher::bashMatches('ls *', 'ls'), RuleMatcher::bashMatches('ls *', 'ls -la'), RuleMatcher::bashMatches('ls *', 'lsof'), RuleMatcher::bashMatches('ls*', 'lsof'),
        RuleMatcher::bashMatches('git commit:*', 'git commit -q -m x'), RuleMatcher::bashMatches('git commit:*', 'git commit'), RuleMatcher::bashMatches('git commit:*', 'git commitx'),
        RuleMatcher::bashMatches('git * main', 'git push origin main'), RuleMatcher::bashMatches('git * main', 'git log'), RuleMatcher::bashMatches('npm run build', 'npm run build --watch'),
        RuleMatcher::bashMatches('* --help *', 'npm --help'), RuleMatcher::bashMatches('git:* push', 'git push'),
    ], [true, true, false, true, true, true, false, true, false, false, false, false]],
    // przykład 1: reguła ask wygrywa z allow `Bash(git commit *)` (kolejność deny → ask → allow); część komendy, która pasowała
    ['permissions: example 1 — ask rule over allow, cd before git', [$decide($example1, $settings), $kinds(Approvals::causes('Bash', $example1, '/w/repo', $settings))],
        ['ask Bash(git commit:*) | git commit -q -m F12: opis', "rule:ask-rule=git commit -q -m F12: opis heuristic:cd-git=$home/OFFICE/workbox9/CONTEXT_APP_SYSTEM"]],
    // przykład 2: zmienne ($PATH, $TMPDIR, przypisanie PATH); `$(cat <<EOF …)` w opisie commita to nie zmienna
    ['permissions: example 2 — ask rule, variables', [$decide($example2, $settings), $kinds(Approvals::causes('Bash', $example2, '/w/repo', $settings))],
        ['ask Bash(git commit:*) | git commit -m ', 'rule:ask-rule=git commit -m  heuristic:variable=PATH $PATH $TMPDIR']],
    // pętla: zmienna przypisana w komendzie (`for r in`, `X=…`) ma znaną wartość; `do` przed komendą zdjęte; przekierowanie po cd i do zmiennej;
    // `cd .` i `/dev/null` nie są przyczyną; `cd` poza katalog sesji bez git; same komendy tylko do odczytu w katalogu sesji = nieznana
    ['permissions: heuristics — assigned variables, loop body, redirects', [
        $kinds(Approvals::causes('Bash', 'for r in a b; do cd /w/$r && git status > out.txt; done', '/w/repo', $only([]))),
        $kinds(Approvals::causes('Bash', 'D=/w/x; ls $D 2>/dev/null > "$LOGDIR/l.txt"', '/w/repo', $only([]))),
        $kinds(Approvals::causes('Bash', 'cd . && git status 2>/dev/null; echo ok >> /tmp/o.txt', '/w/repo', $only([]))),
        $kinds(Approvals::causes('Bash', 'npm run build', '/w/repo', $only([]))),
        $kinds(Approvals::causes('Bash', 'cd /elsewhere; grep -rl x .', '/w/repo', $only([]))),
        $kinds(Approvals::causes('Bash', 'cd /w/repo/sub && ls', '/w/repo', $only([]))),
    ], ['heuristic:cd-git=/w/$r heuristic:redirect=out.txt', 'heuristic:variable=$LOGDIR heuristic:redirect=$LOGDIR/l.txt', 'heuristic:redirect=/tmp/o.txt',
        'heuristic:no-allow=npm run build', 'heuristic:cd-outside=/elsewhere', 'unknown:unknown']],
    // komenda złożona: allow wymaga każdej części (komendy tylko do odczytu bez reguły), deny/ask — dowolnej; deny przed ask.
    // Uproszczenie: `$(…)` w cudzysłowie nie jest rozbijane (Claude Code je rozbija i zapytałby) — tu wychodzi `allow`
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
    // inne narzędzia: tylko nazwa (gołe, `Tool(*)`, glob MCP); allow z globem bez prefiksu serwera nic nie pozwala
    ['permissions: tool-name rules', [
        $decide('', $only(['ask' => ['Edit']]), 'Edit'), $decide('', $only(['allow' => ['mcp__srv__*']]), 'mcp__srv__get'),
        $decide('', $only(['allow' => ['*']]), 'WebFetch'), $decide('', $only(['ask' => ['mcp__*']]), 'mcp__srv__get'), $decide('', $only(['allow' => ['Read(./src/**)']]), 'Read'),
    ], ['ask Edit', 'allow mcp__srv__*', 'none | missing: WebFetch', 'ask mcp__*', 'none | missing: Read']],
    // ustawienia: dekodowany tylko klucz `permissions`; env/hooks nie trafiają do wyniku; listy łączone, defaultMode z najwyższego poziomu;
    // niepoprawny JSON (plik lokalny bez zamknięcia) = pusty
    ['permissions: settings — permissions key only, lists merged, mode precedence', [
        str_contains(json_encode($settings->sources), 'TRESC'), array_column($settings->rules('ask'), 'rule'), count($settings->rules('allow')), $settings->mode(),
        array_column($settings->sources, 'scope'), Settings::permissionsJson('{"a": "}\"{", "b": [1, {"c": "]"}], "permissions": {"allow": ["Bash(x)"]}, "z": 1}'),
        Settings::permissionsJson('{"env": {"permissions": {"allow": ["evil"]}}}'), Settings::permissionsJson('[1]'), Settings::permissionsJson('{"permissions": [1]}'),
    ], [false, ['Bash(git commit:*)', 'Bash(git push:*)'], 5, 'default', ['user', 'project', 'local'], ['allow' => ['Bash(x)']], null, null, null]],
    // wiersze: odrzucone przez usera, czekało ≥ 90 s (też Edit), prośba z hooka w trakcie szybkiego wywołania, prośba bez wywołania;
    // szybkie wywołanie bez hooka i powiadomienie idle_prompt — pominięte
    ['approvals: rows — hook, rejected, waited; unmatched hook request', array_map(static fn (array $r): string => ($r['id'] ?? '-') . ':' . implode('+', $r['how']) . ':' . $r['shape']
        . ':' . $r['causes'][0]['kind'], $rows),
        ['-:hook:WebFetch:no-allow', 'tu_edit:waited:Edit:no-allow', 'tu_hook:hook:npm run:no-allow', 'tu_rej:rejected:git commit:ask-rule', 'tu_wait:waited:git commit:ask-rule']],
    // najczęściej pytane: kształt → liczba, ostatnie wystąpienie (wiersze od najnowszego)
    ['approvals: top shapes', array_map(static fn (array $g): string => $g['shape'] . '=' . $g['count'] . '@' . ($g['last']['id'] ?? '-') . ' ' . $g['last']['ts'], $top),
        ['git commit=2@tu_rej 2026-01-01T10:03:00Z', 'Edit=1@tu_edit 2026-01-01T10:06:00Z', 'WebFetch=1@- 2026-01-01T10:09:00Z', 'npm run=1@tu_hook 2026-01-01T10:05:00Z']],
    // ustawienia: plik `--settings` (`~/OFFICE/workbox9/.claude/settings.json`) tylko dla sesji w podkatalogu workbox9, najwyższy poziom;
    // `auto` w pliku projektu nie działa (dokumentacja trybów); z pliku workbox9 tylko `permissions`
    ['permissions: settings — workbox9 --settings file, auto in project file ignored', [
        array_column($inWorkbox->sources, 'scope'), $inWorkbox->source(Settings::CLI)['file'] ?? null, $inWorkbox->mode(), $inWorkbox->modeFile(),
        array_column($inWorkbox->rules('allow'), 'rule'), str_contains(json_encode($inWorkbox->sources), 'TRESC'),
        array_column($atWorkbox->sources, 'scope'), array_column($elsewhere->sources, 'scope'), $projectAuto->mode(),
    ], [['user', 'project', 'local', 'cli'], '~/OFFICE/workbox9/.claude/settings.json', 'dontAsk', '~/OFFICE/workbox9/.claude/settings.json',
        ['Bash(make *)'], false, ['user', 'project', 'local'], ['user', 'project', 'local'], 'default']],
    // tryb z transkryptu: wpis przy zmianie, spoza listy trybów pominięty; tryb wywołania = ostatnia wiadomość przed nim, prośba z hooka ma pierwszeństwo
    ['approvals: permission mode — transcript changes, mode at call, hook mode first', [
        array_map(static fn (array $m): string => substr($m['ts'], 11, 8) . '=' . $m['mode'], $transcriptModes),
        [Approvals::modeAt($transcriptModes, '2026-01-01T08:59:59Z'), Approvals::modeAt($transcriptModes, '2026-01-01T10:00:00Z'), Approvals::modeAt($transcriptModes, '2026-01-01T10:00:30Z')],
        array_map(static fn (array $r): string => ($r['id'] ?? '-') . ':' . ($r['mode'] ?? 'null'), $modeRows),
        array_column($hookState, 'mode'), Approvals::modeCounts($modeRows),
    ], [['09:00:00=auto', '10:00:30=default'], [null, 'auto', 'default'],
        ['-:default', 'tu_edit:default', 'tu_hook:plan', 'tu_rej:default', 'tu_wait:auto'], ['acceptEdits', null], ['default' => 3, 'plan' => 1, 'auto' => 1]]],
    // skutek trybu tylko z dokumentacji trybów; reszta par (np. reguła ask w auto, default) = null
    ['approvals: permission mode effect on causes (docs only)', array_map(static fn (array $c): ?string => Approvals::modeEffect($c[0], $c[1]), [
        ['auto', 'no-allow'], ['auto', 'protected-path'], ['auto', 'ask-rule'], ['dontAsk', 'ask-rule'], ['dontAsk', 'variable'], ['dontAsk', 'unknown'],
        ['bypassPermissions', 'protected-path'], ['bypassPermissions', 'no-allow'], ['default', 'no-allow'], ['acceptEdits', 'protected-path'], [null, 'no-allow'],
    ]), ['classifier', 'classifier', null, 'denied', 'denied', null, 'allowed', null, null, null, null]],
    // hook: zapisany kształt komendy (program + podkomenda) i reguły z propozycji (toolName + ruleContent), bez treści komendy,
    // opisu, ścieżek ani innych typów propozycji; reguła ze znakiem sterującym odrzucona
    ['hook: PermissionRequest — command shape and suggested rules only', [str_contains($hookRaw, 'TRESC'),
        array_map(static fn (array $h): string => $h['detail'] . ':' . implode(',', $h['commands']) . ':' . implode(',', $h['rules']), $hookState)],
        [false, ['Bash:cd,git commit,curl:Bash(git commit *)', 'Edit::']]],
    // chronione ścieżki (dokumentacja „Protected paths”): katalogi jako składnik ścieżki, pliki po nazwie, wyjątki `.claude`
    ['approvals: protected paths', array_map([Approvals::class, 'protectedPath'], [
        '/w/repo/.claude/skills/sk-x/SKILL.md', '/w/repo/.git/config', '/home/u/.config/git/ignore', '/home/u/.zshrc', '/w/repo/.vscode/settings.json',
        '/w/repo/.claude/worktrees/a/b.php', '/home/u/.claude/projects/p/memory/note.md', '/home/u/.claude/projects/p/s.jsonl', '/w/repo/.github/workflows/ci.yml',
        '/w/repo/src/a.php', '/home/u/.config/gitx/a',
    ]), [true, true, true, true, true, false, false, true, false, false, false]],
    // przyczyna: Edit w `.claude/skills` czekał ≥ 90 s → chroniona ścieżka zamiast „brak reguły allow”; przekierowanie Bash do `.git`;
    // reguła ask zostaje pierwsza; zwykły plik — bez zmian
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
