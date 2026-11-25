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

/** Controller: GET only, parameters validated against a whitelist, no parameter is a path. */
final class App
{

    /** `s=all` — the "all sessions" scope: the same tabs as a session, data from the var/summary aggregates. */
    public const ALL = 'all';

    /** Table rows in the "all" scope without `limit=all` (thousands of files). */
    private const ALL_ROWS = 300;

    /** The left panel shows sessions of all projects — choosing a project filters the list in the browser. */
    private const SIDEBAR_LIMIT = 300;

    /** Explanations in the details panel: what a file operation means from the agent's point of view. */
    public const OP_HELP = [
        Touch::READ => 'the content reached the agent (Read, cat, sed, head…)',
        Touch::CONTEXT => 'Claude Code loaded it into the context by itself (CLAUDE.md, rules, @file) — not a tool call',
        Touch::SEARCH => 'searched — the agent saw only the matching lines (Grep, grep, rg)',
        Touch::LIST => 'listed — the agent saw names or metadata, not the content (ls, Glob, find, wc, stat)',
        Touch::EDIT => 'changed in place (Edit, sed -i)',
        Touch::WRITE => 'created or overwritten (Write, > file, cp, mv)',
        Touch::EXEC => 'run as a program or given to one — the agent saw its output, not the file',
    ];

    /** Program context in a Bash command (Command::$contexts). */
    public const CONTEXT_HELP = [
        'pipe' => 'right side of a pipe |',
        'subst' => 'inside $(…)',
        'xargs' => 'run by xargs',
        'inner' => 'inside bash -c / sh -c',
        'remote' => 'run on another machine (ssh)',
        'stdin-script' => 'script fed through stdin (heredoc)',
    ];

    /** What the tool does — one sentence for the call panel. */
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
        // overview views (ScopeViews): page and JSON data, project scope from Scope
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
        // without a session: the "Overview" start page (recomputes aggregates, so before reading costs for the left panel)
        $overview = $view === null ? $this->overview($q) : null;
        $scope = Scope::fromRequest($q, $_COOKIE);
        $sidebarTokens = SummaryStore::tokenTotals(array_column($sessions, 'id'));
        // open session: counters from the transcript, the aggregate may be from before the latest calls
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
     * Session dump to var/session_dump/<name>/ — the same view (source, agent filter) as on screen.
     * POST from our own page only (index.php checks the origin); after the write, a redirect with the dump name.
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

    /** Session dump with the current source and filters to `var/session_dump/<name>/`; returns the name. */
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

    /** Whether a session assessment is running — a cheap read of the `.running` file without parsing the transcript (the "Assessment" tab asks every 5 s). */
    private function judgeStatus(array $q): void
    {
        $sid = is_string($q['s'] ?? null) ? $q['s'] : '';
        header('Content-Type: application/json');
        echo json_encode(['running' => HookLog::validSessionId($sid) && SessionJudge::running($sid, time()) !== null]);
    }

    /** On-demand session assessment: dump of the whole session (without view filters) and the judge in the background (SessionJudge) → the "Assessment" tab. */
    private function judge(array $q): void
    {
        $sid = is_string($q['s'] ?? null) ? $q['s'] : '';
        $view = HookLog::validSessionId($sid) ? $this->view(['s' => $sid, 'view' => 'judge']) : null;
        if ($view === null || $view['missing'] || $view['allSessions']) {
            $this->notFound();
            return;
        }
        if (SessionJudge::disabledInDemo()) {
            http_response_code(403);
            echo t('The session judge is disabled in the demo');
            return;
        }
        $started = SessionJudge::start($sid, SessionExport::dumpRoot() . '/' . $this->dump($view), time());
        header('Location: /?' . http_build_query(['s' => $sid, 'view' => 'judge'] + ($started ? [] : ['busy' => '1'])), true, 303);
    }

    /** Old summary address → the "all" scope with remapped parameters (tabs replaced `kind`). */
    private function summaryRedirect(array $q): void
    {
        $str = static fn (string $k): string => is_string($q[$k] ?? null) ? $q[$k] : '';
        $target = array_filter([
            's' => self::ALL,
            'view' => match ($str('kind')) { 'commands' => 'commands', 'blocked' => 'blocked', default => '' },
            'pick' => $str('file'),
            'pcmd' => $str('cmd'),
            'sort' => in_array($str('sort'), ['path', 'sessions'], true) ? $str('sort') : '',
            // old project filter → project scope (Scope)
            Scope::PARAM => $str('project'),
        ] + array_intersect_key(array_map('strval', array_filter($q, 'is_string')), array_flip(['days', 'recent', 'find', 'exclude', 'scope', 'limit'])), static fn (string $v): bool => $v !== '');
        header('Location: /?' . http_build_query($target), true, 302);
    }

