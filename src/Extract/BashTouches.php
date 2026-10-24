<?php

declare(strict_types=1);

namespace Viz\Extract;

use Viz\Model\Command;
use Viz\Model\Extraction;
use Viz\Model\Touch;
use Viz\Path;

/**
 * Heurystyczne wyciąganie ścieżek z komendy Bash.
 * Wszystko, czego nie da się ustalić statycznie, trafia do `unresolved` — nigdy nie jest pomijane po cichu.
 */
final class BashTouches
{
    /** Komendy, które nie czytają plików projektu. */
    private const NOFILE = [
        'echo', 'printf', 'pwd', 'date', 'export', 'unset', 'set', 'true', 'false', 'sleep', 'which', 'type',
        'hash', 'alias', 'read', 'local', 'declare', 'return', 'exit', 'test', '[', '[[', 'kill', 'pkill', 'ps',
        'lsof', 'whoami', 'id', 'uname', 'hostname', 'wait', 'jobs', 'basename', 'dirname', 'realpath', 'readlink',
        'seq', 'expr', 'bc', 'mktemp', 'clear', 'history', 'open', 'curl', 'wget', 'ping', 'tr', 'ln', 'chmod',
        'chown', 'shift', 'break', 'continue', 'trap', 'source-highlight', 'say', 'osascript', 'pbcopy', 'pbpaste',
        'git-status', 'printenv', 'getopts', 'umask', 'ulimit', 'disown', 'fg', 'bg', 'times', 'logout',
    ];

    /** Programy, które same czytają pliki — ich odczytów parser nie zobaczy. */
    private const INDIRECT = [
        'composer', 'npm', 'npx', 'yarn', 'pnpm', 'make', 'phpunit', 'pest', 'phpstan', 'php-cs-fixer', 'psalm',
        'rector', 'deptrac', 'docker', 'docker-compose', 'symfony', 'brew', 'pip', 'pip3', 'mysql', 'psql',
        'sqlite3', 'console', 'claude', 'ssh', 'scp', 'rsync', 'dep', 'vendor/bin', 'gh',
    ];

    /** Metadane bez treści — agent widzi liczbę, sumę, typ. */
    private const METADATA = ['wc', 'stat', 'file', 'md5', 'md5sum', 'shasum', 'sha1sum', 'sha256sum', 'cmp', 'du', 'sips'];

    /** Czytają treść pliku w całości. */
    private const READERS = [
        'cat' => '', 'tac' => '', 'nl' => 'bvwsin', 'less' => '', 'more' => '', 'bat' => 'lrp', 'strings' => 'n',
        'xxd' => 'lcsg', 'hexdump' => 'ns', 'od' => 'ANjt', 'diff' => 'UCIFLSx', 'comm' => '', 'column' => 'stc',
        'iconv' => 'ft', 'base64' => 'io', 'sort' => 'ktoTS', 'uniq' => 'fs', 'cut' => 'dfcb', 'paste' => 'd',
        'fold' => 'w', 'fmt' => 'w', 'jq' => '', 'yq' => '', 'php-l' => '',
    ];

    private const KEYWORDS = ['if', 'then', 'else', 'elif', 'do', 'while', 'until', '!', '{', '}', 'time', 'done', 'fi', 'esac'];

    private BashLexer $lexer;
    /** @var array<string, ?string> zmienne przypisane w tej komendzie (`OM=/ścieżka; ls $OM`) */
    private array $vars = [];
    /** @var array<string, list<array<string, mixed>>> zmienne pętli `for x in a b c` */
    private array $loops = [];
    /** Głębokość rekurencji `bash -c` / `eval` / `ssh "…"`. */
    private int $depth = 0;

    /** Narzędzia, których pierwszy argument pozycyjny to podkomenda (`git push`, `composer install`). */
    private const SUBCOMMANDS = ['git', 'gh', 'composer', 'npm', 'npx', 'yarn', 'pnpm', 'docker', 'docker-compose', 'brew', 'symfony', 'pip', 'pip3', 'cargo', 'go', 'dep', 'kubectl', 'make'];

    private const WRAPPERS = ['sudo', 'nohup', 'command', 'builtin', 'exec', 'nice', 'env', 'timeout'];

    public function __construct()
    {
        $this->lexer = new BashLexer();
    }

