<?php

declare(strict_types=1);

namespace Viz\Permissions;

use Viz\Extract\BashLexer;

/**
 * Which `permissions` rule decided a call — per the Claude Code documentation (code.claude.com/docs/en/permissions, 2026-11-09):
 * - order: deny, then ask, then allow; the first match in that order decides, rule specificity does not change it
 *   (a matching `ask` asks even when a narrower `allow` also matches)
 * - compound command (`&&`, `||`, `;`, `|`, `&`, newline; also substitution and subshell): deny/ask — when any part matches,
 *   allow — every part must be allowed
 * - `Tool`, `Tool(*)` = the whole tool; Bash: `*` = any text, a trailing ` *` (the only asterisk) also matches the bare command,
 *   a trailing `:*` = ` *`; before matching the wrappers `timeout`, `time`, `nice`, `nohup`, `stdbuf`, `command`, `builtin`,
 *   `noglob`, bare `xargs` are stripped; deny/ask also match after a leading variable assignment (`FOO=bar rm …`)
 *
 * Simplifications (deliberate): command words joined with a single space after quotes are removed (BashLexer), `$(…)` in quotes is not
 * split into parts; allow does not strip assignments (the list of "safe" variables in Claude Code is not published); the built-in set of
 * read-only commands = the list from the documentation (without `git` forms, without exceptions for redirects and `cd` + `git`); path rules
 * (`Read(…)`, `Edit(…)`), `WebFetch(domain:…)` and parameters (`Tool(param:value)`) are not matched — only the tool name works.
 */
final class RuleMatcher
{
    /** Read-only commands listed in the documentation (the Claude Code set is larger and not fully published). */
    public const READ_ONLY = ['ls', 'cat', 'echo', 'pwd', 'head', 'tail', 'grep', 'find', 'wc', 'which', 'diff', 'stat', 'du', 'cd'];
    private const WRAPPERS = ['timeout', 'time', 'nice', 'nohup', 'stdbuf', 'command', 'builtin', 'noglob'];
    /** Shell control words before a command (`do git …`, `then rm …`) — a command in the body of a loop or condition is also a part. */
    private const KEYWORDS = ['do', 'then', 'else', 'elif', 'if', 'while', 'until', '!', '{', '}', 'done', 'fi'];
    private const SEPARATORS = ['&&', '||', ';', '|', '&', "\n", '(', '$(', ')'];

