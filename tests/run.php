<?php

declare(strict_types=1);

/*
 * Testy parsera ścieżek. Uruchomienie: php tests/run.php
 * Każdy przypadek: komenda → oczekiwane dotknięcia "op:ścieżka-względna[:partial[:zakres]]" (kolejność bez znaczenia)
 * oraz liczba ślepych plamek (unresolved).
 */

use Viz\Extract\BashTouches;
use Viz\Extract\ToolTouches;
use Viz\Model\Extraction;

require __DIR__ . '/../src/autoload.php';

$root = sys_get_temp_dir() . '/viz-test-' . getmypid();
foreach (['src/Domain', 'sub', 'docs', 'bin'] as $dir) {
    mkdir($root . '/' . $dir, 0700, true);
}
foreach (['a.txt', 'src/X.php', 'src/Y.php', 'src/Domain/Order.php', 'sub/inner.txt', 'docs/r.md', 'data.json', 'bin/compare.php'] as $file) {
    file_put_contents($root . '/' . $file, "x\n");
}
$root = realpath($root);

$cases = [
    ['cat a.txt', ['read:a.txt'], 0],
    ['cd sub && cat inner.txt', ['read:sub/inner.txt'], 0],
    ["cd $root/sub; cat inner.txt", ['read:sub/inner.txt'], 0],
    ["sed -n '1,20p' src/X.php", ['read:src/X.php:partial:1-20'], 0],
    ['sed -n 40,55p docs/r.md; sed -n 140,155p a.txt', ['read:docs/r.md:partial:40-55', 'read:a.txt:partial:140-155'], 0],
    ['grep -rn "foo" src --include=*.php | head', ['search:src'], 0],
    ['grep -n foo a.txt src/X.php', ['search:a.txt', 'search:src/X.php'], 0],
    ['grep -e foo -e bar a.txt', ['search:a.txt'], 0],
    ['grep -rln "x" | head -20', ['search:.'], 0],
    ['head -20 a.txt; tail -n 5 docs/r.md', ['read:a.txt:partial:1-20', 'read:docs/r.md:partial:ostatnie 5'], 0],
    ["ls src && find docs -name '*.md'", ['list:src', 'list:docs'], 0],
    ['cat src/*.php', ['read:src/X.php', 'read:src/Y.php'], 0],
    ['echo hi > out.txt', ['write:out.txt'], 0],
    ['for f in a.txt; do cat "$f"; done', ['read:a.txt'], 0],
    ['for f in a.txt docs/r.md; do echo "== $f"; head -3 $f; done', ['read:a.txt:partial:1-3', 'read:docs/r.md:partial:1-3'], 0],
    ['while read f; do cat "$f"; done < list.txt', ['read:list.txt'], 1],
    ["OM=$root/src; ls \$OM; cat \$OM/X.php", ['list:src', 'read:src/X.php'], 0],
    ["OM=$root; cd \$OM && cat a.txt", ['read:a.txt'], 0],
    ['export D=docs; cat "$D/r.md"', ['read:docs/r.md'], 0],
    ['F="src/*.php"; cat $F', ['read:src/X.php', 'read:src/Y.php'], 0],
    ["python3 - <<'EOF'\nopen('a.txt').read()\nimport os\nEOF\ncat docs/r.md", ['read:docs/r.md'], 1],
    ["sed -i '' 's/a/b/' a.txt", ['edit:a.txt'], 0],
    ["sed -i 's/a/b/' a.txt", ['edit:a.txt'], 0],
    ["awk '/x/,/y/' docs/r.md | cut -c1-80", ['read:docs/r.md:partial'], 0],
    ["grep -rl foo src | xargs sed -n '1p'", ['search:src'], 1],
    ['rg "pattern"', ['search:.'], 0],
    ['rg -n "x" -g "*.php" src/Domain', ['search:src/Domain'], 0],
    ['git diff -- a.txt', ['read:a.txt:partial'], 0],
    ['git log --oneline -4; git status --short', [], 0],
    ['wc -l a.txt', ['list:a.txt'], 0],
    ['cp a.txt b.txt', ['read:a.txt', 'write:b.txt'], 0],
    ['vendor/bin/pest 2>&1 | tail -8', [], 0],
    ['cat a.txt 2>/dev/null', ['read:a.txt'], 0],
    ["jq '.x' data.json", ['read:data.json'], 0],
    ['sips -g pixelWidth a.txt', ['list:a.txt'], 0],
    ['convert-xyz a.txt', ['read:a.txt'], 0],
    ['php bin/compare.php 2026-09 | grep -n 2027', ['exec:bin/compare.php'], 0],
    ['php bin/compare.php a.txt', ['exec:bin/compare.php', 'exec:a.txt'], 0],
    ['grep -n "x" $(grep -rl "enum" src) | head', ['search:src'], 1],
    ['cat "src/Domain/Order.php" \\' . "\n" . '  docs/r.md', ['read:src/Domain/Order.php', 'read:docs/r.md'], 0],
    ['grep -n "a|b" a.txt', ['search:a.txt'], 0],
    ['cd /nonexistent-dir-xyz && cat a.txt', ['read:/nonexistent-dir-xyz/a.txt'], 0],
    ["cat <<'EOF' > notes.md\ncat a.txt\nEOF", ['write:notes.md'], 0],
    ['ls -la $HOME/.zzz-nope', ['list:' . getenv('HOME') . '/.zzz-nope'], 0],
];

$bash = new BashTouches();
$failures = 0;
foreach ($cases as [$cmd, $expected, $expectedUnresolved]) {
    $got = describe($bash->extract($cmd, $root), $root);
    $unresolved = count($bash->extract($cmd, $root)->unresolved);
    sort($got);
    sort($expected);
    if ($got !== $expected || $unresolved !== $expectedUnresolved) {
        $failures++;
        echo "FAIL: $cmd\n  oczekiwane: " . implode(', ', $expected) . " | unresolved=$expectedUnresolved\n  otrzymane:  " . implode(', ', $got) . " | unresolved=$unresolved\n";
        foreach ($bash->extract($cmd, $root)->unresolved as $u) {
            echo "    - $u\n";
        }
    }
}

$tools = new ToolTouches();
$toolCases = [
    ['Read', ['file_path' => "$root/a.txt"], ['read:a.txt']],
    ['Read', ['file_path' => "$root/a.txt", 'offset' => 10, 'limit' => 5], ['read:a.txt:partial:10-14']],
    ['Edit', ['file_path' => "$root/src/X.php"], ['edit:src/X.php']],
    ['Grep', ['pattern' => 'x'], ['search:.']],
    ['Glob', ['pattern' => '**/*.php', 'path' => "$root/src"], ['list:src']],
];
foreach ($toolCases as [$tool, $input, $expected]) {
    $got = describe($tools->extract($tool, $input, $root), $root);
    if ($got !== $expected) {
        $failures++;
        echo "FAIL: $tool " . json_encode($input) . "\n  oczekiwane: " . implode(', ', $expected) . "\n  otrzymane:  " . implode(', ', $got) . "\n";
    }
}

