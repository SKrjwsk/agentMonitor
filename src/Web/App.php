<?php

declare(strict_types=1);

namespace Viz\Web;

use Viz\Boundary;
use Viz\Clock;
use Viz\Export\Csv;
use Viz\Export\SessionExport;
use Viz\Office\OfficeState;
use Viz\Model\Agent;
use Viz\Model\Event;
use Viz\Model\Session;
use Viz\Model\Touch;
use Viz\Path;
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
    private const LIVE_WINDOW = 120;

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
            $tool === Event::TOOL_CONTEXT => 'not a tool call: Claude Code put this file into the agent context by itself',
            $tool === 'Bash' => 'shell command run by the agent',
            in_array($tool, ['Read', 'NotebookRead'], true) => 'file read tool',
            in_array($tool, ['Edit', 'MultiEdit', 'NotebookEdit'], true) => 'file edit tool',
            $tool === 'Write' => 'file write tool (creates or overwrites the whole file)',
            $tool === 'Grep' => 'content search tool',
            $tool === 'Glob' => 'file name search tool',
            in_array($tool, ['Agent', 'Task'], true) => 'started a subagent with a task',
            $tool === 'SubagentHandback' => 'a subagent sent its reply back',
            $tool === 'SendMessage' => 'message to another agent or session',
            $tool === 'Skill' => 'loaded a skill (instructions)',
            str_starts_with($tool, 'mcp__') => 'tool of an MCP server',
            default => 'tool call',
        };
    }

    public const OP_LABELS = [Touch::READ => 'read', Touch::CONTEXT => 'context', Touch::EDIT => 'edit', Touch::WRITE => 'write', Touch::SEARCH => 'search', Touch::LIST => 'listing', Touch::EXEC => 'exec'];

    public function handle(string $path, array $query, string $method = 'GET'): void
    {
        if ($method === 'POST') {
            $path === '/export' ? $this->export($query) : $this->notFound();
            return;
        }
        match ($path) {
            '/' => $this->page($query),
            '/session' => $this->fragment($query),
            '/version' => $this->version($query),
            '/graph.json' => $this->graph($query),
            '/office' => $this->officePage(),
            '/office.json' => $this->office(),
            '/summary' => $this->summaryRedirect($query),
            '/export.csv' => $this->csv($query),
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
            echo '<section viz-session><p class="empty">No session.</p></section>';
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
        $name = SessionExport::dumpName($view['session'], $view['agentFilter']);
        (new SessionExport())->writeDir(
            SessionExport::dumpRoot() . '/' . $name,
            $view['session'], $view['agentEvents'], $view['stats'], $view['title'], $view['p']['src'], $view['agentFilter'],
            array_filter(['turn' => $view['turn'] ?: null, 'scope' => $view['p']['scope'] ?: null, 'problems' => $view['p']['problems'] ?: null]),
        );
        header('Location: /?' . self::query($view, ['dumped' => $name]), true, 303);
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
        ] + array_intersect_key(array_map('strval', array_filter($q, 'is_string')), array_flip(['days', 'recent', 'find', 'exclude', 'project', 'scope', 'limit'])), static fn (string $v): bool => $v !== '');
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
        $period = $p['recent'] !== '' ? $p['recent'] : ($p['days'] > 0 ? $p['days'] . 'd' : 'all');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="agentmonitor-' . $p['view'] . '-' . Clock::local(Clock::nowUtc(), 'Ymd-His') . '-' . $period . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        if ($p['view'] === 'boundary') {
            fputcsv($out, ['time', 'kind', 'stopped_by', 'tool', 'call', 'paths_outside', 'session', 'project'], ',', '"', '');
            foreach ($view['boundary'] as $b) {
                fputcsv($out, [
                    Clock::local($b['ts'], 'Y-m-d H:i:s'), $b['kind'], self::csvSafe((string) ($b['by'] ?? '')), self::csvSafe((string) $b['tool']), self::csvSafe($b['summary']),
                    self::csvSafe(implode('; ', array_map(static fn (array $t): string => $t['op'] . ' ' . $t['path'], $b['paths']))),
                    self::csvSafe($b['title'] !== '' ? $b['title'] : (string) $b['session_id']), self::csvSafe(basename($b['cwd'])),
                ], ',', '"', '');
            }
            fclose($out);
            return;
        }
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
     * Timeline / Graph / Log: wszystkie wywołania w zakresie filtrów.
     */
    private function sessionCsv(array $view): void
    {
        $p = $view['p'];
        /** @var Session $session */
        $session = $view['session'];
        $agent = static fn (string $id): string => isset($session->agents[$id]) ? self::agentLabel($session->agents[$id]) : $id;
        $status = static fn (Event $e): string => $e->denial !== null ? 'blocked' : (!$e->failed ? 'ok' : ($e->executed() ? 'exit≠0' : 'tool error'));
        $tab = in_array($p['view'], ['read', 'changed', 'commands', 'blocked', 'boundary', 'messages'], true) ? $p['view'] : 'calls';
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
        } elseif ($tab === 'boundary') {
            $row(['time', 'agent', 'kind', 'stopped_by', 'tool', 'call', 'paths_outside']);
            foreach ($view['boundary'] as $b) {
                $row([Clock::local($b['ts'], 'Y-m-d H:i:s'), $agent((string) $b['agent']), $b['kind'], (string) ($b['by'] ?? ''), $b['tool'], $b['summary'],
                    implode('; ', array_map(static fn (array $t): string => $t['op'] . ' ' . $t['path'], $b['paths']))]);
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
        $ranking = Ranking::build($source, $p['days'], $p['project'], 'sessions', $p['scope'] === 'project' && $p['project'] !== '');
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

        $commands = CommandRanking::build($source, $p['days'], $p['project'], match ($p['sort']) { 'path' => 'program', 'sessions' => 'sessions', default => 'count' });
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

        // naruszenia granicy ze wszystkich sesji w zakresie; `boundary` = filtr rodzaju
        $boundary = [];
        foreach ($ranking['sessions'] as $sess) {
            foreach ((array) ($sess['boundary'] ?? []) as $b) {
                $hay = $b['tool'] . ' ' . $b['summary'] . ' ' . implode(' ', array_column($b['paths'], 'path'));
                if (($cutoff !== '' && $b['ts'] < $cutoff) || ($p['boundary'] !== '' && $b['kind'] !== $p['boundary']) || ($p['find'] !== '' && mb_stripos($hay, $p['find']) === false)) {
                    continue;
                }
                foreach ($excludes as $x) {
                    if (mb_stripos($hay, $x) !== false) {
                        continue 2;
                    }
                }
                $boundary[] = $b + ['session_id' => (string) $sess['session_id'], 'title' => (string) ($sess['title'] ?? ''), 'cwd' => (string) ($sess['cwd'] ?? ''), 'id' => null];
            }
        }
        usort($boundary, static fn (array $a, array $b): int => strcmp($b['ts'], $a['ts']));

        $sessionsById = [];
        foreach ($ranking['sessions'] as $sess) {
            $sessionsById[(string) $sess['session_id']] = $sess;
        }

        return [
            'allSessions' => true,
            'missing' => false,
            'sid' => self::ALL,
            'p' => $p,
            'title' => $p['recent'] !== '' ? 'All sessions — last ' . ($p['recent'] === '5m' ? '5 minutes' : 'hour') : 'All sessions',
            'agentFilter' => '',
            'agentColors' => [],
            'read' => FileTable::sort($read, $p['sort'] !== '' ? $p['sort'] : 'count', 'reads'),
            'changed' => FileTable::sort($changed, $p['sort'] !== '' ? $p['sort'] : 'count', 'mods'),
            'commands' => $commands,
            'blocked' => $blocked,
            'boundary' => $boundary,
            'boundaryOn' => Boundary::fromEnv()->enabled(),
            'counts' => ['read' => count($read), 'changed' => count($changed), 'commands' => count($commands), 'blocked' => count($blocked), 'boundary' => count($boundary)],
            'rowLimit' => $p['limit'] ? PHP_INT_MAX : self::ALL_ROWS,
            'sessionsById' => $sessionsById,
            'projects' => $ranking['projects'],
            'archived' => count($archive),
            'withTranscript' => count(array_filter($archive, static fn (array $s): bool => TranscriptReader::locate((string) $s['session_id']) !== null)),
            'refreshed' => $refreshed,
            'live' => $p['recent'] !== '' && $p['live'] !== false,
            'active' => false,
            'version' => $p['recent'] !== '' ? LiveSummary::version(LiveSummary::WINDOWS[$p['recent']]) : '-',
        ];
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
        $projects = [];
        $items = [];
        $sessions = [];
        $info = [];
        $usd = 0.0;
        foreach ($store->all() as $s) {
            $cwd = (string) ($s['cwd'] ?? '');
            $key = self::fileHash($cwd);
            $projects[$key] ??= ['label' => basename($cwd) ?: '?', 'cwd' => $cwd, 'sessions' => 0];
            $projects[$key]['sessions']++;
            if (($p['project'] !== '' && $key !== $p['project']) || ($cutoff !== '' && (string) ($s['last'] ?? '') < $cutoff)) {
                continue;
            }
            $sid = (string) $s['session_id'];
            $sessions[$sid] = ['id' => $sid, 'title' => (string) ($s['title'] ?? ''), 'cwd' => $cwd, 'last' => (string) ($s['last'] ?? ''),
                'usd' => (float) ($s['tokens']['total']['usd'] ?? 0), 'high' => 0, 'warn' => 0, 'kinds' => []];
            $usd += $sessions[$sid]['usd'];
            foreach ((array) ($s['signals']['info'] ?? []) as $k => $v) {
                $info[$k] = ($info[$k] ?? 0) + $v;
            }
            foreach ((array) ($s['signals']['items'] ?? []) as $it) {
                if (!isset(Signals::KINDS[$it['kind']]) || ($cutoff !== '' && $it['ts'] < $cutoff)) {
                    continue;
                }
                [$axis, $actor, $sev] = Signals::KINDS[$it['kind']];
                $items[] = $it + ['session_id' => $sid, 'title' => $sessions[$sid]['title'], 'cwd' => $cwd, 'axis' => $axis, 'actor' => $actor, 'sev' => $sev];
                if ($sev !== 'info' && $axis !== 'cost') {
                    $sessions[$sid][$sev]++;
                    $sessions[$sid]['kinds'][$it['kind']] = ($sessions[$sid]['kinds'][$it['kind']] ?? 0) + 1;
                }
            }
        }
        uasort($projects, static fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions']);
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
        $flagged = array_filter($sessions, static fn (array $s): bool => $s['high'] + $s['warn'] > 0);
        usort($flagged, static fn (array $a, array $b): int => [$b['high'], $b['warn'], $b['usd']] <=> [$a['high'], $a['warn'], $a['usd']]);
        $turns = $kindGroup('expensive-turn');

        return [
            'p' => $p,
            'days' => $days,
            'projects' => $projects,
            'axes' => $axes,
            'turns' => ['top' => array_slice($turns['items'], 0, 8)] + $turns,
            'info' => $info,
            'usd' => $usd,
            'sessionCount' => count($sessions),
            'flagged' => array_slice($flagged, 0, 15),
            'flaggedCount' => count($flagged),
        ];
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
            'huge-context' => ['view' => 'timeline', 'turn' => ''],
            'protected-path', 'auto-mode', 'report-blocked', 'hook-text' => ['view' => 'blocked', 'pev' => (string) $it['ev']],
            'outside-executed', 'unknown-paths', 'outside-stopped' => ['view' => 'boundary', 'pev' => (string) $it['ev'],
                'boundary' => ['outside-executed' => Boundary::OUTSIDE, 'unknown-paths' => Boundary::SUSPICIOUS, 'outside-stopped' => Boundary::STOPPED][$it['kind']]],
            'subagent-failed' => ['view' => 'commands', 'status' => 'failed', 'pev' => (string) $it['ev']],
            'error-rate' => ['view' => 'commands', 'status' => 'failed', 'turn' => ''],
            default => ['view' => 'messages'],
        });

        return '/?' . http_build_query(array_filter($q, static fn (string $v): bool => $v !== ''));
    }

    private function graph(array $q): void
    {
        $view = $this->view($q);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if ($view === null || $view['missing'] || $view['allSessions']) {
            echo '{"nodes":[],"links":[],"hidden":0}';
            return;
        }
        $p = $view['p'];
        echo json_encode(
            Graph::build($view['session'], $view['agentEvents'], $view['agentColors'], $p['all'], $p['dirs'], $p['origin'] !== '' ? $p['origin'] : null),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
        );
    }

    /** Wirtualne biuro: widok globalny (wszystkie aktywne sesje), obok Overview w lewym panelu. */
    private function officePage(): void
    {
        $sessions = SessionIndex::recent(self::SIDEBAR_LIMIT);
        $view = null;
        $overview = null;
        $office = true;
        $sidebarTokens = SummaryStore::tokenTotals(array_column($sessions, 'id'));
        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__, 2) . '/templates/layout.php';
    }

    /**
     * Stan biura (`OfficeState`) ze wszystkich sesji aktywnych w oknie `STALE_GONE`: transkrypty zmienione w oknie (mtime jako tani nadzbiór),
     * dokładny filtr robi `OfficeState` z czasów zdarzeń. Zawsze z transkryptu — hook nie zna odpowiedzi modelu ani modelu agenta.
     */
    private function office(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $now = time();
        $limits = Boundary::fromEnv();
        $reader = new TranscriptReader();
        $builds = array_map(
            static fn (string $path): array => OfficeState::build($reader->read($path), $now, $limits),
            LiveSummary::candidates(OfficeState::STALE_GONE),
        );
        echo json_encode(OfficeState::merge($builds, $now), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }


    private function notFound(): void
    {
        http_response_code(404);
        echo '404';
    }

    /** @return array{s: string, src: string, agent: string, pick: string, live: ?bool, follow: bool, st: int, se: int} */
    private function params(array $q): array
    {
        $str = static fn (string $k): string => is_string($q[$k] ?? null) ? $q[$k] : '';

        return [
            's' => $str('s') === self::ALL || HookLog::validSessionId($str('s')) ? $str('s') : '',
            'src' => in_array($str('src'), ['transcript', 'hook'], true) ? $str('src') : 'transcript',
            'agent' => preg_match('/^(main|[a-f0-9]{1,40})$/', $str('agent')) ? $str('agent') : '',
            'pick' => preg_match('/^[a-f0-9]{12}$/', $str('pick')) ? $str('pick') : '',
            'live' => $str('live') === '' ? null : $str('live') === '1',
            'follow' => $str('follow') === '1',
            'turn' => max(0, (int) $str('turn')),
            'pcmd' => preg_match('/^[\w.+\-\[\]]{1,64}$/u', $str('pcmd')) ? $str('pcmd') : '',
            // wywołanie pokazane w panelu szczegółów (id tool_use albo uuid załącznika)
            'pev' => preg_match('/^[\w\-]{1,80}$/', $str('pev')) ? $str('pev') : '',
            'scope' => $str('scope') === 'project' ? 'project' : '',
            'problems' => $str('problems') === '1',
            'status' => in_array($str('status'), self::STATUSES, true) ? $str('status') : '',
            'boundary' => in_array($str('boundary'), Boundary::KINDS, true) ? $str('boundary') : '',
            'view' => self::viewName($str('view')),
            'sort' => in_array($str('sort'), FileTable::SORTS, true) ? $str('sort') : '',
            'more' => $str('more') === '1',
            'group' => $str('group') === '1',
            'mk' => in_array($str('mk'), ['user', 'agents'], true) ? $str('mk') : '',
            'rows' => in_array($str('rows'), ['dirs', 'commands'], true) ? $str('rows') : 'files',
            'sx' => max(0, (int) $str('sx')),
            'dumped' => SessionExport::validDumpName($str('dumped')) ? $str('dumped') : '',
            'sy' => max(0, (int) $str('sy')),
            'origin' => in_array($str('origin'), ['context', 'self'], true) ? $str('origin') : '',
            'all' => $str('all') === '1',
            'dirs' => $str('dirs') !== '0',
            'st' => max(0, (int) $str('st')),
            'se' => max(0, (int) $str('se')),
            // zakres „all”: okres, projekt, szukanie, wykluczenia, wszystkie wiersze
            'days' => $str('recent') === '' && in_array($str('days'), ['1', '7', '14', '30'], true) ? (int) $str('days') : 0,
            'recent' => isset(LiveSummary::WINDOWS[$str('recent')]) ? $str('recent') : '',
            'find' => mb_substr(trim($str('find')), 0, 200),
            'exclude' => mb_substr(trim($str('exclude')), 0, 500),
            'project' => preg_match('/^[a-f0-9]{12}$/', $str('project')) ? $str('project') : '',
            'limit' => $str('limit') === 'all',
        ];
    }

    /** Widoki-pytania + widoki dodatkowe; stare `logs` (sprzed podziału na pytania) = `log`. */
    public const VIEWS = ['read', 'changed', 'commands', 'blocked', 'boundary', 'messages', 'timeline', 'graph', 'log'];

    /** Zakładki dostępne w zakresie „all” — agregaty nie mają pojedynczych wywołań, wiadomości ani czasu. */
    public const ALL_VIEWS = ['read', 'changed', 'commands', 'blocked', 'boundary'];

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
        if (!$p['follow']) {
            return $p['s'] !== '' ? $p['s'] : null;
        }
        if ($p['src'] === 'hook') {
            $files = glob(HookLog::dir() . '/*.jsonl') ?: [];
            usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
            $id = isset($files[0]) ? basename($files[0], '.jsonl') : '';

            return HookLog::validSessionId($id) ? $id : null;
        }

        return SessionIndex::recent(1)[0]['id'] ?? null;
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
        $statusCounts = ['executed' => 0, 'blocked' => 0, 'failed' => 0];
        foreach ($events as $e) {
            if ($e->tool !== Event::TOOL_CONTEXT) {
                $statusCounts[self::status($e)]++;
            }
        }
        // granica pracy agentów (`.env`): rodzaj per wywołanie — znaczniki w Commands, zakładka Boundary (bez filtra statusu), filtr `boundary`
        $limits = Boundary::fromEnv();
        $crossed = [];
        $boundaryRows = [];
        $boundaryCounts = array_fill_keys(Boundary::KINDS, 0);
        foreach ($events as $e) {
            $kind = $limits->classify($e);
            if ($kind === null) {
                continue;
            }
            $crossed[$e->toolUseId] = $kind;
            $boundaryCounts[$kind]++;
            if ($p['boundary'] === '' || $p['boundary'] === $kind) {
                $boundaryRows[] = ['ts' => $e->ts, 'agent' => $e->agentId, 'tool' => $e->tool, 'summary' => $e->summary, 'kind' => $kind,
                    'by' => $kind === Boundary::STOPPED ? Boundary::stoppedBy($e) : null,
                    'paths' => array_map(static fn (Touch $t): array => ['path' => $t->path, 'op' => $t->op], $limits->outside($e)),
                    'id' => $e->toolUseId, 'session_id' => null, 'title' => '', 'cwd' => (string) $session->cwd];
            }
        }
        if ($p['status'] !== '') {
            $events = array_values(array_filter($events, static fn (Event $e): bool => self::status($e) === $p['status']));
        }
        if ($p['boundary'] !== '') {
            $events = array_values(array_filter($events, static fn (Event $e): bool => ($crossed[$e->toolUseId] ?? null) === $p['boundary']));
        }
        $stats = Stats::of($events);
        $shown = $p['origin'] === ''
            ? $events
            : array_values(array_filter($events, static fn (Event $e): bool => ($e->tool === Event::TOOL_CONTEXT) === ($p['origin'] === 'context')));
        // przy blokadach i błędach tabela pokazuje pliki, do których agent próbował sięgnąć
        $tried = in_array($p['status'], ['blocked', 'failed'], true) || $p['boundary'] === Boundary::STOPPED;
        $files = FileTable::build($shown, $problems, $tried);
        $readOps = $p['more'] ? [Touch::READ, Touch::CONTEXT, Touch::SEARCH, Touch::LIST, Touch::EXEC] : [Touch::READ, Touch::CONTEXT];
        $read = array_values(array_filter($files, static fn (array $f): bool => array_intersect_key($f['ops'], array_flip($readOps)) !== []));
        $changed = array_values(array_filter($files, static fn (array $f): bool => $f['mods'] > 0));
        $bash = $tried || $p['boundary'] !== ''
            ? array_values(array_filter($shown, static fn (Event $e): bool => $e->tool !== Event::TOOL_CONTEXT))
            : array_values(array_filter($shown, static fn (Event $e): bool => $e->commands !== [] || $e->tool === 'Bash'));
        $messages = self::messages($session, $agentFilter, $turn > 0 ? $turns[$turn - 1]['ts'] : null, $turn > 0 ? ($turns[$turn]['ts'] ?? null) : null);
        $mtime = $p['src'] === 'hook' ? (int) filemtime($path) : SessionIndex::mtime($path);
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
        if ($p['sort'] === 'cost') {
            usort($bash, static fn (Event $a, Event $b): int => ($b->carry['usd'] ?? 0) <=> ($a->carry['usd'] ?? 0));
        }

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
            'timeline' => $p['view'] === 'timeline' ? (new Timeline())->build($session, $events, array_flip(array_keys($session->agents)), $p['rows'], $p['origin'], $problems, $turn > 0 ? $turns[$turn - 1]['ts'] : null) : null,
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
            'counts' => [
                'read' => count($read),
                'changed' => count($changed),
                'commands' => count($bash),
                'blocked' => count($blockedRows),
                'boundary' => count($boundaryRows),
                'messages' => count($messages),
            ],
            'blocked' => array_reverse($blockedRows),
            'boundary' => array_reverse($boundaryRows),
            'boundaryCounts' => $boundaryCounts,
            'crossed' => $crossed,
            'boundaryOn' => $limits->enabled(),
            'rowLimit' => PHP_INT_MAX,
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
            'live' => $p['live'] ?? (time() - $mtime < self::LIVE_WINDOW),
            'active' => time() - $mtime < self::LIVE_WINDOW,
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
     * Projekty (cwd sesji) do wyboru w lewym panelu, od ostatnio aktywnego. Klucz jak w podsumowaniu (`fileHash` cwd).
     * Nazwa = ostatni katalog; przy powtórzeniu (ten sam katalog w różnych miejscach) dwa ostatnie.
     *
     * @param list<array{cwd: string, mtime: int}> $sessions
     * @return array<string, array{label: string, cwd: string, sessions: int}>
     */
    public static function sidebarProjects(array $sessions): array
    {
        $projects = [];
        foreach ($sessions as $s) {
            $key = self::fileHash($s['cwd']);
            $projects[$key] ??= ['label' => basename($s['cwd']) ?: '?', 'cwd' => $s['cwd'], 'sessions' => 0];
            $projects[$key]['sessions']++;
        }
        $names = array_count_values(array_column($projects, 'label'));
        foreach ($projects as &$pr) {
            if ($names[$pr['label']] > 1) {
                $pr['label'] = basename(dirname($pr['cwd'])) . '/' . $pr['label'];
            }
        }

        return $projects;
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

    public static function query(array $view, array $override = []): string
    {
        $p = $view['p'];
        $q = array_filter([
            's' => $p['follow'] ? '' : $view['sid'],
            'src' => $p['src'] === 'transcript' ? '' : $p['src'],
            'agent' => $view['agentFilter'] ?? '',
            'follow' => $p['follow'] ? '1' : '',
            'turn' => $p['turn'] > 0 ? (string) $p['turn'] : '',
            'scope' => $p['scope'],
            'problems' => $p['problems'] ? '1' : '',
            'status' => $p['status'],
            'boundary' => $p['boundary'],
            'view' => $p['view'] === 'read' ? '' : $p['view'],
            'sort' => $p['sort'],
            'more' => $p['more'] ? '1' : '',
            'group' => $p['group'] ? '1' : '',
            'mk' => $p['mk'],
            'pcmd' => $p['pcmd'],
            'rows' => $p['rows'] === 'files' ? '' : $p['rows'],
            'origin' => $p['origin'],
            'all' => $p['all'] ? '1' : '',
            'dirs' => $p['dirs'] ? '' : '0',
            'days' => $p['days'] > 0 ? (string) $p['days'] : '',
            'recent' => $p['recent'],
            'find' => $p['find'],
            'exclude' => $p['exclude'],
            'project' => $p['project'],
            'limit' => $p['limit'] ? 'all' : '',
        ], static fn (string $v): bool => $v !== '');

        return http_build_query(array_filter(array_merge($q, $override), static fn (string $v): bool => $v !== ''));
    }

    public static function agentLabel(Agent $a): string
    {
        return $a->id === Agent::MAIN ? 'main' : $a->label();
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
            'long' => $usd . ' · ' . $t['calls'] . ($t['calls'] === 1 ? ' model call' : ' model calls') . ($t['subagentCalls'] > 0 ? ' (' . $t['subagentCalls'] . ' by subagents)' : '')
                . ' · output ' . $out . ' · new context ' . self::tokenCount($t['cacheWrite']) . ' · cache read ' . self::tokenCount($t['cacheRead'] + $t['input']),
        ];
    }
}