    public function extract(string $command, ?string $cwd): Extraction
    {
        $out = new Extraction($cwd);
        $this->vars = [];
        $this->loops = [];
        foreach ($this->commands($this->lexer->tokens($command)) as $cmd) {
            $this->simple($cmd, $out);
        }

        return $out;
    }

    /**
     * Podział na proste komendy. `$(…)` zawiesza bieżącą komendę i po `)` ją kontynuuje
     * (`cat $(ls) plik` to jedno `cat`), zwykła grupa `( … )` tylko rozdziela.
     *
     * @return list<array{words: list<array<string, mixed>>, redirs: list<array{0: string, 1: ?array<string, mixed>}>, afterPipe: bool, stdin: bool, substitution: bool, subst: bool, depth: int}>
     */
    private function commands(array $tokens): array
    {
        $cmds = [];
        $stack = [];
        $cur = $this->emptyCommand(false, 0);
        $count = count($tokens);
        for ($k = 0; $k < $count; $k++) {
            $t = $tokens[$k];
            if ($t['t'] === 'op') {
                if ($t['v'] === '$(') {
                    $cur['substitution'] = $cur['substitution'] || $cur['words'] !== [];
                    $stack[] = ['cur' => $cur, 'kind' => 'subst'];
                    $cur = $this->emptyCommand(false, count($stack));
                    continue;
                }
                if ($cur['words'] !== [] || $cur['redirs'] !== []) {
                    $cmds[] = $cur;
                }
                if ($t['v'] === '(') {
                    $stack[] = ['cur' => null, 'kind' => 'group'];
                    $cur = $this->emptyCommand(false, count($stack));
                    continue;
                }
                if ($t['v'] === ')') {
                    $top = array_pop($stack);
                    $cur = ($top['kind'] ?? '') === 'subst' && $top['cur'] !== null ? $top['cur'] : $this->emptyCommand(false, count($stack));
                    continue;
                }
                $cur = $this->emptyCommand($t['v'] === '|', count($stack), in_array('subst', array_column($stack, 'kind'), true));
                continue;
            }
            if ($t['t'] === 'redir') {
                if ($t['v'] === 'heredoc') {
                    $cur['stdin'] = true;
                    continue;
                }
                $target = ($tokens[$k + 1]['t'] ?? '') === 'word' ? $tokens[++$k] : null;
                if ($t['v'] === '<<<') {
                    $cur['stdin'] = true;
                    continue;
                }
                $cur['redirs'][] = [$t['v'], $target];
                if ($t['v'] === '<') {
                    $cur['stdin'] = true;
                }
                continue;
            }
            $cur['words'][] = $t;
        }
        if ($cur['words'] !== [] || $cur['redirs'] !== []) {
            $cmds[] = $cur;
        }
        // komendy zawieszone przez niedomknięte `$(` też się liczą
        foreach (array_reverse($stack) as $frame) {
            if ($frame['cur'] !== null && $frame['cur']['words'] !== []) {
                $cmds[] = $frame['cur'];
            }
        }

        return $cmds;
    }

    private function emptyCommand(bool $afterPipe, int $depth, ?bool $subst = null): array
    {
        return ['words' => [], 'redirs' => [], 'afterPipe' => $afterPipe, 'stdin' => $afterPipe, 'substitution' => false, 'subst' => $subst ?? $depth > 0, 'depth' => $depth];
    }

    /** Zapis wystąpienia programu z kontekstem (potok, podstawienie, xargs, nakładki). */
    private function record(array $cmd, string $name, array $args, array $wrappers, bool $xargs, Extraction $out): void
    {
        $contexts = [];
        $pipedFrom = null;
        if ($cmd['afterPipe']) {
            $contexts[] = Command::PIPE;
            $pipedFrom = $out->lastByDepth[$cmd['depth']] ?? null;
        }
        if ($cmd['subst']) {
            $contexts[] = Command::SUBST;
        }
        if ($xargs) {
            $contexts[] = Command::XARGS;
        }
        $interpreters = ['php', 'python', 'python3', 'node', 'ruby', 'perl', 'bash', 'sh', 'zsh'];
        $positional = array_values(array_filter($args, static fn (array $a): bool => !str_starts_with((string) $a['v'], '-')));
        $inline = array_filter($args, static fn (array $a): bool => in_array($a['v'], ['-c', '-r', '-e', '-m'], true)) !== [];
        if (in_array($name, $interpreters, true) && $cmd['stdin'] && !$inline && ($positional === [] || $positional[0]['v'] === '-')) {
            $contexts[] = Command::STDIN_SCRIPT;
        }
        $out->commands[] = new Command($name, $this->subcommand($name, $args), $contexts, $wrappers, $pipedFrom);
        $out->lastByDepth[$cmd['depth']] = $name;
    }