    /** CSV of the current tab with the current filters — in a session and in the "all" scope; the whole list, not just the shown rows. Without `s` = all sessions. */
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
            // the "all" Log is pages of the latest calls, not the whole list — CSV only from a session
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
     * CSV of one session's tab. Messages: subagent responses without content (they quote files — the "no file content" rule);
     * Log: all calls within the filters.
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
            // Commands: calls from the list (program filter); other views: all calls within the scope
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
     * An entry starting with `/` or `~` = a path prefix (full or with `~`); any other = a fragment anywhere.
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

    /** Spreadsheets execute cells starting with = + - @ — the data is untrusted. */
    private static function csvSafe(string $value): string
    {
        return Csv::safe($value);
    }

    /**
     * The "all" scope: tabs Files read / Files changed / Commands / Blocked from the var/summary aggregates
     * (archive — stays after transcripts are deleted). Rows in the same shape as in a session, without fields the aggregates do not know.
     */
    private function allView(array $p): array
    {
        $store = new SummaryStore();
        $refreshed = $store->refresh();
        $archive = $store->all();
        $source = $p['recent'] !== '' ? LiveSummary::aggregates(LiveSummary::WINDOWS[$p['recent']]) : $archive;
        // the "sessions" sort keeps all files (also those only searched/listed); the order is set by FileTable::sort
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

        // block log from all sessions in scope (period, project), newest on top
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

        // permission questions from all sessions in scope (aggregates from `PARSER` 18), newest on top
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
            // Log from transcripts (aggregates have no single calls) — computed only on its own tab, without a counter
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
     * "all" scope Log: the latest tool calls (`ALL_ROWS`, older ones in pages via `before`) from sessions that still have a transcript.
     * Sessions from the most recently active; reading stops when the next session ended before the oldest call of a full page —
     * usually a few transcripts. Without "show all": the whole thing is parsing every transcript in one request, and `php -S` serves one at a time.
     *
     * @param list<array<string, mixed>> $sessions session aggregates in scope (period, project)
     * @param list<string> $excludes
     * @return list<array{event: Event, session_id: string, title: string, cwd: string}> newest first
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

    /** Start page axes (Signals::KINDS) in display order; in each one separately: what you can change and what the agent can. */
    public const OVERVIEW_AXES = [
        'context' => 'Context',
        'communication' => 'Communication',
        'boundaries' => 'Boundaries',
    ];

    /** Default period of the start page (days); `days=all` = the whole history. */
    private const OVERVIEW_DAYS = 7;

    /**
     * Start page: signals from all sessions (var/summary aggregates, so archived ones too) in the period and project.
     * The `info` counters are the sum of sessions active in the period (the aggregate does not know their time).
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
            // tool time, equipment and result sizes (aggregates from `PARSER` 15; older ones lack the fields)
            foreach ((array) ($s['slow'] ?? []) as $c) {
                if ($cutoff === '' || $c['ts'] >= $cutoff) {
                    $slow[] = $c + ['session_id' => $sid, 'title' => $sessions[$sid]['title']];
                }
            }
            // calls from the "permission?" threshold: count and sum over the whole session, list of the longest from the period
            $waits['count'] += (int) ($s['waits']['count'] ?? 0);
            $waits['ms'] += (int) ($s['waits']['ms'] ?? 0);
            foreach ((array) ($s['waits']['top'] ?? []) as $c) {
                if ($cutoff === '' || $c['ts'] >= $cutoff) {
                    $waits['top'][] = $c + ['session_id' => $sid, 'title' => $sessions[$sid]['title']];
                }
            }
            // time per tool: sum over the whole session (the aggregate does not split it into periods — like the session cost)
            foreach ((array) ($s['tool_time'] ?? []) as $tool => $t) {
                $r = $toolTime[$tool] ?? ['tool' => (string) $tool, 'count' => 0, 'ms' => 0, 'max' => 0];
                $toolTime[$tool] = ['count' => $r['count'] + $t['count'], 'ms' => $r['ms'] + $t['ms'], 'max' => max($r['max'], $t['max'])] + $r;
            }
            foreach ((array) ($s['equipment'] ?? []) as $kind => $names) {
                foreach ((array) $names as $name => $row) {
                    $e = $equipment[$kind][$name] ?? ['name' => (string) $name, 'count' => 0, 'last' => '', 'sessions' => 0];
                    $sum = ['count' => $e['count'] + $row['count'], 'last' => max($e['last'], (string) $row['last']), 'sessions' => $e['sessions'] + 1];
                    if ($kind === 'mcp') {
                        // MCP server time and errors: only sessions recomputed since `PARSER` 20 (older aggregates lack the fields — they count as 0)
                        foreach (['timed', 'ms', 'failed'] as $f) {
                            $sum[$f] = ($e[$f] ?? 0) + (int) ($row[$f] ?? 0);
                        }
                        $sum['max'] = max($e['max'] ?? 0, (int) ($row['max'] ?? 0));
                    }
                    $equipment[$kind][$name] = $sum + $e;
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
        // `high` first, then amounts descending (kinds in $), the rest from newest
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

        // charts: cost over time (session at its last activity), cost by project, signals by kind
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
        // projects with the same directory name (different paths) get the parent directory in the name
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
     * Cost chart timeline: the last 24 hours (hourly) or the last days of the period; without a period — from the first to the last session (max 90 days).
     * Empty intervals have cost 0, so the columns stand at equal spacing.
     *
     * @param array<string, array{usd: float, n: int}> $byTime key = `Y-m-d` or `Y-m-d H` in local time
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

    /** Signal amount for sorting; kinds outside Signals::USD_KINDS = 0. */
    private static function signalUsd(array $it): float
    {
        return in_array($it['kind'], Signals::USD_KINDS, true) ? (float) $it['value'] : 0.0;
    }

    /** Signal link to the session view that shows the cause (turn, call in the details panel, tab). */
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

    /** Overview view (`ScopeViews`): a page with the left panel; the browser fetches data from the view's JSON route. */
    private function scopePage(array $scopeView, array $q): void
    {
        $sessions = SessionIndex::recent(self::SIDEBAR_LIMIT);
        $view = null;
        $overview = null;
        $scope = Scope::fromRequest($q, $_COOKIE);
        $sidebarTokens = SummaryStore::tokenTotals(array_column($sessions, 'id'));
        // remembered view that "Live preview" in the left panel and a bare `/` lead to
        header('Set-Cookie: ' . ScopeViews::COOKIE . '=' . $scopeView['path'] . '; Path=/; Max-Age=31536000; SameSite=Lax');
        $_COOKIE[ScopeViews::COOKIE] = $scopeView['path'];
        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__, 2) . '/templates/layout.php';
    }

