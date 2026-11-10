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
    => ['ts' => "2026-01-01T$ts" . 'Z', 'agent' => $agent, 'model' => $model, 'final' => true, 'input' => 0, 'output' => 0, 'cacheWrite' => 0, 'cacheRead' => 0,
        'cost' => $usd === null ? null : ['input' => 0.0, 'read' => 0.0, 'write' => $usd, 'output' => 0.0]];
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
    ['feed for the office: who is gone by office thresholds, head, start', (static function () use ($officeEv, $officeResp, $officeLimits): array {
        $agents = ['main' => new Viz\Model\Agent('main', 'main', 'main session', null), 'a1' => new Viz\Model\Agent('a1', 'Explore', 'szukaj', 'tu_1')];
        $session = new Viz\Model\Session('s', '', 't', '/w', $agents, [$officeEv('a1', '10:09:50', null)], 1, [], [$officeResp('main', '09:50:00'), $officeResp('a1', '10:09:49', 'claude-haiku-5-5')]);
        $live = Viz\Office\LiveState::session($session, (int) strtotime('2026-01-01T10:10:00Z'), $officeLimits);

        return array_map(static fn (array $a): string => $a['id'] . ':' . $a['state'] . ':' . ($a['gone'] ? 'gone' : 'here') . ':' . $a['persona'] . ':' . $a['start'], $live['agents']);
    })(), ['main:delegated:gone:opus:2026-01-01T09:50:00Z', 'a1:working:here:haiku:2026-01-01T10:09:49Z']],
    ['spent = all responses, burn = last 5 min', (static function () use ($officeResp): array {
        $agents = ['main' => new Viz\Model\Agent('main', 'main', 'main session', null)];
        $session = new Viz\Model\Session('s', '', 't', '/w', $agents, [], 0, [], [$officeResp('main', '09:00:00', usd: 2.0), $officeResp('main', '10:09:00', usd: 0.25)]);
        $a = Viz\Office\OfficeState::build($session, (int) strtotime('2026-01-01T10:10:00Z'))['agents'][0];

        return [(string) $a['spent'], (string) $a['burn']];
    })(), ['2.25', '0.25']],
    ['messages: fresh only, known agents, peer from outside', (static function () use ($officeResp): array {
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
        $m = Viz\Office\OfficeState::messages($session, (int) strtotime('2026-01-01T10:10:00Z'));

        return array_map(static fn (array $x): string => $x['kind'] . ':' . ($x['from'] ?? 'outside') . '→' . $x['to'] . ':' . $x['text'], $m);
    })(), ['task:main→a1:szukaj plików', 'result:a1→main:szukaj plików', 'peer:outside→main:szukaj plików']],
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
    }, ['app.js', 'office.js', 'feed.js']), ['1000', '1000', '1000']],
];
foreach ($officeCases as [$name, $got, $expected]) {
    if ($got !== $expected) {
        $failures++;
        echo "FAIL: office $name\n  oczekiwane: " . implode(', ', $expected) . "\n  otrzymane:  " . implode(', ', $got) . "\n";
    }
}

// Live (LiveState): okno 30 min, subagent zakończony = done, kontekst i okno modelu, zablokowany raport, granica; `now` = 10:10:00
$liveNow = (int) strtotime('2026-01-01T10:10:00Z');
$liveResp = static fn (string $agent, string $ts, int $tokens = 0, string $model = 'claude-opus-5-5'): array
    => ['ts' => "2026-01-01T$ts" . 'Z', 'agent' => $agent, 'model' => $model, 'tokens' => $tokens, 'cost' => null, 'final' => true, 'input' => 1, 'output' => 10, 'cacheWrite' => 0, 'cacheRead' => $tokens];
