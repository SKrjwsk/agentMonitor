<?php

declare(strict_types=1);

namespace Viz\Extract;

use Viz\Path;

/**
 * Tokenizer podzbioru składni shella wystarczającego do wyciągania ścieżek.
 *
 * Tokeny:
 * - ['t' => 'word', 'v' => string, 'glob' => bool, 'dyn' => bool, 'quoted' => bool]
 * - ['t' => 'op', 'v' => '&&'|'||'|';'|'|'|'&'|"\n"|'('|'$('|')']   `$(` = podstawienie komendy / backtick / `<(` `>(`
 * - ['t' => 'redir', 'v' => '<'|'>'|'>>'|'<<<'|'heredoc']
 *
 * `dyn` = słowo zawiera zmienną (`$NAZWA` zostaje w `v`) albo podstawienie komendy (`opaque`).
 */
final class BashLexer
{
    private string $s;
    private int $i;
    private int $n;
    /** @var list<array<string, mixed>> */
    private array $tokens;
    private ?string $word;
    private bool $glob;
    private bool $dyn;
    private bool $quoted;
    /** Słowo zawiera podstawienie komendy — nie do rozwinięcia nawet przy znanych zmiennych. */
    private bool $opaque;
    /** @var list<array{0: string, 1: bool}> [delimiter, stripTabs] */
    private array $heredocs;
    private bool $backtick;

    /** @return list<array<string, mixed>> */
    public function tokens(string $source): array
    {
        $this->s = $source;
        $this->i = 0;
        $this->n = strlen($source);
        $this->tokens = [];
        $this->heredocs = [];
        $this->backtick = false;
        $this->reset();

        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            $next = $this->s[$this->i + 1] ?? '';

            if ($c === '\\') {
                if ($next === "\n") {
                    $this->i += 2;
                    continue;
                }
                $this->append($next);
                $this->i += 2;
                continue;
            }
            if ($c === "'") {
                $end = strpos($this->s, "'", $this->i + 1);
                $end = $end === false ? $this->n : $end;
                $this->append(substr($this->s, $this->i + 1, $end - $this->i - 1));
                $this->quoted = true;
                $this->i = $end + 1;
                continue;
            }
            if ($c === '"') {
                $this->doubleQuoted();
                continue;
            }
            if ($c === "\n") {
                $this->flush();
                $this->op("\n");
                $this->i++;
                $this->skipHeredocBodies();
                continue;
            }
            if ($c === ' ' || $c === "\t" || $c === "\r") {
                $this->flush();
                $this->i++;
                continue;
            }
            if ($c === '#' && $this->word === null) {
                $nl = strpos($this->s, "\n", $this->i);
                $this->i = $nl === false ? $this->n : $nl;
                continue;
            }
            if ($c === '$') {
                if ($next === '(') {
                    $this->flush();
                    $this->op('$(');
                    $this->i += 2;
                    continue;
                }
                $this->variable();
                continue;
            }
            if ($c === '`') {
                $this->flush();
                $this->op($this->backtick ? ')' : '$(');
                $this->backtick = !$this->backtick;
                $this->i++;
                continue;
            }
            if ($c === '|') {
                $this->flush();
                if ($next === '|') {
                    $this->op('||');
                    $this->i += 2;
                } else {
                    $this->op('|');
                    $this->i += $next === '&' ? 2 : 1;
                }
                continue;
            }
            if ($c === '&') {
                $this->flush();
                if ($next === '&') {
                    $this->op('&&');
                    $this->i += 2;
                } elseif ($next === '>') {
                    $this->redir(($this->s[$this->i + 2] ?? '') === '>' ? '>>' : '>');
                    $this->i += ($this->s[$this->i + 2] ?? '') === '>' ? 3 : 2;
                } else {
                    $this->op('&');
                    $this->i++;
                }
                continue;
            }
            if ($c === ';') {
                $this->flush();
                $this->op(';');
                $this->i += $next === ';' ? 2 : 1;
                continue;
            }
            if ($c === '(' || $c === ')') {
                $this->flush();
                $this->op($c);
                $this->i++;
                continue;
            }
            if ($c === '<' || $c === '>') {
                $this->redirection($c, $next);
                continue;
            }
            if ($c === '~' && $this->word === null && ($next === '/' || $next === '' || ctype_space($next))) {
                $this->append(Path::home());
                $this->i++;
                continue;
            }
            if ($c === '*' || $c === '?' || $c === '[') {
                $this->glob = true;
            }
            $this->append($c);
            $this->i++;
        }
        $this->flush();

