<?php

declare(strict_types=1);

namespace Viz\Permissions;

use Viz\Model\Event;
use Viz\Model\Session;
use Viz\Model\Touch;
use Viz\Summary\SummaryStore;

/**
 * Zakładka „Zgody” sesji: wywołania, przy których Claude Code pytał usera o zgodę (albo prawdopodobnie pytał), z przyczyną.
 *
 * Skąd wiadomo, że pytał (`how`; transkrypt nie zapisuje samego pytania):
 * - `hook` — zdarzenie `PermissionRequest` albo `Notification: permission_prompt` z hooka (`HookLog::state`) między startem a końcem
 *   wywołania tego agenta; prośba bez pasującego wywołania = osobny wiersz bez `id`
 * - `rejected` — odrzucone przez usera (`Event::denial` z `by` = `user`)
 * - `waited` — bez blokady, trwało ≥ `LiveState::PERMISSION_GUESS` (`SummaryStore::waited`, także Bash — `waits` w agregacie ma dla Bash próg 10 min);
 *   długa komenda bez pytania wygląda tak samo
 * Zgoda udzielona szybciej niż próg, bez hooka, jest niewidoczna.
 *
 * Przyczyna (`causes`, poziom `rule` / `heuristic` / `unknown`): pasująca reguła `ask` z ustawień (`RuleMatcher`) — pewna w granicach
 * dopasowania; zapis w chronionej ścieżce (`PROTECTED_DIRS`, `PROTECTED_FILES`) oraz wzorce z komunikatów i dokumentacji Claude Code
 * rozpoznane w treści komendy (`cd` + `git`, zmienna, przekierowanie poza katalog sesji albo do nieznanego katalogu, `cd` poza katalog sesji)
 * i brak reguły allow — domysł; nic z tego — nieznana.
 * Katalog sesji = jedyny znany katalog roboczy (dodatkowe z `--add-dir` / `additionalDirectories` nie są znane).
 *
 * Tryb uprawnień wiersza (`mode`): `permission_mode` prośby z hooka, inaczej tryb z ostatniej wiadomości usera przed wywołaniem
 * (`Session::modes`, transkrypt); subagent — tryb sesji głównej (dokumentacja: `permissionMode` subagenta jest pomijany, jego wywołania
 * przechodzą tę samą kolejność decyzji co sesja). `modeEffect` = skutek trybu dla przyczyny, tylko wprost z dokumentacji.
 */
final class Approvals
{
    public const HOOK = 'hook';
    public const WAITED = 'waited';
    public const REJECTED = 'rejected';
    /** Komenda wyjęta z „najczęściej pytanych”, gdy w części jest więcej niż program (powłoka, przypisania). */
    private const NOT_SHAPE = ['export', 'set', 'unset', 'source', '.', 'true', 'false', ':'];
    /**
     * Chronione ścieżki według code.claude.com/docs/en/permission-modes „Protected paths” (2026-11-09): zapisu nie zatwierdza automatycznie
     * żaden tryb poza `bypassPermissions`, reguły allow tego nie zmieniają (`default`, `acceptEdits` — pytanie, `auto` — klasyfikator,
     * `dontAsk` — odmowa). Dotyczy narzędzi zapisu plików i celów przekierowań `>` w Bash (permissions „Redirections”).
     * Dopasowanie po składnikach ścieżki na dowolnym poziomie. Pominięte: wpisy zależne od uruchomienia (`--plugin-dir`, `--settings`,
     * pliki ustawień-dowiązania) i konfiguracja npm z listy dokumentacji (jej nazwy nie da się zapisać w kodzie — hook sekretów blokuje).
     */
    private const PROTECTED_DIRS = ['.git', '.config/git', '.vscode', '.idea', '.husky', '.cargo', '.devcontainer', '.yarn', '.mvn', '.claude'];
    private const PROTECTED_FILES = ['.gitconfig', '.gitmodules', '.bashrc', '.bash_profile', '.bash_login', '.bash_aliases', '.bash_logout', '.zshrc', '.zprofile',
        '.zshenv', '.zlogin', '.zlogout', '.profile', '.envrc', '.yarnrc', '.yarnrc.yml', '.pnp.cjs', '.pnp.loader.mjs', '.pnpmfile.cjs', 'bunfig.toml',
        '.bunfig.toml', '.bazelrc', '.bazelversion', '.bazeliskrc', '.pre-commit-config.yaml', 'lefthook.yml', 'lefthook.yaml', '.lefthook.yml', '.lefthook.yaml',
        'gradle-wrapper.properties', 'maven-wrapper.properties', '.devcontainer.json', '.ripgreprc', 'pyrightconfig.json', '.mcp.json', '.claude.json'];
    /**
     * Skutek trybu dla rodzaju przyczyny, wprost z code.claude.com/docs/en/permission-modes (2026-11-09); reszta par = brak wpisu
     * (pytanie albo nieustalone — widok nic nie dopisuje):
     * - `auto`: chronione ścieżki i wywołania bez reguły allow idą do klasyfikatora (reguły ask nadal pytają)
     * - `dontAsk`: każde wywołanie, które by pytało, jest odrzucane — także reguły ask i chronione ścieżki
     * - `bypassPermissions`: zapis w chronionej ścieżce dozwolony
     */
    private const MODE_EFFECTS = [
        'auto' => ['protected-path' => 'classifier', 'no-allow' => 'classifier'],
        'dontAsk' => ['ask-rule' => 'denied', 'protected-path' => 'denied', 'no-allow' => 'denied', 'cd-git' => 'denied', 'cd-outside' => 'denied',
            'variable' => 'denied', 'redirect' => 'denied'],
        'bypassPermissions' => ['protected-path' => 'allowed'],
    ];
    /** Narzędzia zapisu plików, których ścieżkę porównuje się z chronionymi. */
    private const WRITE_TOOLS = ['Write', 'Edit', 'MultiEdit', 'NotebookEdit'];