$liveSession = static function (array $events, array $usage, array $subs = [], ?Viz\Boundary $limits = null) use ($officeLimits, $liveNow): ?array {
    $agents = ['main' => new Viz\Model\Agent('main', 'main', 'main session', null)];
    foreach ($subs as $id) {
        $agents[$id] = new Viz\Model\Agent($id, 'Explore', 'szukaj', 'tu_' . $id);
    }

    return Viz\Office\LiveState::session(new Viz\Model\Session('s', '', 't', '/w', $agents, $events, count($events), [], $usage, [], []), $liveNow, $limits ?? $officeLimits);
};
$liveStates = static fn (?array $s): array => $s === null ? ['none'] : array_map(static fn (array $a): string => $a['id'] . ':' . $a['state'], $s['agents']);
$liveCtx = $liveSession([], [$liveResp('main', '10:09:00', 452_000)]);
$liveBound = $liveSession([
    $officeEv('main', '09:30:00', '09:30:01', 'Read', null, [$touch('/etc/old')]),
    $officeEv('main', '10:01:00', '10:01:01', 'Read', null, [$touch('/etc/a')]),
    $officeEv('main', '10:02:00', '10:02:01', 'Read', ['by' => 'user', 'detail' => null], [$touch('/etc/c')]),
    new Viz\Model\Event('2026-01-01T10:03:00Z', 's', 'main', 'Bash', 'tu_py', 'python3 - <<EOF', '/w', false, [], unresolved: ['heredoc'], endTs: '2026-01-01T10:03:01Z'),
    $officeEv('main', '10:04:00', '10:04:01', 'Read', null, [$touch('/w/ok.txt')]),
    $officeEv('main', '10:09:30', null, 'Read', null, [$touch('/etc/b')]),
], [$liveResp('main', '10:09:29')]);
$liveCases = [
    // oś pracy: prompty bez treści, minione oczekiwanie (ostatnia aktywność sesji głównej → prompt), wiadomość do innej sesji
    ['axis: prompts, past waits and sends to other sessions', (static function () use ($officeEv, $touch, $liveNow, $officeLimits, $liveResp): array {
        $session = new Viz\Model\Session('s', '', 't', '/w', ['main' => new Viz\Model\Agent('main', 'main', 'main session', null)],
            [$officeEv('main', '10:01:00', '10:01:05', 'Read', null, [$touch('/w/a')])], 1,
            [['ts' => '2026-01-01T10:04:00Z', 'text' => 'tajne', 'kind' => 'prompt'], ['ts' => '2026-01-01T10:06:00Z', 'text' => 'message from: x', 'kind' => 'peer']],
            [$liveResp('main', '10:02:00')],
            [['ts' => '2026-01-01T10:05:00Z', 'from' => 'main', 'to' => 'app_system_leader', 'kind' => 'send', 'text' => 'hi', 'cut' => false],
             ['ts' => '2026-01-01T10:05:30Z', 'from' => 'main', 'to' => 'main', 'kind' => 'send', 'text' => 'self', 'cut' => false]]);
        $row = Viz\Office\LiveState::session($session, $liveNow, $officeLimits);

        return [$row['prompts'], $row['waits'], $row['sends']];
    })(), [[['ts' => '2026-01-01T10:04:00Z', 'kind' => 'prompt'], ['ts' => '2026-01-01T10:06:00Z', 'kind' => 'peer']],
        [['from' => '2026-01-01T10:02:00Z', 'to' => '2026-01-01T10:04:00Z']], [['ts' => '2026-01-01T10:05:00Z', 'to' => 'app_system_leader']]]],
    ['agents: tokens of the whole session per agent', $liveCtx['agents'][0]['tokens'], ['output' => 10, 'cacheWrite' => 0, 'cacheRead' => 452_000, 'input' => 1]],
    // wywołanie poza granicą przez drugą ścieżkę: tekst = ścieżka poza granicą, nie pierwsza
    ['calls: outside call shows the outside path', array_column($liveSession([$officeEv('main', '10:05:00', '10:05:01', 'Bash', null, [$touch('/w/ok.txt'), $touch('/etc/x')])], [])['agents'][0]['calls'], 'text'), ['/etc/x']],
    // zakładka Flow: cała sesja bez okna — stara sesja nie znika, wywołania sprzed okna zostają
    ['session whole: old session kept with all calls', (static function () use ($officeEv, $touch, $liveNow, $officeLimits): array {
        $session = new Viz\Model\Session('s', '', 't', '/w', ['main' => new Viz\Model\Agent('main', 'main', 'main session', null)],
            [$officeEv('main', '09:00:00', '09:00:01', 'Read', null, [$touch('/w/a')])], 1, [], [], [], []);
        $whole = Viz\Office\LiveState::session($session, $liveNow, $officeLimits, true);

        return [Viz\Office\LiveState::session($session, $liveNow, $officeLimits) === null, count($whole['agents'][0]['calls'] ?? [])];
    })(), [true, 1]],
    ['idle 20 min stays (the office lets it go after 10 min)', $liveStates($liveSession([], [$liveResp('main', '09:50:00')])), ['main:idle']],
    ['idle 40 min → outside the window', $liveStates($liveSession([], [$liveResp('main', '09:30:00')])), ['none']],
    ['finished subagent in the window = done', $liveStates($liveSession([$officeEv('aaa', '10:00:00', '10:00:10')], [$liveResp('main', '09:59:00'), $liveResp('aaa', '10:01:00')], ['aaa'])), ['main:idle', 'aaa:done']],
    ['context = last response, window 1M by default', [$liveCtx['agents'][0]['context'], $liveCtx['agents'][0]['contextMax']], [452_000, 1_000_000]],
    ['context window per model, never below the context', [Viz\Office\LiveState::contextWindow('claude-haiku-4-5'), Viz\Office\LiveState::contextWindow('claude-haiku-5-5'),
        $liveSession([], [$liveResp('main', '10:09:00', 250_000, 'claude-haiku-4-5')])['agents'][0]['contextMax']], [200_000, 1_000_000, 250_000]],
    ['blocked report of a subagent', $liveSession([$officeEv('aaa', '10:05:00', '10:05:01', 'SubagentHandback', ['by' => 'hook', 'detail' => null])], [$liveResp('main', '10:00:00'), $liveResp('aaa', '10:05:02')], ['aaa'])['agents'][1]['reportBlocked']['by'] ?? null, 'hook'],
    ['report delivered after a block = no warning', $liveSession([$officeEv('aaa', '10:05:00', '10:05:01', 'SubagentHandback', ['by' => 'hook', 'detail' => null]), $officeEv('aaa', '10:06:00', '10:06:01', 'SubagentHandback')],
        [$liveResp('main', '10:00:00'), $liveResp('aaa', '10:06:02')], ['aaa'])['agents'][1]['reportBlocked'], null],
    ['boundary: kinds in the window, in call order', array_map(static fn (array $b): string => $b['kind'] . '=' . $b['text'] . ($b['by'] !== null ? ' by ' . $b['by'] : ''), $liveBound['boundary']),
        ['outside=/etc/a', 'stopped=/etc/c by user', 'unknown=python3 - <<EOF', 'trying=/etc/b']],
    ['judge: structured_output, JSON in result, error and non-JSON output', (static function (): array {
        $j = ['context' => 'c', 'good' => [], 'bad' => [], 'improve' => [], 'caveats' => [], 'reasoning' => 'r', 'verdict' => 'mixed', 'tldr' => ['t']];
        $p = static fn (array|string $out): array => Viz\Judge\SessionJudge::parse(is_string($out) ? $out : (string) json_encode($out));
        $a = $p(['is_error' => false, 'result' => '', 'structured_output' => $j, 'total_cost_usd' => 0.123456, 'num_turns' => 7, 'duration_ms' => 61000, 'session_id' => '0b5c2c1e-1111-4222-8333-444455556666']);
        $b = $p(['is_error' => false, 'result' => json_encode($j)]);
        $c = $p(['is_error' => true, 'result' => 'Credit balance is too low']);
        $d = $p('Error: not logged in');

        return [$a['ok'], $a['judgment']['verdict'], $a['costUsd'], $a['turns'], $a['judgeSession'], $b['ok'], $b['judgeSession'], $c['ok'], $c['error'], $d['ok'], str_starts_with((string) $d['error'], 'output is not JSON')];
    })(), [true, 'mixed', 0.1235, 7, '0b5c2c1e-1111-4222-8333-444455556666', true, null, false, 'Credit balance is too low', false, true]],
    ['judge: read-only tools, no MCP, no --bare (keeps hooks and OAuth), judge session saved (visible in the monitor); reasoning before verdict', (static function (): array {
        $cmd = Viz\Judge\SessionJudge::command('0b5c2c1e-1111-4222-8333-444455556666', '/w/var/session_dump/x');
        $props = array_keys(Viz\Judge\SessionJudge::schema()['properties']);
        $after = static fn (string $flag): ?string => $cmd[array_search($flag, $cmd, true) + 1] ?? null;

        return [$after('--name'), in_array('/w/var/session_dump/x', $cmd, true), in_array('--bare', $cmd, true), in_array('--no-session-persistence', $cmd, true), $after('--tools'), $after('--permission-mode'), in_array('--strict-mcp-config', $cmd, true),
            array_search('reasoning', $props, true) < array_search('verdict', $props, true)];
    })(), ['agentMonitor_judge_0b5c2c1e-1111-4222-8333-444455556666', true, false, false, 'Read,Grep,Glob', 'dontAsk', true, true]],
    ['judge report: session id and paths for Claude Code on top, sections in order, findings with owner', (static function (): array {
        $sid = '0b5c2c1e-1111-4222-8333-444455556666';
        $f = ['severity' => 'high', 'title' => 'Czekanie na zgodę', 'evidence' => 'longest_until_result 34 min', 'fix' => 'allow git commit', 'owner' => 'hooks-permissions',
            'owner_path' => '~/.claude/settings.json', 'why_missed' => 'brak reguły', 'confidence' => 'high'];
        $r = ['stamp' => '20261106-140956', 'title' => 'T', 'dump' => '/w/var/session_dump/x', 'transcript' => '/p/t.jsonl', 'started' => 0, 'judgeSession' => null,
            'judgment' => ['task' => 'a', 'outcome' => 'b', 'termination' => 'completed', 'timeline' => [], 'findings' => [$f], 'strengths' => [], 'prompt_tips' => [],
                'tools' => [], 'missing_evidence' => [], 'reasoning' => 'r', 'verdict' => 'mixed', 'tldr' => ['x']]];
        $md = Viz\Judge\SessionJudge::markdown($sid, $r);
        $pos = static fn (string $needle): int|false => strpos($md, $needle);

        return [str_contains(Viz\Judge\SessionJudge::handoff($sid, $r), 'Sesja Claude Code: ' . $sid), str_contains($md, 'Transkrypt: /p/t.jsonl'),
            $pos('## TL;DR') < $pos('## Gdzie się gubił') && $pos('## Gdzie się gubił') < $pos('## Problemy') && $pos('## Problemy') < $pos('## Prompty'),
            str_contains($md, 'właściciel: hooks-permissions (~/.claude/settings.json)')];
    })(), [true, true, true, true]],
    ['judge patterns: one place to fix = one group across sessions (path spellings merged), ranked by sessions; old format counted apart', (static function (): array {
        $f = static fn (string $path, string $sev): array => ['severity' => $sev, 'title' => 't ' . $path, 'owner' => 'hooks-permissions', 'owner_path' => $path, 'fix' => 'x'];
        $rep = static fn (string $sid, array $findings, array $timeline = []): array => ['sid' => $sid, 'title' => '', 'stamp' => 's', 'judgment' => ['findings' => $findings, 'timeline' => $timeline]];
        $r = Viz\Judge\Patterns::build([
            $rep('a', [$f('~/.claude/settings.json', 'low'), $f('/Users/Sebastian/.claude/settings.json', 'high')], [['kind' => 'blocked'], ['kind' => 'good']]),
            $rep('b', [$f('~/.claude/settings.json', 'medium'), $f('/x/only.md', 'high')], [['kind' => 'blocked']]),
            ['sid' => 'c', 'title' => '', 'stamp' => 's', 'judgment' => ['good' => []]],
        ]);

        return [$r['sessions'], $r['legacy'], count($r['groups']), $r['groups'][0]['path'], $r['groups'][0]['sessions'], count($r['groups'][0]['items']), $r['groups'][0]['severity'], $r['turns']];
    })(), [2, 1, 2, '.claude/settings.json', 2, 3, 3, [['kind' => 'blocked', 'count' => 2, 'sessions' => 2]]]],
    ['usdWindow: cost of model responses in the window, older ones skipped', (static function () use ($liveSession): array {
        $r = static fn (string $ts, ?float $usd): array => ['ts' => "2026-01-01T$ts" . 'Z', 'agent' => 'main', 'model' => 'claude-opus-5-5', 'tokens' => 0, 'final' => true,
            'input' => 0, 'output' => 0, 'cacheWrite' => 0, 'cacheRead' => 0, 'cost' => $usd === null ? null : ['input' => 0.0, 'read' => $usd, 'write' => 0.0, 'output' => 0.0]];

        return [$liveSession([], [$r('09:00:00', 9.0), $r('09:41:10', 0.1), $r('10:09:30', 0.3), $r('10:09:55', null)])['agents'][0]['usdWindow']];
    })(), [0.4]],
    ['counts: unique files read / changed and executed Bash in the window, per agent and per session', (static function () use ($liveSession, $liveResp, $officeEv): array {
        $op = static fn (string $path, string $o): Viz\Model\Touch => new Viz\Model\Touch($path, $o, true);
        $s = $liveSession([
            $officeEv('main', '09:30:00', '09:30:01', 'Read', null, [$op('/w/old', 'read')]),
            $officeEv('main', '10:01:00', '10:01:01', 'Read', null, [$op('/w/a', 'read')]),
            $officeEv('main', '10:02:00', '10:02:01', 'Read', null, [$op('/w/a', 'read')]),
            $officeEv('main', '10:03:00', '10:03:01', 'Edit', null, [$op('/w/a', 'edit')]),
            $officeEv('main', '10:04:00', '10:04:01', 'Bash'),
            $officeEv('main', '10:04:10', '10:04:11', 'Bash', ['by' => 'hook', 'detail' => null]),
            $officeEv('aaa', '10:05:00', '10:05:01', 'Read', null, [$op('/w/a', 'read'), $op('/w/b', 'read')]),
            $officeEv('aaa', '10:06:00', '10:06:01', 'Write', null, [$op('/w/c', 'write')]),
        ], [$liveResp('main', '10:07:00'), $liveResp('aaa', '10:06:02')], ['aaa']);

        return [$s['agents'][0]['counts'], $s['agents'][1]['counts'], $s['counts']];
    })(), [['read' => 1, 'write' => 1, 'bash' => 1], ['read' => 2, 'write' => 1, 'bash' => 0], ['read' => 2, 'write' => 2, 'bash' => 1]]],
    ['calls: result cost in context from Event::carry (none = null)', (static function () use ($liveNow, $officeLimits): array {
        $e = new Viz\Model\Event('2026-01-01T10:05:00Z', 's', 'main', 'Read', 'r1', 'a', '/w', false, [], endTs: '2026-01-01T10:05:01Z', carry: ['tokens' => 12_000, 'calls' => 4, 'usd' => 0.123456]);
        $f = new Viz\Model\Event('2026-01-01T10:06:00Z', 's', 'main', 'Read', 'r2', 'b', '/w', false, [], endTs: '2026-01-01T10:06:01Z');
        $session = new Viz\Model\Session('s', '', 't', '/w', ['main' => new Viz\Model\Agent('main', 'main', 'main session', null)], [$e, $f], 2, [], [], [], []);
        $calls = Viz\Office\LiveState::session($session, $liveNow, $officeLimits)['agents'][0]['calls'];

        return array_map(static fn (array $c): array => [$c['tokens'], $c['usd']], $calls);
    })(), [[12_000, 0.1235], [null, null]]],
    ['calls: status per call in the window (blocked before boundary, open call = trying)', array_column($liveBound['agents'][0]['calls'], 'status'), ['outside', 'blocked', 'unknown', 'ok', 'trying']],
    ['map: directories by zone, newest first (outside: read, stopped; inside: zone root)', array_map(static fn (array $m): string => $m['zone'] . ':' . ($m['root'] ?? '—') . ':' . $m['dir'] . ':r' . $m['read'] . ':s' . $m['stopped'] . ':' . implode(',', $m['agents']), $liveBound['map']),
        ['out:—:/etc:r3:s1:main', 'rw:/w:.:r1:s0:main']],
    ['zone of a path: rw, r, write in r = out with its root, system program, outside', (static function (): array {
        $b = new Viz\Boundary(['/w/'], ['/ro']);
        $z = static fn (string $path, string $op) => implode('@', array_filter($b->zoneOf(new Viz\Model\Touch($path, $op, true)), static fn ($v): bool => $v !== null));

        return [$z('/w/a/b.php', 'edit'), $z('/ro/x.md', 'read'), $z('/ro/x.md', 'write'), $z('/usr/bin/git', 'exec'), $z('/home/x', 'read'), json_encode($b->zones(), JSON_UNESCAPED_SLASHES)];
    })(), ['rw@/w', 'r@/ro', 'out@/ro', 'system', 'out', '{"rw":["/w"],"r":["/ro"]}']],
    ['boundary off without configuration', $liveSession([], [$liveResp('main', '10:09:00')], [], new Viz\Boundary([], []))['boundary'], null],
    ['merge: threshold from Signals, newest session first', (static function () use ($liveNow, $officeLimits): array {
        $m = Viz\Office\LiveState::merge([['id' => 'a', 'last' => '2026-01-01T10:00:00Z'], ['id' => 'b', 'last' => '2026-01-01T10:05:00Z']], $liveNow, $officeLimits);

        return [$m['contextHigh'], implode(',', array_column($m['sessions'], 'id')), $m['window']];
    })(), [Viz\Signals::CONTEXT_TOKENS, 'b,a', 1800]],
    // zakres projektów: biała lista kluczy, parametr wygrywa z ciasteczkiem (pusty = wszystkie), dopasowanie po katalogu sesji
    ['scope: only 12-hex keys, no duplicates', Viz\Web\Scope::parse('abcdefabcdef, zzz,ABCDEF123456,abcdefabcdef,../x')->keys, ['abcdefabcdef']],
    ['scope: empty parameter = all, wins over the cookie', Viz\Web\Scope::fromRequest(['projects' => ''], ['viz-scope' => 'abcdefabcdef'])->all(), true],
    ['scope: cookie without parameter', Viz\Web\Scope::fromRequest([], ['viz-scope' => 'abcdefabcdef'])->keys, ['abcdefabcdef']],
    ['scope: matches the session directory', (static function (): array {
        $s = Viz\Web\Scope::parse(Viz\Web\App::fileHash('/w'));

        return [$s->includes('/w'), $s->includes('/x'), $s->includes(null), Viz\Web\Scope::parse('')->includes(null)];
    })(), [true, false, false, true]],
    // rejestr widoków przeglądowych: trasy, dane, lewy panel, przełącznik; każdy szablon istnieje
    ['scope views: routes and data', [Viz\Web\ScopeViews::page('/lanes')['template'] ?? null, [Viz\Web\ScopeViews::isData('/feed.json'), Viz\Web\ScopeViews::isData('/office.json'), Viz\Web\ScopeViews::isData('/live.json')], Viz\Web\ScopeViews::page('/live'),
        array_column(Viz\Web\ScopeViews::menu(), 'path'), Viz\Web\ScopeViews::last(['viz-live' => '/agents']), Viz\Web\ScopeViews::last(['viz-live' => '/nope']), array_column(Viz\Web\ScopeViews::menu('/office'), 'path'), Viz\Web\ScopeViews::menu()[0]['label'], array_column(Viz\Web\ScopeViews::group('live'), 'path')], ['lanes.php', [true, false, false], null, ['/lanes'], '/agents', '/lanes', ['/office'], 'Live preview', ['/lanes', '/office', '/agents']]],
    ['scope views: templates exist', array_values(array_filter(array_column(Viz\Web\ScopeViews::VIEWS, 'template'), static fn (string $t): bool => !is_file(__DIR__ . '/../templates/' . $t))), []],
    ['scope views: label and title translated', array_values(array_filter(array_merge(array_column(Viz\Web\ScopeViews::VIEWS, 'label'), array_column(Viz\Web\ScopeViews::VIEWS, 'title')),
        static fn (string $k): bool => !isset((require __DIR__ . '/../lang/pl.php')[$k]))), []],
    // hook — zdarzenia stanu: tylko biała lista pól, treść (message, title, tool_input, error_details, last_assistant_message, pola obce) odrzucona;
    // z PermissionRequest tylko kształt komendy (`git commit`) i reguła z propozycji (bez `destination` i treści komendy)
    ['hook: state events — whitelist only, content dropped, unknown types and events skipped', (static function () use ($root): array {
        $dir = "$root/hook-state";
        $sid = '0b5c2c1e-1111-4222-8333-444455556666';
        $rec = new Viz\Source\HookRecorder($dir);
        $base = ['session_id' => $sid, 'transcript_path' => '/x/t.jsonl', 'cwd' => '/w', 'permission_mode' => 'default', 'effort' => ['level' => 'high']];
        $rec->record($base + ['hook_event_name' => 'Notification', 'notification_type' => 'permission_prompt', 'message' => 'TRESC-1 Claude needs your permission', 'title' => 'TRESC-2']);
        $rec->record($base + ['hook_event_name' => 'Notification', 'notification_type' => 'auth_success', 'message' => 'TRESC-0']);
        $rec->record($base + ['hook_event_name' => 'PermissionRequest', 'agent_id' => 'abc123', 'agent_type' => 'Explore', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'git commit -m "TRESC-3"'],
            'permission_suggestions' => [['type' => 'addRules', 'rules' => [['toolName' => 'Bash', 'ruleContent' => 'git commit *']], 'behavior' => 'allow', 'destination' => 'TRESC-4']]]);
        $rec->record($base + ['hook_event_name' => 'StopFailure', 'error' => 'rate_limit', 'error_details' => 'TRESC-5', 'last_assistant_message' => 'TRESC-6']);
        $rec->record($base + ['hook_event_name' => 'StopFailure', 'error' => 'TRESC-made-up']);
        $rec->record(['hook_event_name' => 'SessionEnd', 'reason' => 'prompt_input_exit', 'session_id' => $sid, 'cwd' => "/w\nTRESC-8", 'extra' => 'TRESC-7', 'permission_mode' => 'TRESC-mode']);
        $rec->record($base + ['hook_event_name' => 'PreToolUse', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'TRESC-9']]);
        $rec->record(['hook_event_name' => 'SessionEnd', 'session_id' => '../x', 'reason' => 'other']);
        $raw = (string) @file_get_contents("$dir/$sid.state.jsonl");
        $lines = array_map(static fn (string $l): array => (array) json_decode($l, true), array_values(array_filter(explode("\n", $raw))));
        $keys = array_values(array_unique(array_merge(...array_map(array_keys(...), $lines))));
        sort($keys);

        return [str_contains($raw, 'TRESC'), is_file("$dir/$sid.jsonl"), count($lines), $keys,
            array_map(static fn (array $h): string => $h['event'] . ':' . $h['agent'] . ':' . $h['detail'], Viz\Source\HookLog::readState("$dir/$sid.state.jsonl")),
            [$lines[0]['permission_mode'], $lines[0]['effort'], $lines[4]['permission_mode'], $lines[4]['cwd'], $lines[1]['agent_type']]];
    })(), [false, false, 5, ['agent_id', 'agent_type', 'commands', 'cwd', 'effort', 'error', 'event', 'notification_type', 'permission_mode', 'reason', 'rules', 'session_id', 'tool_name', 'ts'],
        ['Notification:main:permission_prompt', 'PermissionRequest:abc123:Bash', 'StopFailure:main:rate_limit', 'StopFailure:main:unknown', 'SessionEnd:main:prompt_input_exit'],
        ['default', 'high', null, null, 'Explore']]],
    // zgodność wsteczna: payload bez `hook_event_name` (stary) i PostToolUseFailure trafiają do logu narzędzi, nie do pliku stanu
    ['hook: tool events stay in the tool log, old payload without event name too; common fields added', (static function () use ($root): array {
        $dir = "$root/hook-tools";
        $sid = '0b5c2c1e-1111-4222-8333-444455556667';
        $rec = new Viz\Source\HookRecorder($dir);
        $rec->record(['session_id' => $sid, 'tool_name' => 'Read', 'tool_input' => ['file_path' => '/w/a.txt', 'content' => 'TRESC'], 'tool_use_id' => 'tu1', 'cwd' => '/w']);
        $rec->record(['session_id' => $sid, 'hook_event_name' => 'PostToolUseFailure', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'false'], 'tool_use_id' => 'tu2', 'cwd' => '/w',
            'error' => "Exit code 1\nTRESC", 'permission_mode' => 'plan', 'effort' => ['level' => 'max']]);
        $lines = array_map(static fn (string $l): array => (array) json_decode($l, true), array_values(array_filter(explode("\n", (string) @file_get_contents("$dir/$sid.jsonl")))));

        return [count($lines), $lines[0]['tool'] ?? null, $lines[0]['input'] ?? null, $lines[0]['failed'] ?? null, $lines[1]['failed'] ?? null, $lines[1]['error'] ?? null,
            $lines[1]['permission_mode'] ?? null, $lines[1]['effort'] ?? null, is_file("$dir/$sid.state.jsonl"), Viz\Source\HookLog::readState("$dir/$sid.jsonl")];
    })(), [2, 'Read', ['file_path' => '/w/a.txt'], false, true, 'Exit code 1', 'plan', 'max', false, []]],
    // stany z hooka: prośba o zgodę po starcie trwającego wywołania = waiting-permission; koniec sesji; błąd API z typem; bez zdarzenia — stan z transkryptu
    ['hook states: waiting-permission, ended, stuck with type; without events the transcript state stays', (static function () use ($officeEv, $liveResp, $liveNow, $officeLimits): array {
        $h = static fn (string $ts, string $event, ?string $detail, string $agent = 'main'): array => ['ts' => "2026-01-01T$ts" . 'Z', 'event' => $event, 'agent' => $agent, 'detail' => $detail];
        $s = static function (array $events, array $usage, array $hooks, array $subs = []) use ($liveNow, $officeLimits): ?array {
            $agents = ['main' => new Viz\Model\Agent('main', 'main', 'main session', null)];
            foreach ($subs as $id) {
                $agents[$id] = new Viz\Model\Agent($id, 'Explore', 'szukaj', 'tu_' . $id);
            }

            return Viz\Office\LiveState::session(new Viz\Model\Session('s', '', 't', '/w', $agents, $events, count($events), [], $usage, [], []), $liveNow, $officeLimits, false, $hooks);
        };
        $st = static fn (?array $x): string => implode(' ', array_map(static fn (array $a): string => $a['id'] . ':' . $a['state'], $x['agents'] ?? []));
        $open = [$officeEv('main', '10:08:00', null)];
        $resp = [$liveResp('main', '10:07:59')];
        $ask = $s($open, $resp, [$h('10:08:05', 'PermissionRequest', 'Bash')]);
        $note = $s($open, $resp, [$h('10:08:06', 'Notification', 'permission_prompt')]);
        $before = $s($open, $resp, [$h('10:07:00', 'PermissionRequest', 'Bash')]);
        $idleNote = $s($open, $resp, [$h('10:08:06', 'Notification', 'idle_prompt')]);
        $none = $s($open, $resp, []);
        $past = $s([$officeEv('main', '10:05:00', '10:06:00')], [$liveResp('main', '10:06:01')], [$h('10:05:10', 'PermissionRequest', 'Bash')]);
        $sub = $s([$officeEv('aaa', '10:08:00', null)], [$liveResp('main', '10:07:00'), $liveResp('aaa', '10:07:59')], [$h('10:08:05', 'PermissionRequest', 'Read', 'aaa')], ['aaa']);
        $ended = $s([], [$liveResp('main', '10:05:00')], [$h('10:06:00', 'SessionEnd', 'prompt_input_exit')]);
        $resumed = $s([], [$liveResp('main', '10:07:00')], [$h('10:06:00', 'SessionEnd', 'resume')]);
        $failed = $s([], [$liveResp('main', '10:05:00')], [$h('10:05:02', 'StopFailure', 'overloaded')]);

        return [$st($ask), $ask['agents'][0]['since'], $ask['agents'][0]['permission'], $ask['agents'][0]['calls'][0]['permission'], $ask['permissionHook'],
            $st($note), $st($before), $st($idleNote), $idleNote['permissionHook'], $st($none), $none['permissionHook'], $none['agents'][0]['calls'][0]['permission'],
            $st($past), $past['agents'][0]['calls'][0]['permission'], $st($sub),
            $st($ended), $ended['agents'][0]['ended'], $ended['agents'][0]['gone'], $st($resumed),
            $st($failed), $failed['agents'][0]['stuck'], $failed['agents'][0]['stuckType']];
    })(), ['main:waiting-permission', '2026-01-01T10:08:05Z', ['ts' => '2026-01-01T10:08:05Z', 'tool' => 'Bash'], '2026-01-01T10:08:05Z', true,
        'main:waiting-permission', 'main:working', 'main:working', false, 'main:working', false, null,
        'main:idle', '2026-01-01T10:05:10Z', 'main:delegated aaa:waiting-permission',
        'main:ended', 'prompt_input_exit', true, 'main:idle',
        'main:stuck', 'api-error', 'overloaded']],
    // hook — Stop, subagenci, instrukcje, kompaktowanie: tylko biała lista; `last_assistant_message`, opisy zadań w tle, prompty cronów,
    // `custom_instructions`, `compact_summary`, pola obce odrzucone; agent wewnętrzny (pusty `agent_type`) i Subagent* bez `agent_id` pominięte
    ['hook: Stop, subagent, instructions and compaction events — whitelist only, content dropped', (static function () use ($root): array {
        $dir = "$root/hook-life";
        $sid = '0b5c2c1e-1111-4222-8333-444455556668';
        $rec = new Viz\Source\HookRecorder($dir);
        $base = ['session_id' => $sid, 'transcript_path' => '/x/t.jsonl', 'cwd' => '/w', 'permission_mode' => 'default', 'prompt_id' => 'TRESC-0'];
        $rec->record($base + ['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'last_assistant_message' => 'TRESC-1',
            'background_tasks' => [['id' => 't1', 'type' => 'shell', 'status' => 'running', 'description' => 'TRESC-2', 'command' => 'TRESC-3']],
            'session_crons' => [['id' => 'c1', 'schedule' => '* * * * *', 'recurring' => true, 'prompt' => 'TRESC-4']]]);
        $rec->record($base + ['hook_event_name' => 'SubagentStart', 'agent_id' => 'abc123', 'agent_type' => 'Explore']);
        $rec->record($base + ['hook_event_name' => 'SubagentStop', 'agent_id' => 'abc123', 'agent_type' => 'Explore', 'stop_hook_active' => 'TRESC-5',
            'agent_transcript_path' => '/x/t/subagents/agent-abc123.jsonl', 'last_assistant_message' => 'TRESC-6']);
        $rec->record($base + ['hook_event_name' => 'SubagentStop', 'agent_id' => 'def456', 'agent_type' => '', 'last_assistant_message' => 'TRESC-7']);
        $rec->record($base + ['hook_event_name' => 'SubagentStart', 'agent_type' => 'Explore']);
        $rec->record($base + ['hook_event_name' => 'SubagentStop', 'agent_id' => 'abc124', 'agent_type' => 'Plan', 'agent_transcript_path' => 'relative/TRESC-8']);
        $rec->record($base + ['hook_event_name' => 'InstructionsLoaded', 'file_path' => '/w/CLAUDE.md', 'memory_type' => 'Project', 'load_reason' => 'session_start', 'content' => 'TRESC-9']);
        $rec->record($base + ['hook_event_name' => 'InstructionsLoaded', 'file_path' => '/w/.claude/rules/a.md', 'memory_type' => 'TRESC-10', 'load_reason' => 'path_glob_match',
            'globs' => ['src/**/*.php', "TRESC\n11", 5], 'trigger_file_path' => '/w/src/A.php', 'parent_file_path' => "/w/\x01TRESC-12"]);
        $rec->record($base + ['hook_event_name' => 'InstructionsLoaded', 'file_path' => 'TRESC-13/CLAUDE.md', 'load_reason' => 'include']);
        $rec->record($base + ['hook_event_name' => 'PreCompact', 'trigger' => 'manual', 'custom_instructions' => 'TRESC-14']);
        $rec->record($base + ['hook_event_name' => 'PostCompact', 'trigger' => 'TRESC-15', 'compact_summary' => 'TRESC-16']);
        $read = static fn (string $f): array => array_map(static fn (string $l): array => (array) json_decode($l, true), array_values(array_filter(explode("\n", (string) @file_get_contents($f)))));
        $keys = static function (array $lines): array {
            $k = array_values(array_unique(array_merge(...array_map(array_keys(...), $lines))));
            sort($k);

            return $k;
        };
        [$state, $context] = [$read("$dir/$sid.state.jsonl"), $read("$dir/$sid.context.jsonl")];
        $raw = (string) @file_get_contents("$dir/$sid.state.jsonl") . @file_get_contents("$dir/$sid.context.jsonl");

        return [str_contains($raw, 'TRESC'), str_contains($raw, 'last_assistant_message'), is_file("$dir/$sid.jsonl"), count($state), count($context), $keys($state), $keys($context),
            array_map(static fn (array $h): string => $h['event'] . ':' . $h['agent'] . ':' . $h['detail'], Viz\Source\HookLog::readState("$dir/$sid.state.jsonl")),
            [$state[0]['stop_hook_active'], $state[0]['background_tasks'], $state[2]['stop_hook_active'], $state[2]['agent_transcript_path'], $state[3]['agent_transcript_path']],
            array_map(static fn (array $i): array => array_diff_key($i, ['ts' => 0]), Viz\Source\HookLog::readInstructions("$dir/$sid.context.jsonl")),
            array_map(static fn (array $c): string => $c['agent'] . ':' . $c['phase'] . ':' . $c['trigger'], Viz\Source\HookLog::readCompactions("$dir/$sid.context.jsonl")),
            Viz\Source\HookLog::readState("$dir/$sid.context.jsonl"), Viz\Source\HookLog::instructions('../x'), Viz\Source\HookLog::compactions('../x')];
    })(), [false, false, false, 4, 4,
        ['agent_id', 'agent_transcript_path', 'agent_type', 'background_tasks', 'cwd', 'effort', 'event', 'permission_mode', 'session_id', 'stop_hook_active', 'ts'],
        ['agent_id', 'event', 'file_path', 'globs', 'load_reason', 'memory_type', 'parent_file_path', 'session_id', 'trigger', 'trigger_file_path', 'ts'],
        ['Stop:main:', 'SubagentStart:abc123:Explore', 'SubagentStop:abc123:Explore', 'SubagentStop:abc124:Plan'],
        [false, 1, null, '/x/t/subagents/agent-abc123.jsonl', null],
        [['agent' => 'main', 'file' => '/w/CLAUDE.md', 'memoryType' => 'Project', 'reason' => 'session_start', 'globs' => [], 'trigger' => null, 'parent' => null],
            ['agent' => 'main', 'file' => '/w/.claude/rules/a.md', 'memoryType' => null, 'reason' => 'path_glob_match', 'globs' => ['src/**/*.php'], 'trigger' => '/w/src/A.php', 'parent' => null]],
        ['main:pre:manual', 'main:post:'], [], [], []]],
    // stany z hooka: Stop = dokładne „czeka na Ciebie” (także przy wywołaniu bez wyniku), Stop sprzed aktywności i brak Stop — heurystyka;
    // SubagentStart = start i praca przed pierwszą odpowiedzią, SubagentStop = dokładny koniec; hook nie tworzy agenta spoza transkryptu
    ['hook states: Stop and subagent start / stop exact, transcript heuristic as fallback', (static function () use ($officeEv, $liveResp, $liveNow, $officeLimits): array {
        $h = static fn (string $ts, string $event, ?string $detail = null, string $agent = 'main'): array => ['ts' => "2026-01-01T$ts" . 'Z', 'event' => $event, 'agent' => $agent, 'detail' => $detail];
        $s = static function (array $events, array $usage, array $hooks, array $subs = []) use ($liveNow, $officeLimits): ?array {
            $agents = ['main' => new Viz\Model\Agent('main', 'main', 'main session', null)];
            foreach ($subs as $id) {
                $agents[$id] = new Viz\Model\Agent($id, 'Explore', 'szukaj', 'tu_' . $id);
            }

            return Viz\Office\LiveState::session(new Viz\Model\Session('s', '', 't', '/w', $agents, $events, count($events), [], $usage, [], []), $liveNow, $officeLimits, false, $hooks);
        };
        $st = static fn (?array $x): string => implode(' ', array_map(static fn (array $a): string => $a['id'] . ':' . $a['state'], $x['agents'] ?? []));
        $done = [$officeEv('main', '10:05:00', '10:05:01')];
        $stopped = $s($done, [$liveResp('main', '10:05:02')], [$h('10:05:03', 'Stop')]);
        $plain = $s($done, [$liveResp('main', '10:05:02')], []);
        $ghost = $s([$officeEv('main', '10:06:00', null)], [$liveResp('main', '10:05:59')], [$h('10:06:30', 'Stop')]);
        $again = $s([...$done, $officeEv('main', '10:07:00', null)], [$liveResp('main', '10:05:02'), $liveResp('main', '10:06:59')], [$h('10:05:03', 'Stop')]);
        $early = $s([], [$liveResp('main', '10:07:00')], [$h('10:08:00', 'SubagentStart', 'Explore', 'aaa')], ['aaa']);
        $end = $s([$officeEv('aaa', '10:08:00', '10:08:05')], [$liveResp('main', '10:07:00'), $liveResp('aaa', '10:08:04')],
            [$h('10:07:50', 'SubagentStart', 'Explore', 'aaa'), $h('10:08:09', 'SubagentStop', 'Explore', 'aaa')], ['aaa']);
        $endless = $s([$officeEv('aaa', '10:08:00', '10:08:05')], [$liveResp('main', '10:07:00'), $liveResp('aaa', '10:08:04')], [], ['aaa']);
        $stale = $s([$officeEv('aaa', '10:08:00', null)], [$liveResp('main', '10:07:00'), $liveResp('aaa', '10:07:59')], [$h('10:07:00', 'SubagentStop', 'Explore', 'aaa')], ['aaa']);
        $foreign = $s([], [$liveResp('main', '10:07:00')], [$h('10:08:00', 'SubagentStart', 'Explore', 'zzz')]);

        return [$st($stopped), $stopped['agents'][0]['since'], $st($plain), $plain['agents'][0]['since'], $st($ghost), $ghost['agents'][0]['since'], $ghost['agents'][0]['tool'], $st($again),
            $st($early), $early['agents'][1]['since'], $early['agents'][1]['start'],
            $st($end), $end['agents'][1]['since'], $end['agents'][1]['last'], $end['agents'][1]['start'], $st($endless), $st($stale), $st($foreign)];
    })(), ['main:idle', '2026-01-01T10:05:03Z', 'main:idle', '2026-01-01T10:05:02Z', 'main:idle', '2026-01-01T10:06:30Z', null, 'main:working',
        'main:delegated aaa:working', '2026-01-01T10:08:00Z', '2026-01-01T10:08:00Z',
        'main:idle aaa:done', '2026-01-01T10:08:09Z', '2026-01-01T10:08:09Z', '2026-01-01T10:07:50Z', 'main:delegated aaa:working', 'main:delegated aaa:working', 'main:idle']],
    // przeglądarka: zgadywanie po `permissionGuess` zostaje jako fallback, dane z hooka mają pierwszeństwo
    ['lanes.js: guess kept as a fallback, hook permission and session end used', array_values(array_filter(['data.permissionGuess', 's.permissionHook', 'c.permission', "'waiting-permission'", "'ended'"],
        static fn (string $t): bool => !str_contains((string) file_get_contents(__DIR__ . '/../public/assets/lanes.js'), $t))), []],
    // kompaktowanie z hooka na osi pracy: para Pre → Post = jedno (chwila Pre, trigger z Pre), samo Post = w swojej chwili,
    // subagent i zdarzenia sprzed okna pominięte; bez zdarzeń — pusta lista (nic się nie rysuje). Fixture, nie prawdziwe zdarzenia
    ['compactions in the feed row: one per Pre/Post pair, main only, window', (static function () use ($liveResp, $liveNow, $officeLimits): array {
        $c = static fn (string $ts, string $phase, ?string $trigger, string $agent = 'main'): array => ['ts' => "2026-01-01T$ts" . 'Z', 'agent' => $agent, 'phase' => $phase, 'trigger' => $trigger];
        $row = static fn (array $compactions): ?array => Viz\Office\LiveState::session(new Viz\Model\Session('s', '', 't', '/w', ['main' => new Viz\Model\Agent('main', 'main', 'main session', null)], [], 0, [],
            [$liveResp('main', '10:09:00')], [], []), $liveNow, $officeLimits, false, [], $compactions);
        $events = [$c('09:30:00', 'pre', 'auto'), $c('09:30:20', 'post', 'auto'), $c('09:50:00', 'pre', 'manual'), $c('09:50:30', 'post', 'manual'),
            $c('10:00:00', 'pre', 'auto', 'abc123'), $c('10:05:00', 'post', 'auto'), $c('10:08:00', 'pre', null)];

        return [$row($events)['compactions'], $row([])['compactions']];
    })(), [[['ts' => '2026-01-01T09:50:00Z', 'trigger' => 'manual'], ['ts' => '2026-01-01T10:05:00Z', 'trigger' => 'auto'], ['ts' => '2026-01-01T10:08:00Z', 'trigger' => null]], []]],
    ['lanes.js draws compactions, legend and verdict without zeros', array_values(array_filter(['s.compactions', "'viz-lanes-legend'", '.filter(([, v]) => v > 0)'],
        static fn (string $t): bool => !str_contains((string) file_get_contents(__DIR__ . '/../public/assets/lanes.js'), $t))), []],
];
foreach ($liveCases as [$name, $got, $expected]) {
    if ($got !== $expected) {
        $failures++;
        echo "FAIL: live $name\n  oczekiwane: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n  otrzymane:  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n";
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
    ['office.js: data from the shared feed, refresh error shown', $missing('public/assets/office.js', ['VizFeed.Start(', 'fromFeed(feed.data)', "'refresh not working'", 'office.failed = feed.failed']), []],
    ['feed.js: refresh guards', $missing('public/assets/feed.js', ['root._busy = true', 'root._busy = false', 'new AbortController()', 'FETCH_TIMEOUT_MS = 5000', '.catch(() => { feed.misses++; feed.failed = feed.misses >= 2; })', "addEventListener('viz-scope'"]), []],
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

// program o nazwie liczbowej (np. „2026”): PHP zamienia taki klucz tablicy na int, ranking nie może się wywrócić
$numericRank = \Viz\Summary\CommandRanking::build([['session_id' => 's1', 'last' => '2026-10-01T00:00:00', 'commands' => ['2026' => ['count' => 1, 'failed' => 0, 'blocked' => 0, 'agents' => 1, 'last' => '2026-10-01T00:00:00', 'from' => [], 'subs' => [], 'contexts' => []]]]], 0, \Viz\Web\Scope::parse(''), 'count');
if (($numericRank[0]['program'] ?? null) !== '2026' || ($numericRank[0]['role'] ?? null) !== 'unknown') {
    $failures++;
    echo "FAIL: CommandRanking: numeric program name\n";
}

// czas narzędzi (endTs − ts), wyposażenie (MCP / skille / subagenci) i rozmiary `tool-results/` (tylko stat) w agregacie sesji;
// Agent i AskUserQuestion poza rankingiem najwolniejszych (praca subagenta, odpowiedź usera); Bash 2 min = praca (próg Bash 11 min)
$eqLine = static fn (string $type, string $ts, array $content): string => json_encode(['type' => $type, 'timestamp' => $ts, 'cwd' => $root, 'message' => ['role' => $type, 'content' => $content]]) . "\n";
$eqCall = static fn (string $ts, string $id, string $tool, array $input): string => $eqLine('assistant', $ts, [['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => $input]]);
$eqResult = static fn (string $ts, string $id): string => $eqLine('user', $ts, [['type' => 'tool_result', 'tool_use_id' => $id, 'content' => 'ok']]);
mkdir("$root/eq/s4/tool-results", 0700, true);
file_put_contents("$root/eq/s4.jsonl",
    $eqCall('2026-01-01T10:00:00Z', 'tu_fast', 'Bash', ['command' => 'ls'])
    . $eqResult('2026-01-01T10:00:01.500Z', 'tu_fast')
    . $eqCall('2026-01-01T10:00:02Z', 'tu_slow', 'Bash', ['command' => 'phpunit && ls'])
    . $eqResult('2026-01-01T10:02:02Z', 'tu_slow')
    . $eqCall('2026-01-01T10:02:03Z', 'tu_mcp', 'mcp__zammad__get_ticket', ['id' => 1])
    . $eqResult('2026-01-01T10:02:05Z', 'tu_mcp')
    . $eqCall('2026-01-01T10:02:06Z', 'tu_skill', 'Skill', ['skill' => 'sk-herodot'])
    . $eqResult('2026-01-01T10:02:07Z', 'tu_skill')
    . $eqCall('2026-01-01T10:02:08Z', 'tu_sub', 'Agent', ['description' => 'x', 'subagent_type' => 'Explore', 'prompt' => 'p'])
    . $eqResult('2026-01-01T10:12:08Z', 'tu_sub')
    . $eqCall('2026-01-01T10:12:09Z', 'tu_ask', 'AskUserQuestion', ['questions' => []])
    . $eqResult('2026-01-01T10:30:09Z', 'tu_ask'));
file_put_contents("$root/eq/s4/tool-results/tu_slow.txt", str_repeat('r', 5000));
file_put_contents("$root/eq/s4/tool-results/other.json", str_repeat('o', 100));
$eqAgg = Viz\Summary\SummaryStore::aggregate((new Viz\Source\TranscriptReader())->read("$root/eq/s4.jsonl"), 'test');
$eqRank = \Viz\Summary\CommandRanking::build([$eqAgg + ['session_id' => 's4']], 0, \Viz\Web\Scope::parse(''), 'time');
$gotEq = [
    implode(',', array_map(static fn (array $c): string => $c['ev'] . ':' . $c['ms'], $eqAgg['slow'])),
    $eqAgg['waits']['count'] . ':' . $eqAgg['waits']['ms'] . ':' . implode(',', array_column($eqAgg['waits']['top'], 'ev')),
    json_encode($eqAgg['tool_time']['Bash']),
    json_encode(array_map(static fn (array $k): array => array_map(static fn (array $r): int => $r['count'], $k), $eqAgg['equipment'])),
    $eqAgg['commands']['phpunit']['ms'] . '/' . $eqAgg['commands']['ls']['ms'] . '/' . $eqAgg['commands']['ls']['max_ms'],
    implode(',', array_column($eqRank, 'program')),
    $eqAgg['results']['files'] . ':' . $eqAgg['results']['bytes'] . ':' . implode(',', array_map(static fn (array $r): string => $r['id'] . '=' . $r['tool'], $eqAgg['results']['top'])),
    implode(' | ', array_map([Viz\Web\App::class, 'duration'], [850, 4200, 59990, 185000, 4380000])),
];
$expectedEq = [
    'tu_slow:120000,tu_mcp:2000,tu_fast:1500,tu_skill:1000',
    '0:0:',
    '{"count":2,"ms":121500,"max":120000}',
    '{"mcp":{"zammad":1},"skills":{"sk-herodot":1},"subagents":{"Explore":1}}',
    '120000/121500/120000',
    'ls,phpunit',
    '2:5100:tu_slow=Bash,other=',
    '850 ms | 4.2 s | 1 min 00 s | 3 min 05 s | 1 h 13 min',
];
if ($gotEq !== $expectedEq) {
    $failures++;
    echo "FAIL: tool time / equipment / tool-results\n  oczekiwane: " . implode("\n              ", $expectedEq) . "\n  otrzymane:  " . implode("\n              ", $gotEq) . "\n";
}

// grupa „może czekało na zgodę” (`waits`): Bash od maksymalnego timeoutu + 60 s zapasu (11 min), inne narzędzia od 90 s;
// zakładka Zgody (`SummaryStore::waited`) zostaje przy 90 s także dla Bash
file_put_contents("$root/eq/s5.jsonl",
    $eqCall('2026-01-01T10:00:00Z', 'tu_b95', 'Bash', ['command' => 'phpunit'])
    . $eqResult('2026-01-01T10:01:35Z', 'tu_b95')
    . $eqCall('2026-01-01T10:02:00Z', 'tu_b11m', 'Bash', ['command' => 'git push'])
    . $eqResult('2026-01-01T10:13:00Z', 'tu_b11m')
    . $eqCall('2026-01-01T10:14:00Z', 'tu_e95', 'Edit', ['file_path' => "$root/x.txt", 'old_string' => 'a', 'new_string' => 'b'])
    . $eqResult('2026-01-01T10:15:35Z', 'tu_e95'));
$waitSession = (new Viz\Source\TranscriptReader())->read("$root/eq/s5.jsonl");
$waitAgg = Viz\Summary\SummaryStore::aggregate($waitSession, 'test');
$gotWait = [
    implode(',', array_column($waitAgg['slow'], 'ev')),
    implode(',', array_column($waitAgg['waits']['top'], 'ev')),
    implode(',', array_keys($waitAgg['tool_time'])),
    implode(',', array_map(static fn (Viz\Model\Event $e): string => $e->toolUseId . '=' . (Viz\Summary\SummaryStore::waited($e) ? 1 : 0), $waitSession->events)),
];
$expectedWait = ['tu_b95', 'tu_b11m,tu_e95', 'Bash', 'tu_b95=1,tu_b11m=1,tu_e95=1'];
if ($gotWait !== $expectedWait) {
    $failures++;
    echo "FAIL: tool time — Bash threshold 11 min, other tools 90 s\n  oczekiwane: " . implode(' | ', $expectedWait) . "\n  otrzymane:  " . implode(' | ', $gotWait) . "\n";
}

// zakładka Zgody: reguły uprawnień, ustawienia (tylko `permissions`), wiersze i podpowiedzi, hook PermissionRequest (tests/approvals.test.php)
$approvalCases = require __DIR__ . '/approvals.test.php';
foreach ($approvalCases as [$name, $got, $expected]) {
    if ($got !== $expected) {
        $failures++;
        echo "FAIL: $name\n  " . json_encode($got, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n  oczekiwano " . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

// podgląd procesów: parser i widok na fixture (tests/processes.test.php)
$procCases = require __DIR__ . '/processes.test.php';
foreach ($procCases as [$name, $got, $expected]) {
    if ($got !== $expected) {
        $failures++;
        echo "FAIL: $name\n  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n  oczekiwano " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// słownik PL: każdy literał z t() / th() (PHP) i T() (JS) ma wpis w lang/pl.php, a {placeholdery} klucza i tłumaczenia są te same
$dict = require __DIR__ . '/../lang/pl.php';
$langKeys = [];
foreach (['src', 'templates', 'public/assets'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $ext = $file->getExtension();
        if (($ext !== 'php' && $ext !== 'js') || str_contains($file->getPathname(), '/vendor/')) {
            continue;
        }
        $pattern = $ext === 'php' ? "/(?<![\\w>\$])th?\\(\\s*'((?:[^'\\\\]|\\\\.)*)'(?=\\s*[,)])/" : "/(?<![\\w\$])T\\(\\s*'((?:[^'\\\\]|\\\\.)*)'(?=\\s*[,)])/";
        preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $m);
        foreach ($m[1] as $key) {
            $langKeys[stripslashes($key)] = basename($file->getPathname());
        }
    }
}
$langCases = [
    ['lang/pl.php: keys used in code without a translation', array_keys(array_diff_key($langKeys, $dict)), []],
    ['lang/pl.php: placeholders differ from the key', array_keys(array_filter($dict, static function (string $pl, string $en): bool {
        preg_match_all('/\{\w+\}/', $en, $a);
        preg_match_all('/\{\w+\}/', $pl, $b);
        sort($a[0]);
        sort($b[0]);

        return $a[0] !== $b[0];
    }, ARRAY_FILTER_USE_BOTH)), []],
];
foreach ($langCases as [$name, $got, $expected]) {
    if ($got !== $expected) {
        $failures++;
        echo "FAIL: $name\n  " . implode("\n  ", array_slice($got, 0, 30)) . (count($got) > 30 ? "\n  … (" . count($got) . ')' : '') . "\n";
    }
}

// G: limit wierszy list, wspólny korzeń ścieżek sesji (App::pathRoot), licznik zakładki Log (App::logCount)
mkdir("$root/g-root/repo/.git", 0700, true);
$gr = "$root/g-root";
$gPaths = ["$gr/repo/src/a.php", "$gr/repo/src/b.php", "$gr/repo/README.md", "$gr/other/x.txt"];
$gEv = static fn (string $tool): Viz\Model\Event => new Viz\Model\Event('2026-01-01T00:00:00Z', 's', 'main', $tool, 'id', '', null, false, []);
$gLog = [$gEv('Read'), $gEv('Bash'), $gEv(Viz\Model\Event::TOOL_CONTEXT)];
$gCases = [
    ['rowLimit: default', Viz\Web\App::rowLimit(['limit' => false]), 300],
    ['rowLimit: limit=all', Viz\Web\App::rowLimit(['limit' => true]), PHP_INT_MAX],
    ['pathRoot: repository root with half of the paths', Viz\Web\App::pathRoot($gPaths, null), "$gr/repo"],
    ['pathRoot: session directory wins when it has half of the paths', Viz\Web\App::pathRoot($gPaths, "$gr/repo/src"), "$gr/repo/src"],
    ['pathRoot: no repository — directory common to all paths', Viz\Web\App::pathRoot(["$gr/other/x.txt", "$gr/other/y/z.txt"], null), "$gr/other"],
    ['pathRoot: no paths = null, duplicates count once', [Viz\Web\App::pathRoot([], null), Viz\Web\App::pathRoot(array_merge(array_fill(0, 9, "$gr/other/x.txt"), ["$gr/repo/a", "$gr/repo/b"]), null)], [null, "$gr/repo"]],
    ['logCount: tool calls without files loaded by Claude Code', Viz\Web\App::logCount($gLog, ''), ['n' => 2, 'context' => 1]],
    ['logCount: origin=context counts the loaded files', Viz\Web\App::logCount([$gLog[2]], 'context'), ['n' => 1, 'context' => 1]],
];
foreach ($gCases as [$name, $got, $expected]) {
    if ($got !== $expected) {
        $failures++;
        echo "FAIL: $name\n  oczekiwane: " . json_encode($expected) . "\n  otrzymane:  " . json_encode($got) . "\n";
    }
}

exec('rm -rf ' . escapeshellarg($root));
$total = count($gCases) + count($langCases) + count($approvalCases) + count($procCases) + 1 + count($cases) + count($toolCases) + count($commandCases) + count($roles) + count($denialCases) + count($boundaryCases) + count($officeCases) + count($liveCases) + count($csvCases) + count($guardCases) + $nodeRan + 4;
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
