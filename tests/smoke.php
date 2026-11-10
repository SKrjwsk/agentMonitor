<?php

declare(strict_types=1);

/*
 * Test dymny widoków na transkrypcie z fixture. Uruchomienie: php tests/smoke.php
 * Serwer (`php -S`) działa na kopii aplikacji (src, templates, public/index.php) i sztucznym HOME z transkryptem sesji
 * (sesja główna, subagent, blokada hooka, wywołanie poza granicą, Bash z kodem ≠ 0, dwie tury). Nie dotyka ani `~/.claude`,
 * ani `var/` repozytorium (agregaty i zrzuty trafiają do `var/` kopii), a granica pracy agentów idzie zmienną procesu.
 * Sprawdza: kod odpowiedzi każdej trasy, brak ostrzeżeń PHP (w odpowiedzi i w logu serwera), kilka treści i bramki dostępu.
 */

$repo = dirname(__DIR__);
$root = (string) realpath(sys_get_temp_dir()) . '/viz-smoke-' . getmypid();
$work = "$root/work";
$outside = "$root/elsewhere";
$app = "$root/app";
$home = "$root/home";
$checks = 0;
$failures = [];

$copy = static function (string $from, string $to) use (&$copy): void {
    mkdir($to, 0700, true);
    foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $name) {
        is_dir("$from/$name") ? $copy("$from/$name", "$to/$name") : copy("$from/$name", "$to/$name");
    }
};
$remove = static function (string $path): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($path);
};