        return $this->tokens;
    }

    private function redirection(string $c, string $next): void
    {
        // "2>" / "1>": deskryptor przyklejony do operatora nie jest słowem
        if ($this->word !== null && !$this->quoted && ctype_digit($this->word)) {
            $this->reset();
        } else {
            $this->flush();
        }

        if ($c === '<') {
            if ($next === '<' && ($this->s[$this->i + 2] ?? '') === '<') {
                $this->redir('<<<');
                $this->i += 3;
                return;
            }
            if ($next === '<') {
                $this->i += 2;
                $strip = ($this->s[$this->i] ?? '') === '-';
                if ($strip) {
                    $this->i++;
                }
                $this->heredocDelimiter($strip);
                return;
            }
            if ($next === '(') {
                $this->op('$(');
                $this->i += 2;
                return;
            }
            if ($next === '&') {
                $this->i += 2;
                $this->skipFdTarget();
                return;
            }
            $this->redir('<');
            $this->i++;
            return;
        }

        if ($next === '>') {
            $this->redir('>>');
            $this->i += 2;
            return;
        }
        if ($next === '&') {
            $this->i += 2;
            if (!$this->skipFdTarget()) {
                $this->redir('>');
            }
            return;
        }
        if ($next === '(') {
            $this->op('$(');
            $this->i += 2;
            return;
        }
        $this->redir('>');
        $this->i += $next === '|' ? 2 : 1;
    }

    /** `>&2`, `<&0`, `>&-` — przekierowanie na deskryptor, nie na plik. */
    private function skipFdTarget(): bool
    {
        $j = $this->i;
        while ($j < $this->n && (ctype_digit($this->s[$j]) || $this->s[$j] === '-')) {
            $j++;
        }
        if ($j === $this->i) {
            return false;
        }
        $this->i = $j;

        return true;
    }

    private function heredocDelimiter(bool $strip): void
    {
        while ($this->i < $this->n && ($this->s[$this->i] === ' ' || $this->s[$this->i] === "\t")) {
            $this->i++;
        }
        $delim = '';
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === "'" || $c === '"') {
                $end = strpos($this->s, $c, $this->i + 1);
                $end = $end === false ? $this->n : $end;
                $delim .= substr($this->s, $this->i + 1, $end - $this->i - 1);
                $this->i = $end + 1;
                continue;
            }
            if ($c === '\\') {
                $this->i++;
                continue;
            }
            if (ctype_space($c) || str_contains(';|&<>()', $c)) {
                break;
            }
            $delim .= $c;
            $this->i++;
        }
        $this->redir('heredoc');
        $this->heredocs[] = [$delim, $strip];
    }

    private function skipHeredocBodies(): void
    {
        foreach ($this->heredocs as [$delim, $strip]) {
            while ($this->i < $this->n) {
                $nl = strpos($this->s, "\n", $this->i);
                $line = substr($this->s, $this->i, ($nl === false ? $this->n : $nl) - $this->i);
                $this->i = $nl === false ? $this->n : $nl + 1;
                if (($strip ? ltrim($line, "\t") : $line) === $delim) {
                    break;
                }
            }
        }
        $this->heredocs = [];
    }

    private function doubleQuoted(): void
    {
        $this->quoted = true;
        $this->i++;
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === '"') {
                $this->i++;
                return;
            }
            if ($c === '\\' && str_contains('$`"\\' . "\n", $this->s[$this->i + 1] ?? '')) {
                $this->append($this->s[$this->i + 1]);
                $this->i += 2;
                continue;
            }
            if ($c === '$') {
                $this->variable();
                continue;
            }
            if ($c === '`') {
                $end = strpos($this->s, '`', $this->i + 1);
                $this->i = $end === false ? $this->n : $end + 1;
                $this->dyn = true;
                $this->opaque = true;
                $this->append('');
                continue;
            }
            $this->append($c);
            $this->i++;
        }
    }

    private function variable(): void
    {
        $rest = substr($this->s, $this->i + 1, 80);
        if (preg_match('/^(\{HOME\}|HOME)(?![A-Za-z0-9_])/', $rest, $m)) {
            $this->append(Path::home());
            $this->i += 1 + strlen($m[1]);
            return;
        }
        if (preg_match('/^(\{[^}]*\}|[A-Za-z_][A-Za-z0-9_]*|[0-9?@*#$!-])/', $rest, $m)) {
            $this->append('$' . $m[1]);
            $this->dyn = true;
            $this->i += 1 + strlen($m[1]);
            return;
        }
        if (($rest[0] ?? '') === '(') {
            // $( w cudzysłowie — wynik podstawienia nieznany
            $depth = 0;
            for ($j = $this->i + 1; $j < $this->n; $j++) {
                $depth += $this->s[$j] === '(' ? 1 : ($this->s[$j] === ')' ? -1 : 0);
                if ($depth === 0) {
                    break;
                }
            }
            $this->i = $j + 1;
            $this->dyn = true;
            $this->opaque = true;
            $this->append('');
            return;
        }
        $this->append('$');
        $this->i++;
    }

    private function append(string $text): void
    {
        $this->word = ($this->word ?? '') . $text;
    }

    private function flush(): void
    {
        if ($this->word !== null) {
            $this->tokens[] = ['t' => 'word', 'v' => $this->word, 'glob' => $this->glob, 'dyn' => $this->dyn, 'quoted' => $this->quoted, 'opaque' => $this->opaque];
        }
        $this->reset();
    }

    private function reset(): void
    {
        $this->word = null;
        $this->glob = false;
        $this->dyn = false;
        $this->quoted = false;
        $this->opaque = false;
    }

    private function op(string $v): void
    {
        $this->tokens[] = ['t' => 'op', 'v' => $v];
    }

    private function redir(string $v): void
    {
        $this->tokens[] = ['t' => 'redir', 'v' => $v];
    }
}
