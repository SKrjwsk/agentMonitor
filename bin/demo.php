<?php

declare(strict_types=1);

/*
 * Starts agentMonitor on synthetic Claude Code sessions, so you can look around without any sessions of your own.
 * Usage: php bin/demo.php [--port=8095] [--dir=<work dir>] [--no-serve]
 * Everything lives in a work directory (default: a new private directory under the system temp dir, removed when the server stops;
 * with --dir it is recreated on every run and must be a directory this demo created before): a fake HOME with transcripts and
 * a copy of the app (the session judge is switched off there: it would start the real Claude Code), so neither `~/.claude` nor this repository's `var/` is read or written. The paths in the sessions are made up.
 */

require __DIR__ . '/../src/autoload.php';

$options = getopt('', ['port::', 'dir::', 'no-serve']);
$port = (int) ($options['port'] ?? 8095);
$repo = dirname(__DIR__);
const MARKER = '.agent-monitor-demo';

$fail = static function (string $message): never {
    fwrite(STDERR, "bin/demo.php: $message\n");
    exit(2);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        array_map(static fn (string $name) => $remove("$path/$name"), array_diff(scandir($path) ?: [], ['.', '..']));
        rmdir($path);
    }
};
$copy = static function (string $from, string $to) use (&$copy): void {
    mkdir($to, 0700, true);
    foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $name) {
        is_dir("$from/$name") ? $copy("$from/$name", "$to/$name") : copy("$from/$name", "$to/$name");
    }
};

// The work directory is wiped on every run, so only a directory this script created (it holds a marker file) may be reused.
// Without --dir a new private directory with an unpredictable name is made, so nothing in a shared /tmp can be reused or redirected.
// getopt() drops an empty `--dir=`, so the arguments are checked as well
$dirGiven = array_filter($argv, static fn (string $argument): bool => str_starts_with($argument, '--dir')) !== [];
$explicitDir = $dirGiven ? rtrim(is_string($options['dir'] ?? null) ? $options['dir'] : '', '/') : null;
if ($explicitDir === '') {
    $fail('--dir must not be empty');
}
$root = $explicitDir ?? sys_get_temp_dir() . '/agent-monitor-demo-' . bin2hex(random_bytes(6));
if (is_link($root)) {
    $fail("$root is a symbolic link; refusing to use it");
}
if (file_exists($root)) {
    $isOwnDemo = is_dir($root) && is_file($root . '/' . MARKER) && !is_link($root . '/' . MARKER) && fileowner($root) === getmyuid();
    $isOwnDemo || $fail("$root exists and was not created by this demo (no " . MARKER . " file, or another owner); refusing to delete it");
    $remove($root);
}
mkdir($root, 0700, true);
file_put_contents($root . '/' . MARKER, "Created by bin/demo.php; the whole directory is deleted on the next run with the same --dir.\n");
$home = "$root/home";
$app = "$root/app";

mkdir($home . '/.claude/projects', 0700, true);
foreach (['src', 'templates', 'public', 'lang', 'prompts'] as $dir) {
    $copy("$repo/$dir", "$app/$dir");
}

$now = time();
$at = static fn (int $ago): string => gmdate('Y-m-d\TH:i:s\Z', $now - $ago);
$line = static fn (string $cwd, string $type, int $ago, array $content, array $extra = []): string => json_encode(
    ['type' => $type, 'timestamp' => $at($ago), 'cwd' => $cwd, 'message' => ['role' => $type, 'content' => $content]] + $extra,
) . "\n";
$say = static fn (string $cwd, string $model, int $ago, string $id, array $content, int $cacheRead = 60000): string => json_encode(
    ['type' => 'assistant', 'timestamp' => $at($ago), 'cwd' => $cwd, 'message' => ['id' => $id, 'model' => $model, 'role' => 'assistant', 'stop_reason' => 'end_turn',
        'content' => $content, 'usage' => ['input_tokens' => 4, 'output_tokens' => 350, 'cache_read_input_tokens' => $cacheRead, 'cache_creation_input_tokens' => 1800]]],
) . "\n";
$use = static fn (string $id, string $tool, array $input): array => ['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => $input];
$result = static fn (string $id, string $text, bool $error = false): array => ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $text] + ($error ? ['is_error' => true] : []);
$text = static fn (string $t): array => ['type' => 'text', 'text' => $t];
$hookBlock = ['toolDenialKind' => 'permission-rule', 'permissionDecision' => ['decision' => 'reject', 'source' => 'hook', 'reasonType' => 'hook']];
$opus = 'claude-opus-5-5';
$sonnet = 'claude-sonnet-5-5';