    private function subcommand(string $name, array $args): ?string
    {
        $values = array_map(static fn (array $a): string => (string) $a['v'], $args);
        if (in_array($name, ['php', 'python', 'python3', 'node'], true)) {
            $pos = array_values(array_filter($values, static fn (string $v): bool => !str_starts_with($v, '-')));
            if ($pos === []) {
                return null;
            }
            $script = basename($pos[0]);

            return $script === 'console' && isset($pos[1]) ? 'console ' . $pos[1] : $script;
        }
        if (!in_array($name, self::SUBCOMMANDS, true)) {
            return null;
        }
        for ($i = 0, $n = count($values); $i < $n; $i++) {
            if (in_array($values[$i], ['-C', '-c', '--git-dir', '--work-tree', '-f', '--file'], true)) {
                $i++;
                continue;
            }
            if (!str_starts_with($values[$i], '-')) {
                return $values[$i];
            }
        }

        return null;
    }

    /** Komenda w napisie (`bash -c`, `eval`, `ssh host "…"`) — parsowana rekurencyjnie, z kontekstem. */
    private function nested(string $code, ?string $cwd, string $context, Extraction $out, bool $keepTouches): void
    {
        if ($this->depth >= 3 || trim($code) === '') {
            return;
        }
        [$vars, $loops] = [$this->vars, $this->loops];
        $this->depth++;
        $inner = $this->extract($code, $cwd);
        $this->depth--;
        [$this->vars, $this->loops] = [$vars, $loops];
        foreach ($inner->commands as $c) {
            $out->commands[] = $c->withContext($context);
        }
        if ($keepTouches) {
            array_push($out->touches, ...$inner->touches);
            array_push($out->unresolved, ...$inner->unresolved);
            array_push($out->indirect, ...$inner->indirect);
            array_push($out->unknown, ...$inner->unknown);
        }
    }

    private function ssh(array $args, Extraction $out): void
    {
        $p = $this->parseArgs($args, 'pilFJLRDbcEemOQSWwo', []);
        $pos = $p['pos'];
        if (count($pos) < 2) {
            return;
        }
        array_shift($pos);
        // ścieżki na zdalnej maszynie nie są lokalnymi plikami — tylko komendy
        $this->nested(implode(' ', array_map(static fn (array $a): string => (string) $a['v'], $pos)), null, Command::REMOTE, $out, false);
    }