    /**
     * Overview views data: the shared feed (`Feed`, with cache) for sessions in the project scope — the only data route
     * (`ScopeViews::isData`). Always from the transcript — the hook does not know the model's response or the agent's model.
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
            // call shown in the details panel (tool_use id or attachment uuid)
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
            // the session assessment was already running, no new one started (POST /judge)
            'busy' => $str('busy') === '1',
            'sy' => max(0, (int) $str('sy')),
            'origin' => in_array($str('origin'), ['context', 'self'], true) ? $str('origin') : '',
            'st' => max(0, (int) $str('st')),
            'se' => max(0, (int) $str('se')),
            // Overview and the "all" scope: projects from the left panel (`viz-scope` cookie, the `projects` parameter takes precedence)
            'projects' => Scope::fromRequest($q, $_COOKIE),
            // "all" scope: period, search, exclusions, all rows
            'days' => $str('recent') === '' && in_array($str('days'), ['1', '7', '14', '30'], true) ? (int) $str('days') : 0,
            'recent' => isset(LiveSummary::WINDOWS[$str('recent')]) ? $str('recent') : '',
            'find' => mb_substr(trim($str('find')), 0, 200),
            'exclude' => mb_substr(trim($str('exclude')), 0, 500),
            'limit' => $str('limit') === 'all',
            // "all" scope, Log tab: the page of calls older than this moment ("older" link)
            'before' => preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d{1,6})?Z?$/', $str('before')) ? $str('before') : '',
        ];
    }

    /** Question views + additional views; the old `logs` (from before the split into questions) = `log`. */
    public const VIEWS = ['read', 'changed', 'commands', 'blocked', 'approvals', 'messages', 'flow', 'log', 'judge'];

