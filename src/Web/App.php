<?php

declare(strict_types=1);

namespace Viz\Web;

use Viz\Boundary;
use Viz\Clock;
use Viz\Export\Csv;
use Viz\Export\SessionExport;
use Viz\Judge\Patterns;
use Viz\Judge\SessionJudge;
use Viz\Office\Feed;
use Viz\Office\LiveState;
use Viz\Model\Agent;
use Viz\Model\Event;
use Viz\Model\Session;
use Viz\Model\Touch;
use Viz\Path;
use Viz\Permissions\Approvals;
use Viz\Permissions\Settings;
use Viz\Problems;
use Viz\Signals;
use Viz\Source\HookLog;
use Viz\Source\SessionIndex;
use Viz\Source\TranscriptReader;
use Viz\Stats;
use Viz\Summary\CommandRanking;
use Viz\Summary\LiveSummary;
use Viz\Summary\Ranking;
use Viz\Summary\SummaryStore;

/** Kontroler: tylko GET, parametry walidowane białą listą, żaden parametr nie jest ścieżką. */
final class App
{

    /** `s=all` — zakres „wszystkie sesje”: te same zakładki co sesja, dane z agregatów var/summary. */
    public const ALL = 'all';

    /** Wiersze tabel w zakresie „all” bez `limit=all` (tysiące plików). */
    private const ALL_ROWS = 300;

    /** Lewy panel pokazuje sesje wszystkich projektów — wybór projektu filtruje listę w przeglądarce. */
    private const SIDEBAR_LIMIT = 300;

    /** Objaśnienia w panelu szczegółów: co znaczy operacja na pliku z punktu widzenia agenta. */
    public const OP_HELP = [
        Touch::READ => 'the content reached the agent (Read, cat, sed, head…)',
        Touch::CONTEXT => 'Claude Code loaded it into the context by itself (CLAUDE.md, rules, @file) — not a tool call',
        Touch::SEARCH => 'searched — the agent saw only the matching lines (Grep, grep, rg)',
        Touch::LIST => 'listed — the agent saw names or metadata, not the content (ls, Glob, find, wc, stat)',
        Touch::EDIT => 'changed in place (Edit, sed -i)',
        Touch::WRITE => 'created or overwritten (Write, > file, cp, mv)',
        Touch::EXEC => 'run as a program or given to one — the agent saw its output, not the file',
    ];

    /** Kontekst programu w komendzie Bash (Command::$contexts). */
    public const CONTEXT_HELP = [
        'pipe' => 'right side of a pipe |',
        'subst' => 'inside $(…)',
        'xargs' => 'run by xargs',
        'inner' => 'inside bash -c / sh -c',
        'remote' => 'run on another machine (ssh)',
        'stdin-script' => 'script fed through stdin (heredoc)',
    ];

    /** Co robi narzędzie — jedno zdanie do panelu wywołania. */
    public static function toolHelp(string $tool): string
    {
        return match (true) {
            $tool === Event::TOOL_CONTEXT => t('not a tool call: Claude Code put this file into the agent context by itself'),
            $tool === 'Bash' => t('shell command run by the agent'),
            in_array($tool, ['Read', 'NotebookRead'], true) => t('file read tool'),
            in_array($tool, ['Edit', 'MultiEdit', 'NotebookEdit'], true) => t('file edit tool'),
            $tool === 'Write' => t('file write tool (creates or overwrites the whole file)'),
            $tool === 'Grep' => t('content search tool'),
            $tool === 'Glob' => t('file name search tool'),
            in_array($tool, ['Agent', 'Task'], true) => t('started a subagent with a task'),
            $tool === 'SubagentHandback' => t('a subagent sent its reply back'),
            $tool === 'SendMessage' => t('message to another agent or session'),
            $tool === 'Skill' => t('loaded a skill (instructions)'),
            str_starts_with($tool, 'mcp__') => t('tool of an MCP server'),
            default => t('tool call'),
        };
    }

    public const OP_LABELS = [Touch::READ => 'read', Touch::CONTEXT => 'context', Touch::EDIT => 'edit', Touch::WRITE => 'write', Touch::SEARCH => 'search', Touch::LIST => 'listing', Touch::EXEC => 'exec'];

    public function handle(string $path, array $query, string $method = 'GET'): void
    {
        if ($method === 'POST') {
            match ($path) {
                '/export' => $this->export($query),
                '/judge' => $this->judge($query),
                default => $this->notFound(),
            };
            return;
        }
        // widoki przeglądowe (ScopeViews): strona i dane JSON, zakres projektów z Scope
        if (($view = ScopeViews::page($path)) !== null) {
            $this->scopePage($view, $query);
            return;
        }
        if (ScopeViews::isData($path)) {
            $this->scopeData($path, Scope::fromRequest($query, $_COOKIE));
            return;
        }
        match ($path) {
            '/' => $query === [] ? $this->redirect(ScopeViews::last($_COOKIE)) : $this->page($query),
            '/session' => $this->fragment($query),
            '/version' => $this->version($query),
            '/summary' => $this->summaryRedirect($query),
            '/export.csv' => $this->csv($query),
            '/judge.json' => $this->judgeStatus($query),
            default => $this->notFound(),
        };
    }

    public static function fileHash(string $path): string
    {
        return substr(sha1(Path::key($path)), 0, 12);
    }