$server = null;
try {
    foreach ([$work, $outside, "$app/public", "$app/lang"] as $dir) {
        mkdir($dir, 0700, true);
    }
    $copy("$repo/src", "$app/src");
    $copy("$repo/templates", "$app/templates");
    copy("$repo/public/index.php", "$app/public/index.php");
    // nazwy plików i komend z fixture nie występują w promptach ani w tytule sesji (te są w lewym panelu każdej strony),
    // więc asercje na nich zawodzą, gdy widok nie pokazuje wywołań
    file_put_contents("$work/alpha-report.txt", "a\n");
    file_put_contents("$work/beta-notes.txt", "b\n");
    file_put_contents("$outside/data.txt", "d\n");

    // fixture: transkrypt sesji z subagentem w sztucznym HOME
    $sid = '11111111-2222-4333-8444-555555555555';
    $now = time();
    $at = static fn (int $ago): string => gmdate('Y-m-d\TH:i:s\Z', $now - $ago);
    $line = static fn (string $type, int $ago, array $content, array $extra = []): string => json_encode(
        ['type' => $type, 'timestamp' => $at($ago), 'cwd' => $work, 'message' => ['role' => $type, 'content' => $content]] + $extra,
    ) . "\n";
    $say = static fn (int $ago, string $id, array $content): string => json_encode(['type' => 'assistant', 'timestamp' => $at($ago), 'cwd' => $work,
        'message' => ['id' => $id, 'model' => 'claude-opus-5-5', 'role' => 'assistant', 'stop_reason' => 'end_turn', 'content' => $content,
            'usage' => ['input_tokens' => 3, 'output_tokens' => 120, 'cache_read_input_tokens' => 20000, 'cache_creation_input_tokens' => 800]]]) . "\n";
    $use = static fn (string $id, string $tool, array $input): array => ['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => $input];
    $result = static fn (string $id, string $text, bool $error = false): array => ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $text] + ($error ? ['is_error' => true] : []);

    $projects = "$home/.claude/projects/-viz-smoke";
    mkdir("$projects/$sid/subagents", 0700, true);
    file_put_contents("$projects/$sid.jsonl",
        // czas narzędzi i wyposażenie: Bash dłuższy niż próg „zgoda?” (90 s), serwer MCP, skill; wynik zapisany w `tool-results/`
        $line('user', 400, [['type' => 'text', 'text' => 'Zmierz czas']], ['permissionMode' => 'auto'])
        . $say(395, 'm0a', [$use('tu_slowtool', 'Bash', ['command' => 'slowtool --wait'])])
        . $line('user', 300, [$result('tu_slowtool', 'ok')])
        . $say(299, 'm0b', [$use('tu_mcp', 'mcp__smokesrv__lookup', ['q' => 'x'])])
        . $line('user', 298, [$result('tu_mcp', 'ok')])
        . $say(297, 'm0c', [$use('tu_skill', 'Skill', ['skill' => 'sk-smoke-skill'])])
        . $line('user', 296, [$result('tu_skill', 'ok')])
        . $say(295, 'm0d', [['type' => 'text', 'text' => 'Zmierzone']])
        . $line('user', 120, [['type' => 'text', 'text' => 'Przeczytaj pierwszy plik i uruchom agenta']])
        . $say(115, 'm1', [$use('tu_read', 'Read', ['file_path' => "$work/alpha-report.txt"])])
        . $line('user', 114, [$result('tu_read', 'a')])
        . $say(110, 'm2', [$use('tu_bash', 'Bash', ['command' => 'cat beta-notes.txt && ls', 'description' => 'czytaj drugi'])])
        . $line('user', 109, [$result('tu_bash', 'b')])
        . $say(105, 'm3', [$use('tu_agent', 'Agent', ['description' => 'szukaj', 'subagent_type' => 'Explore', 'prompt' => 'znajdz foo'])])
        . $line('user', 90, [$result('tu_agent', 'znaleziono')])
        . $say(85, 'm4', [$use('tu_out', 'Bash', ['command' => "cat $outside/data.txt"])])
        . $line('user', 84, [$result('tu_out', 'd')])
        . $say(80, 'm5', [$use('tu_stop', 'Read', ['file_path' => "$outside/data.txt"])])
        . $line('user', 79, [$result('tu_stop', 'Error: PreToolUse:Read hook error: [bash "$HOME/.claude/hooks/example-hook.sh"]: BLOCKED by example hook: denied by rule matching \'rule-x\'', true)],
            ['toolDenialKind' => 'permission-rule', 'permissionDecision' => ['decision' => 'reject', 'source' => 'hook', 'reasonType' => 'hook']])
        // narzędzie o nazwie zaczynającej się od `=` (nazwa z niezaufanego transkryptu) zablokowane hookiem — komórka CSV nie może być formułą
        . $say(78, 'm5b', [$use('tu_evil', '=EVIL', [])])
        . $line('user', 77, [$result('tu_evil', 'Error: PreToolUse hook error: [bash "$HOME/.claude/hooks/example-hook.sh"]: BLOCKED by example hook: denied by rule matching \'rule-x\'', true)],
            ['toolDenialKind' => 'permission-rule', 'permissionDecision' => ['decision' => 'reject', 'source' => 'hook', 'reasonType' => 'hook']])
        . $line('user', 60, [['type' => 'text', 'text' => 'Popraw drugi plik']])
        . $say(55, 'm6', [$use('tu_fail', 'Bash', ['command' => 'uniqtool run'])])
        . $line('user', 54, [$result('tu_fail', "Exit code 127\nzsh: command not found: uniqtool", true)])
        . $say(50, 'm7', [$use('tu_edit', 'Edit', ['file_path' => "$work/beta-notes.txt", 'old_string' => 'b', 'new_string' => 'bb'])])
        . $line('user', 49, [$result('tu_edit', 'ok')])
        . $say(48, 'm8', [$use('tu_write', 'Write', ['file_path' => "$work/gamma-output.txt", 'content' => 'g'])])
        . $line('user', 47, [$result('tu_write', 'ok')])
        . $say(45, 'm9', [['type' => 'text', 'text' => 'Gotowe']]));
    // ustawienia Claude Code w sztucznym HOME: zakładka Zgody czyta tylko `permissions` (env nie może trafić do widoku)
    file_put_contents("$home/.claude/settings.json", json_encode(['env' => ['SMOKE_SECRET' => 'SMOKE-ENV-VALUE'], 'permissions' => ['ask' => ['Bash(slowtool:*)'], 'defaultMode' => 'default']]));
    mkdir("$projects/$sid/tool-results", 0700, true);
    file_put_contents("$projects/$sid/tool-results/tu_slowtool.txt", str_repeat('r', 4096));
    file_put_contents("$projects/$sid/subagents/agent-abc123.meta.json", json_encode(['agentType' => 'Explore', 'description' => 'szukaj', 'toolUseId' => 'tu_agent']));
    file_put_contents("$projects/$sid/subagents/agent-abc123.jsonl",
        $say(100, 's1', [$use('tu_grep', 'Grep', ['pattern' => 'foo', 'path' => $work])])
        . $line('user', 99, [$result('tu_grep', 'brak')])
        . $say(95, 's2', [['type' => 'text', 'text' => 'koniec']]));

    // serwer na wolnym porcie, na kopii aplikacji
    $port = 0;
    for ($p = 8110; $p < 8200 && $port === 0; $p++) {
        $probe = @stream_socket_server("tcp://127.0.0.1:$p");
        if ($probe !== false) {
            fclose($probe);
            $port = $p;
        }
    }
    if ($port === 0) {
        throw new RuntimeException('no free port in 8110-8199');
    }
    $log = "$root/server.log";
    $server = proc_open(
        [PHP_BINARY, '-d', 'opcache.enable=0', '-d', 'display_errors=1', '-S', "127.0.0.1:$port", '-t', "$app/public", "$app/public/index.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
        $pipes,
        $app,
        ['HOME' => $home, 'PATH' => (string) getenv('PATH'), 'TMPDIR' => (string) getenv('TMPDIR'), 'AGENT_ALLOWED_RW' => $work, 'AGENT_ALLOWED_R' => ''],
    );
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) {
        $conn = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 0.2);
        $conn !== false ? ($up = true) && fclose($conn) : usleep(100_000);
    }
    if (!$up) {
        throw new RuntimeException('server did not start: ' . trim((string) file_get_contents($log)));
    }

    /** @return array{0: int, 1: string, 2: string} kod, treść, nagłówki */
    $request = static function (string $path, string $method = 'GET', array $headers = []) use ($port): array {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'follow_location' => 0, 'header' => implode("\r\n", $headers), 'timeout' => 20]]);
        $body = (string) @file_get_contents("http://127.0.0.1:$port$path", false, $context);
        $head = $http_response_header ?? [];
        preg_match('/\s(\d{3})\s/', (string) ($head[0] ?? ''), $m);

        return [(int) ($m[1] ?? 0), $body, implode("\n", $head)];
    };
    $phpError = '/(?:Warning|Notice|Deprecated|Fatal error|Parse error)(?:<\/b>)?:/';
    $check = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
        $checks++;
        if (!$ok) {
            $failures[] = $name . ($detail !== '' ? " — $detail" : '');
        }
    };
    // trasa → kod; opcjonalnie fragmenty tekstu i wzorce, które muszą być w treści (markery tylko z fixture albo ze stałych szablonów widoku)
    $get = static function (string $path, int $code = 200, array $contains = [], array $patterns = []) use ($request, $check, $phpError): string {
        [$status, $body] = $request($path);
        $check("GET $path → $code", $status === $code, "got $status");
        $check("GET $path bez ostrzeżeń PHP", preg_match($phpError, $body) !== 1);
        foreach ($contains as $needle) {
            $check("GET $path zawiera '{$needle}'", str_contains($body, $needle));
        }
        foreach ($patterns as $pattern) {
            $check("GET $path pasuje do $pattern", preg_match($pattern, $body) === 1);
        }

        return $body;
    };

    // widoki sesji z transkryptu: tabela plików, lista wywołań, log, panel, oś czasu i graf mają własne markery
    $s = "/?s=$sid";
    $get($s, 200, ['alpha-report.txt', 'class="tabs"', '⇩ export', 'class="files calls"']);
    // wiersz tabeli plików (`<span class="base">nazwa</span>`) jest tylko w files.php
    $get("$s&view=read", 200, ['<span class="base">alpha-report.txt</span>', '<span class="base">beta-notes.txt</span>', 'class="files calls"']);
    $get("$s&view=changed", 200, ['<span class="base">gamma-output.txt</span>', '<span class="base">beta-notes.txt</span>', 'class="files calls"']);
    // tytuł `<code class="cmd" title="…">` jest tylko w commands.php (nazwy komend są też w panelu kosztów nagłówka)
    $get("$s&view=commands", 200, ['<code class="cmd" title="uniqtool run">', '<code class="cmd" title="cat beta-notes.txt &amp;&amp; ls">']);
    $get("$s&view=blocked", 200, ['rule-x']);
    // Zgody: tryb z transkryptu nad tabelą; „najczęściej pytane” = czas ostatniego wystąpienia i dlaczego, klik = ostatnie wywołanie
    $approvalsPage = $get("$s&view=approvals", 200, ['class="files calls approvals approvals-top"', 'Bash(slowtool:*)', 'slowtool --wait', 'viz-ev="tu_slowtool"',
        'permission mode:', '<span class="tag">auto</span>']);
    $check('approvals: top row opens the last call', preg_match('/approvals-top.*?<tr viz-ev="tu_slowtool"/su', $approvalsPage) === 1);
    $check('approvals: env from settings not shown', !str_contains($approvalsPage, 'SMOKE-ENV-VALUE'));
    $get("$s&view=approvals&pev=tu_slowtool", 200, ['class="drawer"', 'slowtool --wait']);
    $get("$s&view=messages", 200, ['znajdz foo']);
    $check('Flow bez zdarzeń InstructionsLoaded: brak sekcji „Context loaded”', !str_contains($get("$s&view=flow", 200, ['viz-flow-data', 'viz-flow-diagram']), 'viz-flow-context'));
    // zdarzenia kontekstu z hooka (fixture, format zapisu HookRecorder): Flow — sekcja „Context loaded”, feed — kompaktowanie sesji głównej
    @mkdir("$app/var/events", 0700, true);
    file_put_contents("$app/var/events/$sid.context.jsonl", implode("\n", array_map('json_encode', [
        ['ts' => $at(300), 'event' => 'InstructionsLoaded', 'session_id' => $sid, 'agent_id' => 'main', 'file_path' => "$work/CLAUDE.md", 'memory_type' => 'Project', 'load_reason' => 'session_start', 'globs' => [], 'trigger_file_path' => null, 'parent_file_path' => null],
        ['ts' => $at(200), 'event' => 'InstructionsLoaded', 'session_id' => $sid, 'agent_id' => 'main', 'file_path' => "$work/.claude/rules/smoke-rule.md", 'memory_type' => 'Project', 'load_reason' => 'path_glob_match', 'globs' => ['*.txt'], 'trigger_file_path' => "$work/alpha-report.txt", 'parent_file_path' => null],
        ['ts' => $at(100), 'event' => 'PreCompact', 'session_id' => $sid, 'agent_id' => 'main', 'trigger' => 'manual'],
        ['ts' => $at(90), 'event' => 'PostCompact', 'session_id' => $sid, 'agent_id' => 'main', 'trigger' => 'manual'],
    ])) . "\n");
    $flowPage = $get("$s&view=flow", 200, ['viz-flow-context', 'Context loaded (2)', '.claude/rules/smoke-rule.md', 'path_glob_match', "title=\"$work/CLAUDE.md\""]);
    $check('Flow: plik względem cwd sesji (pełna ścieżka tylko w title)', str_contains($flowPage, '>CLAUDE.md</td>'));
    $get("$s&view=judge", 200, ['action="/judge?s=', 'No assessments yet.']);
    $get("/judge.json?s=$sid", 200, ['"running":false']);
    $get("$s&view=log", 200, ['<code class="summary" title="uniqtool run">', '<code class="summary" title="cat beta-notes.txt &amp;&amp; ls">', 'class="ev-head"', 'class="badge err"']);
    $get("/session?s=$sid&view=read", 200, ['alpha-report.txt']);
    $get("/version?s=$sid");

    // filtry i panele szczegółów
    $get("$s&view=read&agent=main");
    $get("$s&view=read&agent=abc123");
    $get("$s&view=commands&status=blocked");
    $get("$s&view=commands&status=failed");
    $get("$s&view=commands&status=executed");
    $get("$s&view=read&turn=1");
    $get("$s&view=read&turn=2");
    $get("$s&view=read&scope=project&origin=self");
    $get("$s&view=read&sort=cost");
    $get("$s&view=commands&group=1&pcmd=cat");
    $get("$s&view=commands&pev=tu_bash", 200, ['class="drawer"', 'shell command run by the agent']);
    $get("$s&view=log&pev=tu_stop", 200, ['class="drawer"', 'data.txt']);

    // CSV zakładek sesji
    foreach (['read', 'changed', 'commands', 'blocked', 'messages', 'log'] as $view) {
        $get("/export.csv?s=$sid&view=$view");
    }

    // zakres „wszystkie sesje” z agregatów kopii, Overview, biuro
    $get('/?s=all&view=read', 200, ['<span class="base">alpha-report.txt</span>', 'rank-search']);
    $get('/?s=all&view=changed', 200, ['<span class="base">gamma-output.txt</span>']);
    $get('/?s=all&view=commands', 200, ['<span class="base">uniqtool</span>']);
    $get('/?s=all&view=blocked', 200, ['rule-x']);
    // Zgody z agregatów (wiersz → zakładka Zgody sesji) i Log z transkryptów (strony przez `before`)
    $get('/?s=all&view=approvals', 200, ['class="files calls approvals approvals-top"', 'Bash(slowtool:*)', 'slowtool --wait', 'pev=tu_slowtool', 'permission mode:', '<span class="tag">auto</span>']);
    $get('/?s=all&view=log', 200, ['class="ev-head"', '<code class="summary" title="uniqtool run">', 'class="badge err"', "s=$sid&amp;view=log&amp;pev="]);
    $get('/?s=all&view=log&recent=1h', 200, ['<code class="summary" title="uniqtool run">']);
    $get('/?s=all&view=log&before=2000-01-01T00:00:00Z', 200, ['No tool calls in this scope.', '← newest']);
    $get('/export.csv?s=all&view=approvals', 200, ['slowtool']);
    $get('/export.csv?s=all&view=log', 404);
    $get('/?s=all&recent=1h', 200, ['alpha-report.txt']);
    $get('/?s=all&recent=5m', 200, ['alpha-report.txt']);
    $get('/version?s=all&recent=5m');
    foreach (['read' => 'alpha-report.txt', 'changed' => 'gamma-output.txt', 'commands' => 'uniqtool', 'blocked' => 'rule-x'] as $view => $marker) {
        $get("/export.csv?s=all&view=$view", 200, [$marker]);
    }
    foreach (['read' => 'alpha-report.txt', 'changed' => 'gamma-output.txt', 'commands' => 'uniqtool', 'blocked' => 'rule-x'] as $view => $marker) {
        $get("/export.csv?s=$sid&view=$view", 200, [$marker]);
    }
    // komórka `tool` w CSV „all” (Blocked) pochodzi z niezaufanego transkryptu: `=EVIL` ma dostać prefiks `'`, nie stać na początku komórki
    $blockedCsv = $get('/export.csv?s=all&view=blocked', 200, ["'=EVIL"]);
    $check('CSV „all” Blocked: nazwa narzędzia nie zaczyna komórki od „=”', preg_match('/(^|,)=EVIL/m', $blockedCsv) === 0);
    $sessionBlockedCsv = $get("/export.csv?s=$sid&view=blocked", 200, ["'=EVIL"]);
    $check('CSV sesji Blocked: nazwa narzędzia nie zaczyna komórki od „=”', preg_match('/(^|,)=EVIL/m', $sessionBlockedCsv) === 0);
    // Overview: rodzaje sygnałów z fixture mają licznik ≠ ✓ (sama oś Boundaries jest stałą etykietą szablonu)
    $signals = ['/Outside the boundary — executed<\/span>(?:<span class="ov-usd">[^<]*<\/span>)?<span class="ov-n">[1-9]\d*<\/span>/u',
        '/Unknown paths — suspicious<\/span>(?:<span class="ov-usd">[^<]*<\/span>)?<span class="ov-n">[1-9]\d*<\/span>/u'];
    foreach (['/?overview=1', '/?overview=1&days=1', '/?overview=1&days=30', '/?overview=1&days=all'] as $overviewPath) {
        $get($overviewPath, 200, ['Boundaries'], $signals);
    }
    // czas narzędzi i wyposażenie w Przeglądzie; „zgoda?” = bez blokady, Bash ≥ 11 min, inne ≥ 90 s; sortowanie Commands po czasie (sesja i „all”)
    $overview = $get('/?overview=1&days=30', 200, ['Time in tools', 'slowest calls under 90 s (Bash under 11 min)', 'tool results saved on disk — files: 1', 'MCP servers', 'smokesrv', 'sk-smoke-skill', 'Explore'],
        ['/href="\/\?s=' . $sid . '&amp;view=log&amp;pev=tu_slowtool"/']);
    $check('Przegląd: Bash 95 s = praca narzędzia (najwolniejsze), nie grupa „zgoda?”; Agent poza rankingiem',
        preg_match('/slowest calls under 90 s \(Bash under 11 min\).*?title="slowtool --wait"/su', $overview) === 1
        && preg_match('/<li data-tip="[^"]*slowtool[^"]*" viz-sev="warn">/u', $overview) === 0 && !str_contains($overview, 'data-tip="Agent ·'));
    $get("$s&view=commands&sort=time", 200, ['viz-wait'], ['/<code class="cmd"[^>]*>[^<]*slowtool/u']);
    $check('Commands sort=time: najdłuższe pierwsze', preg_match('/<code class="cmd" title="([^"]*)"/', $get("$s&view=commands&sort=time"), $m) === 1 && $m[1] === 'slowtool --wait', $m[1] ?? '');
    $check('Commands „all” sort=time: program z najdłuższym czasem pierwszy', preg_match('/<span class="base">([^<]*)<\/span>/', $get('/?s=all&view=commands&sort=time', 200, ['1 min 35 s']), $m) === 1 && $m[1] === 'slowtool', $m[1] ?? '');
    $get('/office', 200, ['viz-office-canvas', 'head = model · shirt = project']);
    // biuro 3D czyta wspólny feed; stara trasa danych biura nie istnieje
    $check('GET /office.json → 404 (dane biura = /feed.json)', $request('/office.json')[0] === 404);
    $office = json_decode($get('/feed.json'), true);
    $officeAgent = $office['sessions'][0]['agents'][0] ?? [];
    $check('feed.json: dane biura (persona, start, gone, archiwum, monety)', isset($officeAgent['persona'], $officeAgent['start'], $officeAgent['gone'], $officeAgent['archive'], $office['coinUsd'], $office['burnFrom']));
    $check('feed.json: klucz processes (available, groups, orphans; bez argumentów procesów)', is_bool($office['processes']['available'] ?? null) && is_array($office['processes']['groups'] ?? null) && is_array($office['processes']['orphans'] ?? null));
    // koszulka = kolor projektu (z nazwy katalogu sesji), głowa = klasa modelu (persona)
    $check('feed.json: sesja ma kolor projektu ze stałej palety', preg_match('/^#[0-9a-f]{6}$/', (string) ($office['sessions'][0]['projectColor'] ?? '')) === 1);
    $check('feed.json: agent opus ma personę opus', ($officeAgent['persona'] ?? '') === 'opus', (string) ($officeAgent['persona'] ?? ''));
    $check('feed.json: kompaktowanie sesji głównej z hooka (para Pre/Post = jedno, trigger)', ($office['sessions'][0]['compactions'] ?? null) === [['ts' => $at(100), 'trigger' => 'manual']], json_encode($office['sessions'][0]['compactions'] ?? null));
    $get('/office', 200, ['scope-switch', 'href="/lanes"', 'viz-office-url="/feed.json"']);

    // strona startowa: goły `/` prowadzi do osi pracy; Przegląd pod `/?overview=1`
    $check('GET / → 303 do /lanes', ($r = $request('/'))[0] === 303 && str_contains($r[2], 'Location: /lanes'), (string) $r[0]);
    $check('GET / z ciasteczkiem viz-live → 303 do ostatniego widoku', ($r = $request('/', 'GET', ['Cookie: viz-live=/agents']))[0] === 303 && str_contains($r[2], 'Location: /agents'), (string) $r[0]);
    $check('wejście na widok przeglądowy ustawia ciasteczko viz-live', str_contains($request('/office')[2], 'viz-live=/office'));
    // widok przeglądowy: strona, dane, zakres projektów z parametru i z ciasteczka
    $get('/lanes', 200, ['scope-switch', 'viz-scope-label', 'href="/office"']);
    // dane mapy w feedzie (strefy z konfiguracji, katalogi poza granicą z fixture)
    $get('/agents', 200, ['viz-agents-list', 'href="/office"', 'href="/agents" viz-current']);
    $get('/lanes', 200, ['viz-lanes-list', 'href="/agents"', 'href="/lanes" viz-current']);
    $check('GET /map → 404 (mapa w kartach usunięta)', $request('/map')[0] === 404);
    $mapFeed = json_decode($get('/feed.json'), true);
    $mapRows = $mapFeed['sessions'][0]['map'] ?? [];
    $check('feed.json: strefy granicy z konfiguracji', count($mapFeed['zones']['rw'] ?? []) === 1);
    $check('feed.json: mapa ma katalog poza granicą i katalog w strefie', in_array('out', array_column($mapRows, 'zone'), true) && in_array('rw', array_column($mapRows, 'zone'), true), json_encode(array_column($mapRows, 'zone')));
    $live = json_decode($get('/feed.json'), true);
    $liveSession = $live['sessions'][0] ?? [];
    $check('feed.json zawiera sesję fixture', count($live['sessions'] ?? []) >= 1);
    $check('feed.json: agent ma kontekst, okno modelu i wywołania', isset($liveSession['agents'][0]['context'], $liveSession['agents'][0]['contextMax']) && count($liveSession['agents'][0]['calls'] ?? []) >= 1 && is_array($liveSession['messages'] ?? null));
    $check('feed.json: granica skonfigurowana w fixture', ($live['boundary'] ?? null) === true && is_array($liveSession['boundary'] ?? null));
    $check('feed.json: próg kontekstu i okno', ($live['contextHigh'] ?? 0) > 0 && ($live['window'] ?? 0) === 1800);
    $projectKey = (string) ($liveSession['projectKey'] ?? '');
    $check('feed.json: zakres = projekt sesji → sesja jest', count(json_decode($get('/feed.json?projects=' . $projectKey), true)['sessions'] ?? []) >= 1);
    $check('feed.json: zakres = inny projekt → pusto', (json_decode($get('/feed.json?projects=000000000000'), true)['sessions'] ?? null) === []);
    $check('feed.json: zakres z ciasteczka viz-scope', (json_decode($request('/feed.json', 'GET', ['Cookie: viz-scope=000000000000'])[1], true)['sessions'] ?? null) === []);
    // pamięć podręczna feedu w var/ kopii: plik per zakres, 0600; stara trasa danych Live nie istnieje
    $feedCache = glob("$app/var/cache/feed-*.json") ?: [];
    $check('feed: pamięć podręczna w var/cache, plik 0600', $feedCache !== [] && (fileperms($feedCache[0]) & 0777) === 0600, implode(', ', $feedCache));
    $check('GET /live.json → 404 (dane Live = /feed.json)', $request('/live.json')[0] === 404);
    $get('/lanes?projects=' . $projectKey, 200, ['value="' . $projectKey . '" viz-scope-name']);
    // Przegląd i „Wszystkie sesje” liczą po tym samym zakresie (ciasteczko `viz-scope`, parametr `projects` ma pierwszeństwo); bez osobnego filtra projektu
    $other = ['Cookie: viz-scope=000000000000'];
    $check('scope: overview and s=all without a project parameter', preg_match('/[?;]project=/', $get('/?overview=1&days=30', 200, ['viz-scope-reload']) . $get('/?s=all', 200, ['viz-scope-reload'])) === 0);
    $check('scope: overview — cookie of another project → 0 sessions', str_contains($request('/?overview=1&days=30', 'GET', $other)[1], '· 0 sessions ·'));
    $check('scope: overview — parameter wins over the cookie', !str_contains($request('/?overview=1&days=30&projects=' . $projectKey, 'GET', $other)[1], '· 0 sessions ·'));
    foreach (['read', 'changed', 'commands', 'blocked', 'approvals', 'log'] as $tab) {
        [$status, $body] = $request('/?s=all&view=' . $tab, 'GET', $other);
        $check("scope: s=all&view=$tab — cookie of another project → 200, 0 sessions", $status === 200 && str_contains($body, '0 sessions in scope') && preg_match($phpError, $body) !== 1);
    }
    $check('scope: s=all counts follow the cookie', !str_contains($request('/?s=all&view=approvals', 'GET', $other)[1], 'slowtool --wait')
        && str_contains($request('/?s=all&view=approvals', 'GET', ['Cookie: viz-scope=' . $projectKey])[1], 'slowtool --wait'));
    $check('scope: CSV follows the cookie', !str_contains($request('/export.csv?s=all&view=approvals', 'GET', $other)[1], 'slowtool'));
    $check('scope: old project parameter → projects', str_contains($request('/summary?project=' . $projectKey)[2], 'projects=' . $projectKey));

    // przekierowanie ze starego adresu i nieznana trasa
    [$status, , $head] = $request('/summary?kind=blocked');
    $check('GET /summary → 302 na s=all', $status === 302 && str_contains($head, 's=all'), "got $status");
    $check('GET /nie-ma → 404', $request('/nie-ma')[0] === 404);

    // bramki dostępu
    $check('obcy Host → 403', $request('/', 'GET', ['Host: obcy.example:1'])[0] === 403);
    $check('POST / → 403', $request('/', 'POST')[0] === 403);
    $check('POST /export bez nagłówków pochodzenia → 403', $request("/export?s=$sid", 'POST')[0] === 403);
    $check('POST /judge bez nagłówków pochodzenia → 403 (obca strona nie uruchomi Claude Code)', $request("/judge?s=$sid", 'POST')[0] === 403);
    $check('PUT / → 405', $request('/', 'PUT')[0] === 405);

    // zrzut sesji: tylko POST z własnej strony, zapis w var/ kopii aplikacji
    [$status, , $head] = $request("/export?s=$sid", 'POST', ['Sec-Fetch-Site: same-origin']);
    $check('POST /export z własnego pochodzenia → 303 z nazwą zrzutu', $status === 303 && str_contains($head, 'dumped='), "got $status");
    $dumps = glob("$app/var/session_dump/*") ?: [];
    $check('zrzut powstał w var/ kopii aplikacji', count($dumps) === 1);
    foreach (['summary.md', 'metrics.json', 'prompts.json', 'events.jsonl', 'files.csv', 'commands.csv', 'agents.json', 'manifest.json'] as $file) {
        $check("zrzut zawiera $file", $dumps !== [] && is_file("$dumps[0]/$file"));
    }

    $phpLines = array_filter(explode("\n", (string) file_get_contents($log)), static fn (string $l): bool => preg_match('/PHP (?:Warning|Notice|Deprecated|Fatal error|Parse error)/', $l) === 1);
    $check('log serwera bez ostrzeżeń PHP', $phpLines === [], substr((string) reset($phpLines), 0, 300));
} catch (Throwable $e) {
    $failures[] = 'wyjątek: ' . $e->getMessage();
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $remove($root);
}

if ($failures !== []) {
    echo "FAIL (" . count($failures) . " z $checks sprawdzeń)\n  " . implode("\n  ", $failures) . "\n";
    exit(1);
}
echo "OK ($checks sprawdzeń)\n";