$session = static function (string $project, string $sid, string $body) use ($home): void {
    $dir = "$home/.claude/projects/$project";
    is_dir($dir) || mkdir($dir, 0700, true);
    file_put_contents("$dir/$sid.jsonl", $body);
};

// session 1: a bug fix with a failing test, a subagent, a read outside the work folder, a hook block and an approval wait
$shop = '/home/demo/work/shop-api';
$s1 = 'a1111111-1111-4111-8111-111111111111';
$t = 11000;
file_put_contents("$home/.claude/settings.json", json_encode(['permissions' => ['ask' => ['Bash(git commit:*)'], 'deny' => ['Read(/etc/**)'], 'defaultMode' => 'default']]));
$session('-home-demo-work-shop-api', $s1,
    $line($shop, 'user', $t, [$text('Fix the rounding bug in invoice totals: 19.99 x 3 must give 59.97')], ['permissionMode' => 'default'])
    . $say($shop, $opus, $t - 20, 'a1', [$use('s1_read', 'Read', ['file_path' => "$shop/src/Invoice/Totals.php"])], 20000)
    . $line($shop, 'user', $t - 19, [$result('s1_read', '<?php // ...')])
    . $say($shop, $opus, $t - 60, 'a2', [$use('s1_grep', 'Grep', ['pattern' => 'round\(', 'path' => "$shop/src"])], 24000)
    . $line($shop, 'user', $t - 58, [$result('s1_grep', 'src/Invoice/Totals.php:42')])
    . $say($shop, $opus, $t - 110, 'a3', [$use('s1_test', 'Bash', ['command' => 'vendor/bin/phpunit --filter TotalsTest', 'description' => 'run the failing test'])], 30000)
    . $line($shop, 'user', $t - 120, [$result('s1_test', "Exit code 1\nFailed asserting that 59.96999999999999 matches expected 59.97.", true)])
    . $say($shop, $opus, $t - 160, 'a4', [$use('s1_agent', 'Agent', ['description' => 'find other callers', 'subagent_type' => 'Explore', 'prompt' => 'Find every caller of Totals::sum'])], 36000)
    . $line($shop, 'user', $t - 200, [$result('s1_agent', 'Two callers: InvoicePdf and ExportJob')])
    . $say($shop, $opus, $t - 210, 'a5', [$use('s1_csv', 'Bash', ['command' => 'head -3 /home/demo/Downloads/prices.csv'])], 40000)
    . $line($shop, 'user', $t - 211, [$result('s1_csv', 'sku,price')])
    . $say($shop, $opus, $t - 220, 'a6', [$use('s1_hosts', 'Read', ['file_path' => '/etc/hosts'])], 41000)
    . $line($shop, 'user', $t - 221, [$result('s1_hosts', 'Error: PreToolUse:Read hook error: [bash "$HOME/.claude/hooks/guard.sh"]: BLOCKED by guard hook: denied by rule matching \'/etc/\'', true)], $hookBlock)
    . $say($shop, $opus, $t - 270, 'a7', [$use('s1_edit', 'Edit', ['file_path' => "$shop/src/Invoice/Totals.php", 'old_string' => 'round($sum, 2)', 'new_string' => 'round($sum, 2, PHP_ROUND_HALF_UP)'])], 48000)
    . $line($shop, 'user', $t - 271, [$result('s1_edit', 'ok')])
    . $say($shop, $opus, $t - 300, 'a8', [$use('s1_test2', 'Bash', ['command' => 'vendor/bin/phpunit --filter TotalsTest'])], 52000)
    . $line($shop, 'user', $t - 310, [$result('s1_test2', 'OK (4 tests)')])
    . $say($shop, $opus, $t - 360, 'a9', [$use('s1_commit', 'Bash', ['command' => 'git commit -am "Fix rounding of invoice totals"'])], 55000)
    . $line($shop, 'user', $t - 540, [$result('s1_commit', '1 file changed')])
    . $say($shop, $opus, $t - 550, 'a10', [$text('Fixed: Totals::sum now rounds half up; the test passes.')], 58000));
mkdir("$home/.claude/projects/-home-demo-work-shop-api/$s1/subagents", 0700, true);
file_put_contents("$home/.claude/projects/-home-demo-work-shop-api/$s1/subagents/agent-abc123.meta.json", json_encode(['agentType' => 'Explore', 'description' => 'find other callers', 'toolUseId' => 's1_agent']));
file_put_contents("$home/.claude/projects/-home-demo-work-shop-api/$s1/subagents/agent-abc123.jsonl",
    $say($shop, $sonnet, $t - 165, 'b1', [$use('s1a_grep', 'Grep', ['pattern' => 'Totals::sum', 'path' => "$shop/src"])], 9000)
    . $line($shop, 'user', $t - 170, [$result('s1a_grep', 'src/Pdf/InvoicePdf.php, src/Job/ExportJob.php')])
    . $say($shop, $sonnet, $t - 195, 'b2', [$text('Two callers: InvoicePdf and ExportJob')], 11000));