// komendy (M11): program[:podkomenda][@konteksty][<program po lewej stronie |][^nakładki]
$commandCases = [
    ['curl -s https://x.example/a | cat', ['curl', 'cat@pipe<curl']],
    ['grep -rn foo src | head -20 | wc -l', ['grep', 'head@pipe<grep', 'wc@pipe<head']],
    ['cat $(find . -name "*.php") | sort', ['find@subst', 'cat', 'sort@pipe<cat']],
    ['grep -rl foo src | xargs sed -n 1p', ['grep', 'sed@pipe,xargs<grep']],
    ['bash -c "cd sub && cat inner.txt"', ['bash', 'cd@inner', 'cat@inner']],
    ['ssh prod "tail -100 /var/log/app.log | grep ERROR"', ['ssh', 'tail@remote', 'grep@pipe,remote<tail']],
    ['sudo rm -rf /tmp/x', ['rm^sudo']],
    ['timeout 5 curl -s x.example', ['curl^timeout']],
    ['git -C repo push origin main', ['git:push']],
    ["python3 - <<'EOF'\nprint(1)\nEOF", ['python3@stdin-script']],
    ['php console mediapress:vod:item --x=1', ['php:console mediapress:vod:item']],
    ['composer precommit 2>&1 | tail -3', ['composer:precommit', 'tail@pipe<composer']],
    ['eval "ls -la"', ['eval', 'ls@inner']],
    ['for f in a.txt; do cat "$f"; done', ['cat']],
    ['nano a.txt', ['nano']],
];
foreach ($commandCases as [$cmd, $expected]) {
    $got = array_map(static function (Viz\Model\Command $c): string {
        return $c->program . ($c->sub !== null ? ':' . $c->sub : '') . ($c->contexts !== [] ? '@' . implode(',', $c->contexts) : '')
            . ($c->pipedFrom !== null ? '<' . $c->pipedFrom : '') . ($c->wrappers !== [] ? '^' . implode(',', $c->wrappers) : '');
    }, $bash->extract($cmd, $root)->commands);
    sort($got);
    sort($expected);
    if ($got !== $expected) {
        $failures++;
        echo "FAIL komendy: $cmd\n  oczekiwane: " . implode(', ', $expected) . "\n  otrzymane:  " . implode(', ', $got) . "\n";
    }
}
$roles = [['nano', 'interactive'], ['grep', 'search'], ['curl', 'network'], ['frobnicate', 'unknown']];
foreach ($roles as [$program, $role]) {
    if (Viz\Extract\ToolRoles::role($program) !== $role) {
        $failures++;
        echo "FAIL rola: $program → " . Viz\Extract\ToolRoles::role($program) . " (oczekiwane $role)\n";
    }
}

// parser logu systemowego (format zakładany; prawdziwy log fs_usage niesprawdzony)
$log = $root . '/fs.log';
file_put_contents($log, implode("\n", [
    "13:56:13.100001  open              F=4        (R_____________)  $root/a.txt                       0.000020   head.41001",
    "13:56:13.100050  RdData[A]         D=0x00a1b2c3  B=0x1000   /dev/disk3s1  $root/a.txt     0.000100 W head.41001",
    "13:56:13.200001  stat64                                         $root/src                         0.000010   claude.40000",
    "41003 13:56:13.300001 openat(AT_FDCWD, \"$root/docs/r.md\", O_RDONLY) = 3",
    "41003 13:56:13.300002 openat(AT_FDCWD, \"$root/nope.txt\", O_RDONLY) = -1 ENOENT (No such file or directory)",
]) . "\n");
$rows = array_map(static fn (array $r): string => $r['op'] . ':' . substr($r['path'], strlen($root) + 1) . ':' . $r['proc'], Viz\Verify\FsLog::parse($log));
$expectedRows = ['open:a.txt:head', 'open:a.txt:head', 'stat:src:claude', 'open:docs/r.md:pid:41003'];
if ($rows !== $expectedRows) {
    $failures++;
    echo "FAIL: FsLog\n  oczekiwane: " . implode(', ', $expectedRows) . "\n  otrzymane:  " . implode(', ', $rows) . "\n";
}

// wiadomości: wynik komendy usera (/model) to nie prompt; prompt dla subagenta, odpowiedź przez SubagentHandback (przycięta), odpowiedź synchroniczna, SendMessage
$line = static fn (string $type, string $ts, array $content): string => json_encode(['type' => $type, 'timestamp' => $ts, 'cwd' => $root, 'message' => ['role' => $type, 'content' => $content]]) . "\n";
mkdir("$root/proj/sess/subagents", 0700, true);
file_put_contents("$root/proj/sess.jsonl",
    $line('user', '2026-01-01T10:00:00Z', [['type' => 'text', 'text' => 'zrób to']])
    . $line('user', '2026-01-01T10:00:00Z', [['type' => 'text', 'text' => '<local-command-stdout>Set model to Opus</local-command-stdout>']])
    . $line('assistant', '2026-01-01T10:00:01Z', [['type' => 'tool_use', 'id' => 'tu_async', 'name' => 'Agent', 'input' => ['description' => 'a', 'subagent_type' => 'Explore', 'prompt' => 'przeszukaj src']]])
    . $line('user', '2026-01-01T10:00:02Z', [['type' => 'tool_result', 'tool_use_id' => 'tu_async', 'content' => [['type' => 'text', 'text' => 'Async agent launched successfully.']]]])
    . $line('assistant', '2026-01-01T10:00:03Z', [['type' => 'tool_use', 'id' => 'tu_sync', 'name' => 'Agent', 'input' => ['description' => 'b', 'subagent_type' => 'Plan', 'prompt' => 'zaplanuj']]])
    . $line('user', '2026-01-01T10:00:09Z', [['type' => 'tool_result', 'tool_use_id' => 'tu_sync', 'content' => [['type' => 'text', 'text' => 'plan gotowy']]]])
    . $line('assistant', '2026-01-01T10:00:10Z', [['type' => 'tool_use', 'id' => 'tu_send', 'name' => 'SendMessage', 'input' => ['to' => 'uds:/tmp/x.sock', 'message' => 'cześć']]]));
foreach (['aaa' => ['tu_async', 'Explore'], 'bbb' => ['tu_sync', 'Plan']] as $id => [$toolUseId, $type]) {
    file_put_contents("$root/proj/sess/subagents/agent-$id.meta.json", json_encode(['agentType' => $type, 'description' => $id, 'toolUseId' => $toolUseId]));
}
file_put_contents("$root/proj/sess/subagents/agent-aaa.jsonl",
    $line('assistant', '2026-01-01T10:00:05Z', [['type' => 'tool_use', 'id' => 'tu_hb', 'name' => 'SubagentHandback', 'input' => ['message' => str_repeat('x', Viz\Source\TranscriptReader::RESULT_LIMIT + 50)]]]));
