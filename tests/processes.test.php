<?php

declare(strict_types=1);

/*
 * Tests of the process view (Processes, ProcessView) on FIXTURES: samples of `ps` and `lsof` output built from the documented
 * macOS format, not captured from a real system. Loaded by tests/run.php; returns a list of [name, actual, expected].
 */

use Viz\Boundary;
use Viz\Office\ProcessView;
use Viz\Source\Processes;
use Viz\Web\Scope;

$psFixture = <<<'PS'
  PID  PPID     ELAPSED  %CPU    RSS STAT COMM
    1     0  5-03:12:44   0.0  12000 Ss   /sbin/launchd
  500     1       10:00   0.1   9000 Ss   /usr/sbin/cfprefsd
 1001   400     02:15:07  12.5 204800 S+   /opt/homebrew/Caskroom/claude-code@latest/2.1.312/claude
 1002  1001       02:14   0.3  51200 S    /usr/local/bin/node
 1003  1002       00:05  99.9  10240 R    /bin/sleep
 2001     1    1-00:00:01   2,5  20480 S    /Applications/Google Chrome.app/Contents/MacOS/Google Chrome
 2002     1       07:00   0.0   4096 S    /usr/local/bin/php
 3001   500       00:09   0.0   1024 S    /usr/bin/unrelated
this line is not a process row
 9999     1       xx:yy   0.0   1024 S    /bin/badetime
PS;
$cwdFixture = "p1001\nfcwd\nn/Users/test/workspace/proj-a\np1002\nfcwd\nn/Users/test/workspace/proj-a/sub\np2001\nfcwd\nn/Users/test/Elsewhere\np2002\nfcwd\nn/Users/test/workspace/proj-b\n";
$listenFixture = <<<'LSOF'
COMMAND     PID USER   FD   TYPE             DEVICE SIZE/OFF NODE NAME
node       1002 test   22u  IPv4 0x1234567890abcdef      0t0  TCP 127.0.0.1:3000 (LISTEN)
node       1002 test   23u  IPv6 0x1234567890abcde0      0t0  TCP [::1]:3000 (LISTEN)
node       1002 test   24u  IPv6 0x1234567890abcde1      0t0  TCP *:8080 (LISTEN)
php        2002 test    4u  IPv4 0x1234567890abcde2      0t0  TCP 127.0.0.1:8181 (LISTEN)
Google\x20Chrome 2001 test 30u IPv4 0x1234567890abcde3 0t0 TCP 127.0.0.1:9222 (LISTEN)
node       1002 test   25u  IPv4 0x1234567890abcde4      0t0  TCP 127.0.0.1:3000->127.0.0.1:50000 (ESTABLISHED)
LSOF;

$fakeRun = static function (array &$calls) use ($psFixture, $cwdFixture, $listenFixture): callable {
    return static function (array $argv) use (&$calls, $psFixture, $cwdFixture, $listenFixture): ?string {
        $calls[] = $argv;

        return match ($argv[0] . ($argv[1] ?? '')) {
            'ps-xo' => $psFixture,
            'lsof-a' => $cwdFixture,
            default => $listenFixture,
        };
    };
};

$parsed = Processes::parsePs($psFixture);
$calls = [];
$snap = Processes::snapshot(1000, $fakeRun($calls));
$failCalls = [];
$snapFail = Processes::snapshot(1000, static function (array $argv) use (&$failCalls): ?string {
    $failCalls[] = $argv;

    return null;
});
$snapEmpty = Processes::snapshot(1000, static fn (array $argv): string => '');
$byPid = array_column($snap['procs'], null, 'pid');

$limits = new Boundary(['/Users/test/workspace'], []);
$view = ProcessView::build($snap, Scope::parse(''), $limits);
$groupKey = array_key_first($view['groups']);
$unavailable = ProcessView::build($snapFail, Scope::parse(''), $limits);
$noZones = ProcessView::build($snap, Scope::parse(''), new Boundary([], []));
$allowedKeys = ['pid', 'ppid', 'name', 'uptime', 'cpu', 'rss', 'stat', 'ports', 'claude'];

$t0 = microtime(true);
$slow = Processes::exec([PHP_BINARY, '-r', 'sleep(5);'], 300);
$elapsed = microtime(true) - $t0;