    /**
     * @param list<Event> $events wywołania w zakresie filtrów widoku
     * @param list<array{ts: string, event: string, agent: string, detail: ?string, commands?: list<string>, rules?: list<string>, mode?: ?string}> $hooks `HookLog::state`
     * @param list<array{ts: string, mode: string}> $modes `Session::modes`
     * @return list<array<string, mixed>> wiersze od najnowszego
     */
    public static function rows(array $events, array $hooks, Settings $settings, ?string $cwd, int $now, array $modes = []): array
    {
        $asks = array_values(array_filter($hooks, static fn (array $h): bool => $h['event'] === 'PermissionRequest'
            || ($h['event'] === 'Notification' && $h['detail'] === 'permission_prompt')));
        $used = [];
        $rows = [];
        foreach ($events as $e) {
            if ($e->tool === Event::TOOL_CONTEXT) {
                continue;
            }
            $how = [];
            $request = null;
            $from = self::time($e->ts);
            $to = $e->endTs !== null ? self::time($e->endTs) : (float) $now;
            foreach ($asks as $k => $h) {
                $at = self::time($h['ts']);
                if ($h['agent'] !== $e->agentId || $at < $from - 1 || $at > $to + 1
                    || ($h['event'] === 'PermissionRequest' && $h['detail'] !== null && $h['detail'] !== $e->tool)) {
                    continue;
                }
                $used[$k] = true;
                $how[self::HOOK] = true;
                if ($h['event'] === 'PermissionRequest') {
                    $request ??= $h;
                }
            }
            if (($e->denial['by'] ?? null) === 'user') {
                $how[self::REJECTED] = true;
            }
            if (SummaryStore::waited($e)) {
                $how[self::WAITED] = true;
            }
            if ($how === []) {
                continue;
            }
            $writes = in_array($e->tool, self::WRITE_TOOLS, true)
                ? array_values(array_map(static fn (Touch $t): string => $t->path, array_filter($e->touches, static fn (Touch $t): bool => $t->op === Touch::EDIT || $t->op === Touch::WRITE)))
                : [];
            $rows[] = self::row($e->ts, $e->agentId, $e->tool, $e->toolUseId, $e->summary, $e->command, $e->cwd ?? $cwd, $e->commands, array_keys($how),
                SummaryStore::durationMs($e), $request, $settings, $request['mode'] ?? self::modeAt($modes, $e->ts), $writes);
        }
        foreach ($asks as $k => $h) {
            if (!isset($used[$k]) && $h['event'] === 'PermissionRequest') {
                $rows[] = self::row($h['ts'], $h['agent'], (string) ($h['detail'] ?? '?'), null, implode(' · ', $h['commands'] ?? []), null, $cwd, [], [self::HOOK], null, $h, $settings,
                    $h['mode'] ?? self::modeAt($modes, $h['ts']));
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($b['ts'], $a['ts']));

        return $rows;
    }

    /**
     * „Najczęściej pytane”: kształt komendy (program + podkomenda) albo narzędzie → liczba, przyczyny najczęstszego wariantu
     * (po pierwszej przyczynie i regule) i ostatnie wystąpienie (`last` — wiersz z `rows`: czas, wywołanie, sesja).
     *
     * @param list<array<string, mixed>> $rows od najnowszego
     * @return list<array{shape: string, count: int, causes: list<array<string, mixed>>, last: array<string, mixed>}>
     */
    public static function top(array $rows, int $limit = 8): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $g = $groups[$r['shape']] ?? ['shape' => $r['shape'], 'count' => 0, 'causes' => [], 'last' => $r];
            $g['count']++;
            $key = $r['causes'][0]['kind'] . ':' . ($r['causes'][0]['rule'] ?? '');
            $g['causes'][$key] ??= ['n' => 0, 'causes' => $r['causes']];
            $g['causes'][$key]['n']++;
            $groups[$r['shape']] = $g;
        }
        $out = [];
        foreach ($groups as $g) {
            uasort($g['causes'], static fn (array $a, array $b): int => $b['n'] <=> $a['n']);
            $best = reset($g['causes']);
            $out[] = ['shape' => $g['shape'], 'count' => $g['count'], 'causes' => $best['causes'], 'last' => $g['last']];
        }
        usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['shape'], $b['shape']));

        return array_slice($out, 0, $limit);
    }

    /**
     * Przyczyny wywołania od najpewniejszej. Bash bez treści komendy (wiersz tylko z hooka) — tylko reguły po nazwie narzędzia.
     * Zapis w chronionej ścieżce ma pierwszeństwo przed „brak reguły allow” (allow go nie zatwierdza).
     *
     * @param list<string> $writes ścieżki zapisywane przez `Write` / `Edit` / `NotebookEdit`
     * @return list<array{level: string, kind: string, detail: ?string, rule?: string, file?: string, scope?: string}>
     */
    public static function causes(string $tool, ?string $command, ?string $cwd, Settings $settings, array $writes = []): array
    {
        $out = [];
        $d = RuleMatcher::decide($tool, $command, $settings);
        if ($d['decision'] === 'ask' || $d['decision'] === 'deny') {
            $out[] = ['level' => 'rule', 'kind' => $d['decision'] . '-rule', 'detail' => $d['segment'], 'rule' => (string) $d['rule'], 'file' => (string) $d['file'], 'scope' => (string) $d['scope']];
        }
        foreach ($writes as $w) {
            if (self::protectedPath($w)) {
                $out['protected-path'] = ['level' => 'heuristic', 'kind' => 'protected-path', 'detail' => $w];
                break;
            }
        }
        if ($tool === 'Bash' && $command !== null) {
            $parts = RuleMatcher::segments($command);
            $cdTo = null;
            $vars = [];
            $assigned = [];
            $redirect = null;
            foreach ($parts as $p) {
                foreach ($p['words'] as $w) {
                    if (preg_match('/^([A-Za-z_]\w*)=/', $w, $m)) {
                        $assigned[] = '$' . $m[1];
                        // zapis do zmiennych specjalnych powłoki — Claude Code pyta zawsze (dokumentacja: PATH, IFS)
                        if ($m[1] === 'PATH' || $m[1] === 'IFS') {
                            $vars[] = $m[1];
                        }
                    }
                }
                $words = RuleMatcher::strip($p['words'], true);
                if (($words[0] ?? '') === 'for' && isset($words[1])) {
                    $assigned[] = '$' . $words[1];
                }
                if (($words[0] ?? '') === 'cd' && isset($words[1]) && self::otherDir($words[1], $cwd)) {
                    $cdTo = $words[1];
                }
                if ($cdTo !== null && ($words[0] ?? '') === 'git' && !isset($out['cd-git'])) {
                    $out['cd-git'] = ['level' => 'heuristic', 'kind' => 'cd-git', 'detail' => $cdTo];
                }
                // `$(cat <<'EOF' …)` — typowy opis commita, nie zmienna
                if (!($p['subst'] && ($words[0] ?? '') === 'cat' && $p['heredoc'])) {
                    $vars = [...$vars, ...$p['dyn']];
                }
                foreach ($p['redirects'] as $r) {
                    $t = $r['target'];
                    if ($r['op'] !== '<' && !isset($out['protected-path']) && self::protectedPath($t)) {
                        $out['protected-path'] = ['level' => 'heuristic', 'kind' => 'protected-path', 'detail' => $t];
                    }
                    // cel względny po `cd`, cel ze zmienną albo bezwzględny poza katalogiem sesji
                    if ($r['op'] !== '<' && !str_starts_with($t, '/dev/')
                        && (str_contains($t, '$') || ($cdTo !== null && !str_starts_with($t, '/')) || self::outside($t, $cwd))) {
                        $redirect ??= $t;
                    }
                }
            }
            // zmienna przypisana w tej samej komendzie (`X=…`, `for X in`) ma znaną wartość
            $vars = array_values(array_diff(array_unique($vars), array_diff($assigned, ['$PATH', '$IFS'])));
            if ($vars !== []) {
                $out[] = ['level' => 'heuristic', 'kind' => 'variable', 'detail' => implode(' ', array_slice($vars, 0, 4))];
            }
            if ($redirect !== null) {
                $out[] = ['level' => 'heuristic', 'kind' => 'redirect', 'detail' => $redirect];
            }
            // `cd` poza katalog sesji bez `git` (dokumentacja: `cd` jest tylko do odczytu wewnątrz katalogów roboczych)
            if ($cdTo !== null && !isset($out['cd-git']) && self::outside($cdTo, $cwd)) {
                $out[] = ['level' => 'heuristic', 'kind' => 'cd-outside', 'detail' => $cdTo];
            }
        }
        if ($out === [] && $d['decision'] === 'none') {
            $out[] = ['level' => 'heuristic', 'kind' => 'no-allow', 'detail' => implode(' · ', array_slice($d['missing'], 0, 3))];
        }

        return $out === [] ? [['level' => 'unknown', 'kind' => 'unknown', 'detail' => null]] : array_values($out);
    }

    /**
     * @param list<\Viz\Model\Command> $commands
     * @param list<string> $how
     * @param ?array{commands?: list<string>, rules?: list<string>} $request
     * @param list<string> $writes
     * @return array<string, mixed>
     */
    private static function row(string $ts, string $agent, string $tool, ?string $id, string $summary, ?string $command, ?string $cwd, array $commands,
        array $how, ?int $ms, ?array $request, Settings $settings, ?string $mode, array $writes = []): array
    {
        $causes = self::causes($tool, $command, $cwd, $settings, $writes);
        $shape = self::shape($tool, $causes[0], $commands, $request['commands'] ?? []);
        $suggested = $request['rules'] ?? [];

        return ['ts' => $ts, 'agent' => $agent, 'tool' => $tool, 'id' => $id, 'summary' => $summary, 'how' => $how, 'ms' => $ms,
            'shape' => $shape, 'causes' => $causes, 'suggested' => $suggested, 'mode' => $mode];
    }

    /**
     * Tryb w chwili `$ts`: ostatni wpis `Session::modes` nie późniejszy niż `$ts`; przed pierwszym albo bez wpisów = null.
     *
     * @param list<array{ts: string, mode: string}> $modes od najstarszego
     */
    public static function modeAt(array $modes, string $ts): ?string
    {
        $at = self::time($ts);
        $mode = null;
        foreach ($modes as $m) {
            if (self::time($m['ts']) > $at) {
                break;
            }
            $mode = $m['mode'];
        }

        return $mode;
    }

    /** Skutek trybu dla rodzaju przyczyny (`MODE_EFFECTS`): `classifier` / `denied` / `allowed`; null = pytanie albo nieustalone. */
    public static function modeEffect(?string $mode, string $kind): ?string
    {
        return $mode === null ? null : self::MODE_EFFECTS[$mode][$kind] ?? null;
    }

    /**
     * Tryby wierszy z liczbą, od najczęstszego; wiersz bez trybu (brak w transkrypcie i hooku, agregat sprzed `PARSER` 19) = klucz ''.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    public static function modeCounts(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = (string) ($r['mode'] ?? '');
            $out[$k] = ($out[$k] ?? 0) + 1;
        }
        arsort($out);

        return $out;
    }

    /**
     * Kształt komendy do grupowania: stały początek pasującej reguły (`git commit`), inaczej program + podkomenda pierwszego programu
     * spoza komend tylko do odczytu, inaczej kształt z hooka, inaczej pierwszy program poza `cd`; inne narzędzia — nazwa narzędzia.
     *
     * @param array<string, mixed> $cause
     * @param list<\Viz\Model\Command> $commands
     * @param list<string> $hookCommands
     */
    private static function shape(string $tool, array $cause, array $commands, array $hookCommands): string
    {
        if ($tool !== 'Bash') {
            return $tool;
        }
        // reguła z ustawień: jej stały początek (`Bash(git commit:*)` → `git commit`)
        $spec = isset($cause['rule']) ? RuleMatcher::parse((string) $cause['rule'])['spec'] ?? null : null;
        if ($spec !== null) {
            $prefix = trim((string) preg_replace('/(:\*|\*).*$/s', '', $spec));
            if ($prefix !== '') {
                return $prefix;
            }
        }
        $fallback = null;
        foreach ($commands as $c) {
            if (!in_array($c->program, RuleMatcher::READ_ONLY, true) && !in_array($c->program, self::NOT_SHAPE, true)) {
                return $c->program . ($c->sub !== null ? ' ' . $c->sub : '');
            }
            $fallback ??= $c->program !== 'cd' ? $c->program : null;
        }

        return $hookCommands[0] ?? $fallback ?? 'Bash';
    }

    /**
     * Ścieżka chroniona (`PROTECTED_DIRS` jako składnik ścieżki, `PROTECTED_FILES` jako nazwa pliku). Wyjątki `.claude` z dokumentacji:
     * `worktrees/`, `plans/`, `jobs/<id>/tmp/`, pliki `.md` w `projects/<projekt>/memory/` i `agent-memory/`.
     */
    public static function protectedPath(string $path): bool
    {
        $parts = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== '' && $s !== '.'));
        if (in_array(end($parts) ?: '', self::PROTECTED_FILES, true)) {
            return true;
        }
        $md = str_ends_with($path, '.md');
        foreach ($parts as $i => $part) {
            $rest = array_slice($parts, $i + 1);
            if ($rest === []) {
                break;
            }
            if ($part === '.claude') {
                $exception = in_array($rest[0], ['worktrees', 'plans'], true)
                    || ($rest[0] === 'jobs' && ($rest[2] ?? null) === 'tmp')
                    || ($md && $rest[0] === 'projects' && ($rest[2] ?? null) === 'memory')
                    || ($md && $rest[0] === 'agent-memory');
                if (!$exception) {
                    return true;
                }
                continue;
            }
            if (in_array($part, self::PROTECTED_DIRS, true) || ($part === '.config' && $rest[0] === 'git' && count($rest) > 1)) {
                return true;
            }
        }

        return false;
    }

    /** `cd` do katalogu innego niż katalog sesji (względny `cd` = zawsze inny; `cd .` nie). */
    private static function otherDir(string $target, ?string $cwd): bool
    {
        if ($target === '.' || $target === '') {
            return false;
        }
        if ($cwd === null || !str_starts_with($target, '/')) {
            return true;
        }

        return rtrim($target, '/') !== rtrim($cwd, '/');
    }

    /** Cel przekierowania poza katalogiem sesji (względny = w nim). */
    private static function outside(string $target, ?string $cwd): bool
    {
        if (!str_starts_with($target, '/')) {
            return false;
        }
        if ($cwd === null) {
            return true;
        }
        $cwd = rtrim($cwd, '/');

        return $target !== $cwd && !str_starts_with($target, $cwd . '/');
    }

    private static function time(string $ts): float
    {
        try {
            return (float) (new \DateTimeImmutable($ts))->format('U.u');
        } catch (\Exception) {
            return 0.0;
        }
    }
}