file_put_contents("$root/proj/sess/subagents/agent-bbb.jsonl", '');
$got = array_map(
    static fn (array $m): string => $m['kind'] . ':' . $m['from'] . '>' . $m['to'] . ($m['cut'] ? ':cut' : '') . ($m['kind'] === 'result' && $m['from'] === 'aaa' ? ':' . mb_strlen($m['text']) : ''),
    (new Viz\Source\TranscriptReader())->read("$root/proj/sess.jsonl")->messages,
);
$expectedMessages = ['user:user>main', 'task:main>aaa', 'task:main>bbb', 'result:aaa>main:cut:' . Viz\Source\TranscriptReader::RESULT_LIMIT, 'result:bbb>main', 'send:main>uds:/tmp/x.sock'];
if ($got !== $expectedMessages) {
    $failures++;
    echo "FAIL: messages\n  oczekiwane: " . implode(', ', $expectedMessages) . "\n  otrzymane:  " . implode(', ', $got) . "\n";
}

// tokeny: jedna odpowiedź w kilku liniach (output_tokens niepełne w pierwszej), `<synthetic>` pomijany, subagent liczony w turze,
// odpowiedź bez stop_reason (sam start strumienia) = output niepełne
$usageLine = static fn (string $ts, string $id, string $model, int $out, int $read, int $write, ?string $stop = 'end_turn'): string => json_encode(['type' => 'assistant', 'timestamp' => $ts, 'cwd' => $root,
    'message' => ['id' => $id, 'model' => $model, 'role' => 'assistant', 'stop_reason' => $stop, 'content' => [['type' => 'text', 'text' => '.']],
        'usage' => ['input_tokens' => 2, 'output_tokens' => $out, 'cache_read_input_tokens' => $read, 'cache_creation_input_tokens' => $write]]]) . "\n";
mkdir("$root/tok/s2/subagents", 0700, true);
file_put_contents("$root/tok/s2.jsonl",
    $line('user', '2026-01-01T10:00:00Z', [['type' => 'text', 'text' => 'pierwsza']])
    . $usageLine('2026-01-01T10:00:01Z', 'm1', 'opus', 16, 1000, 500, null)
    . $usageLine('2026-01-01T10:00:01Z', 'm1', 'opus', 1706, 1000, 500)
    . $usageLine('2026-01-01T10:00:02Z', 'syn', '<synthetic>', 0, 0, 0)
    . $line('user', '2026-01-01T11:00:00Z', [['type' => 'text', 'text' => 'druga']])
    . $usageLine('2026-01-01T11:00:01Z', 'm2', 'opus', 10, 3000, 0));
file_put_contents("$root/tok/s2/subagents/agent-ccc.meta.json", json_encode(['agentType' => 'Explore', 'description' => 'c', 'toolUseId' => 'tu_x']));
file_put_contents("$root/tok/s2/subagents/agent-ccc.jsonl", $usageLine('2026-01-01T10:00:05Z', 's1', 'haiku', 7, 200, 100, null));
$tokSession = (new Viz\Source\TranscriptReader())->read("$root/tok/s2.jsonl");
$t = static fn (array $x): string => "{$x['calls']}/{$x['subagentCalls']} partial={$x['outputPartial']} out={$x['output']} w={$x['cacheWrite']} r={$x['cacheRead']} " . implode(',', array_keys($x['models']));
$gotTokens = [$t($tokSession->tokens()), $t($tokSession->tokens('', '2026-01-01T10:00:00Z', '2026-01-01T11:00:00Z')), $t($tokSession->tokens('ccc'))];
$expectedTokens = ['3/1 partial=1 out=1723 w=600 r=4200 opus,haiku', '2/1 partial=1 out=1713 w=600 r=1200 opus,haiku', '1/1 partial=1 out=7 w=100 r=200 haiku'];
if ($gotTokens !== $expectedTokens) {
    $failures++;
    echo "FAIL: tokens\n  oczekiwane: " . implode(' | ', $expectedTokens) . "\n  otrzymane:  " . implode(' | ', $gotTokens) . "\n";
}

// koszt wyników narzędzi: zapis cache następnej odpowiedzi bez jej poprzedniego wyjścia, dzielony po długości wyników;
// dalsze odpowiedzi czytają wynik do kompaktowania (kontekst spada o > 40%); zapis 1 h = 2× wejścia; model spoza cennika = bez kosztu
$costLine = static fn (string $ts, string $id, int $out, int $read, int $write, int $write1h, array $content = []): string => json_encode(['type' => 'assistant', 'timestamp' => $ts, 'cwd' => $root,
    'message' => ['id' => $id, 'model' => 'claude-opus-5-5', 'role' => 'assistant', 'stop_reason' => 'end_turn', 'content' => $content ?: [['type' => 'text', 'text' => '.']],
        'usage' => ['input_tokens' => 0, 'output_tokens' => $out, 'cache_read_input_tokens' => $read, 'cache_creation_input_tokens' => $write,
            'cache_creation' => ['ephemeral_5m_input_tokens' => $write - $write1h, 'ephemeral_1h_input_tokens' => $write1h]]]]) . "\n";
$use = static fn (string $id): array => ['type' => 'tool_use', 'id' => $id, 'name' => 'Read', 'input' => ['file_path' => "/tmp/$id"]];
mkdir("$root/cost", 0700, true);
file_put_contents("$root/cost/s3.jsonl",
    $line('user', '2026-01-01T10:00:00Z', [['type' => 'text', 'text' => 'start']])
    . $costLine('2026-01-01T10:00:01Z', 'c1', 100, 0, 10000, 0, [$use('t1'), $use('t2')])
    . $line('user', '2026-01-01T10:00:02Z', [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => str_repeat('x', 3000)], ['type' => 'tool_result', 'tool_use_id' => 't2', 'content' => str_repeat('y', 1000)]])
    . $costLine('2026-01-01T10:00:03Z', 'c2', 50, 10000, 2100, 2100, [$use('t3')])
    . $line('user', '2026-01-01T10:00:04Z', [['type' => 'tool_result', 'tool_use_id' => 't3', 'content' => 'z']])
    . $costLine('2026-01-01T10:00:05Z', 'c3', 10, 12100, 60, 0)
    . $costLine('2026-01-01T10:00:06Z', 'c4', 10, 12160, 100, 0)
    . $costLine('2026-01-01T10:00:07Z', 'c5', 10, 3000, 1000, 0));