    /**
     * Tabs available in the "all" scope. Without Messages (conversations of different sessions mixed in time say nothing, and aggregates do not have them),
     * Flow (diagram of the main session and its subagents — a cross-section of all sessions is the overview views Work axis / Agents) and Assessment (assessment of one session).
     */
    public const ALL_VIEWS = ['read', 'changed', 'commands', 'blocked', 'approvals', 'log'];

    /**
     * Call status: executed (also Bash with exit code ≠ 0), blocked (hook, deny rule, auto mode, user — `Event::denial`)
     * or failed without a block (tool error, e.g. "File does not exist").
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
        // turns = user prompts; turn N lasts from prompt N to prompt N+1
        $turns = array_values(array_filter($session->prompts, static fn (array $pr): bool => $pr['kind'] === 'prompt'));
        $turn = $p['turn'] >= 1 && $p['turn'] <= count($turns) ? $p['turn'] : 0;
        // problems are marked on the agent's full set (re-reads count across the whole session), filtered at the end
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
        // Blocked tab: blocked calls within the other filters (without the status filter)
        $blockedRows = [];
        foreach ($events as $e) {
            if ($e->denial !== null) {
                $blockedRows[] = ['ts' => $e->ts, 'agent' => $e->agentId, 'tool' => $e->tool, 'summary' => $e->summary, 'by' => $e->denial['by'], 'detail' => $e->denial['detail'],
                    'id' => $e->toolUseId, 'session_id' => null, 'title' => '', 'cwd' => (string) $session->cwd];
            }
        }
        // Approvals tab: permission questions (hook, user rejection, long wait) with the cause from settings and the command
        $approvalSettings = Settings::forSession($session->cwd);
        $approvals = Approvals::rows($events, HookLog::state($sid), $approvalSettings, $session->cwd, time(), $session->modes);
        $statusCounts = ['executed' => 0, 'blocked' => 0, 'failed' => 0];
        foreach ($events as $e) {
            if ($e->tool !== Event::TOOL_CONTEXT) {
                $statusCounts[self::status($e)]++;
            }
        }
        // agent work boundary (the AGENT_ALLOWED_* settings): kind per call, markers in Commands
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
        // for blocks and errors the table shows the files the agent tried to reach
        $tried = in_array($p['status'], ['blocked', 'failed'], true);
        $files = FileTable::build($shown, $problems, $tried);
        $readOps = $p['more'] ? [Touch::READ, Touch::CONTEXT, Touch::SEARCH, Touch::LIST, Touch::EXEC] : [Touch::READ, Touch::CONTEXT];
        $read = array_values(array_filter($files, static fn (array $f): bool => array_intersect_key($f['ops'], array_flip($readOps)) !== []));
        $changed = array_values(array_filter($files, static fn (array $f): bool => $f['mods'] > 0));
        // Read / Changed views of one session: a flat list of calls, newest on top (like Log)
        $readCalls = array_reverse(FileTable::calls($shown, $readOps, $problems, $tried));
        $changedCalls = array_reverse(FileTable::calls($shown, [Touch::EDIT, Touch::WRITE], $problems, $tried));
        $bash = $tried
            ? array_values(array_filter($shown, static fn (Event $e): bool => $e->tool !== Event::TOOL_CONTEXT))
            : array_values(array_filter($shown, static fn (Event $e): bool => $e->commands !== [] || $e->tool === 'Bash'));
        $messages = self::messages($session, $agentFilter, $turn > 0 ? $turns[$turn - 1]['ts'] : null, $turn > 0 ? ($turns[$turn]['ts'] ?? null) : null);
        $transcript = TranscriptReader::locate($sid);
        // tokens within the turn (or the whole session) per agent — independent of agent and status filters
        $tokFrom = $turn > 0 ? $turns[$turn - 1]['ts'] : null;
        $tokTo = $turn > 0 ? ($turns[$turn]['ts'] ?? null) : null;
        $agentTokens = array_filter(
            array_map(static fn (Agent $a): array => $session->tokens($a->id, $tokFrom, $tokTo), $session->agents),
            static fn (array $t): bool => $t['calls'] > 0,
        );
        // by call count, not output: subagent output is sometimes understated (`outputPartial`)
        uasort($agentTokens, static fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);
        // most expensive tool results in the same scope (all agents): write cost + reads at later calls
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
        // common path root of the whole session (without filters, so it does not jump between tabs) — files-calls.php, log.php
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
            // Flow tab: the whole session in the feed row shape (flow.js); agent and turn filters do not apply to it
            'flow' => $p['view'] === 'flow' ? LiveState::session($session, time(), Boundary::fromEnv(), true) : null,
            // Flow tab: instruction files loaded into the context (InstructionsLoaded hook; paths only, no content)
            'flowContext' => $p['view'] === 'flow' ? HookLog::instructions($session->id) : [],
            'turns' => $turns,
            'turn' => $turn,
            // token usage per turn (all agents, subagents by time) — independent of agent and status filters
            'turnTokens' => array_map(static fn (int $i): array => $session->tokens('', $turns[$i]['ts'], $turns[$i + 1]['ts'] ?? null), array_keys($turns)),
            'tokens' => $session->tokens('', $tokFrom, $tokTo),
            'sessionTokens' => $session->tokens(),
            'carried' => array_sum(array_map(static fn (Event $e): float => $e->carry['usd'] ?? 0.0, array_filter($session->events,
                static fn (Event $e): bool => ($tokFrom === null || $e->ts >= $tokFrom) && ($tokTo === null || $e->ts < $tokTo)))),
            'expensive' => array_slice($expensive, 0, 8),
            'agentTokens' => $agentTokens,
            // agent select: usage over the whole session, regardless of turn
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
            // Log also shows files loaded by Claude Code — a hint for the tab counter (tabs.php)
            'logContext' => $logCount['context'],
            'pathRoot' => $pathRoot,
            'blocked' => array_reverse($blockedRows),
            'approvals' => $approvals,
            'approvalsTop' => Approvals::top($approvals),
            'approvalSettings' => $approvalSettings,
            // session permission mode from the transcript (changes at user messages) — line above the Approvals table
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
     * Messages within the filters: agent = sender or recipient; turn = time from a prompt to the next prompt.
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
     * Touches under the session directory only; a call disappears when all its files were outside the project.
     * Calls without files (Bash without paths, Agent) stay — that is also work in the project.
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

    /** Path for display: relative to the session cwd, otherwise with `~`. */
    /**
     * Projects to choose the scope from in the left panel (`Projects::of`).
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

    /** Call text for the list: paths under the session directory relative, home ones with `~`. */
    public static function shortText(string $text, ?string $cwd): string
    {
        if ($cwd !== null && $cwd !== '/') {
            $text = str_replace($cwd . '/', '', $text);
        }
        $home = Path::home();

        return $home !== '' ? str_replace($home . '/', '~/', $text) : $text;
    }