    private function simple(array $cmd, Extraction $out): void
    {
        $cwd = $out->cwdAfter;
        foreach ($cmd['redirs'] as [$op, $target]) {
            if ($target === null) {
                continue;
            }
            $this->pathArgs($this->expand($target), $cwd, $op === '<' ? Touch::READ : Touch::WRITE, $out, false);
        }

        $words = $cmd['words'];
        $xargs = false;
        $assignments = [];
        $wrappers = [];
        while ($words !== []) {
            $w = $words[0];
            $v = $w['v'];
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $v)) {
                $assignments[] = array_shift($words);
                continue;
            }
            if (in_array($v, self::KEYWORDS, true) || in_array($v, ['sudo', 'nohup', 'command', 'builtin', 'exec', 'nice', 'env'], true)) {
                if (in_array($v, self::WRAPPERS, true)) {
                    $wrappers[] = $v;
                }
                array_shift($words);
                while ($words !== [] && str_starts_with($words[0]['v'], '-') && in_array($v, ['sudo', 'nice', 'env'], true)) {
                    array_shift($words);
                }
                continue;
            }
            if ($v === 'timeout') {
                $wrappers[] = 'timeout';
                array_shift($words);
                while ($words !== [] && str_starts_with($words[0]['v'], '-')) {
                    array_shift($words);
                }
                array_shift($words);
                continue;
            }
            if ($v === 'xargs') {
                $xargs = true;
                array_shift($words);
                // tylko flagi samego xargs — flagi komendy wewnętrznej zostają
                while ($words !== [] && str_starts_with($words[0]['v'], '-')) {
                    $flag = array_shift($words)['v'];
                    if (strlen($flag) === 2 && str_contains('IinPLdsE', $flag[1])) {
                        array_shift($words);
                    }
                }
                continue;
            }
            break;
        }
        if ($words === []) {
            array_walk($assignments, $this->assign(...));
            return;
        }
        if ($words[0]['v'] === 'export') {
            $this->record($cmd, 'export', [], $wrappers, $xargs, $out);
            array_walk($words, $this->assign(...));
            return;
        }
        if ($words[0]['v'] === 'for') {
            $this->forLoop($words);
            return;
        }
        $words = array_merge(...array_map($this->expand(...), $words));
        if ($words === []) {
            return;
        }

        $first = $words[0];
        if ($first['dyn']) {
            $out->unresolved[] = $this->snippet($words) . ' — command from a variable';
            return;
        }
        $prog = $first['v'];
        $name = basename($prog);
        $args = array_slice($words, 1);

        if (in_array($name, ['for', 'case', 'select', 'function'], true)) {
            return;
        }
        $this->record($cmd, $name, $args, $wrappers, $xargs, $out);
        if ($name === 'eval') {
            $this->nested(implode(' ', array_map(static fn (array $a): string => (string) $a['v'], $args)), $cwd, Command::INNER, $out, true);
            return;
        }
        if ($name === 'ssh') {
            $this->ssh($args, $out);
            return;
        }
        if ($name === 'cd' || $name === 'pushd') {
            $this->cd($args, $out);
            return;
        }
        if ($name === 'popd') {
            $out->cwdAfter = null;
            return;
        }
        if ($cmd['substitution'] && !in_array($name, self::NOFILE, true)) {
            $out->unresolved[] = $this->snippet($words) . ' $(…) — arguments from substitution';
        }
        if ($xargs && !in_array($name, self::NOFILE, true)) {
            $out->unresolved[] = 'xargs ' . $this->snippet($words) . ' — paths from stdin';
        }

        match (true) {
            in_array($name, ['head', 'tail'], true) => $this->headTail($name, $args, $cwd, $out),
            in_array($name, ['sed', 'gsed'], true) => $this->sed($args, $cwd, $out),
            in_array($name, ['awk', 'gawk'], true) => $this->awk($args, $cwd, $out),
            in_array($name, ['grep', 'egrep', 'fgrep', 'ggrep'], true) => $this->grep($args, $cwd, $out, false),
            in_array($name, ['rg', 'ag', 'ack'], true) => $this->grep($args, $cwd, $out, true),
            in_array($name, ['ls', 'tree'], true) => $this->listing($args, $cwd, $out, $name === 'ls' ? 'IwT' : 'LIPo'),
            $name === 'find' => $this->find($args, $cwd, $out),
            in_array($name, ['cp', 'mv'], true) => $this->copyMove($name, $args, $cwd, $out),
            in_array($name, ['rm', 'rmdir', 'unlink', 'touch', 'mkdir', 'tee', 'truncate'], true) => $this->writes($args, $cwd, $out),
            $name === 'git' => $this->git($args, $cwd, $out),
            in_array($name, ['php', 'python', 'python3', 'node', 'ruby', 'perl', 'bash', 'sh', 'zsh', 'source', '.'], true)
                => $this->interpreter($name, $args, $cmd['stdin'], $cwd, $out),
            in_array($name, self::METADATA, true) => $this->simpleArgs($args, '', $cwd, Touch::LIST, $out),
            array_key_exists($name, self::READERS) => $this->reader($name, $args, $cwd, $out),
            in_array($name, self::NOFILE, true) => null,
            $this->isIndirect($prog) => $this->indirect($prog, $args, $cwd, $out),
            default => $this->unknown($name, $args, $cwd, $out),
        };
    }

    /** `NAZWA=wartość` — zapamiętuje wartość albo null, gdy nie da się jej ustalić. */
    private function assign(array $word): void
    {
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', (string) $word['v'], $m)) {
            return;
        }
        $value = $this->expand(['v' => $m[2]] + $word);
        $this->vars[$m[1]] = count($value) === 1 && !$value[0]['dyn'] ? (string) $value[0]['v'] : null;
    }

    private function forLoop(array $words): void
    {
        $var = $words[1]['v'] ?? '';
        if (($words[2]['v'] ?? '') !== 'in' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $var)) {
            return;
        }
        $items = array_merge(...array_map($this->expand(...), array_slice($words, 3)));
        $items = array_values(array_filter($items, static fn (array $i): bool => !$i['dyn']));
        if ($items === []) {
            unset($this->loops[$var]);
            return;
        }
        $this->loops[$var] = $items;
    }

    /**
     * Rozwija `$NAZWA` / `${NAZWA}` znanymi zmiennymi; zmienna pętli daje jedno słowo na element listy.
     *
     * @return list<array<string, mixed>>
     */
    private function expand(array $word): array
    {
        if (!$word['dyn'] || ($word['opaque'] ?? false)) {
            return [$word];
        }
        $pattern = '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}|\$([A-Za-z_][A-Za-z0-9_]*)/';
        preg_match_all($pattern, (string) $word['v'], $m);
        $names = array_filter(array_merge($m[1], $m[2]));
        $loop = null;
        foreach ($names as $name) {
            if (isset($this->loops[$name])) {
                $loop = $name;
                break;
            }
        }
        $result = [];
        foreach ($loop !== null ? $this->loops[$loop] : [null] as $item) {
            $glob = (bool) $word['glob'] || (bool) ($item['glob'] ?? false);
            $v = preg_replace_callback($pattern, function (array $mm) use ($loop, $item, &$glob): string {
                $name = $mm[1] !== '' ? $mm[1] : ($mm[2] ?? '');
                if ($name === $loop) {
                    return (string) $item['v'];
                }
                $value = $this->vars[$name] ?? null;
                if ($value === null) {
                    return $mm[0];
                }
                $glob = $glob || strpbrk($value, '*?[') !== false;

                return $value;
            }, (string) $word['v']);
            $result[] = ['v' => $v, 'dyn' => str_contains((string) $v, '$'), 'glob' => $glob] + $word;
        }

        return $result;
    }

    private function cd(array $args, Extraction $out): void
    {
        $args = array_values(array_filter($args, static fn (array $a): bool => !in_array($a['v'], ['-P', '-L', '--'], true)));
        if ($args === []) {
            $out->cwdAfter = Path::home();
            return;
        }
        if ($args[0]['dyn'] || $args[0]['v'] === '-') {
            $out->unresolved[] = 'cd ' . $args[0]['v'] . ' — unknown working directory';
            $out->cwdAfter = null;
            return;
        }
        $out->cwdAfter = Path::resolve($args[0]['v'], $out->cwdAfter);
    }

    private function headTail(string $name, array $args, ?string $cwd, Extraction $out): void
    {
        $p = $this->parseArgs($args, 'nc', ['--lines', '--bytes']);
        $n = $p['flags']['n'] ?? $p['flags']['lines'] ?? null;
        foreach (array_keys($p['flags']) as $flag) {
            if (is_int($flag) || ctype_digit((string) $flag)) {
                $n = (string) $flag;
            }
        }
        $n = is_string($n) ? ltrim($n, '+-') : '10';
        $range = $name === 'head' ? '1-' . $n : 'ostatnie ' . $n;
        if (isset($p['flags']['c']) || isset($p['flags']['bytes'])) {
            $range = 'bajty';
        }
        $this->pathArgs($p['pos'], $cwd, Touch::READ, $out, false, true, $range);
    }

    private function sed(array $args, ?string $cwd, Extraction $out): void
    {
        // BSD: `sed -i '' …` — pusty sufiks kopii zapasowej jako osobny argument
        $clean = [];
        $count = count($args);
        $inPlace = false;
        for ($k = 0; $k < $count; $k++) {
            $v = $args[$k]['v'];
            if ($v === '-i' || $v === '--in-place' || preg_match('/^-[nEr]*i/', $v)) {
                $inPlace = true;
                $nextV = $args[$k + 1]['v'] ?? null;
                if ($v === '-i' && $nextV !== null && ($nextV === '' || preg_match('/^\.[A-Za-z0-9~_-]{1,8}$/', $nextV))) {
                    $k++;
                }
                $flags = preg_replace('/i.*$/', '', $v);
                if ($flags !== '-' && $flags !== '' && $flags !== '--in-place') {
                    $clean[] = ['v' => $flags] + $args[$k];
                }
                continue;
            }
            $clean[] = $args[$k];
        }
        $p = $this->parseArgs($clean, 'efl', ['--expression', '--file']);
        $pos = $p['pos'];
        $script = null;
        if (!isset($p['flags']['e']) && !isset($p['flags']['f']) && !isset($p['flags']['expression'])) {
            $script = array_shift($pos)['v'] ?? null;
        } else {
            $script = is_string($p['flags']['e'] ?? null) ? $p['flags']['e'] : null;
        }
        if ($inPlace) {
            $this->pathArgs($pos, $cwd, Touch::EDIT, $out, false);
            return;
        }
        $quiet = isset($p['flags']['n']) || isset($p['flags']['quiet']);
        $range = null;
        if ($quiet && $script !== null && preg_match('/^\s*(\d+|\$)\s*(?:,\s*(\d+|\$)\s*)?p\s*$/', $script, $m)) {
            $range = $m[1] . (isset($m[2]) && $m[2] !== '' ? '-' . $m[2] : '');
        }
        $this->pathArgs($pos, $cwd, Touch::READ, $out, false, $quiet, $range);
    }

    private function awk(array $args, ?string $cwd, Extraction $out): void
    {
        $p = $this->parseArgs($args, 'Fvf', []);
        $pos = $p['pos'];
        if (!isset($p['flags']['f'])) {
            array_shift($pos);
        }
        $pos = array_values(array_filter($pos, static fn (array $a): bool => !preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $a['v'])));
        $this->pathArgs($pos, $cwd, Touch::READ, $out, false, true);
    }

    private function grep(array $args, ?string $cwd, Extraction $out, bool $recursiveDefault): void
    {
        $short = $recursiveDefault ? 'efgtTABCmMjEr' : 'efmABCdD';
        $long = ['--include', '--exclude', '--exclude-dir', '--regexp', '--file', '--max-count', '--context',
            '--after-context', '--before-context', '--glob', '--iglob', '--type', '--type-not', '--max-depth',
            '--sort', '--sortr', '--encoding', '--max-filesize', '--type-add', '--replace', '--label'];
        $p = $this->parseArgs($args, $short, $long);
        $pos = $p['pos'];
        $f = $p['flags'];
        if (!isset($f['e']) && !isset($f['f']) && !isset($f['regexp']) && !isset($f['file'])) {
            array_shift($pos);
        }
        $recursive = $recursiveDefault || isset($f['r']) || isset($f['R']) || isset($f['recursive']);
        if ($pos === []) {
            if ($recursive && $cwd !== null) {
                $out->add(new Touch($cwd, Touch::SEARCH, is_dir($cwd)));
            }
            return;
        }
        $this->pathArgs($pos, $cwd, Touch::SEARCH, $out, false);
    }

    private function listing(array $args, ?string $cwd, Extraction $out, string $valued): void
    {
        $p = $this->parseArgs($args, $valued, []);
        if ($p['pos'] === []) {
            if ($cwd !== null) {
                $out->add(new Touch($cwd, Touch::LIST, is_dir($cwd)));
            }
            return;
        }
        $this->pathArgs($p['pos'], $cwd, Touch::LIST, $out, false);
    }

    private function find(array $args, ?string $cwd, Extraction $out): void
    {
        $roots = [];
        foreach ($args as $k => $a) {
            if (str_starts_with($a['v'], '-') || in_array($a['v'], ['(', '!', ')'], true)) {
                if (in_array('-exec', array_column(array_slice($args, $k), 'v'), true)
                    || in_array('-execdir', array_column(array_slice($args, $k), 'v'), true)) {
                    $out->unresolved[] = 'find … -exec — files from find output';
                }
                break;
            }
            $roots[] = $a;
        }
        if ($roots === []) {
            if ($cwd !== null) {
                $out->add(new Touch($cwd, Touch::LIST, is_dir($cwd)));
            }
            return;
        }
        $this->pathArgs($roots, $cwd, Touch::LIST, $out, false);
    }

    private function copyMove(string $name, array $args, ?string $cwd, Extraction $out): void
    {
        $pos = $this->parseArgs($args, 'tS', [])['pos'];
        if (count($pos) < 2) {
            return;
        }
        $dest = array_pop($pos);
        $this->pathArgs($pos, $cwd, $name === 'cp' ? Touch::READ : Touch::WRITE, $out, false);
        $this->pathArgs([$dest], $cwd, Touch::WRITE, $out, false);
    }

    private function writes(array $args, ?string $cwd, Extraction $out): void
    {
        $this->pathArgs($this->parseArgs($args, 'ms', [])['pos'], $cwd, Touch::WRITE, $out, false);
    }

    private function git(array $args, ?string $cwd, Extraction $out): void
    {
        $k = 0;
        $count = count($args);
        while ($k < $count && str_starts_with($args[$k]['v'], '-')) {
            if ($args[$k]['v'] === '-C' && isset($args[$k + 1])) {
                $cwd = $args[$k + 1]['dyn'] ? null : Path::resolve($args[$k + 1]['v'], $cwd);
                $k += 2;
                continue;
            }
            $k += $args[$k]['v'] === '-c' ? 2 : 1;
        }
        $sub = $args[$k]['v'] ?? '';
        $rest = array_slice($args, $k + 1);
        if ($sub === 'grep') {
            $this->grep($rest, $cwd, $out, true);
            return;
        }
        if (!in_array($sub, ['diff', 'log', 'show', 'blame', 'annotate', 'checkout', 'restore'], true)) {
            return;
        }
        $op = in_array($sub, ['checkout', 'restore'], true) ? Touch::WRITE : Touch::READ;
        $pos = array_values(array_filter($rest, static fn (array $a): bool => !str_starts_with($a['v'], '-')));
        $this->pathArgs($pos, $cwd, $op, $out, true, $op === Touch::READ && $sub !== 'blame');
    }

    private function interpreter(string $name, array $args, bool $stdin, ?string $cwd, Extraction $out): void
    {
        $inline = ['php' => 'r', 'python' => 'c', 'python3' => 'c', 'node' => 'e', 'ruby' => 'e', 'perl' => 'e', 'bash' => 'c', 'sh' => 'c', 'zsh' => 'c'];
        $p = $this->parseArgs($args, ($inline[$name] ?? '') . 'dcmfWXO', []);
        if (in_array($name, ['bash', 'sh', 'zsh'], true) && is_string($p['flags']['c'] ?? null)) {
            $this->nested($p['flags']['c'], $cwd, Command::INNER, $out, true);
            return;
        }
        if (isset($p['flags'][$inline[$name] ?? '-']) || isset($p['flags']['m'])) {
            $out->indirect[] = $name . ' (inline code)';
            return;
        }
        $pos = $p['pos'];
        if ($pos === [] || $pos[0]['v'] === '-') {
            if ($stdin) {
                $out->unresolved[] = $name . ' — script from stdin/heredoc';
            }
            $out->indirect[] = $name;
            return;
        }
        // uruchomienie skryptu to nie odczyt: agent widzi wynik programu, nie treść pliku
        $script = array_shift($pos);
        $this->pathArgs([$script], $cwd, Touch::EXEC, $out, false);
        $this->pathArgs($pos, $cwd, Touch::EXEC, $out, true);
        $out->indirect[] = $name . ' ' . basename($script['v']);
    }

    private function reader(string $name, array $args, ?string $cwd, Extraction $out): void
    {
        $p = $this->parseArgs($args, self::READERS[$name], ['--arg', '--argjson', '--slurpfile', '--rawfile']);
        $pos = $p['pos'];
        if (in_array($name, ['jq', 'yq'], true)) {
            array_shift($pos);
        }
        if (isset($p['flags']['o'])) {
            $this->pathArgs([['v' => $p['flags']['o'], 'glob' => false, 'dyn' => false, 'quoted' => false]], $cwd, Touch::WRITE, $out, false);
        }
        $this->pathArgs($pos, $cwd, Touch::READ, $out, false);
    }

    private function simpleArgs(array $args, string $valued, ?string $cwd, string $op, Extraction $out): void
    {
        $this->pathArgs($this->parseArgs($args, $valued, [])['pos'], $cwd, $op, $out, false);
    }

    private function isIndirect(string $prog): bool
    {
        if (str_contains($prog, 'vendor/bin/') || str_contains($prog, 'node_modules/.bin/')) {
            return true;
        }

        return in_array(basename($prog), self::INDIRECT, true);
    }

    private function indirect(string $prog, array $args, ?string $cwd, Extraction $out): void
    {
        $out->indirect[] = basename($prog);
        if (str_contains($prog, '/')) {
            $this->pathArgs([['v' => $prog, 'glob' => false, 'dyn' => false, 'quoted' => false]], $cwd, Touch::EXEC, $out, true);
        }
        $this->pathArgs(array_values(array_filter($args, static fn (array $a): bool => !str_starts_with($a['v'], '-'))), $cwd, Touch::EXEC, $out, true);
    }

    private function unknown(string $name, array $args, ?string $cwd, Extraction $out): void
    {
        $out->unknown[] = $name;
        $this->pathArgs(array_values(array_filter($args, static fn (array $a): bool => !str_starts_with($a['v'], '-'))), $cwd, Touch::READ, $out, true);
    }

    /**
     * @param list<array<string, mixed>> $tokens
     */
    private function pathArgs(array $tokens, ?string $cwd, string $op, Extraction $out, bool $requireExists, bool $partial = false, ?string $range = null): void
    {
        foreach ($tokens as $t) {
            $v = (string) $t['v'];
            if ($v === '' || $v === '-' || str_starts_with($v, '/dev/')) {
                continue;
            }
            if ($t['dyn']) {
                if (!$requireExists) {
                    $out->unresolved[] = $v . ' — variable in path';
                }
                continue;
            }
            if ($cwd === null && !str_starts_with($v, '/')) {
                if (!$requireExists) {
                    $out->unresolved[] = $v . ' — unknown working directory';
                }
                continue;
            }
            $abs = Path::resolve($v, $cwd);
            if ($abs === null) {
                continue;
            }
            if ($t['glob']) {
                $matches = glob($abs, GLOB_NOSORT) ?: [];
                if ($matches === [] && !$requireExists) {
                    $out->unresolved[] = $v . ' — glob without matches';
                }
                foreach ($matches as $m) {
                    $out->add(new Touch(Path::normalize($m), $op, true, $partial, $range));
                }
                continue;
            }
            $exists = file_exists($abs);
            if (!$exists && ($requireExists || !$this->looksLikePath($v))) {
                continue;
            }
            $out->add(new Touch($abs, $op, $exists, $partial, $range));
        }
    }

    private function looksLikePath(string $v): bool
    {
        return !preg_match('/\s/', $v) && (str_contains($v, '/') || preg_match('/\.[A-Za-z0-9]{1,6}$/', $v) === 1);
    }

    /**
     * Rozbiór flag w stylu getopt.
     *
     * @return array{flags: array<string, string|true>, pos: list<array<string, mixed>>}
     */
    private function parseArgs(array $args, string $shortValued, array $longValued): array
    {
        $flags = [];
        $pos = [];
        $count = count($args);
        for ($k = 0; $k < $count; $k++) {
            $v = (string) $args[$k]['v'];
            if ($v === '--') {
                array_push($pos, ...array_slice($args, $k + 1));
                break;
            }
            if (str_starts_with($v, '--')) {
                $eq = strpos($v, '=');
                if ($eq !== false) {
                    $flags[substr($v, 2, $eq - 2)] = substr($v, $eq + 1);
                } elseif (in_array($v, $longValued, true)) {
                    $flags[substr($v, 2)] = (string) ($args[++$k]['v'] ?? '');
                    if (in_array($v, ['--arg', '--argjson', '--slurpfile', '--rawfile'], true)) {
                        $k++;
                    }
                } else {
                    $flags[substr($v, 2)] = true;
                }
                continue;
            }
            if (str_starts_with($v, '-') && $v !== '-' && !$args[$k]['quoted']) {
                $len = strlen($v);
                if (ctype_digit(substr($v, 1))) {
                    $flags[substr($v, 1)] = true;
                    continue;
                }
                for ($j = 1; $j < $len; $j++) {
                    $ch = $v[$j];
                    if ($shortValued !== '' && str_contains($shortValued, $ch)) {
                        $rest = substr($v, $j + 1);
                        $flags[$ch] = $rest !== '' ? $rest : (string) ($args[++$k]['v'] ?? '');
                        break;
                    }
                    $flags[$ch] = true;
                }
                continue;
            }
            $pos[] = $args[$k];
        }

        return ['flags' => $flags, 'pos' => $pos];
    }

    private function snippet(array $words): string
    {
        return mb_strimwidth(implode(' ', array_column($words, 'v')), 0, 60, '…');
    }
}