$costSession = (new Viz\Source\TranscriptReader())->read("$root/cost/s3.jsonl");
$carryOf = [];
foreach ($costSession->events as $e) {
    $carryOf[$e->toolUseId] = $e->carry === null ? '-' : $e->carry['tokens'] . '×' . $e->carry['calls'] . '=' . round((float) $e->carry['usd'], 4);
}
ksort($carryOf);
$gotCost = [
    implode(' ', array_map(static fn ($k, $v): string => "$k:$v", array_keys($carryOf), $carryOf)),
    implode(',', [Viz\Pricing::rates('claude-opus-5-5-x')[2], Viz\Pricing::rates('claude-opus-5')[2], Viz\Pricing::rates('gpt') === null ? 'null' : '?']),
    $tokSession->tokens()['unpriced'] . ' ' . round($costSession->tokens()['usd'], 4),
];
// t1: 2000·3/4 = 1500 tokenów × (zapis 1 h 4·2 = 8 $/M + 2 dalsze odczyty po 0,20 $/M); t3: zapis 60 − wyjście 50 = 10 tokenów;
// sesja: c1 0,052 + c2 0,0198 + c3 0,00292 + c4 0,00313 + c5 0,0058
$expectedCost = ['t1:1500×2=0.0126 t2:500×2=0.0042 t3:10×1=0.0001', '0.2,0.5,null', '3 0.0837'];
if ($gotCost !== $expectedCost) {
    $failures++;
    echo "FAIL: cost\n  oczekiwane: " . implode(' | ', $expectedCost) . "\n  otrzymane:  " . implode(' | ', $gotCost) . "\n";
}

// Overview: blokada hooka na ścieżce z wzorcem reguły = protected-path (2 = zaraz potem kolejna blokada), wzorzec tylko w tekście = info,
// wiadomość agenta zablokowana = report-blocked, auto mode = high, deny rule = info; incydenty: przerwanie, limit (powtórka w 30 min = jeden)
$sigRule = "example-hook.sh: 'secret-dir'";
$sigEvent = static fn (string $id, string $ts, string $tool, string $summary, array $touches, ?array $denial, string $agent = 'main'): Viz\Model\Event
    => new Viz\Model\Event($ts, 's4', $agent, $tool, $id, $summary, '/p', $denial !== null, $touches, denial: $denial);
$sigEvents = [
    $sigEvent('b1', '2026-01-01T10:00:01Z', 'Bash', 'cat /p/secret-dir/a', [new Viz\Model\Touch('/p/secret-dir/a', Viz\Model\Touch::READ, true)], ['by' => 'hook', 'detail' => $sigRule]),
    $sigEvent('b2', '2026-01-01T10:00:02Z', 'Bash', 'echo secret-dir', [], ['by' => 'hook', 'detail' => $sigRule]),
    $sigEvent('b3', '2026-01-01T10:00:03Z', 'Bash', 'ls /p', [new Viz\Model\Touch('/p/secret-dir', Viz\Model\Touch::LIST, true)], ['by' => 'hook', 'detail' => $sigRule]),
    $sigEvent('b4', '2026-01-01T10:00:04Z', 'SubagentHandback', 'report about secret-dir', [], ['by' => 'hook', 'detail' => $sigRule], 'sub1'),
    $sigEvent('b5', '2026-01-01T10:00:05Z', 'Bash', 'rm -rf x', [], ['by' => 'auto mode', 'detail' => 'Irreversible Local Destruction']),
    $sigEvent('b6', '2026-01-01T10:00:06Z', 'Bash', 'mkdir y', [], ['by' => 'deny rule', 'detail' => null]),
];
$sigIncidents = array_values(array_filter([
    Viz\Source\TranscriptReader::incident(['type' => 'user', 'timestamp' => '2026-01-01T10:01:00Z', 'message' => ['content' => [['type' => 'text', 'text' => '[Request interrupted by user for tool use]']]]], 'main'),
    Viz\Source\TranscriptReader::incident(['type' => 'assistant', 'isApiErrorMessage' => true, 'timestamp' => '2026-01-01T10:02:00Z', 'message' => ['content' => [['type' => 'text', 'text' => "You've hit your session limit · resets 6pm"]]]], 'main'),
    Viz\Source\TranscriptReader::incident(['type' => 'assistant', 'isApiErrorMessage' => true, 'timestamp' => '2026-01-01T10:12:00Z', 'message' => ['content' => [['type' => 'text', 'text' => "You've hit your session limit · resets 6pm"]]]], 'main'),
    Viz\Source\TranscriptReader::incident(['type' => 'user', 'timestamp' => '2026-01-01T10:13:00Z', 'message' => ['content' => 'zwykły prompt']], 'main'),
]));
$sig = Viz\Signals::of(new Viz\Model\Session('s4', '/x', 't', '/p', [Viz\Model\Agent::MAIN => new Viz\Model\Agent('main', 'main', 'main', null)], $sigEvents, 6, incidents: $sigIncidents), boundary: new Viz\Boundary([], []));
$gotSig = [implode(' ', array_map(static fn (array $it): string => $it['kind'] . ($it['ev'] !== null ? ':' . $it['ev'] : '') . ($it['value'] > 1 ? '×' . $it['value'] : ''), $sig['items'])),
    "text={$sig['info']['hook_text']} deny={$sig['info']['deny_rule']}"];
$expectedSig = ['protected-path:b1×2 hook-text:b2 protected-path:b3×2 report-blocked:b4 auto-mode:b5 interrupt limit', 'text=1 deny=1'];
if ($gotSig !== $expectedSig) {
    $failures++;
    echo "FAIL: signals\n  oczekiwane: " . implode(' | ', $expectedSig) . "\n  otrzymane:  " . implode(' | ', $gotSig) . "\n";
}

// higiena kontekstu: przerwa > 5 min z odbudową cache, krótkie pytanie w dużym kontekście, powrót po nocy (bez /compact)
$sigUsage = static fn (string $ts, int $tokens, int $read, int $write, float $readUsd, float $writeUsd): array => ['ts' => $ts, 'agent' => 'main', 'model' => 'm',
    'tokens' => $tokens, 'input' => 0, 'cacheRead' => $read, 'cacheWrite' => $write, 'write1h' => 0, 'output' => 100, 'final' => true,
    'cost' => ['input' => 0.0, 'read' => $readUsd, 'write' => $writeUsd, 'output' => 0.0]];