// session 2: docs work, repeated reads and a command denied by a rule
$docs = '/home/demo/work/docs-site';
$s2 = 'a2222222-2222-4222-8222-222222222222';
$t = 7200;
$session('-home-demo-work-docs-site', $s2,
    $line($docs, 'user', $t, [$text('Update the install guide for the new CLI flags')], ['permissionMode' => 'acceptEdits'])
    . $say($docs, $sonnet, $t - 30, 'c1', [$use('s2_r1', 'Read', ['file_path' => "$docs/content/install.md"])], 15000)
    . $line($docs, 'user', $t - 29, [$result('s2_r1', '# Install')])
    . $say($docs, $sonnet, $t - 90, 'c2', [$use('s2_r2', 'Read', ['file_path' => "$docs/content/install.md"])], 18000)
    . $line($docs, 'user', $t - 89, [$result('s2_r2', '# Install')])
    . $say($docs, $sonnet, $t - 150, 'c3', [$use('s2_r3', 'Read', ['file_path' => "$docs/content/cli.md"])], 21000)
    . $line($docs, 'user', $t - 149, [$result('s2_r3', '# CLI')])
    . $say($docs, $sonnet, $t - 200, 'c4', [$use('s2_rm', 'Bash', ['command' => 'rm -rf build/'])], 23000)
    . $line($docs, 'user', $t - 199, [$result('s2_rm', 'Error: Permission to use Bash has been denied. Rule: Bash(rm -rf:*)', true)], ['toolDenialKind' => 'permission-rule', 'permissionDecision' => ['decision' => 'reject', 'source' => 'config', 'reasonType' => 'rule']])
    . $say($docs, $sonnet, $t - 260, 'c5', [$use('s2_e1', 'Edit', ['file_path' => "$docs/content/install.md", 'old_string' => '# Install', 'new_string' => '# Install (v2)'])], 25000)
    . $line($docs, 'user', $t - 259, [$result('s2_e1', 'ok')])
    . $say($docs, $sonnet, $t - 300, 'c6', [$text('The install guide now lists the new flags.')], 26000));

// session 3: an older session, for the cost-by-day chart
$s3 = 'a3333333-3333-4333-8333-333333333333';
$t = 180000;
$session('-home-demo-work-shop-api', $s3,
    $line($shop, 'user', $t, [$text('Add a /health endpoint')])
    . $say($shop, $opus, $t - 40, 'd1', [$use('s3_w', 'Write', ['file_path' => "$shop/src/Http/Health.php", 'content' => '<?php'])], 80000)
    . $line($shop, 'user', $t - 39, [$result('s3_w', 'ok')])
    . $say($shop, $opus, $t - 120, 'd2', [$use('s3_t', 'Bash', ['command' => 'vendor/bin/phpunit'])], 90000)
    . $line($shop, 'user', $t - 119, [$result('s3_t', 'OK (31 tests)')])
    . $say($shop, $opus, $t - 150, 'd3', [$text('Added /health.')], 95000));

$clock = static fn (int $ago): string => Viz\Clock::local($at($ago), 'H:i');

