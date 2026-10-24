<?php

declare(strict_types=1);

namespace Viz\Extract;

use Viz\Model\Command;

/**
 * Klasyfikacja programów — służy rolom i sygnałom, nie wykrywaniu (wykrywa parser).
 * Program spoza listy ma rolę `unknown`.
 */
final class ToolRoles
{
    private const ROLES = [
        'read' => ['cat', 'head', 'tail', 'less', 'more', 'sed', 'awk', 'gawk', 'wc', 'nl', 'bat', 'strings', 'xxd', 'od', 'hexdump', 'jq', 'yq', 'column', 'cut', 'sort', 'uniq', 'diff', 'comm', 'tac', 'paste', 'fold', 'fmt', 'iconv', 'base64'],
        'search' => ['grep', 'egrep', 'fgrep', 'rg', 'ag', 'ack', 'find', 'fd', 'locate', 'mdfind'],
        'files' => ['ls', 'tree', 'du', 'df', 'stat', 'file', 'cp', 'mv', 'rm', 'rmdir', 'mkdir', 'touch', 'chmod', 'chown', 'ln', 'tee', 'truncate', 'tar', 'zip', 'unzip', 'gzip', 'gunzip', 'realpath', 'readlink', 'basename', 'dirname', 'md5', 'md5sum', 'shasum', 'sha256sum', 'sips', 'unlink', 'mktemp'],
        'vcs' => ['git', 'gh', 'svn'],
        'network' => ['curl', 'wget', 'ssh', 'scp', 'rsync', 'sftp', 'nc', 'ping', 'dig', 'nslookup', 'http', 'telnet'],
        'build-test' => ['composer', 'npm', 'npx', 'yarn', 'pnpm', 'make', 'phpunit', 'pest', 'phpstan', 'php-cs-fixer', 'psalm', 'rector', 'deptrac', 'docker', 'docker-compose', 'symfony', 'pip', 'pip3', 'brew', 'dep', 'cargo', 'go', 'tsc', 'eslint', 'prettier'],
        'interpreter' => ['php', 'python', 'python3', 'node', 'ruby', 'perl', 'bash', 'sh', 'zsh', 'deno', 'bun'],
        'database' => ['mysql', 'psql', 'sqlite3', 'redis-cli', 'mysqldump'],
        'shell' => ['cd', 'pushd', 'popd', 'echo', 'printf', 'export', 'set', 'unset', 'test', '[', '[[', 'true', 'false', 'read', 'source', '.', 'alias', 'type', 'which', 'pwd', 'exit', 'return', 'sleep', 'wait', 'eval', 'local', 'declare', 'shift', 'trap', 'seq', 'expr', 'bc', 'tr', 'xargs', 'printenv', 'date', 'env'],
        'system' => ['ps', 'kill', 'pkill', 'lsof', 'uname', 'whoami', 'id', 'hostname', 'open', 'pbcopy', 'pbpaste', 'osascript', 'defaults', 'launchctl', 'crontab', 'say', 'top', 'htop', 'sudo'],
        'interactive' => ['nano', 'vim', 'vi', 'emacs', 'top', 'htop', 'watch'],
    ];

    /** Programy, które bez terminala zwykle wiszą albo kończą się błędem. */
    private const INTERACTIVE = ['nano', 'vim', 'vi', 'emacs', 'top', 'htop', 'watch'];

    public static function role(string $program): string
    {
        foreach (self::ROLES as $role => $programs) {
            if ($role !== 'interactive' && in_array($program, $programs, true)) {
                return $role;
            }
        }

        return in_array($program, self::INTERACTIVE, true) ? 'interactive' : 'unknown';
    }

    public static function interactive(Command $c): bool
    {
        return in_array($c->program, self::INTERACTIVE, true) && !$c->has(Command::REMOTE);
    }

    /** Komenda ryzykowna: usuwanie, wypychanie, cofanie historii, uprawnienia roota. */
    public static function risky(Command $c): ?string
    {
        return match (true) {
            in_array('sudo', $c->wrappers, true) || $c->program === 'sudo' => 'sudo',
            $c->program === 'rm' => 'rm',
            $c->program === 'git' && in_array($c->sub, ['push', 'reset'], true) => 'git ' . $c->sub,
            default => null,
        };
    }
}