$ctxSession = new Viz\Model\Session('s5', '/x', 't', '/p', [Viz\Model\Agent::MAIN => new Viz\Model\Agent('main', 'main', 'main', null)], [], 0,
    prompts: array_map(static fn (array $x): array => ['ts' => $x[0], 'text' => $x[1], 'kind' => 'prompt'], [
        ['2026-01-01T10:00:00Z', 'start'], ['2026-01-01T10:20:00Z', 'after a break'], ['2026-01-01T10:21:00Z', 'quick'],
        ['2026-01-02T09:00:00Z', 'next day'], ['2026-01-02T15:00:00Z', '/compact keep the plan'],
    ]),
    usage: [
        $sigUsage('2026-01-01T10:00:05Z', 20_000, 10_000, 10_000, 0.3, 0.2),
        $sigUsage('2026-01-01T10:20:05Z', 200_000, 0, 200_000, 0.0, 2.0),
        $sigUsage('2026-01-01T10:21:05Z', 210_000, 205_000, 5_000, 0.6, 0.0),
        $sigUsage('2026-01-02T09:00:05Z', 215_000, 0, 215_000, 0.0, 2.5),
        $sigUsage('2026-01-02T09:00:30Z', 220_000, 220_000, 5_000, 0.66, 0.0),
        $sigUsage('2026-01-02T15:00:05Z', 220_000, 0, 220_000, 0.0, 2.6),
    ]);
$gotCtx = implode(' ', array_map(static fn (array $it): string => $it['kind'] . '=' . round($it['value'], 2), Viz\Signals::of($ctxSession, boundary: new Viz\Boundary([], []))['items']));
$expectedCtx = 'cold-cache=2 quick-question=0.6 stale-session=3.15 cold-cache=2.5 cold-cache=2.6';
if ($gotCtx !== $expectedCtx) {
    $failures++;
    echo "FAIL: signals (kontekst)\n  oczekiwane: $expectedCtx\n  otrzymane:  $gotCtx\n";
}

// blokady: z decyzji zapisanej w linii tool_result, a w starszych transkryptach z treści błędu
$denialCases = [
    [['toolDenialKind' => 'permission-rule', 'permissionDecision' => ['decision' => 'reject', 'source' => 'hook', 'reasonType' => 'hook']],
        'Error: PreToolUse:Bash hook error: [bash "$HOME/.claude/hooks/example-hook.sh"]: BLOCKED by example hook: denied by rule matching \'secret-dir\'',
        "hook|example-hook.sh: 'secret-dir'"],
    [['toolDenialKind' => 'permission-rule', 'permissionDecision' => ['decision' => 'reject', 'source' => 'config', 'reasonType' => 'subcommandResults']],
        'Error: Permission to use Bash with command ssh host ls has been denied.', 'deny rule|'],
    [['toolDenialKind' => 'automode-blocked', 'permissionDecision' => ['decision' => 'reject', 'source' => 'config', 'reasonType' => 'classifier']],
        'Error: Permission for this action was denied by the Claude Code auto mode classifier. Reason: [Irreversible Local Destruction]. If…', 'auto mode|Irreversible Local Destruction'],
    [['toolDenialKind' => 'user-rejected'], 'User rejected tool use', 'user|'],
    [[], "The user doesn't want to proceed with this tool use.", 'user|'],
    [['permissionDecision' => ['decision' => 'accept', 'source' => 'config', 'reasonType' => 'rule']], 'Error: File does not exist.', 'null'],
];
foreach ($denialCases as [$line, $error, $expected]) {
    $d = Viz\Source\TranscriptReader::denial($line, $error);
    $got = $d === null ? 'null' : $d['by'] . '|' . $d['detail'];
    if ($got !== $expected) {
        $failures++;
        echo "FAIL: denial $error\n  oczekiwane: $expected\n  otrzymane:  $got\n";
    }
}

// konfiguracja: linie KLUCZ=wartość i lista ścieżek po przecinku
file_put_contents($root . '/cfg', "# comment\nAGENT_ALLOWED_RW=~/projects, /private/tmp/claude-1000\nexport AGENT_ALLOWED_R=\"~/.claude/skills\"\n\nnot a pair\n");
$gotEnv = json_encode(Viz\Env::parse($root . '/cfg'), JSON_UNESCAPED_SLASHES);
$expectedEnv = '{"AGENT_ALLOWED_RW":"~/projects, /private/tmp/claude-1000","AGENT_ALLOWED_R":"~/.claude/skills"}';
if ($gotEnv !== $expectedEnv) {
    $failures++;
    echo "FAIL: Env::parse\n  oczekiwane: $expectedEnv\n  otrzymane:  $gotEnv\n";
}
$gotPaths = implode(' ', Viz\Env::pathList('~/projects, /private/tmp/claude-1000,, relative/dir'));
$expectedPaths = Viz\Path::home() . '/projects /private/tmp/claude-1000';
if ($gotPaths !== $expectedPaths) {
    $failures++;
    echo "FAIL: Env::pathList\n  oczekiwane: $expectedPaths\n  otrzymane:  $gotPaths\n";
}

// granica pracy agentów: wykonane poza / podejrzane / zatrzymane; zapis w strefie tylko do odczytu; programy systemowe
$limits = new Viz\Boundary(['/home/u/projects', '/tmp/claude-1000'], ['/home/u/.claude/skills']);
$ev = static fn (string $tool, array $touches, bool $failed = false, ?string $error = null, ?array $denial = null, bool $os = false, array $unresolved = []): Viz\Model\Event
    => new Viz\Model\Event('2026-01-01T00:00:00Z', 's', 'main', $tool, 'id', '', '/home/u/projects', $failed, $touches, $unresolved, error: $error, denial: $denial, osDenied: $os);
$touch = static fn (string $path, string $op = 'read'): Viz\Model\Touch => new Viz\Model\Touch($path, $op, true);
$boundaryCases = [
    ['inside', $ev('Read', [$touch('/home/u/projects/a.txt')]), 'null'],
    ['outside read', $ev('Read', [$touch('/etc/hosts')]), 'outside'],
    ['read-only zone read', $ev('Read', [$touch('/home/u/.claude/skills/x/SKILL.md')]), 'null'],
    ['read-only zone write', $ev('Write', [$touch('/home/u/.claude/skills/x/SKILL.md', 'write')]), 'outside'],
    ['system program', $ev('Bash', [$touch('/usr/bin/php', 'exec')]), 'null'],
    ['blind bash', $ev('Bash', [], unresolved: ['python3 — script from stdin/heredoc']), 'suspicious'],
    ['sandbox denied', $ev('Bash', [$touch('/home/u/.zprofile')], true, 'Exit code 1', null, true), 'stopped'],
    ['hook denied', $ev('Read', [$touch('/etc/hosts')], true, 'Error: blocked', ['by' => 'hook', 'detail' => null]), 'stopped'],
];
if (PHP_OS_FAMILY === 'Darwin') {
    $boundaryCases[] = ['/private alias', $ev('Write', [$touch('/private/tmp/claude-1000/x', 'write')]), 'null'];
}
foreach ($boundaryCases as [$name, $event, $expected]) {
    $got = $limits->classify($event) ?? 'null';
    if ($got !== $expected) {
        $failures++;
        echo "FAIL: boundary $name\n  oczekiwane: $expected\n  otrzymane:  $got\n";
    }
}

