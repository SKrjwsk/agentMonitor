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
        $line('user', 120, [['type' => 'text', 'text' => 'Przeczytaj pierwszy plik i uruchom agenta']])
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
    $get($s, 200, ['alpha-report.txt', 'class="tabs"', '⇩ dump', 'class="files"']);
    // wiersz tabeli plików (`<span class="base">nazwa</span>`) jest tylko w files.php
    $get("$s&view=read", 200, ['<span class="base">alpha-report.txt</span>', '<span class="base">beta-notes.txt</span>', 'class="files"']);
    $get("$s&view=changed", 200, ['<span class="base">gamma-output.txt</span>', '<span class="base">beta-notes.txt</span>', 'class="files"']);
    // tytuł `<code class="cmd" title="…">` jest tylko w commands.php (nazwy komend są też w panelu kosztów nagłówka)
    $get("$s&view=commands", 200, ['<code class="cmd" title="uniqtool run">', '<code class="cmd" title="cat beta-notes.txt &amp;&amp; ls">']);
    $get("$s&view=blocked", 200, ['rule-x']);
    $get("$s&view=boundary", 200, ['data.txt', 'class="files boundary-paths"']);
    $get("$s&view=messages", 200, ['znajdz foo']);
    $get("$s&view=timeline", 200, ['class="tl-main"']);
    $get("$s&view=timeline&rows=dirs", 200, ['class="tl-main"']);
    $get("$s&view=timeline&rows=commands", 200, ['class="tl-main"']);
    $get("$s&view=graph", 200, ['class="graph-canvas"']);
    $get("$s&view=log", 200, ['<code class="summary" title="uniqtool run">', '<code class="summary" title="cat beta-notes.txt &amp;&amp; ls">', 'class="ev-head"', 'class="badge err"']);
    $get("/session?s=$sid&view=read", 200, ['alpha-report.txt']);
    $get("/version?s=$sid");
    $graph = json_decode($get("/graph.json?s=$sid"), true);
    $check('graph.json to JSON z węzłami', is_array($graph) && $graph !== []);

    // filtry i panele szczegółów
    $get("$s&view=read&agent=main");
    $get("$s&view=read&agent=abc123");
    $get("$s&view=commands&status=blocked");
    $get("$s&view=commands&status=failed");
    $get("$s&view=commands&status=executed");
    $get("$s&view=boundary&boundary=outside");
    $get("$s&view=boundary&boundary=stopped");
    $get("$s&view=read&turn=1");
    $get("$s&view=read&turn=2");
    $get("$s&view=read&scope=project&origin=self");
    $get("$s&view=read&sort=cost");
    $get("$s&view=commands&group=1&pcmd=cat");
    $get("$s&view=commands&pev=tu_bash", 200, ['class="drawer"', 'shell command run by the agent']);
    $get("$s&view=log&pev=tu_stop", 200, ['class="drawer"', 'data.txt']);

    // CSV zakładek sesji
    foreach (['read', 'changed', 'commands', 'blocked', 'boundary', 'messages', 'log'] as $view) {
        $get("/export.csv?s=$sid&view=$view");
    }

    // zakres „wszystkie sesje” z agregatów kopii, Overview, biuro
    $get('/?s=all&view=read', 200, ['<span class="base">alpha-report.txt</span>', 'rank-search']);
    $get('/?s=all&view=changed', 200, ['<span class="base">gamma-output.txt</span>']);
    $get('/?s=all&view=commands', 200, ['<span class="base">uniqtool</span>']);
    $get('/?s=all&view=blocked', 200, ['rule-x']);
    $get('/?s=all&view=boundary', 200, ['data.txt']);
    $get('/?s=all&recent=1h', 200, ['alpha-report.txt']);
    $get('/?s=all&recent=5m', 200, ['alpha-report.txt']);
    $get('/version?s=all&recent=5m');
    foreach (['read' => 'alpha-report.txt', 'changed' => 'gamma-output.txt', 'commands' => 'uniqtool', 'blocked' => 'rule-x', 'boundary' => 'data.txt'] as $view => $marker) {
        $get("/export.csv?s=all&view=$view", 200, [$marker]);
    }
    foreach (['read' => 'alpha-report.txt', 'changed' => 'gamma-output.txt', 'commands' => 'uniqtool', 'blocked' => 'rule-x', 'boundary' => 'data.txt'] as $view => $marker) {
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
    foreach (['/', '/?days=1', '/?days=30', '/?days=all'] as $overviewPath) {
        $get($overviewPath, 200, ['Boundaries'], $signals);
    }
    $get('/office', 200, ['viz-office-canvas', 'head = model · shirt = project']);
    $office = json_decode($get('/office.json'), true);
    $check('office.json zawiera agenta sesji', is_array($office) && count($office['agents'] ?? []) >= 1);
    // koszulka = kolor projektu (z nazwy katalogu sesji), głowa = klasa modelu (persona)
    $check('office.json: sesja ma kolor projektu ze stałej palety', preg_match('/^#[0-9a-f]{6}$/', (string) ($office['sessions'][0]['projectColor'] ?? '')) === 1);
    $check('office.json: agent opus ma personę opus', ($office['agents'][0]['persona'] ?? '') === 'opus', (string) ($office['agents'][0]['persona'] ?? ''));

    // przekierowanie ze starego adresu i nieznana trasa
    [$status, , $head] = $request('/summary?kind=blocked');
    $check('GET /summary → 302 na s=all', $status === 302 && str_contains($head, 's=all'), "got $status");
    $check('GET /nie-ma → 404', $request('/nie-ma')[0] === 404);

    // bramki dostępu
    $check('obcy Host → 403', $request('/', 'GET', ['Host: obcy.example:1'])[0] === 403);
    $check('POST / → 403', $request('/', 'POST')[0] === 403);
    $check('POST /export bez nagłówków pochodzenia → 403', $request("/export?s=$sid", 'POST')[0] === 403);
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