    private function page(array $q): void
    {
        $sessions = SessionIndex::recent(self::SIDEBAR_LIMIT);
        $view = $this->view($q);
        // bez sesji: strona startowa „Overview” (przelicza agregaty, więc przed odczytem kosztów do lewego panelu)
        $overview = $view === null ? $this->overview($q) : null;
        $scope = Scope::fromRequest($q, $_COOKIE);
        $sidebarTokens = SummaryStore::tokenTotals(array_column($sessions, 'id'));
        // otwarta sesja: liczniki z transkryptu, agregat bywa sprzed ostatnich wywołań
        if (isset($view['session']) && $view['session']->usage !== []) {
            $all = $view['sessionTokens'];
            $sidebarTokens[$view['sid']] = ['usd' => $all['unpriced'] < $all['calls'] ? $all['usd'] : null, 'output' => $all['output'], 'partial' => $all['outputPartial'] > 0, 'upTo' => null];
        }
        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__, 2) . '/templates/layout.php';
    }

    private function fragment(array $q): void
    {
        $view = $this->view($q);
        header('Content-Type: text/html; charset=utf-8');
        if ($view === null) {
            echo '<section viz-session><p class="empty">' . th('No session.') . '</p></section>';
            return;
        }
        require dirname(__DIR__, 2) . '/templates/session.php';
    }

    private function version(array $q): void
    {
        $p = $this->params($q);
        $sid = $this->resolveSessionId($p);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        if ($sid === null) {
            echo '-';
            return;
        }
        if ($sid === self::ALL) {
            echo $p['recent'] !== '' ? LiveSummary::version(LiveSummary::WINDOWS[$p['recent']]) : '-';
            return;
        }
        echo $sid, ':', $this->sourceVersion($p['src'], $sid);
    }

    /**
     * Zrzut sesji do var/session_dump/<nazwa>/ — ten sam widok (źródło, filtr agenta) co na ekranie.
     * Tylko POST z własnej strony (index.php sprawdza pochodzenie); po zapisie przekierowanie z nazwą zrzutu.
     */
    private function export(array $q): void
    {
        $view = $this->view($q);
        if ($view === null || $view['missing'] || $view['allSessions']) {
            $this->notFound();
            return;
        }
        header('Location: /?' . self::query($view, ['dumped' => $this->dump($view)]), true, 303);
    }

    /** Zrzut sesji z bieżącym źródłem i filtrami do `var/session_dump/<nazwa>/`; zwraca nazwę. */
    private function dump(array $view): string
    {
        $name = SessionExport::dumpName($view['session'], $view['agentFilter']);
        (new SessionExport())->writeDir(
            SessionExport::dumpRoot() . '/' . $name,
            $view['session'], $view['agentEvents'], $view['stats'], $view['title'], $view['p']['src'], $view['agentFilter'],
            array_filter(['turn' => $view['turn'] ?: null, 'scope' => $view['p']['scope'] ?: null, 'problems' => $view['p']['problems'] ?: null]),
        );

        return $name;
    }

    /** Czy ocena sesji trwa — tani odczyt pliku `.running` bez parsowania transkryptu (zakładka „Ocena” pyta co 5 s). */
    private function judgeStatus(array $q): void
    {
        $sid = is_string($q['s'] ?? null) ? $q['s'] : '';
        header('Content-Type: application/json');
        echo json_encode(['running' => HookLog::validSessionId($sid) && SessionJudge::running($sid, time()) !== null]);
    }

    /** Ocena sesji na żądanie: zrzut całej sesji (bez filtrów widoku) i sędzia w tle (SessionJudge) → zakładka „Ocena”. */
    private function judge(array $q): void
    {
        $sid = is_string($q['s'] ?? null) ? $q['s'] : '';
        $view = HookLog::validSessionId($sid) ? $this->view(['s' => $sid, 'view' => 'judge']) : null;
        if ($view === null || $view['missing'] || $view['allSessions']) {
            $this->notFound();
            return;
        }
        $started = SessionJudge::start($sid, SessionExport::dumpRoot() . '/' . $this->dump($view), time());
        header('Location: /?' . http_build_query(['s' => $sid, 'view' => 'judge'] + ($started ? [] : ['busy' => '1'])), true, 303);
    }

    /** Stary adres podsumowania → zakres „all” z przemapowanymi parametrami (zakładki zastąpiły `kind`). */
    private function summaryRedirect(array $q): void
    {
        $str = static fn (string $k): string => is_string($q[$k] ?? null) ? $q[$k] : '';
        $target = array_filter([
            's' => self::ALL,
            'view' => match ($str('kind')) { 'commands' => 'commands', 'blocked' => 'blocked', default => '' },
            'pick' => $str('file'),
            'pcmd' => $str('cmd'),
            'sort' => in_array($str('sort'), ['path', 'sessions'], true) ? $str('sort') : '',
            // stary filtr projektu → zakres projektów (Scope)
            Scope::PARAM => $str('project'),
        ] + array_intersect_key(array_map('strval', array_filter($q, 'is_string')), array_flip(['days', 'recent', 'find', 'exclude', 'scope', 'limit'])), static fn (string $v): bool => $v !== '');
        header('Location: /?' . http_build_query($target), true, 302);
    }

    /** CSV bieżącej zakładki z bieżącymi filtrami — w sesji i w zakresie „all”; cała lista, nie tylko pokazane wiersze. Bez `s` = wszystkie sesje. */
    private function csv(array $q): void
    {
        $view = $this->view($q + ['s' => self::ALL]);
        if ($view === null || $view['missing']) {
            $this->notFound();
            return;
        }
        if (!$view['allSessions']) {
            $this->sessionCsv($view);
            return;
        }
        $p = $view['p'];
        if ($p['view'] === 'log') {
            // Log „all” to strony najnowszych wywołań, nie cała lista — CSV tylko z sesji
            $this->notFound();
            return;
        }
        $period = $p['recent'] !== '' ? $p['recent'] : ($p['days'] > 0 ? $p['days'] . 'd' : 'all');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="agentmonitor-' . $p['view'] . '-' . Clock::local(Clock::nowUtc(), 'Ymd-His') . '-' . $period . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        if ($p['view'] === 'blocked') {
            fputcsv($out, ['time', 'blocked_by', 'rule', 'tool', 'call', 'session', 'project'], ',', '"', '');
            foreach ($view['blocked'] as $b) {
                fputcsv($out, [
                    Clock::local($b['ts'], 'Y-m-d H:i:s'), $b['by'], self::csvSafe((string) ($b['detail'] ?? '')), self::csvSafe((string) $b['tool']),
                    self::csvSafe($b['summary']), self::csvSafe($b['title'] !== '' ? $b['title'] : (string) $b['session_id']), self::csvSafe(basename($b['cwd'])),
                ], ',', '"', '');
            }
            fclose($out);
            return;
        }
        if ($p['view'] === 'approvals') {
            fputcsv($out, ['time', 'how', 'shape', 'causes', 'tool', 'call', 'session', 'project'], ',', '"', '');
            foreach ($view['approvals'] as $r) {
                fputcsv($out, [
                    Clock::local($r['ts'], 'Y-m-d H:i:s'), implode(' ', $r['how']), self::csvSafe($r['shape']),
                    self::csvSafe(implode('; ', array_map(static fn (array $c): string => $c['kind'] . (($c['rule'] ?? null) !== null ? ' ' . $c['rule'] : ''), $r['causes']))),
                    self::csvSafe($r['tool']), self::csvSafe($r['summary']), self::csvSafe($r['title'] !== '' ? $r['title'] : $r['session_id']), self::csvSafe(basename($r['cwd'])),
                ], ',', '"', '');
            }
            fclose($out);
            return;
        }
        if ($p['view'] === 'commands') {
            $top = static fn (array $m): string => implode(' ', array_map(static fn ($k, $n): string => $k . '×' . $n, array_keys(array_slice($m, 0, 5)), array_slice($m, 0, 5)));
            fputcsv($out, ['rank', 'program', 'role', 'count', 'sessions', 'failed_calls', 'blocked_calls', 'top_subcommands', 'piped_from', 'contexts', 'last'], ',', '"', '');
            foreach ($view['commands'] as $i => $c) {
                fputcsv($out, [
                    $i + 1, self::csvSafe($c['program']), $c['role'], $c['count'], count($c['sessions']), $c['failed'], $c['blocked'],
                    self::csvSafe($top($c['subs'])), self::csvSafe($top($c['from'])), self::csvSafe($top($c['contexts'])), Clock::local($c['last'], 'Y-m-d H:i:s'),
                ], ',', '"', '');
            }
            fclose($out);
            return;
        }
        $ops = [Touch::READ, Touch::CONTEXT, Touch::EDIT, Touch::WRITE, Touch::SEARCH, Touch::LIST, Touch::EXEC];
        fputcsv($out, ['rank', 'path', ...$ops, 'sessions', 'last', 'exists'], ',', '"', '');
        foreach ($view[$p['view'] === 'changed' ? 'changed' : 'read'] as $i => $f) {
            fputcsv($out, [
                $i + 1,
                self::csvSafe($f['path']),
                ...array_map(static fn (string $op): int => $f['ops'][$op] ?? 0, $ops),
                count($f['sessions']),
                Clock::local($f['last'], 'Y-m-d H:i:s'),
                $f['exists'] ? 1 : 0,
            ], ',', '"', '');
        }
        fclose($out);
    }

    /**
     * CSV zakładki jednej sesji. Messages: odpowiedzi subagentów bez treści (cytują pliki — reguła „bez treści plików”);
     * Log: wszystkie wywołania w zakresie filtrów.
     */
    private function sessionCsv(array $view): void
    {
        $p = $view['p'];
        /** @var Session $session */
        $session = $view['session'];
        $agent = static fn (string $id): string => isset($session->agents[$id]) ? self::agentLabel($session->agents[$id], true) : $id;
        $status = static fn (Event $e): string => $e->denial !== null ? 'blocked' : (!$e->failed ? 'ok' : ($e->executed() ? 'exit≠0' : 'tool error'));
        $tab = in_array($p['view'], ['read', 'changed', 'commands', 'blocked', 'messages'], true) ? $p['view'] : 'calls';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="agentmonitor-' . substr($view['sid'], 0, 8) . '-' . $tab . '-' . Clock::local(Clock::nowUtc(), 'Ymd-His') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        $row = static fn (array $cells) => fputcsv($out, array_map(static fn ($v): string => is_string($v) ? self::csvSafe($v) : (string) $v, $cells), ',', '"', '');
        $ops = [Touch::READ, Touch::CONTEXT, Touch::EDIT, Touch::WRITE, Touch::SEARCH, Touch::LIST, Touch::EXEC];
        if ($tab === 'read' || $tab === 'changed') {
            $row(['path', ...$ops, 'agents', 'first', 'last', 'exists']);
            foreach ($view[$tab] as $f) {
                $row([$f['path'], ...array_map(static fn (string $op): int => $f['ops'][$op] ?? 0, $ops),
                    implode('; ', array_map($agent, array_keys($tab === 'changed' ? $f['modAgents'] : $f['agents']))),
                    Clock::local($tab === 'changed' ? (string) $f['firstMod'] : $f['first'], 'Y-m-d H:i:s'), Clock::local($f['last'], 'Y-m-d H:i:s'), $f['exists'] ? 1 : 0]);
            }
        } elseif ($tab === 'blocked') {
            $row(['time', 'agent', 'blocked_by', 'rule', 'tool', 'call']);
            foreach ($view['blocked'] as $b) {
                $row([Clock::local($b['ts'], 'Y-m-d H:i:s'), $agent((string) $b['agent']), $b['by'], (string) ($b['detail'] ?? ''), $b['tool'], $b['summary']]);
            }
        } elseif ($tab === 'messages') {
            $row(['time', 'from', 'to', 'kind', 'text']);
            foreach ($view['messages'] as $m) {
                $row([Clock::local($m['ts'], 'Y-m-d H:i:s'), $m['from'] === 'user' ? 'user' : $agent($m['from']), $agent($m['to']), $m['kind'],
                    $m['kind'] === 'result' ? '(reply text not exported — it usually quotes files)' : $m['text']]);
            }
        } else {
            // Commands: wywołania z listy (filtr programu); pozostałe widoki: wszystkie wywołania w zakresie
            $events = $tab === 'commands' ? $view['bash'] : array_reverse($view['events']);
            $row(['time', 'end', 'agent', 'tool', 'status', 'blocked_by', 'call', 'programs', 'files']);
            foreach ($events as $e) {
                $row([Clock::local($e->ts, 'Y-m-d H:i:s'), $e->endTs !== null ? Clock::local($e->endTs, 'Y-m-d H:i:s') : '', $agent($e->agentId), $e->tool === Event::TOOL_CONTEXT ? 'context' : $e->tool, $status($e),
                    $e->denial !== null ? $e->denial['by'] . ($e->denial['detail'] !== null ? ': ' . $e->denial['detail'] : '') : '', $e->summary,
                    implode(' ', array_map(static fn ($c): string => $c->program, $e->commands)),
                    implode('; ', array_map(static fn (Touch $t): string => $t->op . ' ' . $t->path, $e->touches))]);
            }
        }
        fclose($out);
    }

    /**
     * Wpis zaczynający się od `/` albo `~` = prefiks ścieżki (pełnej albo z `~`); każdy inny = fragment w dowolnym miejscu.
     *
     * @param list<string> $excludes
     */
    private static function excluded(string $path, array $excludes): bool
    {
        $short = self::shortPath($path, null);
        foreach ($excludes as $x) {
            $hit = str_starts_with($x, '/') || str_starts_with($x, '~')
                ? stripos($path, $x) === 0 || stripos($short, $x) === 0
                : mb_stripos($short, $x) !== false;
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /** Arkusze wykonują komórki zaczynające się od = + - @ — dane są niezaufane. */
    private static function csvSafe(string $value): string
    {
        return Csv::safe($value);
    }

    /**
     * Zakres „all”: zakładki Files read / Files changed / Commands / Blocked z agregatów var/summary
     * (archiwum — zostaje po usunięciu transkryptów). Wiersze w tym samym kształcie co w sesji, bez pól, których agregaty nie znają.
     */
    private function allView(array $p): array
    {
        $store = new SummaryStore();
        $refreshed = $store->refresh();
        $archive = $store->all();
        $source = $p['recent'] !== '' ? LiveSummary::aggregates(LiveSummary::WINDOWS[$p['recent']]) : $archive;
        // sort „sessions” zostawia wszystkie pliki (także tylko szukane/listowane); kolejność ustala FileTable::sort
        $ranking = Ranking::build($source, $p['days'], $p['projects'], 'sessions', $p['scope'] === 'project' && !$p['projects']->all());
        $excludes = array_values(array_filter(array_map('trim', explode(',', $p['exclude'])), static fn (string $x): bool => $x !== ''));
        $files = [];
        foreach ($ranking['files'] as $f) {
            if (($p['find'] !== '' && mb_stripos(self::shortPath($f['path'], null), $p['find']) === false) || ($excludes !== [] && self::excluded($f['path'], $excludes))) {
                continue;
            }
            $o = $f['ops'];
            $files[] = [
                'path' => $f['path'], 'hash' => $f['hash'], 'ops' => $o,
                'reads' => ($o[Touch::READ] ?? 0) + ($o[Touch::CONTEXT] ?? 0), 'mods' => ($o[Touch::EDIT] ?? 0) + ($o[Touch::WRITE] ?? 0),
                'partialOnly' => false, 'agents' => [], 'modAgents' => [], 'first' => '', 'last' => $f['last'], 'firstMod' => null,
                'exists' => $f['exists'], 'blindEdit' => false, 'sessions' => $f['sessions'],
            ];
        }
        $readOps = $p['more'] ? [Touch::READ, Touch::CONTEXT, Touch::SEARCH, Touch::LIST, Touch::EXEC] : [Touch::READ, Touch::CONTEXT];
        $read = array_values(array_filter($files, static fn (array $f): bool => array_intersect_key($f['ops'], array_flip($readOps)) !== []));
        $changed = array_values(array_filter($files, static fn (array $f): bool => $f['mods'] > 0));

        $commands = CommandRanking::build($source, $p['days'], $p['projects'], match ($p['sort']) { 'path' => 'program', 'sessions' => 'sessions', 'time' => 'time', default => 'count' });
        $commands = array_values(array_filter($commands, static function (array $c) use ($p, $excludes): bool {
            if ($p['find'] !== '' && mb_stripos($c['program'] . ' ' . implode(' ', array_keys($c['subs'])) . ' ' . $c['role'], $p['find']) === false) {
                return false;
            }
            foreach ($excludes as $x) {
                if (strcasecmp($c['program'], $x) === 0 || strcasecmp($c['role'], $x) === 0) {
                    return false;
                }
            }

            return true;
        }));

        // log blokad ze wszystkich sesji w zakresie (okres, projekt), najnowsze na górze
        $blocked = [];
        $cutoff = $p['days'] > 0 ? gmdate('Y-m-d\TH:i:s', time() - $p['days'] * 86400) : '';
        foreach ($ranking['sessions'] as $sess) {
            foreach ((array) ($sess['blocked'] ?? []) as $b) {
                $hay = $b['tool'] . ' ' . $b['summary'] . ' ' . $b['by'] . ' ' . ($b['detail'] ?? '');
                if (($cutoff !== '' && $b['ts'] < $cutoff) || ($p['find'] !== '' && mb_stripos($hay, $p['find']) === false)) {
                    continue;
                }
                foreach ($excludes as $x) {
                    if (mb_stripos($hay, $x) !== false) {
                        continue 2;
                    }
                }
                $blocked[] = $b + ['session_id' => (string) $sess['session_id'], 'title' => (string) ($sess['title'] ?? ''), 'cwd' => (string) ($sess['cwd'] ?? ''), 'id' => null];
            }
        }
        usort($blocked, static fn (array $a, array $b): int => strcmp($b['ts'], $a['ts']));

        // pytania o zgodę ze wszystkich sesji w zakresie (agregaty od `PARSER` 18), najnowsze na górze
        $approvals = [];
        foreach ($ranking['sessions'] as $sess) {
            foreach ((array) ($sess['approvals'] ?? []) as $r) {
                $hay = $r['tool'] . ' ' . $r['summary'] . ' ' . $r['shape'] . ' ' . implode(' ', array_map(static fn (array $c): string => $c['kind'] . ' ' . ($c['rule'] ?? '') . ' ' . ($c['detail'] ?? ''), $r['causes']));
                if (($cutoff !== '' && $r['ts'] < $cutoff) || ($p['find'] !== '' && mb_stripos($hay, $p['find']) === false)) {
                    continue;
                }
                foreach ($excludes as $x) {
                    if (mb_stripos($hay, $x) !== false) {
                        continue 2;
                    }
                }
                $approvals[] = $r + ['session_id' => (string) $sess['session_id'], 'title' => (string) ($sess['title'] ?? ''), 'cwd' => (string) ($sess['cwd'] ?? '')];
            }
        }
        usort($approvals, static fn (array $a, array $b): int => strcmp($b['ts'], $a['ts']));

        $sessionsById = [];
        foreach ($ranking['sessions'] as $sess) {
            $sessionsById[(string) $sess['session_id']] = $sess;
        }

        return [
            'allSessions' => true,
            'missing' => false,
            'sid' => self::ALL,
            'p' => $p,
            'title' => $p['recent'] !== '' ? ($p['recent'] === '5m' ? t('All sessions — last 5 minutes') : t('All sessions — last hour')) : t('All sessions'),
            'agentFilter' => '',
            'agentColors' => [],
            'read' => FileTable::sort($read, $p['sort'] !== '' ? $p['sort'] : 'count', 'reads'),
            'changed' => FileTable::sort($changed, $p['sort'] !== '' ? $p['sort'] : 'count', 'mods'),
            'commands' => $commands,
            'blocked' => $blocked,
            'approvals' => $approvals,
            'approvalsTop' => Approvals::top($approvals),
            // Log z transkryptów (agregaty nie mają pojedynczych wywołań) — liczony tylko na swojej zakładce, bez licznika
            'log' => $p['view'] === 'log' ? self::allLog($ranking['sessions'], $p, $excludes) : [],
            'logPage' => self::ALL_ROWS,
            'counts' => ['read' => count($read), 'changed' => count($changed), 'commands' => count($commands), 'blocked' => count($blocked), 'approvals' => count($approvals)],
            'rowLimit' => self::rowLimit($p),
            'sessionsById' => $sessionsById,
            'archived' => count($archive),
            'withTranscript' => count(array_filter($archive, static fn (array $s): bool => TranscriptReader::locate((string) $s['session_id']) !== null)),
            'refreshed' => $refreshed,
            'live' => $p['recent'] !== '',
            'version' => $p['recent'] !== '' ? LiveSummary::version(LiveSummary::WINDOWS[$p['recent']]) : '-',
        ];
    }

    /**
     * Log zakresu „all”: najnowsze wywołania narzędzi (`ALL_ROWS`, starsze stronami przez `before`) z sesji, które mają jeszcze transkrypt.
     * Sesje od ostatnio aktywnej; czytanie kończy się, gdy następna sesja skończyła się przed najstarszym wywołaniem pełnej strony —
     * zwykle kilka transkryptów. Bez „pokaż wszystkie”: całość to parsowanie każdego transkryptu w jednym żądaniu, a `php -S` obsługuje jedno naraz.
     *
     * @param list<array<string, mixed>> $sessions agregaty sesji w zakresie (okres, projekt)
     * @param list<string> $excludes
     * @return list<array{event: Event, session_id: string, title: string, cwd: string}> od najnowszego
     */
    private static function allLog(array $sessions, array $p, array $excludes): array
    {
        $from = match (true) {
            $p['recent'] !== '' => gmdate('Y-m-d\TH:i:s', time() - LiveSummary::WINDOWS[$p['recent']]),
            $p['days'] > 0 => gmdate('Y-m-d\TH:i:s', time() - $p['days'] * 86400),
            default => '',
        };
        $before = $p['before'];
        usort($sessions, static fn (array $a, array $b): int => strcmp((string) ($b['last'] ?? ''), (string) ($a['last'] ?? '')));
        $reader = new TranscriptReader();
        $rows = [];
        foreach ($sessions as $s) {
            $sid = (string) $s['session_id'];
            $last = (string) ($s['last'] ?? '');
            if (count($rows) >= self::ALL_ROWS && $last < $rows[self::ALL_ROWS - 1]['event']->ts) {
                break;
            }
            if ($last === '' || ($before !== '' && (string) ($s['first'] ?? '') >= $before)) {
                continue;
            }
            $path = TranscriptReader::locate($sid);
            if ($path === null) {
                continue;
            }
            $meta = ['session_id' => $sid, 'title' => (string) ($s['title'] ?? ''), 'cwd' => (string) ($s['cwd'] ?? '')];
            foreach ($reader->read($path)->events as $e) {
                if ($e->tool === Event::TOOL_CONTEXT || ($from !== '' && $e->ts < $from) || ($before !== '' && $e->ts >= $before)) {
                    continue;
                }
                $hay = $e->tool . ' ' . $e->summary;
                if ($p['find'] !== '' && mb_stripos($hay, $p['find']) === false) {
                    continue;
                }
                foreach ($excludes as $x) {
                    if (mb_stripos($hay, $x) !== false) {
                        continue 2;
                    }
                }
                $rows[] = ['event' => $e] + $meta;
            }
            usort($rows, static fn (array $a, array $b): int => strcmp($b['event']->ts, $a['event']->ts));
            $rows = array_slice($rows, 0, self::ALL_ROWS);
        }

        return $rows;
    }

    /** Osie strony startowej (Signals::KINDS) w kolejności wyświetlania; w każdej osobno: co możesz zmienić ty, a co agent. */
    public const OVERVIEW_AXES = [
        'context' => 'Context',
        'communication' => 'Communication',
        'boundaries' => 'Boundaries',
    ];

    /** Domyślny okres strony startowej (dni); `days=all` = cała historia. */
    private const OVERVIEW_DAYS = 7;

    /**
     * Strona startowa: sygnały ze wszystkich sesji (agregaty var/summary, więc także archiwalne) w okresie i projekcie.
     * Liczniki `info` są sumą sesji aktywnych w okresie (agregat nie zna ich czasu).
     */
    private function overview(array $q): array
    {
        $p = $this->params($q);
        $days = match (is_string($q['days'] ?? null) ? $q['days'] : '') {
            '' => self::OVERVIEW_DAYS,
            'all' => 0,
            default => $p['days'],
        };
        $cutoff = $days > 0 ? gmdate('Y-m-d\TH:i:s', time() - $days * 86400) : '';
        $store = new SummaryStore();
        $store->refresh();
        $items = [];
        $sessions = [];
        $info = [];
        $usd = 0.0;
        $slow = [];
        $waits = ['count' => 0, 'ms' => 0, 'top' => []];
        $toolTime = [];
        $equipment = ['mcp' => [], 'skills' => [], 'subagents' => []];
        $resultFiles = 0;
        $resultBytes = 0;
        $resultTop = [];
        foreach ($store->all() as $s) {
            $cwd = (string) ($s['cwd'] ?? '');
            $key = self::fileHash($cwd);
            if (!$p['projects']->includes($cwd) || ($cutoff !== '' && (string) ($s['last'] ?? '') < $cutoff)) {
                continue;
            }
            $sid = (string) $s['session_id'];
            $sessions[$sid] = ['id' => $sid, 'title' => (string) ($s['title'] ?? ''), 'cwd' => $cwd, 'last' => (string) ($s['last'] ?? ''),
                'usd' => (float) ($s['tokens']['total']['usd'] ?? 0), 'key' => $key];
            $usd += $sessions[$sid]['usd'];
            foreach ((array) ($s['signals']['info'] ?? []) as $k => $v) {
                $info[$k] = ($info[$k] ?? 0) + $v;
            }
            // czas narzędzi, wyposażenie i rozmiary wyników (agregaty od `PARSER` 15; starsze nie mają pól)
            foreach ((array) ($s['slow'] ?? []) as $c) {
                if ($cutoff === '' || $c['ts'] >= $cutoff) {
                    $slow[] = $c + ['session_id' => $sid, 'title' => $sessions[$sid]['title']];
                }
            }
            // wywołania od progu „zgoda?”: liczba i suma z całej sesji, lista najdłuższych z okresu
            $waits['count'] += (int) ($s['waits']['count'] ?? 0);
            $waits['ms'] += (int) ($s['waits']['ms'] ?? 0);
            foreach ((array) ($s['waits']['top'] ?? []) as $c) {
                if ($cutoff === '' || $c['ts'] >= $cutoff) {
                    $waits['top'][] = $c + ['session_id' => $sid, 'title' => $sessions[$sid]['title']];
                }
            }
            // czas per narzędzie: suma całej sesji (agregat nie dzieli go na okresy — jak koszt sesji)
            foreach ((array) ($s['tool_time'] ?? []) as $tool => $t) {
                $r = $toolTime[$tool] ?? ['tool' => (string) $tool, 'count' => 0, 'ms' => 0, 'max' => 0];
                $toolTime[$tool] = ['count' => $r['count'] + $t['count'], 'ms' => $r['ms'] + $t['ms'], 'max' => max($r['max'], $t['max'])] + $r;
            }
            foreach ((array) ($s['equipment'] ?? []) as $kind => $names) {
                foreach ((array) $names as $name => $row) {
                    $e = $equipment[$kind][$name] ?? ['name' => (string) $name, 'count' => 0, 'last' => '', 'sessions' => 0];
                    $equipment[$kind][$name] = ['count' => $e['count'] + $row['count'], 'last' => max($e['last'], (string) $row['last']), 'sessions' => $e['sessions'] + 1] + $e;
                }
            }
            if (is_array($s['results'] ?? null)) {
                $resultFiles += (int) $s['results']['files'];
                $resultBytes += (int) $s['results']['bytes'];
                foreach ($s['results']['top'] as $r) {
                    $resultTop[] = $r + ['session_id' => $sid, 'title' => $sessions[$sid]['title']];
                }
            }
            foreach ((array) ($s['signals']['items'] ?? []) as $it) {
                if (!isset(Signals::KINDS[$it['kind']]) || ($cutoff !== '' && $it['ts'] < $cutoff)) {
                    continue;
                }
                [$axis, $actor, $sev] = Signals::KINDS[$it['kind']];
                $items[] = $it + ['session_id' => $sid, 'title' => $sessions[$sid]['title'], 'cwd' => $cwd, 'axis' => $axis, 'actor' => $actor, 'sev' => $sev];
            }
        }
        // najpierw `high`, potem kwoty malejąco (rodzaje w $), reszta od najnowszych
        usort($items, static fn (array $a, array $b): int => [$a['sev'] === 'high' ? 0 : 1, -self::signalUsd($a), $b['ts']]
            <=> [$b['sev'] === 'high' ? 0 : 1, -self::signalUsd($b), $a['ts']]);
        $kindGroup = static function (string $kind) use ($items): array {
            $list = array_values(array_filter($items, static fn (array $it): bool => $it['kind'] === $kind));

            return ['items' => $list, 'sessions' => count(array_unique(array_column($list, 'session_id'))),
                'usd' => in_array($kind, Signals::USD_KINDS, true) ? array_sum(array_column($list, 'value')) : null];
        };
        $axes = [];
        foreach (array_merge(self::OVERVIEW_AXES, ['env' => 'Outside your and the agent’s control']) as $axis => $label) {
            $inAxis = array_values(array_filter($items, static fn (array $it): bool => $it['axis'] === $axis));
            $alarms = array_filter($inAxis, static fn (array $it): bool => $it['sev'] !== 'info');
            $groups = [];
            foreach (Signals::KINDS as $kind => [$kAxis, $actor]) {
                if ($kAxis === $axis) {
                    $groups[$actor][$kind] = $kindGroup($kind);
                }
            }
            $axes[$axis] = [
                'label' => $label,
                'status' => match (true) {
                    array_filter($alarms, static fn (array $it): bool => $it['sev'] === 'high') !== [] => 'high',
                    $alarms !== [] => 'warn',
                    default => 'ok',
                },
                'count' => count($alarms),
                'groups' => $groups,
                'top' => array_slice(array_values($alarms), 0, 4),
            ];
        }
        $turns = $kindGroup('expensive-turn');

        // wykresy: koszt w czasie (sesja w chwili ostatniej aktywności), koszt wg projektu, sygnały wg rodzaju
        $hourly = $days === 1;
        $byTime = [];
        $byProject = [];
        foreach ($sessions as $sess) {
            if ($sess['last'] !== '') {
                $bucket = Clock::local($sess['last'], $hourly ? 'Y-m-d H' : 'Y-m-d');
                $byTime[$bucket] = ($byTime[$bucket] ?? ['usd' => 0.0, 'n' => 0]);
                $byTime[$bucket]['usd'] += $sess['usd'];
                $byTime[$bucket]['n']++;
            }
            $byProject[$sess['key']] ??= ['key' => $sess['key'], 'label' => basename($sess['cwd']) ?: '?', 'cwd' => $sess['cwd'], 'usd' => 0.0, 'n' => 0];
            $byProject[$sess['key']]['usd'] += $sess['usd'];
            $byProject[$sess['key']]['n']++;
        }
        usort($byProject, static fn (array $a, array $b): int => $b['usd'] <=> $a['usd']);
        // projekty o tej samej nazwie katalogu (różne ścieżki) dostają nadrzędny katalog w nazwie
        $labelCount = array_count_values(array_column($byProject, 'label'));
        foreach ($byProject as &$bp) {
            if ($labelCount[$bp['label']] > 1) {
                $bp['label'] = basename(dirname($bp['cwd'])) . '/' . $bp['label'];
            }
        }
        unset($bp);
        $kindCounts = [];
        foreach ($items as $it) {
            if ($it['sev'] !== 'info') {
                $kindCounts[$it['kind']] ??= ['kind' => $it['kind'], 'n' => 0, 'sev' => $it['sev'], 'sessions' => []];
                $kindCounts[$it['kind']]['n']++;
                $kindCounts[$it['kind']]['sessions'][$it['session_id']] = true;
            }
        }
        usort($kindCounts, static fn (array $a, array $b): int => [$a['sev'] === 'high' ? 0 : 1, $b['n']] <=> [$b['sev'] === 'high' ? 0 : 1, $a['n']]);
        usort($slow, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
        usort($toolTime, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
        usort($waits['top'], static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
        usort($resultTop, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);
        foreach ($equipment as &$names) {
            uasort($names, static fn (array $a, array $b): int => [$b['count'], $b['last']] <=> [$a['count'], $a['last']]);
        }
        unset($names);

        return [
            'slow' => array_slice($slow, 0, 8),
            'toolTime' => array_slice($toolTime, 0, 10),
            'waits' => ['top' => array_slice($waits['top'], 0, 5)] + $waits,
            'equipment' => $equipment,
            'results' => ['files' => $resultFiles, 'bytes' => $resultBytes, 'top' => array_slice($resultTop, 0, 8)],
            'p' => $p,
            'series' => self::timeSeries($byTime, $hourly, $days),
            'byProject' => $byProject,
            'kindCounts' => array_map(static fn (array $k): array => ['sessions' => count($k['sessions'])] + $k, $kindCounts),
            'days' => $days,
            'axes' => $axes,
            'turns' => ['top' => array_slice($turns['items'], 0, 8)] + $turns,
            'info' => $info,
            'usd' => $usd,
            'sessionCount' => count($sessions),
            'patterns' => Patterns::build(Patterns::load()),
        ];
    }

    /**
     * Oś czasu wykresu kosztu: ostatnie 24 godziny (godziny) albo ostatnie dni okresu; bez okresu — od pierwszej do ostatniej sesji (maks. 90 dni).
     * Puste przedziały mają koszt 0, żeby kolumny stały w równych odstępach.
     *
     * @param array<string, array{usd: float, n: int}> $byTime klucz = `Y-m-d` albo `Y-m-d H` w czasie lokalnym
     * @return array{hourly: bool, rows: list<array{key: string, label: string, usd: float, n: int}>}
     */
    private static function timeSeries(array $byTime, bool $hourly, int $days): array
    {
        $now = new \DateTimeImmutable('now', Clock::zone());
        $count = match (true) {
            $hourly => 24,
            $days > 0 => $days,
            $byTime === [] => 1,
            default => min(90, 1 + (int) ((new \DateTimeImmutable(min(array_keys($byTime)), Clock::zone()))->diff($now)->days)),
        };
        $rows = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $t = $now->modify("-$i " . ($hourly ? 'hours' : 'days'));
            $key = $t->format($hourly ? 'Y-m-d H' : 'Y-m-d');
            $rows[] = ['key' => $key, 'label' => $t->format($hourly ? 'H:00' : 'd.m'), 'usd' => $byTime[$key]['usd'] ?? 0.0, 'n' => $byTime[$key]['n'] ?? 0];
        }

        return ['hourly' => $hourly, 'rows' => $rows];
    }

    /** Kwota sygnału do sortowania; rodzaje spoza Signals::USD_KINDS = 0. */
    private static function signalUsd(array $it): float
    {
        return in_array($it['kind'], Signals::USD_KINDS, true) ? (float) $it['value'] : 0.0;
    }

    /** Link sygnału do widoku sesji, który pokazuje przyczynę (tura, wywołanie w panelu szczegółów, zakładka). */
    public static function signalLink(array $it): string
    {
        $q = ['s' => $it['session_id'], 'turn' => $it['turn'] > 0 ? (string) $it['turn'] : ''];
        $q = array_merge($q, match ($it['kind']) {
            'expensive-turn' => ['view' => 'commands', 'sort' => 'cost'],
            'expensive-result' => ['view' => 'commands', 'sort' => 'cost', 'pev' => (string) $it['ev']],
            'huge-context' => ['view' => 'log', 'turn' => ''],
            'protected-path', 'auto-mode', 'report-blocked', 'hook-text' => ['view' => 'blocked', 'pev' => (string) $it['ev']],
            'outside-executed', 'unknown-paths', 'outside-stopped' => ['view' => 'log', 'pev' => (string) $it['ev']],
            'subagent-failed' => ['view' => 'commands', 'status' => 'failed', 'pev' => (string) $it['ev']],
            'error-rate' => ['view' => 'commands', 'status' => 'failed', 'turn' => ''],
            default => ['view' => 'messages'],
        });

        return '/?' . http_build_query(array_filter($q, static fn (string $v): bool => $v !== ''));
    }

    /** Widok przeglądowy (`ScopeViews`): strona z lewym panelem; dane pobiera przeglądarka z trasy JSON widoku. */
    private function scopePage(array $scopeView, array $q): void
    {
        $sessions = SessionIndex::recent(self::SIDEBAR_LIMIT);
        $view = null;
        $overview = null;
        $scope = Scope::fromRequest($q, $_COOKIE);
        $sidebarTokens = SummaryStore::tokenTotals(array_column($sessions, 'id'));
        // zapamiętany widok, do którego prowadzi „Live preview” w lewym panelu i goły `/`
        header('Set-Cookie: ' . ScopeViews::COOKIE . '=' . $scopeView['path'] . '; Path=/; Max-Age=31536000; SameSite=Lax');
        $_COOKIE[ScopeViews::COOKIE] = $scopeView['path'];
        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__, 2) . '/templates/layout.php';
    }

    /**
     * Dane widoków przeglądowych: wspólny feed (`Feed`, z pamięcią podręczną) dla sesji w zakresie projektów — jedyna trasa danych
     * (`ScopeViews::isData`). Zawsze z transkryptu — hook nie zna odpowiedzi modelu ani modelu agenta.
     */
    private function scopeData(string $path, Scope $scope): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(Feed::build($scope, time(), Boundary::fromEnv()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }


    private function redirect(string $to): void
    {
        header('Location: ' . $to, true, 303);
    }

    private function notFound(): void
    {
        http_response_code(404);
        echo '404';
    }

    /** @return array{s: string, src: string, agent: string, pick: string, st: int, se: int} */
    private function params(array $q): array
    {
        $str = static fn (string $k): string => is_string($q[$k] ?? null) ? $q[$k] : '';

        return [
            's' => $str('s') === self::ALL || HookLog::validSessionId($str('s')) ? $str('s') : '',
            'src' => in_array($str('src'), ['transcript', 'hook'], true) ? $str('src') : 'transcript',
            'agent' => preg_match('/^(main|[a-f0-9]{1,40})$/', $str('agent')) ? $str('agent') : '',
            'pick' => preg_match('/^[a-f0-9]{12}$/', $str('pick')) ? $str('pick') : '',
            'turn' => max(0, (int) $str('turn')),
            'pcmd' => preg_match('/^[\w.+\-\[\]]{1,64}$/u', $str('pcmd')) ? $str('pcmd') : '',
            // wywołanie pokazane w panelu szczegółów (id tool_use albo uuid załącznika)
            'pev' => preg_match('/^[\w\-]{1,80}$/', $str('pev')) ? $str('pev') : '',
            'scope' => $str('scope') === 'project' ? 'project' : '',
            'problems' => $str('problems') === '1',
            'status' => in_array($str('status'), self::STATUSES, true) ? $str('status') : '',
            'view' => self::viewName($str('view')),
            'sort' => in_array($str('sort'), FileTable::SORTS, true) ? $str('sort') : '',
            'more' => $str('more') === '1',
            'group' => $str('group') === '1',
            'mk' => in_array($str('mk'), ['user', 'agents'], true) ? $str('mk') : '',
            'sx' => max(0, (int) $str('sx')),
            'dumped' => SessionExport::validDumpName($str('dumped')) ? $str('dumped') : '',
            // ocena sesji już trwała, nowej nie uruchomiono (POST /judge)
            'busy' => $str('busy') === '1',
            'sy' => max(0, (int) $str('sy')),
            'origin' => in_array($str('origin'), ['context', 'self'], true) ? $str('origin') : '',
            'st' => max(0, (int) $str('st')),
            'se' => max(0, (int) $str('se')),
            // Overview i zakres „all”: projekty z lewego panelu (ciasteczko `viz-scope`, parametr `projects` ma pierwszeństwo)
            'projects' => Scope::fromRequest($q, $_COOKIE),
            // zakres „all”: okres, szukanie, wykluczenia, wszystkie wiersze
            'days' => $str('recent') === '' && in_array($str('days'), ['1', '7', '14', '30'], true) ? (int) $str('days') : 0,
            'recent' => isset(LiveSummary::WINDOWS[$str('recent')]) ? $str('recent') : '',
            'find' => mb_substr(trim($str('find')), 0, 200),
            'exclude' => mb_substr(trim($str('exclude')), 0, 500),
            'limit' => $str('limit') === 'all',
            // zakres „all”, zakładka Log: strona wywołań starszych niż ta chwila (link „starsze”)
            'before' => preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d{1,6})?Z?$/', $str('before')) ? $str('before') : '',
        ];
    }

    /** Widoki-pytania + widoki dodatkowe; stare `logs` (sprzed podziału na pytania) = `log`. */
    public const VIEWS = ['read', 'changed', 'commands', 'blocked', 'approvals', 'messages', 'flow', 'log', 'judge'];

    /**
     * Zakładki dostępne w zakresie „all”. Bez Messages (rozmowy różnych sesji przemieszane w czasie nic nie mówią, a agregaty ich nie mają),
     * Flow (diagram sesji głównej i jej subagentów — przekrój wszystkich sesji to widoki przeglądowe Oś pracy / Agenci) i Assessment (ocena jednej sesji).
     */
    public const ALL_VIEWS = ['read', 'changed', 'commands', 'blocked', 'approvals', 'log'];

    /**
     * Status wywołania: wykonane (także Bash z kodem wyjścia ≠ 0), zablokowane (hook, reguła deny, tryb auto, user — `Event::denial`)
     * albo nieudane bez blokady (błąd narzędzia, np. „File does not exist”).
     */
    public const STATUSES = ['executed', 'blocked', 'failed'];

    public static function status(Event $e): string
    {
        return $e->denial !== null ? 'blocked' : ($e->executed() ? 'executed' : 'failed');
    }

    private static function viewName(string $v): string
    {
        return $v === 'logs' ? 'log' : (in_array($v, self::VIEWS, true) ? $v : 'read');
    }

    private function resolveSessionId(array $p): ?string
    {
        if ($p['s'] === self::ALL) {
            return self::ALL;
        }
        return $p['s'] !== '' ? $p['s'] : null;
    }

    private function sourcePath(string $src, string $sid): ?string
    {
        if ($src === 'hook') {
            return is_file(HookLog::path($sid)) ? HookLog::path($sid) : null;
        }

        return TranscriptReader::locate($sid);
    }

    private function sourceVersion(string $src, string $sid): string
    {
        $path = $this->sourcePath($src, $sid);
        if ($path === null) {
            return '0';
        }

        return $src === 'hook' ? (string) filesize($path) : SessionIndex::version($path);
    }

    private function view(array $q): ?array
    {
        $p = $this->params($q);
        $sid = $this->resolveSessionId($p);
        if ($sid === null) {
            return null;
        }
        if ($sid === self::ALL) {
            return $this->allView($p);
        }
        $path = $this->sourcePath($p['src'], $sid);
        if ($path === null) {
            return ['missing' => true, 'allSessions' => false, 'sid' => $sid, 'p' => $p, 'hookAvailable' => is_file(HookLog::path($sid))];
        }
        $session = $p['src'] === 'hook' ? (new HookLog())->read($sid) : (new TranscriptReader())->read($path);
        $agentFilter = isset($session->agents[$p['agent']]) ? $p['agent'] : '';
        $events = $agentFilter === ''
            ? $session->events
            : array_values(array_filter($session->events, static fn (Event $e): bool => $e->agentId === $agentFilter));

        $agentOnlyEvents = $events;
        // tury = prompty usera; tura N trwa od promptu N do promptu N+1
        $turns = array_values(array_filter($session->prompts, static fn (array $pr): bool => $pr['kind'] === 'prompt'));
        $turn = $p['turn'] >= 1 && $p['turn'] <= count($turns) ? $p['turn'] : 0;
        // problemy oznaczamy na pełnym zbiorze agenta (ponowne odczyty liczą się w całej sesji), filtrujemy na końcu
        $problems = Problems::tag($events);
        if ($turn > 0) {
            $from = $turns[$turn - 1]['ts'];
            $to = $turns[$turn]['ts'] ?? '9999';
            $events = array_values(array_filter($events, static fn (Event $e): bool => $e->ts >= $from && $e->ts < $to));
        }
        if ($p['scope'] === 'project' && $session->cwd !== null) {
            $events = self::onlyUnder($events, $session->cwd);
        }
        $problemCount = count(array_filter($events, static fn (Event $e): bool => isset($problems[$e->toolUseId])));
        if ($p['problems']) {
            $events = array_values(array_filter($events, static fn (Event $e): bool => isset($problems[$e->toolUseId])));
        }
        // zakładka Blocked: zablokowane wywołania w zakresie pozostałych filtrów (bez filtra statusu)
        $blockedRows = [];
        foreach ($events as $e) {
            if ($e->denial !== null) {
                $blockedRows[] = ['ts' => $e->ts, 'agent' => $e->agentId, 'tool' => $e->tool, 'summary' => $e->summary, 'by' => $e->denial['by'], 'detail' => $e->denial['detail'],
                    'id' => $e->toolUseId, 'session_id' => null, 'title' => '', 'cwd' => (string) $session->cwd];
            }
        }
        // zakładka Zgody: pytania o zgodę (hook, odrzucenie przez usera, długie czekanie) z przyczyną z ustawień i komendy
        $approvalSettings = Settings::forSession($session->cwd);
        $approvals = Approvals::rows($events, HookLog::state($sid), $approvalSettings, $session->cwd, time(), $session->modes);
        $statusCounts = ['executed' => 0, 'blocked' => 0, 'failed' => 0];
        foreach ($events as $e) {
            if ($e->tool !== Event::TOOL_CONTEXT) {
                $statusCounts[self::status($e)]++;
            }
        }
        // granica pracy agentów (`.env`): rodzaj per wywołanie — znaczniki w Commands
        $limits = Boundary::fromEnv();
        $crossed = [];
        foreach ($events as $e) {
            $kind = $limits->classify($e);
            if ($kind === null) {
                continue;
            }
            $crossed[$e->toolUseId] = $kind;
        }
        if ($p['status'] !== '') {
            $events = array_values(array_filter($events, static fn (Event $e): bool => self::status($e) === $p['status']));
        }
        $stats = Stats::of($events);
        $shown = $p['origin'] === ''
            ? $events
            : array_values(array_filter($events, static fn (Event $e): bool => ($e->tool === Event::TOOL_CONTEXT) === ($p['origin'] === 'context')));
        // przy blokadach i błędach tabela pokazuje pliki, do których agent próbował sięgnąć
        $tried = in_array($p['status'], ['blocked', 'failed'], true);
        $files = FileTable::build($shown, $problems, $tried);
        $readOps = $p['more'] ? [Touch::READ, Touch::CONTEXT, Touch::SEARCH, Touch::LIST, Touch::EXEC] : [Touch::READ, Touch::CONTEXT];
        $read = array_values(array_filter($files, static fn (array $f): bool => array_intersect_key($f['ops'], array_flip($readOps)) !== []));
        $changed = array_values(array_filter($files, static fn (array $f): bool => $f['mods'] > 0));
        // widoki Read / Changed jednej sesji: płaska lista wywołań, najnowsze na górze (jak Log)
        $readCalls = array_reverse(FileTable::calls($shown, $readOps, $problems, $tried));
        $changedCalls = array_reverse(FileTable::calls($shown, [Touch::EDIT, Touch::WRITE], $problems, $tried));
        $bash = $tried
            ? array_values(array_filter($shown, static fn (Event $e): bool => $e->tool !== Event::TOOL_CONTEXT))
            : array_values(array_filter($shown, static fn (Event $e): bool => $e->commands !== [] || $e->tool === 'Bash'));
        $messages = self::messages($session, $agentFilter, $turn > 0 ? $turns[$turn - 1]['ts'] : null, $turn > 0 ? ($turns[$turn]['ts'] ?? null) : null);
        $transcript = TranscriptReader::locate($sid);
        // tokeny w zakresie tury (albo całej sesji) per agent — niezależne od filtrów agenta i statusu
        $tokFrom = $turn > 0 ? $turns[$turn - 1]['ts'] : null;
        $tokTo = $turn > 0 ? ($turns[$turn]['ts'] ?? null) : null;
        $agentTokens = array_filter(
            array_map(static fn (Agent $a): array => $session->tokens($a->id, $tokFrom, $tokTo), $session->agents),
            static fn (array $t): bool => $t['calls'] > 0,
        );
        // po liczbie wywołań, nie po wyjściu: wyjście subagentów bywa zaniżone (`outputPartial`)
        uasort($agentTokens, static fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);
        // najdroższe wyniki narzędzi w tym samym zakresie (wszyscy agenci): koszt zapisu + odczytów przy dalszych wywołaniach
        $expensive = array_values(array_filter($session->events, static fn (Event $e): bool => ($e->carry['usd'] ?? 0) > 0
            && ($tokFrom === null || $e->ts >= $tokFrom) && ($tokTo === null || $e->ts < $tokTo)));
        usort($expensive, static fn (Event $a, Event $b): int => $b->carry['usd'] <=> $a->carry['usd']);
        if ($p['sort'] === 'time') {
            usort($bash, static fn (Event $a, Event $b): int => (SummaryStore::durationMs($b) ?? -1) <=> (SummaryStore::durationMs($a) ?? -1));
        }
        if ($p['sort'] === 'cost') {
            usort($bash, static fn (Event $a, Event $b): int => ($b->carry['usd'] ?? 0) <=> ($a->carry['usd'] ?? 0));
        }
        $logCount = self::logCount($shown, $p['origin']);
        // wspólny korzeń ścieżek całej sesji (bez filtrów, żeby nie skakał między zakładkami) — files-calls.php, log.php
        $ownPaths = [];
        foreach ($session->events as $e) {
            if ($e->tool !== Event::TOOL_CONTEXT) {
                foreach ($e->touches as $t) {
                    $ownPaths[] = $t->path;
                }
            }
        }
        $pathRoot = self::pathRoot($ownPaths, $session->cwd);

        return [
            'allSessions' => false,
            'missing' => false,
            'sid' => $sid,
            'p' => $p,
            'session' => $session,
            'title' => $this->title($session, $transcript),
            'agentFilter' => $agentFilter,
            'agentColors' => array_flip(array_keys($session->agents)),
            'agentEvents' => $events,
            'agentOnlyEvents' => $agentOnlyEvents,
            'originCounts' => [
                '' => count($events),
                'context' => count(array_filter($events, static fn (Event $e): bool => $e->tool === Event::TOOL_CONTEXT)),
                'self' => count(array_filter($events, static fn (Event $e): bool => $e->tool !== Event::TOOL_CONTEXT)),
            ],
            // zakładka Flow: cała sesja w kształcie wiersza feedu (flow.js); filtry agenta i tury jej nie dotyczą
            'flow' => $p['view'] === 'flow' ? LiveState::session($session, time(), Boundary::fromEnv(), true) : null,
            // zakładka Flow: pliki instrukcji wczytane do kontekstu (hook InstructionsLoaded; same ścieżki, bez treści)
            'flowContext' => $p['view'] === 'flow' ? HookLog::instructions($session->id) : [],
            'turns' => $turns,
            'turn' => $turn,
            // zużycie tokenów per tura (wszyscy agenci, subagenci po czasie) — niezależne od filtrów agenta i statusu
            'turnTokens' => array_map(static fn (int $i): array => $session->tokens('', $turns[$i]['ts'], $turns[$i + 1]['ts'] ?? null), array_keys($turns)),
            'tokens' => $session->tokens('', $tokFrom, $tokTo),
            'sessionTokens' => $session->tokens(),
            'carried' => array_sum(array_map(static fn (Event $e): float => $e->carry['usd'] ?? 0.0, array_filter($session->events,
                static fn (Event $e): bool => ($tokFrom === null || $e->ts >= $tokFrom) && ($tokTo === null || $e->ts < $tokTo)))),
            'expensive' => array_slice($expensive, 0, 8),
            'agentTokens' => $agentTokens,
            // select agenta: zużycie w całej sesji, bez względu na turę
            'agentTokensAll' => array_map(static fn (Agent $a): array => $session->tokens($a->id), $session->agents),
            'problems' => $problems,
            'problemCount' => $problemCount,
            'statusCounts' => $statusCounts,
            'events' => array_reverse($shown),
            'stats' => $stats,
            'read' => FileTable::sort($read, $p['sort'] !== '' ? $p['sort'] : 'count', 'reads'),
            'changed' => FileTable::sort($changed, $p['sort'] !== '' ? $p['sort'] : 'path', 'mods'),
            'readCalls' => $readCalls,
            'changedCalls' => $changedCalls,
            'counts' => [
                'read' => count($readCalls),
                'changed' => count($changedCalls),
                'commands' => count($bash),
                'blocked' => count($blockedRows),
                'approvals' => count($approvals),
                'messages' => count($messages),
                'log' => $logCount['n'],
            ],
            // Log pokazuje też pliki wczytane przez Claude Code — podpowiedź licznika zakładki (tabs.php)
            'logContext' => $logCount['context'],
            'pathRoot' => $pathRoot,
            'blocked' => array_reverse($blockedRows),
            'approvals' => $approvals,
            'approvalsTop' => Approvals::top($approvals),
            'approvalSettings' => $approvalSettings,
            // tryb uprawnień sesji z transkryptu (zmiany przy wiadomościach usera) — linia nad tabelą Zgód
            'approvalModes' => $session->modes,
            'crossed' => $crossed,
            'rowLimit' => self::rowLimit($p),
            'bash' => $p['pcmd'] === '' ? $bash : array_values(array_filter($bash, static function (Event $e) use ($p): bool {
                foreach ($e->commands as $c) {
                    if ($c->program === $p['pcmd']) {
                        return true;
                    }
                }

                return false;
            })),
            'allBash' => $bash,
            'messages' => match ($p['mk']) {
                'user' => array_values(array_filter($messages, static fn (array $m): bool => $m['from'] === 'user')),
                'agents' => array_values(array_filter($messages, static fn (array $m): bool => $m['from'] !== 'user')),
                default => $messages,
            },
            'live' => true,
            'version' => $sid . ':' . $this->sourceVersion($p['src'], $sid),
            'hookAvailable' => is_file(HookLog::path($sid)),
            'transcriptAvailable' => $transcript !== null,
            'emptyWarning' => $p['src'] === 'transcript' && $session->toolUseCount === 0 && filesize($path) > 200_000,
        ];
    }

    /**
     * Wiadomości w zakresie filtrów: agent = nadawca albo adresat; tura = czas od promptu do następnego promptu.
     *
     * @return list<array{ts: string, from: string, to: string, kind: string, text: string, cut: bool}>
     */
    private static function messages(Session $session, string $agent, ?string $from, ?string $to): array
    {
        return array_values(array_filter($session->messages, static fn (array $m): bool => ($agent === '' || $m['from'] === $agent || $m['to'] === $agent)
            && ($from === null || $m['ts'] >= $from)
            && ($to === null || $m['ts'] < $to)));
    }

    /**
     * Tylko dotknięcia pod katalogiem sesji; wywołanie znika, gdy wszystkie jego pliki były poza projektem.
     * Wywołania bez plików (Bash bez ścieżek, Agent) zostają — to też praca w projekcie.
     *
     * @param list<Event> $events
     * @return list<Event>
     */
    private static function onlyUnder(array $events, string $cwd): array
    {
        $out = [];
        foreach ($events as $e) {
            $inside = array_values(array_filter($e->touches, static fn (Touch $t): bool => $t->path === $cwd || str_starts_with($t->path, $cwd . '/')));
            if ($e->touches !== [] && $inside === []) {
                continue;
            }
            $out[] = $inside === $e->touches ? $e : new Event(
                $e->ts, $e->sessionId, $e->agentId, $e->tool, $e->toolUseId, $e->summary, $e->cwd, $e->failed, $inside,
                $e->unresolved, $e->indirect, $e->unknown, $e->endTs, $e->error, $e->commands, $e->denial, $e->carry,
            );
        }

        return $out;
    }

    private function title(Session $session, ?string $transcript): string
    {
        if ($session->title !== '') {
            return $session->title;
        }
        if ($transcript !== null) {
            foreach (SessionIndex::recent(200) as $s) {
                if ($s['id'] === $session->id && $s['title'] !== '') {
                    return $s['title'];
                }
            }
        }

        return $session->id;
    }

    /** Ścieżka do wyświetlenia: względna wobec cwd sesji, inaczej z `~`. */
    /**
     * Projekty do wyboru zakresu w lewym panelu (`Projects::of`).
     *
     * @param list<array{cwd: string, mtime: int}> $sessions
     * @return array<string, array{label: string, cwd: string, sessions: int}>
     */
    public static function sidebarProjects(array $sessions): array
    {
        return Projects::of($sessions);
    }

    public static function shortPath(string $path, ?string $cwd): string
    {
        if ($cwd !== null && $path === $cwd) {
            return '.';
        }
        if ($cwd !== null && str_starts_with($path, $cwd . '/')) {
            return substr($path, strlen($cwd) + 1);
        }
        $home = Path::home();

        return $home !== '' && str_starts_with($path, $home . '/') ? '~' . substr($path, strlen($home)) : $path;
    }

    /** Tekst wywołania do listy: ścieżki pod katalogiem sesji względne, domowe z `~`. */
    public static function shortText(string $text, ?string $cwd): string
    {
        if ($cwd !== null && $cwd !== '/') {
            $text = str_replace($cwd . '/', '', $text);
        }
        $home = Path::home();

        return $home !== '' ? str_replace($home . '/', '~/', $text) : $text;
    }

    /** Wiersze list sesji i zakresu „all” (pliki, wiadomości): `limit=all` = wszystkie, inaczej `ALL_ROWS` i link „pokaż wszystkie”. */
    public static function rowLimit(array $p): int
    {
        return $p['limit'] ? PHP_INT_MAX : self::ALL_ROWS;
    }

    /**
     * Wspólny korzeń ścieżek sesji, względem którego listy skracają ścieżki (pokazany raz w pasku listy): najgłębszy katalog
     * z co najmniej połową unikalnych ścieżek, który jest katalogiem sesji albo korzeniem repozytorium (ma `.git`); bez takiego —
     * najgłębszy katalog wspólny dla wszystkich ścieżek. Katalog domowy i wyżej się nie liczą (skraca je `~`). Null = brak.
     *
     * @param iterable<string> $paths
     */
    public static function pathRoot(iterable $paths, ?string $cwd): ?string
    {
        $unique = [];
        foreach ($paths as $path) {
            if (str_starts_with($path, '/')) {
                $unique[$path] = true;
            }
        }
        $n = count($unique);
        if ($n === 0) {
            return null;
        }
        $home = Path::home();
        $minDepth = $home !== '' ? substr_count($home, '/') + 1 : 2;
        $counts = [];
        foreach (array_keys($unique) as $path) {
            for ($dir = dirname((string) $path); substr_count($dir, '/') >= $minDepth; $dir = dirname($dir)) {
                $counts[$dir] = ($counts[$dir] ?? 0) + 1;
            }
        }
        uksort($counts, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($counts as $dir => $c) {
            if (2 * $c >= $n && ($dir === $cwd || file_exists($dir . '/.git'))) {
                return (string) $dir;
            }
        }
        foreach ($counts as $dir => $c) {
            if ($c === $n) {
                return (string) $dir;
            }
        }

        return null;
    }

    /**
     * Licznik zakładki Log: wywołania narzędzi (to samo, co „wszystkie wywołania” w selekcie statusu) i osobno pliki wczytane
     * przez Claude Code (`context`), które Log też pokazuje. Przy filtrze pochodzenia „context” licznikiem są te pliki.
     *
     * @param list<Event> $events
     * @return array{n: int, context: int}
     */
    public static function logCount(array $events, string $origin): array
    {
        $context = count(array_filter($events, static fn (Event $e): bool => $e->tool === Event::TOOL_CONTEXT));

        return ['n' => $origin === 'context' ? $context : count($events) - $context, 'context' => $context];
    }

    public static function query(array $view, array $override = []): string
    {
        $p = $view['p'];
        $q = array_filter([
            's' => $view['sid'],
            'src' => $p['src'] === 'transcript' ? '' : $p['src'],
            'agent' => $view['agentFilter'] ?? '',
            'turn' => $p['turn'] > 0 ? (string) $p['turn'] : '',
            'scope' => $p['scope'],
            'problems' => $p['problems'] ? '1' : '',
            'status' => $p['status'],
            'view' => $p['view'] === 'read' ? '' : $p['view'],
            'sort' => $p['sort'],
            'more' => $p['more'] ? '1' : '',
            'group' => $p['group'] ? '1' : '',
            'mk' => $p['mk'],
            'pcmd' => $p['pcmd'],
            'origin' => $p['origin'],
            'days' => $p['days'] > 0 ? (string) $p['days'] : '',
            'recent' => $p['recent'],
            'find' => $p['find'],
            'exclude' => $p['exclude'],
            'limit' => $p['limit'] ? 'all' : '',
        ], static fn (string $v): bool => $v !== '');

        return http_build_query(array_filter(array_merge($q, $override), static fn (string $v): bool => $v !== ''));
    }

    /** `$raw` = bez tłumaczenia (CSV zostaje po angielsku). */
    public static function agentLabel(Agent $a, bool $raw = false): string
    {
        return $a->id === Agent::MAIN ? ($raw ? 'main' : t('main')) : $a->label();
    }

    /** 812 · 43k · 1.1M */
    public static function tokenCount(int $n): string
    {
        return match (true) {
            $n >= 1_000_000 => round($n / 1_000_000, 1) . 'M',
            $n >= 1_000 => round($n / 1_000) . 'k',
            default => (string) $n,
        };
    }

    /** $0.42 · $3.17 · $12.4 · $128 — koszt według cennika API (Pricing). */
    public static function usd(float $usd): string
    {
        return '$' . match (true) {
            $usd >= 100 => number_format($usd, 0),
            $usd >= 10 => number_format($usd, 1),
            default => number_format($usd, 2),
        };
    }

    /** 850 ms · 4.2 s · 3 min 05 s · 1 h 12 min — czas trwania wywołania (`SummaryStore::durationMs`). */
    public static function duration(int $ms): string
    {
        if ($ms < 1000) {
            return $ms . ' ms';
        }
        if ($ms < 59950) {
            return round($ms / 1000, 1) . ' s';
        }
        $s = (int) round($ms / 1000);

        return $s < 3600 ? sprintf('%d min %02d s', intdiv($s, 60), $s % 60) : sprintf('%d h %02d min', intdiv($s, 3600), intdiv($s % 3600, 60));
    }

    /**
     * Opis zużycia: koszt według cennika API jako miara główna, liczniki tokenów osobno (bez sumy), `≥` przy wyjściu zaniżonym przez transkrypt.
     * `?` przy koszcie = część odpowiedzi to model spoza cennika.
     *
     * @param array{calls: int, subagentCalls: int, outputPartial: int, output: int, cacheWrite: int, cacheRead: int, input: int, models: array<string, int>, usd: float, unpriced: int} $t
     * @return array{short: string, long: string}
     */
    public static function tokenText(array $t): array
    {
        $out = ($t['outputPartial'] > 0 ? '≥' : '') . self::tokenCount($t['output']);
        $usd = self::usd($t['usd']) . ($t['unpriced'] > 0 ? '?' : '');

        return [
            'short' => $usd,
            'long' => $usd . ' · ' . t($t['calls'] === 1 ? '{n} model call' : '{n} model calls', ['n' => $t['calls']]) . ($t['subagentCalls'] > 0 ? ' (' . t('{n} by subagents', ['n' => $t['subagentCalls']]) . ')' : '')
                . ' · ' . t('output {v}', ['v' => $out]) . ' · ' . t('new context {v}', ['v' => self::tokenCount($t['cacheWrite'])]) . ' · ' . t('cache read {v}', ['v' => self::tokenCount($t['cacheRead'] + $t['input'])]),
        ];
    }
}
