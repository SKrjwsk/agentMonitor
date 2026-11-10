<?php

declare(strict_types=1);

namespace Viz\Permissions;

use Viz\Extract\BashLexer;

/**
 * Która reguła `permissions` zdecydowała o wywołaniu — według dokumentacji Claude Code (code.claude.com/docs/en/permissions, 2026-11-09):
 * - kolejność: deny, potem ask, potem allow; pierwsze dopasowanie w tej kolejności decyduje, szczegółowość reguły jej nie zmienia
 *   (pasujące `ask` pyta, nawet gdy pasuje też węższe `allow`)
 * - komenda złożona (`&&`, `||`, `;`, `|`, `&`, nowa linia; także podstawienie i podpowłoka): deny/ask — gdy pasuje którakolwiek część,
 *   allow — każda część musi być dozwolona
 * - `Tool`, `Tool(*)` = całe narzędzie; Bash: `*` = dowolny tekst, końcowe ` *` (jedyna gwiazdka) pasuje też do samej komendy,
 *   `:*` na końcu = ` *`; przed dopasowaniem zdejmowane nakładki `timeout`, `time`, `nice`, `nohup`, `stdbuf`, `command`, `builtin`,
 *   `noglob`, gołe `xargs`; deny/ask pasują też po przypisaniu zmiennej na początku (`FOO=bar rm …`)
 *
 * Uproszczenia (świadome): słowa komendy sklejane pojedynczą spacją po zdjęciu cudzysłowów (BashLexer), `$(…)` w cudzysłowie nie jest
 * rozbijane na części; allow nie zdejmuje przypisań (lista „bezpiecznych” zmiennych Claude Code nie jest opublikowana); wbudowany zbiór
 * komend tylko do odczytu = lista z dokumentacji (bez form `git`, bez wyjątków z przekierowaniem i `cd` + `git`); reguły ścieżek
 * (`Read(…)`, `Edit(…)`), `WebFetch(domain:…)` i parametrów (`Tool(param:value)`) nie są dopasowywane — działa tylko nazwa narzędzia.
 */
final class RuleMatcher
{
    /** Komendy tylko do odczytu wymienione w dokumentacji (zbiór Claude Code jest większy i nieopublikowany w całości). */
    public const READ_ONLY = ['ls', 'cat', 'echo', 'pwd', 'head', 'tail', 'grep', 'find', 'wc', 'which', 'diff', 'stat', 'du', 'cd'];
    private const WRAPPERS = ['timeout', 'time', 'nice', 'nohup', 'stdbuf', 'command', 'builtin', 'noglob'];
    /** Słowa sterujące powłoki przed komendą (`do git …`, `then rm …`) — komenda w ciele pętli i warunku też jest częścią. */
    private const KEYWORDS = ['do', 'then', 'else', 'elif', 'if', 'while', 'until', '!', '{', '}', 'done', 'fi'];
    private const SEPARATORS = ['&&', '||', ';', '|', '&', "\n", '(', '$(', ')'];

    /**
     * Części komendy złożonej. `words` = słowa bez celów przekierowań; `dyn` = zmienne `$X` / `${…}` w słowach i celach przekierowań (bez podstawienia);
     * `subst` = część wewnątrz `$(…)` / backticku; `redirects` = cele `>`, `>>`, `<`.
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
     * Słowa części po zdjęciu słów sterujących i nakładek; `$assignments` = zdejmij też przypisania zmiennych na początku (deny/ask).
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
                // `timeout 30 …` — czas trwania
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

    /** Wzorzec reguły Bash (to, co w nawiasie) a tekst jednej części komendy. */
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
     * Rozbiór reguły `Tool` / `Tool(spec)`; null = niepoprawna.
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
     * Decyzja reguł dla wywołania. `decision`: `deny` / `ask` / `allow` (wszystkie części dozwolone) / `none` (brak reguły allow
     * dla części z `missing`). Przy `deny`/`ask`/`allow` (całe narzędzie) — reguła, plik i część komendy, która pasowała.
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
     * Czy reguła pasuje do wywołania: false = nie; null = pasuje całe narzędzie; string = pasująca część komendy.
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
            // reguły ścieżek, domen i parametrów nie są dopasowywane (uproszczenie)
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
            // `mcp__serwer` = wszystkie narzędzia serwera
            return str_starts_with($pattern, 'mcp__') && str_starts_with($tool, $pattern . '__');
        }
        // allow: glob tylko po stałym prefiksie `mcp__<serwer>__`
        if (!$denyOrAsk && !preg_match('/^mcp__[^*]+__/', $pattern)) {
            return false;
        }

        return fnmatch($pattern, $tool);
    }

    /**
     * Zmienne `$X` / `${…}` w słowie (bez podstawienia komendy — jego wynik to nie zmienna).
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