    /** Rows of session and "all" scope lists (files, messages): `limit=all` = all, otherwise `ALL_ROWS` and a "show all" link. */
    public static function rowLimit(array $p): int
    {
        return $p['limit'] ? PHP_INT_MAX : self::ALL_ROWS;
    }

    /**
     * Common path root of the session, against which lists shorten paths (shown once in the list bar): the deepest directory
     * with at least half of the unique paths that is the session directory or a repository root (has `.git`); without such —
     * the deepest directory common to all paths. The home directory and above do not count (`~` shortens them). Null = none.
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
     * Log tab counter: tool calls (the same as "all calls" in the status select) and separately files loaded
     * by Claude Code (`context`), which Log also shows. With the "context" origin filter the counter is those files.
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

    /** `$raw` = without translation (CSV stays in English). */
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

    /** $0.42 · $3.17 · $12.4 · $128 — cost per the API price list (Pricing). */
    public static function usd(float $usd): string
    {
        return '$' . match (true) {
            $usd >= 100 => number_format($usd, 0),
            $usd >= 10 => number_format($usd, 1),
            default => number_format($usd, 2),
        };
    }

    /** 850 ms · 4.2 s · 3 min 05 s · 1 h 12 min — call duration (`SummaryStore::durationMs`). */
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
     * Usage description: cost per the API price list as the main measure, token counters separately (no sum), `≥` when output is understated by the transcript.
     * `?` next to a cost = part of the responses is from a model outside the price list.
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