return [
    ['procs: parsePs — count of valid rows (header, junk and bad time skipped)', count($parsed), 8],
    ['procs: parsePs — pola procesu claude', $parsed[2], ['pid' => 1001, 'ppid' => 400, 'uptime' => 2 * 3600 + 15 * 60 + 7, 'cpu' => 12.5, 'rss' => 204800 * 1024, 'stat' => 'S+', 'name' => 'claude', 'claude' => true]],
    ['procs: parsePs — program name without path, with a space', $parsed[5]['name'] ?? null, 'Google Chrome'],
    ['procs: parsePs — CPU z przecinkiem', $parsed[5]['cpu'] ?? null, 2.5],
    ['procs: parsePs — empty output', Processes::parsePs(''), []],
    ['procs: parsePs — header only', Processes::parsePs("  PID  PPID ELAPSED %CPU RSS STAT COMM\n"), []],
    ['procs: etime mm:ss', Processes::etime('02:15'), 135],
    ['procs: etime hh:mm:ss', Processes::etime('02:15:07'), 8107],
    ['procs: etime dd-hh:mm:ss', Processes::etime('5-03:12:44'), 5 * 86400 + 3 * 3600 + 12 * 60 + 44],
    ['procs: etime niepoprawny', Processes::etime('xx:yy'), null],
    ['procs: parseCwd', Processes::parseCwd($cwdFixture), [1001 => '/Users/test/workspace/proj-a', 1002 => '/Users/test/workspace/proj-a/sub', 2001 => '/Users/test/Elsewhere', 2002 => '/Users/test/workspace/proj-b']],
    ['procs: parseCwd — pid without an n line skipped, junk and empty output', [Processes::parseCwd("p5\nfcwd\np6\nfcwd\nn/x\nzzz"), Processes::parseCwd('')], [[6 => '/x'], []]],
    ['procs: parseListen — LISTEN ports (IPv4, IPv6, *, no ESTABLISHED, name with \\x20)', Processes::parseListen($listenFixture), [1002 => [3000, 8080], 2002 => [8181], 2001 => [9222]]],
    ['procs: parseListen — empty output', Processes::parseListen(''), []],
    ['procs: snapshot — available, relevant processes (claude and descendants first, then orphaned PPID=1; no foreign ones)', [$snap['available'], array_keys($byPid)], [true, [1001, 1002, 1003, 500, 2001, 2002]]],
    ['procs: snapshot — descendant root (parent 400 outside the list does no harm)', [$byPid[1001]['root'], $byPid[1003]['root'], $byPid[2001]['root'], $byPid[2001]['orphan']], [1001, 1001, null, true]],
    ['procs: snapshot — cwd and ports assigned', [$byPid[1002]['cwd'] ?? null, $byPid[1002]['ports'] ?? null, $byPid[1003]['cwd']], ['/Users/test/workspace/proj-a/sub', [3000, 8080], null]],
    ['procs: snapshot — command failed: availability false, empty list', [$snapFail['available'], $snapFail['procs'], count($failCalls)], [false, [], 1]],
    ['procs: snapshot — empty ps output: unavailable', [$snapEmpty['available'], $snapEmpty['procs']], [false, []]],
    ['procs: privacy — no call fetches the arguments column', array_values(array_filter(array_merge($calls, [Processes::psArgv(), Processes::listenArgv(), Processes::cwdArgv([1])]), static function (array $argv): bool {
        foreach ($argv as $arg) {
            if (preg_match('/(^|[,\s=-])(args|arg|command|cmd|cmdline|ucomm)(,|=|$)/i', $arg) || str_contains($arg, 'eww')) {
                return true;
            }
        }

        return false;
    })), []],
    ['procs: privacy — ps columns are exactly the allowed ones', explode(',', Processes::PS_COLUMNS), ['pid', 'ppid', 'etime', '%cpu', 'rss', 'stat', 'comm']],
    ['procs: privacy — view row has only allowed fields', array_map(static fn (array $p): array => array_keys($p), array_slice($view['groups'][$groupKey]['procs'], 0, 1)), [$allowedKeys]],
    ['procs: ProcessView — grupa po projekcie, liczniki (claude 1, dzieci 2, CPU, RAM)', [count($view['groups']), $view['groups'][$groupKey]['claude'], $view['groups'][$groupKey]['children'], $view['groups'][$groupKey]['cpu'], $view['groups'][$groupKey]['rss']],
        [1, 1, 2, round(12.5 + 0.3 + 99.9, 1), (204800 + 51200 + 10240) * 1024]],
    ['procs: ProcessView — group key = session project key', $groupKey, \Viz\Web\App::fileHash('/Users/test/workspace/proj-a')],
    ['procs: ProcessView — orphans only with a directory in the boundary zone (Chrome outside)', array_map(static fn (array $o): array => [$o['pid'], $o['project']], $view['orphans']), [[2002, 'proj-b']]],
    ['procs: ProcessView — no boundary: zone = home directory, orphans outside it skipped', $noZones['orphans'], []],
    ['procs: ProcessView — project scope filters out', count(ProcessView::build($snap, Scope::parse('aaaaaaaaaaaa'), $limits)['groups']), 0],
    ['procs: ProcessView — unavailable: flag and empty data', [$unavailable['available'], $unavailable['groups'], $unavailable['orphans']], [false, [], []]],
    ['procs: exec — missing program → null (graceful degradation)', Processes::exec(['definitely-not-a-program-xyz'], 1000), null],
    ['procs: exec — program output', Processes::exec([PHP_BINARY, '-r', 'echo "ok";'], 3000), 'ok'],
    ['procs: exec — limit czasu przerywa i zwraca null', [$slow, $elapsed < 3.0], [null, true]],
];