// wirtualne biuro: stan agenta z ostatnich wywołań, odpowiedzi modelu, promptów i zakłóceń; `now` = 10:10:00
$officeEv = static fn (string $agent, string $ts, ?string $end, string $tool = 'Bash', ?array $denial = null, array $touches = [], bool $failed = false): Viz\Model\Event
    => new Viz\Model\Event("2026-01-01T$ts" . 'Z', 's', $agent, $tool, $agent . $ts, 'echo x', '/w', $failed || $denial !== null, $touches, endTs: $end === null ? null : "2026-01-01T$end" . 'Z', denial: $denial);
$officeResp = static fn (string $agent, string $ts, string $model = 'claude-opus-5-5', ?float $usd = null): array
    => ['ts' => "2026-01-01T$ts" . 'Z', 'agent' => $agent, 'model' => $model, 'cost' => $usd === null ? null : ['input' => 0.0, 'read' => 0.0, 'write' => $usd, 'output' => 0.0]];
$officeLimits = new Viz\Boundary(['/w', '/tmp/claude-1000'], []);
$officeState = static function (array $events, array $usage, array $prompts = [], array $incidents = [], array $subs = []) use ($officeLimits): array {
    $agents = ['main' => new Viz\Model\Agent('main', 'main', 'main session', null)];
    foreach ($subs as $id) {
        $agents[$id] = new Viz\Model\Agent($id, 'Explore', 'szukaj', 'tu_' . $id);
    }
    $session = new Viz\Model\Session('s', '', 't', '/w', $agents, $events, count($events), $prompts, $usage, [], $incidents);
    $out = [];
    foreach (Viz\Office\OfficeState::build($session, (int) strtotime('2026-01-01T10:10:00Z'), $officeLimits)['agents'] as $a) {
        $out[] = $a['id'] . ':' . $a['state'] . ($a['tool'] !== null ? ':' . $a['tool'] : '') . ($a['blocked'] !== null ? ':!' . $a['blocked'] : '')
            . ($a['burn'] > 0 ? ':$' . $a['burn'] : '') . ($a['archive'] ? ':archive' : '') . ($a['interrupted'] ? ':interrupted' : '')
            . ($a['state'] === 'delegated' ? ':2→' . $a['waitingFor'] : '') . ($a['boundary'] !== null ? ':' . $a['boundary']['kind'] . '=' . $a['boundary']['target']['text'] : '');
    }

    return $out;
};
$officeCases = [
    ['working', $officeState([$officeEv('main', '10:09:51', null)], [$officeResp('main', '10:09:50')]), ['main:working:Bash']],
    ['open call before newer response = interrupted', $officeState([$officeEv('main', '10:08:00', null)], [$officeResp('main', '10:09:00')]), ['main:idle']],
    ['writing a reply after a tool result = working', $officeState([$officeEv('main', '10:09:01', '10:09:30')], [$officeResp('main', '10:09:00')]), ['main:working']],
    ['writing a reply after a prompt = working', $officeState([], [$officeResp('main', '10:00:00')], [['ts' => '2026-01-01T10:09:55Z', 'text' => 'x', 'kind' => 'prompt']]), ['main:working']],
    ['interrupted by the user', $officeState([], [$officeResp('main', '10:09:00')], [], [['ts' => '2026-01-01T10:09:10Z', 'agent' => 'main', 'kind' => 'interrupt', 'text' => 'x']]), ['main:idle:interrupted']],
    ['command does not wake model', $officeState([], [$officeResp('main', '10:09:00')], [['ts' => '2026-01-01T10:09:55Z', 'text' => '/model', 'kind' => 'command']]), ['main:idle']],
    ['idle stays up to 10 min', $officeState([], [$officeResp('main', '10:01:00')]), ['main:idle']],
    ['idle → gone after 10 min', $officeState([], [$officeResp('main', '09:59:00')]), []],
    ['thinking without a new line → gone after 10 min', $officeState([$officeEv('main', '09:58:00', '09:58:30')], [$officeResp('main', '09:57:59')]), []],
    ['open call stays up to 30 min', $officeState([$officeEv('main', '09:45:00', null)], [$officeResp('main', '09:44:59')]), ['main:working:Bash']],
    ['stale open call → gone after 30 min', $officeState([$officeEv('main', '09:30:00', null)], [$officeResp('main', '09:29:59')]), []],
    ['main waiting for a background subagent stays', $officeState([$officeEv('aaa', '10:09:00', null)], [$officeResp('main', '09:50:00'), $officeResp('aaa', '10:08:59')], [], [], ['aaa']),
        ['main:delegated:2→1', 'aaa:working:Bash']],
    ['stuck on API limit', $officeState([], [$officeResp('main', '10:09:00')], [], [['ts' => '2026-01-01T10:09:10Z', 'agent' => 'main', 'kind' => 'limit', 'text' => 'x']]), ['main:stuck']],
    ['blocked call', $officeState([$officeEv('main', '10:09:40', '10:09:41', 'Read', ['by' => 'hook', 'detail' => null])], [$officeResp('main', '10:09:39')]), ['main:working:!hook']],
    ['subagent working stays, finished ones leave', $officeState(
        [$officeEv('aaa', '10:09:00', null), $officeEv('bbb', '10:08:00', '10:08:10'), $officeEv('ccc', '10:00:00', '10:00:10')],
        [$officeResp('main', '10:08:59'), $officeResp('aaa', '10:08:59'), $officeResp('bbb', '10:09:00'), $officeResp('ccc', '10:01:00')],
        [], [], ['aaa', 'bbb', 'ccc'],
    ), ['main:delegated:2→1', 'aaa:working:Bash']],
    ['burn = cost of the last 5 min', $officeState([], [$officeResp('main', '10:00:00', usd: 3.0), $officeResp('main', '10:06:00', usd: 0.4), $officeResp('main', '10:09:00', usd: 0.35)]), ['main:idle:$0.75']],
    ['boundary: executed outside, then blocked at the wall', $officeState(
        [$officeEv('main', '10:08:00', '10:08:01', 'Read', touches: [new Viz\Model\Touch('/etc/hosts', 'read', true)]),
            $officeEv('main', '10:09:00', '10:09:01', 'Read', ['by' => 'hook', 'detail' => null], [new Viz\Model\Touch('/etc/passwd', 'read', true)])],
        [$officeResp('main', '10:09:30')],
    ), ['main:idle:!hook:stopped=/etc/passwd']],
    ['boundary: call outside still running = trying', $officeState([$officeEv('main', '10:09:50', null, 'Read', touches: [new Viz\Model\Touch('/etc/hosts', 'read', true)])], [$officeResp('main', '10:09:49')]), ['main:working:Read:trying=/etc/hosts']],
    ['archive left when back on project files', $officeState([$officeEv('main', '10:09:30', '10:09:31', 'Read', touches: [new Viz\Model\Touch('/tmp/claude-1000/a', 'read', true)]),
        $officeEv('main', '10:09:40', '10:09:41', 'Read', touches: [new Viz\Model\Touch('/w/a.php', 'read', true)])], [$officeResp('main', '10:09:42')]), ['main:idle']],
    ['boundary older than 2 min is forgotten', $officeState([$officeEv('main', '10:07:00', '10:07:01', 'Read', touches: [new Viz\Model\Touch('/etc/hosts', 'read', true)])], [$officeResp('main', '10:09:30')]), ['main:idle']],
    ['archive = Claude Code files, recent call', $officeState([$officeEv('main', '10:09:40', '10:09:41', 'Read', touches: [new Viz\Model\Touch('/tmp/claude-1000/x/tasks/a.output', 'read', true)])], [$officeResp('main', '10:09:42')]), ['main:idle:archive']],
    ['archive forgotten after 20 s', $officeState([$officeEv('main', '10:09:00', '10:09:01', 'Read', touches: [new Viz\Model\Touch('/tmp/claude-1000/x/a', 'read', true)])], [$officeResp('main', '10:09:42')]), ['main:idle']],
    ['merge: session prefix, empty session skipped, ordered by start', (static function () use ($officeEv): array {
        $agents = ['main' => new Viz\Model\Agent('main', 'main', 'main session', null)];
        $resp = static fn (string $ts): array => ['ts' => "2026-01-01T$ts" . 'Z', 'agent' => 'main', 'model' => 'm', 'cost' => null];
        $now = (int) strtotime('2026-01-01T10:10:00Z');
        $build = static fn (string $id, array $usage) => Viz\Office\OfficeState::build(new Viz\Model\Session($id, '', $id, '/w/' . $id, $agents, [], 0, [], $usage), $now);
        $m = Viz\Office\OfficeState::merge([$build('s2', [$resp('10:09:00')]), $build('s0', [$resp('09:00:00')]), $build('s1', [$resp('10:05:00')])], $now);

        return [implode(',', array_column($m['agents'], 'id')), implode(',', array_column($m['sessions'], 'project'))];
    })(), ['s1:main,s2:main', 's1,s2']],
    ['spent = all responses, burn = last 5 min', (static function () use ($officeResp): array {
        $agents = ['main' => new Viz\Model\Agent('main', 'main', 'main session', null)];
        $session = new Viz\Model\Session('s', '', 't', '/w', $agents, [], 0, [], [$officeResp('main', '09:00:00', usd: 2.0), $officeResp('main', '10:09:00', usd: 0.25)]);
        $a = Viz\Office\OfficeState::build($session, (int) strtotime('2026-01-01T10:10:00Z'))['agents'][0];

        return [(string) $a['spent'], (string) $a['burn']];
    })(), ['2.25', '0.25']],
    ['messages: fresh only, known agents, peer from outside, session prefix', (static function () use ($officeResp): array {
        $agents = ['main' => new Viz\Model\Agent('main', 'main', 'main session', null), 'a1' => new Viz\Model\Agent('a1', 'Explore', 'szukaj', 'tu_1')];
        $msg = static fn (string $ts, string $kind, string $from, string $to): array => ['ts' => "2026-01-01T$ts" . 'Z', 'from' => $from, 'to' => $to, 'kind' => $kind, 'text' => "szukaj\n  plików", 'cut' => false];
        $session = new Viz\Model\Session('s', '', 't', '/w', $agents, [], 0, [], [$officeResp('main', '10:09:59')], [
            $msg('10:09:00', 'task', 'main', 'a1'),
            $msg('10:09:40', 'task', 'main', 'a1'),
            $msg('10:09:45', 'user', 'user', 'main'),
            $msg('10:09:50', 'send', 'a1', 'team-lead'),
            $msg('10:09:55', 'result', 'a1', 'main'),
            $msg('10:09:58', 'peer', 'peer', 'main'),
        ]);
        $now = (int) strtotime('2026-01-01T10:10:00Z');
        $m = Viz\Office\OfficeState::merge([Viz\Office\OfficeState::build($session, $now)], $now)['messages'];

        return array_map(static fn (array $x): string => $x['kind'] . ':' . ($x['from'] ?? 'outside') . '→' . $x['to'] . ':' . $x['text'], $m);
    })(), ['task:s:main→s:a1:szukaj plików', 'result:s:a1→s:main:szukaj plików', 'peer:outside→s:main:szukaj plików']],
    ['persona and model name', [Viz\Office\OfficeState::persona('claude-haiku-4-5-20251001'), Viz\Office\OfficeState::modelName('claude-opus-5-5'),
        Viz\Office\OfficeState::modelName('claude-3-5-haiku-20241022'), Viz\Office\OfficeState::persona('gpt-x')], ['haiku', 'Opus 5.5', 'Haiku 3.5', 'default']],
    // głowa = klasa modelu: każdy model tej samej klasy to ta sama persona; fable i nieznane modele → głowa domyślna
    ['head per model class', array_map(Viz\Office\OfficeState::persona(...), ['claude-opus-5-5', 'claude-opus-4-1', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-3-5-haiku-20241022', 'Claude-OPUS-4', 'claude-fable-5-1', 'gpt-x', '']),
        ['opus', 'opus', 'sonnet', 'haiku', 'haiku', 'opus', 'default', 'default', 'default']],
    // koszulka = kolor projektu: stały, z nazwy (wielkość liter bez znaczenia), ten sam wynik przy każdym wywołaniu
    ['project color is fixed per project name', array_map(Viz\Office\OfficeState::projectColor(...), ['blog', 'blog', 'BLOG', 'agentMonitor', 'shop-api', '']),
        ['#4363d8', '#4363d8', '#4363d8', '#f032e6', '#e6c200', '#e6194b']],
    ['project palette: enough distinct hex colors', (static function (): array {
        $c = Viz\Office\OfficeState::PROJECT_COLORS;

        return [count($c) >= 8 ? 'enough' : 'few', array_filter($c, static fn (string $x): bool => preg_match('/^#[0-9a-f]{6}$/', $x) !== 1) === [] ? 'hex' : 'bad hex',
            count(array_unique($c)) === count($c) ? 'distinct' : 'duplicates'];
    })(), ['enough', 'hex', 'distinct']],
    ['session carries the project color', [Viz\Office\OfficeState::build(new Viz\Model\Session('s', '', 't', '/work/blog', ['main' => new Viz\Model\Agent('main', 'main', 'main session', null)], [], 0), (int) strtotime('2026-01-01T10:10:00Z'))['session']['projectColor']],
        ['#4363d8']],
    // persony w przeglądarce (office.js) to dokładnie te, które zwraca OfficeState::persona; interwały odświeżania
    ['office.js personas match OfficeState::persona', (static function (): array {
        preg_match_all('/^\s{8}(haiku|sonnet|opus|default): \{ skin:/m', (string) file_get_contents(__DIR__ . '/../public/assets/office.js'), $m);
        sort($m[1]);

        return $m[1];
    })(), ['default', 'haiku', 'opus', 'sonnet']],
    ['refresh interval 1 s in session views and office', array_map(static function (string $file): string {
        preg_match('/const POLL_MS = (\d+);/', (string) file_get_contents(__DIR__ . '/../public/assets/' . $file), $m);

        return $m[1] ?? '?';
    }, ['app.js', 'office.js']), ['1000', '1000']],
];
foreach ($officeCases as [$name, $got, $expected]) {
    if ($got !== $expected) {
        $failures++;
        echo "FAIL: office $name\n  oczekiwane: " . implode(', ', $expected) . "\n  otrzymane:  " . implode(', ', $got) . "\n";
    }
}

// CSV: komórki z danych niezaufanych (komendy, ścieżki, nazwy narzędzi) zaczynające się od = + - @ dostają prefiks `'` — arkusze wykonałyby je jako formuły
$formulaEvent = new Viz\Model\Event('2026-01-01T10:00:00Z', 's', 'main', 'Bash', 'tu1', 'x', '/w', false,
    [new Viz\Model\Touch('=HYPERLINK("http://x.example","a")', 'read', false)], commands: [
        new Viz\Model\Command('=HYPERLINK', '=sub', ['@ctx'], ['-wrap'], '+left'),
        new Viz\Model\Command('git', null, [], [], 'ls'),
    ]);
$csvDump = (static function () use ($formulaEvent, $root): array {
    $session = new Viz\Model\Session('s', '', 't', '/w', ['main' => new Viz\Model\Agent('main', 'main', 'main session', null)], [$formulaEvent], 1);
    $dir = $root . '/csv-dump';
    (new Viz\Export\SessionExport())->writeDir($dir, $session, [$formulaEvent], Viz\Stats::of([$formulaEvent]), 't', 'transcript', '');
    $read = static fn (string $file): array => array_map(
        static fn (string $line): array => str_getcsv($line, ',', '"', ''),
        array_values(array_filter(explode("\n", (string) file_get_contents($dir . '/' . $file)))),
    );

    return [$read('files.csv'), $read('commands.csv')];
})();
$csvCases = [
    ['safe(): formula characters get a prefix', array_map(Viz\Export\Csv::safe(...), ['=SUM(1)', '+cmd', '-rf', '@SUM', 'plain', '', 'a=b']), ["'=SUM(1)", "'+cmd", "'-rf", "'@SUM", 'plain', '', 'a=b']],
    ['cells(): text goes through safe(), numbers stay', Viz\Export\Csv::cells(['=x', 3, null, true, 'ok']), ["'=x", 3, null, true, 'ok']],
    ['files.csv: path cell is safe', [$csvDump[0][1][0] ?? '?'], ["'=HYPERLINK(\"http://x.example\",\"a\")"]],
    ['commands.csv: program, sub, contexts, wrappers, piped_from are safe', array_slice($csvDump[1][1] ?? [], 2, 5), ["'=HYPERLINK", "'=sub", "'@ctx", "'-wrap", "'+left"]],
    ['commands.csv: ordinary cells stay untouched', array_slice($csvDump[1][2] ?? [], 2, 5), ['git', '', '', '', 'ls']],
];
foreach ($csvCases as [$name, $got, $expected]) {
    if ($got !== $expected) {
        $failures++;
        echo "FAIL: csv $name\n  oczekiwane: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n  otrzymane:  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// odświeżanie: zabezpieczenia przed nakładaniem żądań, zawieszonym połączeniem i niewidocznym błędem muszą istnieć w kodzie
// (zachowanie sprawdza tests/refresh.test.js; tu — że nikt ich nie usunął, także gdy node jest niedostępny)
$source = static fn (string $file): string => (string) file_get_contents(__DIR__ . '/../' . $file);
$missing = static fn (string $file, array $tokens): array => array_values(array_filter($tokens, static fn (string $t): bool => !str_contains($source($file), $t)));
$guardCases = [
    ['app.js: refresh guards', $missing('public/assets/app.js', ['section._busy = true', 'section._busy = false', '_fragmentUntil', 'new AbortController()', 'FETCH_TIMEOUT_MS = 5000', '.catch(() => refreshFailed(true))', 'refreshFailed(false)', 'viz-refresh-error']), []],
    ['office.js: refresh guards', $missing('public/assets/office.js', ['root._busy = true', 'root._busy = false', 'new AbortController()', 'FETCH_TIMEOUT_MS = 5000', "'refresh not working'", '.catch(() => { office.failed = true; })']), []],
    ['layout.php: refresh error element', $missing('templates/layout.php', ['viz-refresh-error']), []],
];
foreach ($guardCases as [$name, $got, $expected]) {
    if ($got !== $expected) {
        $failures++;
        echo "FAIL: $name\n  brakuje: " . implode(', ', $got) . "\n";
    }
}
// zachowanie (wirtualny zegar, sterowany fetch): wymaga node; bez niego SKIP, a test działa ręcznie: node tests/refresh.test.js
$nodeOut = [];
exec('node ' . escapeshellarg(__DIR__ . '/refresh.test.js') . ' 2>&1', $nodeOut, $nodeCode);
$nodeRan = $nodeCode === 127 ? 0 : 1;
if ($nodeCode === 127) {
    echo "SKIP: node not found — tests/refresh.test.js not run (node tests/refresh.test.js)\n";
} elseif ($nodeCode !== 0) {
    $failures++;
    echo "FAIL: tests/refresh.test.js\n  " . implode("\n  ", $nodeOut) . "\n";
}

exec('rm -rf ' . escapeshellarg($root));
$total = count($cases) + count($toolCases) + count($commandCases) + count($roles) + count($denialCases) + count($boundaryCases) + count($officeCases) + count($csvCases) + count($guardCases) + $nodeRan + 4;
echo $failures === 0 ? "OK ($total przypadków)\n" : "$failures/$total nieudanych\n";
exit($failures === 0 ? 0 : 1);

/** @return list<string> */
function describe(Extraction $e, string $root): array
{
    $out = [];
    foreach ($e->touches as $t) {
        $rel = $t->path === $root ? '.' : (str_starts_with($t->path, $root . '/') ? substr($t->path, strlen($root) + 1) : $t->path);
        $s = $t->op . ':' . $rel;
        if ($t->partial) {
            $s .= ':partial' . ($t->range !== null ? ':' . $t->range : '');
        }
        $out[] = $s;
    }

    return $out;
}