// session judgments (what the Assessment tab shows after a run; written by hand here, no model is called)
$judge = static function (string $sid, string $title, array $judgment) use ($app, $now): void {
    $dir = "$app/var/judge/$sid";
    mkdir($dir, 0700, true);
    $stamp = Viz\Judge\SessionJudge::stamp();
    $record = ['ok' => true, 'judgment' => $judgment, 'error' => null, 'costUsd' => 0.21, 'turns' => 9, 'durationMs' => 74000, 'judgeSession' => null,
        'model' => 'sonnet', 'dump' => 'var/session_dump/demo', 'started' => $now - 600, 'finished' => $now - 520, 'title' => $title, 'transcript' => null];
    file_put_contents("$dir/$stamp.json", json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents("$dir/$stamp.md", Viz\Judge\SessionJudge::markdown($sid, $record + ['stamp' => $stamp]));
};
$finding = static fn (string $severity, string $title, string $evidence, string $fix, string $owner, string $path, string $why): array => [
    'severity' => $severity, 'title' => $title, 'evidence' => $evidence, 'fix' => $fix, 'owner' => $owner, 'owner_path' => $path, 'why_missed' => $why, 'confidence' => 'high'];
$judge($s1, 'Fix the rounding bug in invoice totals', [
    'task' => 'Make Totals::sum return 59.97 for 19.99 x 3.', 'outcome' => 'Rounding fixed with PHP_ROUND_HALF_UP, TotalsTest passes, committed.', 'termination' => 'completed',
    'timeline' => [
        ['time' => $clock(11000 - 60), 'kind' => 'good', 'what' => 'Went straight to the one round() call in src/', 'evidence' => 'events.jsonl Grep round('],
        ['time' => $clock(11000 - 220), 'kind' => 'blocked', 'what' => 'Read /etc/hosts was stopped by a hook', 'evidence' => 'events.jsonl Read, denied by rule /etc/'],
        ['time' => $clock(11000 - 360), 'kind' => 'waiting', 'what' => 'git commit waited about 3 minutes for approval', 'evidence' => 'metrics.json calls.longest_until_result'],
    ],
    'findings' => [
        $finding('medium', 'git commit asks for approval every time', 'approvals.top: git commit x1, rule: ask-rule', 'Move Bash(git commit:*) from ask to allow for this project', 'hooks-permissions', '.claude/settings.json', 'The ask rule outranks any allow'),
        $finding('low', 'Agent read outside the work folder', 'boundary: outside, head /home/demo/Downloads/prices.csv', 'Say in CLAUDE.md that data files live in ./fixtures', 'claude-md', 'CLAUDE.md', 'No instruction names where test data lives'),
    ],
    'strengths' => [['text' => 'Found the single rounding call with one Grep', 'evidence' => 'events.jsonl Grep round(']],
    'prompt_tips' => [['instead' => 'Fix the rounding bug', 'better' => 'Fix the rounding bug in Totals::sum; 19.99 x 3 must give 59.97; run only TotalsTest', 'why' => 'Names the file, the expected value and the test, so no exploration is needed']],
    'tools' => [['kind' => 'permission', 'idea' => 'allow git commit in this project', 'why' => 'removes the 3 minute wait']],
    'missing_evidence' => ['Whether the commit message followed the project convention was not checked'],
    'reasoning' => 'The task was met with a small, correct change; the losses are one approval wait and one read outside the folder.', 'verdict' => 'good', 'tldr' => ['Task done, test green', 'One approval wait: allow git commit', 'One read outside the work folder']]);
$judge($s2, 'Update the install guide for the new CLI flags', [
    'task' => 'Document the new CLI flags in the install guide.', 'outcome' => 'install.md updated; one cleanup command was denied.', 'termination' => 'completed',
    'timeline' => [
        ['time' => $clock(7200 - 90), 'kind' => 'loop', 'what' => 'install.md was read twice before the first edit', 'evidence' => 'metrics.json files.reread'],
        ['time' => $clock(7200 - 200), 'kind' => 'blocked', 'what' => 'rm -rf build/ denied by a deny rule', 'evidence' => 'events.jsonl Bash rm -rf build/'],
    ],
    'findings' => [
        $finding('medium', 'Same file read twice', 'metrics.json files.reread: install.md x2', 'Tell the agent in CLAUDE.md to keep a file it has read in context', 'claude-md', 'CLAUDE.md', 'No rule against re-reading'),
        $finding('low', 'Agent tried a command the deny rule forbids', 'events.jsonl Bash rm -rf build/', 'List the build output folder in CLAUDE.md as generated, not to be deleted', 'claude-md', 'CLAUDE.md', 'The rule only stops the call; the agent does not know why'),
    ],
    'strengths' => [['text' => 'Kept the edit small and scoped to install.md', 'evidence' => 'metrics.json files edited: 1']],
    'prompt_tips' => [], 'tools' => [], 'missing_evidence' => [],
    'reasoning' => 'Done with minor waste: a repeated read and a denied command.', 'verdict' => 'mixed', 'tldr' => ['Guide updated', 'Repeated read, one denied command']]);

echo "Synthetic sessions written to $root\n";
if (isset($options['no-serve'])) {
    echo "App copy: $app (HOME for the server: $home)\n";
    exit(0);
}

echo "agentMonitor demo: http://127.0.0.1:$port/ (Ctrl+C to stop)\n";
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$app/public", "$app/public/index.php"],
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
    $app,
    ['HOME' => $home, 'PATH' => (string) getenv('PATH'), 'TMPDIR' => sys_get_temp_dir(), 'AGENT_ALLOWED_RW' => '/home/demo/work,~/.claude/projects', 'AGENT_ALLOWED_R' => '', 'AGENT_MONITOR_DEMO' => '1'],
);
$code = is_resource($server) ? proc_close($server) : 1;
if ($explicitDir === null) {
    $remove($root);
}
exit($code);