    /**
     * Parts of a compound command. `words` = words without redirect targets; `dyn` = `$X` / `${…}` variables in words and redirect targets (without substitution);
     * `subst` = part inside `$(…)` / backtick; `redirects` = targets of `>`, `>>`, `<`.
     *
     * @return list<array{words: list<string>, dyn: list<string>, subst: bool, redirects: list<array{op: string, target: string}>, heredoc: bool}>
     */
    public static function segments(string $command): array
    {
        $out = [];
        $cur = self::emptySegment(false);
        $depth = 0;
        $tokens = (new BashLexer())->tokens($command);
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t['t'] === 'op' && in_array($t['v'], self::SEPARATORS, true)) {
                if ($cur['words'] !== [] || $cur['redirects'] !== []) {
                    $out[] = $cur;
                }
                $depth += $t['v'] === '$(' ? 1 : ($t['v'] === ')' && $depth > 0 ? -1 : 0);
                $cur = self::emptySegment($depth > 0);
                continue;
            }
            if ($t['t'] === 'redir') {
                if ($t['v'] === 'heredoc' || $t['v'] === '<<<') {
                    $cur['heredoc'] = true;
                    if ($t['v'] === '<<<') {
                        $i++;
                    }
                    continue;
                }
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && $next['t'] === 'word') {
                    $cur['redirects'][] = ['op' => $t['v'], 'target' => (string) $next['v']];
                    $cur['dyn'] = [...$cur['dyn'], ...self::variables($next)];
                    $i++;
                }
                continue;
            }
            if ($t['t'] === 'word') {
                $cur['words'][] = (string) $t['v'];
                $cur['dyn'] = [...$cur['dyn'], ...self::variables($t)];
            }
        }
        if ($cur['words'] !== [] || $cur['redirects'] !== []) {
            $out[] = $cur;
        }

        return $out;
    }

    /**
     * Words of a part after control words and wrappers are stripped; `$assignments` = also strip leading variable assignments (deny/ask).
     *
     * @param list<string> $words
     * @return list<string>
     */
    public static function strip(array $words, bool $assignments): array
    {
        $i = 0;
        $n = count($words);
        while ($i < $n) {
            $w = $words[$i];
            if (in_array($w, self::KEYWORDS, true) || ($assignments && preg_match('/^[A-Za-z_]\w*=/', $w))) {
                $i++;
                continue;
            }
            if (in_array($w, self::WRAPPERS, true)) {
                $i++;
                while ($i < $n && str_starts_with($words[$i], '-')) {
                    $i++;
                }
                // `timeout 30 …` — duration
                if ($w === 'timeout' && $i < $n) {
                    $i++;
                }
                continue;
            }
            if ($w === 'xargs' && $i + 1 < $n && !str_starts_with($words[$i + 1], '-')) {
                $i++;
                continue;
            }
            break;
        }

        return array_slice($words, $i);
    }

    /** Bash rule pattern (what is in the parentheses) against the text of one command part. */
    public static function bashMatches(string $spec, string $text): bool
    {
        if (str_ends_with($spec, ':*')) {
            $spec = substr($spec, 0, -2) . ' *';
        }
        if ($spec === '*' || $spec === '') {
            return true;
        }
        if (substr_count($spec, '*') === 1 && str_ends_with($spec, ' *')) {
            $prefix = substr($spec, 0, -2);

            return $text === $prefix || str_starts_with($text, $prefix . ' ');
        }
        $regex = '/^' . implode('.*', array_map(static fn (string $p): string => preg_quote($p, '/'), explode('*', $spec))) . '$/s';

        return preg_match($regex, $text) === 1;
    }

    /**
     * Parsing of a `Tool` / `Tool(spec)` rule; null = invalid.
     *
     * @return ?array{tool: string, spec: ?string}
     */
    public static function parse(string $rule): ?array
    {
        if (!preg_match('/^([^()\s]+)(?:\((.*)\))?$/s', trim($rule), $m)) {
            return null;
        }

        return ['tool' => $m[1], 'spec' => isset($m[2]) && $m[2] !== '*' ? $m[2] : null];
    }

    /**
     * Rule decision for a call. `decision`: `deny` / `ask` / `allow` (all parts allowed) / `none` (no allow rule
     * for the parts in `missing`). For `deny`/`ask`/`allow` (whole tool) — the rule, file and the command part that matched.
     *
     * @return array{decision: string, rule: ?string, scope: ?string, file: ?string, segment: ?string, missing: list<string>}
     */
    public static function decide(string $tool, ?string $command, Settings $settings): array
    {
        $parts = $tool === 'Bash' && $command !== null ? self::segments($command) : [];
        foreach (['deny', 'ask'] as $behavior) {
            foreach ($settings->rules($behavior) as $r) {
                $hit = self::hit($r['rule'], $tool, $parts, true);
                if ($hit !== false) {
                    return ['decision' => $behavior, 'rule' => $r['rule'], 'scope' => $r['scope'], 'file' => $r['file'], 'segment' => $hit, 'missing' => []];
                }
            }
        }
        $allow = $settings->rules('allow');
        if ($tool !== 'Bash' || $command === null) {
            foreach ($allow as $r) {
                if (self::hit($r['rule'], $tool, [], false) !== false) {
                    return ['decision' => 'allow', 'rule' => $r['rule'], 'scope' => $r['scope'], 'file' => $r['file'], 'segment' => null, 'missing' => []];
                }
            }

            return ['decision' => 'none', 'rule' => null, 'scope' => null, 'file' => null, 'segment' => null, 'missing' => [$tool]];
        }
        $missing = [];
        foreach ($parts as $part) {
            $words = self::strip($part['words'], false);
            if ($words === [] || in_array($words[0], self::READ_ONLY, true)) {
                continue;
            }
            $ok = false;
            foreach ($allow as $r) {
                if (self::hit($r['rule'], 'Bash', [$part], false) !== false) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $missing[] = implode(' ', $words);
            }
        }

        return ['decision' => $missing === [] ? 'allow' : 'none', 'rule' => null, 'scope' => null, 'file' => null, 'segment' => null, 'missing' => array_values(array_unique($missing))];
    }

    /**
     * Whether the rule matches the call: false = no; null = the whole tool matches; string = the matching command part.
     *
     * @param list<array{words: list<string>}> $parts
     */
    private static function hit(string $rule, string $tool, array $parts, bool $denyOrAsk): string|null|false
    {
        $r = self::parse($rule);
        if ($r === null || !self::toolMatches($r['tool'], $tool, $denyOrAsk)) {
            return false;
        }
        if ($r['spec'] === null) {
            return null;
        }
        if ($tool !== 'Bash' || $r['tool'] !== 'Bash') {
            // path, domain and parameter rules are not matched (simplification)
            return false;
        }
        foreach ($parts as $part) {
            $text = implode(' ', self::strip($part['words'], $denyOrAsk));
            if ($text !== '' && self::bashMatches($r['spec'], $text)) {
                return $text;
            }
        }

        return false;
    }

    private static function toolMatches(string $pattern, string $tool, bool $denyOrAsk): bool
    {
        if ($pattern === $tool) {
            return true;
        }
        if (!str_contains($pattern, '*')) {
            // `mcp__server` = all tools of the server
            return str_starts_with($pattern, 'mcp__') && str_starts_with($tool, $pattern . '__');
        }
        // allow: glob only over the fixed prefix `mcp__<server>__`
        if (!$denyOrAsk && !preg_match('/^mcp__[^*]+__/', $pattern)) {
            return false;
        }

        return fnmatch($pattern, $tool);
    }

    /**
     * `$X` / `${…}` variables in a word (without command substitution — its result is not a variable).
     *
     * @param array<string, mixed> $token
     * @return list<string>
     */
    private static function variables(array $token): array
    {
        if (!($token['dyn'] ?? false) || ($token['opaque'] ?? false)) {
            return [];
        }
        preg_match_all('/\$(\{[^}]*\}|[A-Za-z_]\w*)/', (string) $token['v'], $m);

        return array_map(static fn (string $v): string => '$' . $v, $m[1]);
    }

    /** @return array{words: list<string>, dyn: list<string>, subst: bool, redirects: list<array{op: string, target: string}>, heredoc: bool} */
    private static function emptySegment(bool $subst): array
    {
        return ['words' => [], 'dyn' => [], 'subst' => $subst, 'redirects' => [], 'heredoc' => false];
    }
}
