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
$sigRule = "block-secrets.sh: 'secret-dir'";
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
$sig = Viz\Signals::of(new Viz\Model\Session('s4', '/x', 't', '/p', [Viz\Model\Agent::MAIN => new Viz\Model\Agent('main', 'main', 'main', null)], $sigEvents, 6, incidents: $sigIncidents));
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
$gotCtx = implode(' ', array_map(static fn (array $it): string => $it['kind'] . '=' . round($it['value'], 2), Viz\Signals::of($ctxSession)['items']));
$expectedCtx = 'cold-cache=2 quick-question=0.6 stale-session=3.15 cold-cache=2.5 cold-cache=2.6';
if ($gotCtx !== $expectedCtx) {
    $failures++;
    echo "FAIL: signals (kontekst)\n  oczekiwane: $expectedCtx\n  otrzymane:  $gotCtx\n";
}

// blokady: z decyzji zapisanej w linii tool_result, a w starszych transkryptach z treści błędu
$denialCases = [
    [['toolDenialKind' => 'permission-rule', 'permissionDecision' => ['decision' => 'reject', 'source' => 'hook', 'reasonType' => 'hook']],
        'Error: PreToolUse:Bash hook error: [bash "$HOME/.claude/hooks/block-secrets.sh"]: BLOCKED by block-secrets hook: denied by rule matching \'secret-dir\'',
        "hook|block-secrets.sh: 'secret-dir'"],
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

exec('rm -rf ' . escapeshellarg($root));
$total = count($cases) + count($toolCases) + count($commandCases) + count($roles) + count($denialCases) + 2;
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
