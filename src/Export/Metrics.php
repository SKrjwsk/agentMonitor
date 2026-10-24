<?php

declare(strict_types=1);

namespace Viz\Export;

use Viz\Clock;
use Viz\Model\Event;
use Viz\Model\Session;
use Viz\Model\Touch;
use Viz\Path;
use Viz\Extract\ToolRoles;
use Viz\Problems;
use Viz\Web\App;

/**
 * Deterministyczne metryki sesji — materiał dowodowy dla analizy (skill `analiza-sesji`), bez interpretacji.
 */
final class Metrics
{
    private const GAP_SECONDS = 300;

    /** @param list<Event> $events */
    public static function of(Session $session, array $events): array
    {
        $calls = array_values(array_filter($events, static fn (Event $e): bool => $e->tool !== Event::TOOL_CONTEXT));
        $cwd = $session->cwd;
        $short = static fn (string $p): string => App::shortPath($p, $cwd);

        // czas
        $times = array_map(static fn (Event $e): float => self::sec($e->ts), $calls);
        sort($times);
        $active = 0.0;
        $gaps = [];
        for ($i = 1, $n = count($times); $i < $n; $i++) {
            $d = $times[$i] - $times[$i - 1];
            $d > self::GAP_SECONDS ? $gaps[] = (int) round($d / 60) : $active += $d;
        }

        // odczyty i zmiany per plik (bez wywołań z błędem)
        $reads = [];
        $partialOnly = [];
        $context = [];
        $edited = [];
        $editedBlind = [];
        $readByAgent = [];
        $agentsPerFile = [];
        $sharedPaths = [];
        $dirs = [];
        foreach ($events as $e) {
            if (!$e->executed()) {
                continue;
            }
            foreach ($e->touches as $t) {
                $key = Path::key($t->path);
                if (in_array($t->op, [Touch::READ, Touch::CONTEXT, Touch::EDIT, Touch::WRITE], true)) {
                    $agentsPerFile[$key][$e->agentId] = true;
                    $sharedPaths[$key] = $t->path;
                }
                $dirs[Path::key(dirname($t->path))] = true;
                if ($t->op === Touch::CONTEXT) {
                    $context[$key] = $t->path;
                    $readByAgent[$e->agentId][$key] = true;
                }
                if ($t->op === Touch::READ) {
                    $reads[$key] = ['path' => $t->path, 'n' => ($reads[$key]['n'] ?? 0) + 1];
                    $partialOnly[$key] = ($partialOnly[$key] ?? true) && $t->partial;
                    $readByAgent[$e->agentId][$key] = true;
                }
                if (in_array($t->op, [Touch::EDIT, Touch::WRITE], true)) {
                    $edited[$key] = $t->path;
                    if ($t->op === Touch::EDIT && !isset($readByAgent[$e->agentId][$key])) {
                        $editedBlind[$key] = $t->path;
                    }
                    // plik utworzony albo zmieniony przez agenta jest mu znany — kolejna edycja nie jest „na ślepo”
                    $readByAgent[$e->agentId][$key] = true;
                }
            }
        }
        $reread = array_filter($reads, static fn (array $r): bool => $r['n'] > 1);
        uasort($reread, static fn (array $a, array $b): int => $b['n'] <=> $a['n']);

        // błędy
        $failedByTool = [];
        $failedExamples = [];
        $failedReasons = [];
        foreach ($calls as $e) {
            if ($e->failed) {
                $failedByTool[$e->tool] = ($failedByTool[$e->tool] ?? 0) + 1;
                if (count($failedExamples) < 15) {
                    $failedExamples[] = Clock::local($e->ts) . ' ' . $e->tool . ': ' . mb_strimwidth($e->summary, 0, 120, '…')
                        . ($e->error !== null ? ' → ' . $e->error : '');
                }
                if ($e->error !== null) {
                    $reason = mb_substr((string) preg_replace('/[0-9a-f]{6,}|\d+/', '#', $e->error), 0, 70);
                    $failedReasons[$reason] = ($failedReasons[$reason] ?? 0) + 1;
                }
            }
        }

        // eksploracja przed pierwszą zmianą
        $firstEdit = null;
        foreach ($calls as $i => $e) {
            foreach ($e->touches as $t) {
                if (in_array($t->op, [Touch::EDIT, Touch::WRITE], true) && $e->executed()) {
                    $firstEdit = $i;
                    break 2;
                }
            }
        }

        // najdłuższe wywołania
        $durations = [];
        foreach ($calls as $e) {
            if ($e->endTs !== null) {
                $durations[] = ['s' => self::sec($e->endTs) - self::sec($e->ts), 'e' => $e];
            }
        }
        usort($durations, static fn (array $a, array $b): int => $b['s'] <=> $a['s']);

        // agenci
        $agents = [];
        foreach ($session->agents as $agent) {
            $own = array_values(array_filter($calls, static fn (Event $e): bool => $e->agentId === $agent->id));
            if ($own === []) {
                continue;
            }
            $tokens = array_column(array_filter($session->usage, static fn (array $u): bool => $u['agent'] === $agent->id), 'tokens');
            $agents[] = [
                'id' => $agent->id,
                'label' => $agent->label(),
                'calls' => count($own),
                'files_read' => count($readByAgent[$agent->id] ?? []),
                'start' => Clock::local($own[0]->ts),
                'end' => Clock::local(end($own)->ts),
                'max_context_tokens' => $tokens === [] ? null : max($tokens),
                'tokens' => self::tokens($session->tokens($agent->id)),
            ];
        }

        // tury: prompt usera → praca do następnego promptu
        $turns = [];
        $prompts = array_values(array_filter($session->prompts, static fn (array $p): bool => $p['kind'] === 'prompt'));
        foreach ($prompts as $i => $p) {
            $from = $p['ts'];
            $to = $prompts[$i + 1]['ts'] ?? '9999';
            $in = array_values(array_filter($calls, static fn (Event $e): bool => $e->ts >= $from && $e->ts < $to));
            $turnFiles = [];
            $turnEdits = 0;
            foreach ($in as $e) {
                foreach ($e->touches as $t) {
                    $turnFiles[Path::key($t->path)] = true;
                    $turnEdits += in_array($t->op, [Touch::EDIT, Touch::WRITE], true) ? 1 : 0;
                }
            }
            $turns[] = [
                'n' => $i + 1,
                'at' => Clock::local($p['ts']),
                'prompt' => mb_strimwidth($p['text'], 0, 200, '…'),
                'calls' => count($in),
                'files_touched' => count($turnFiles),
                'edits' => $turnEdits,
                'failed' => count(array_filter($in, static fn (Event $e): bool => $e->failed)),
                'minutes' => $in === [] ? 0 : round((self::sec(end($in)->endTs ?? end($in)->ts) - self::sec($from)) / 60, 1),
                'tokens' => self::tokens($session->tokens('', $from, $to)),
            ];
        }

        $shared = array_filter($agentsPerFile, static fn (array $a): bool => count($a) > 1);

        return [
            'time' => [
                'first' => $calls === [] ? null : Clock::local($calls[0]->ts, 'Y-m-d H:i:s'),
                'last' => $calls === [] ? null : Clock::local(end($calls)->ts, 'Y-m-d H:i:s'),
                'active_minutes' => round($active / 60, 1),
                'breaks_over_5min' => $gaps,
            ],
            'calls' => [
                'total' => count($calls),
                'failed' => array_sum($failedByTool),
                'failed_by_tool' => $failedByTool,
                'failed_examples' => $failedExamples,
                'failed_reasons' => (static function (array $r): array { arsort($r); return array_slice($r, 0, 10, true); })($failedReasons),
                // czas od wywołania do wyniku — obejmuje czekanie na zgodę usera
                'longest_until_result' => array_map(static fn (array $d): string => round($d['s'], 1) . ' s · ' . $d['e']->tool . ': ' . mb_strimwidth($d['e']->summary, 0, 120, '…'), array_slice($durations, 0, 8)),
                'before_first_edit' => $firstEdit,
            ],
            'files' => [
                'context_injected' => array_values(array_map($short, $context)),
                'read_unique' => count($reads),
                'read_partial_only' => count(array_filter($partialOnly)),
                'reread' => array_map(static fn (array $r): string => $r['n'] . '× ' . $short($r['path']), array_slice(array_values($reread), 0, 20)),
                'context_also_read_by_tool' => array_values(array_map(static fn (array $r): string => $short($r['path']), array_intersect_key($reads, $context))),
                'edited' => array_values(array_map($short, $edited)),
                'edited_without_prior_read_same_agent' => array_values(array_map($short, $editedBlind)),
                'directories_touched' => count($dirs),
                // tylko pliki czytane albo zmieniane przez ≥ 2 agentów (bez katalogów i listingów)
                'shared_read_between_agents' => count($shared),
                'shared_read_examples' => array_map(static fn (string $k): string => count($agentsPerFile[$k]) . ' agents · ' . $short($sharedPaths[$k]), array_slice(array_keys($shared), 0, 15)),
            ],
            'parser' => [
                'bash_blind' => count(array_filter($calls, static fn (Event $e): bool => $e->tool === 'Bash' && !$e->failed && $e->isBlind())),
                'indirect' => array_count_values(array_merge(...array_map(static fn (Event $e): array => $e->indirect, $calls))),
            ],
            'tokens' => self::tokens($session->tokens()),
            'expensive_results' => self::expensive($session),
            'agents' => $agents,
            'turns' => $turns,
            'problems' => self::problemSummary(Problems::tag($events)),
            'commands' => self::commands($calls),
            'compactions' => count(array_filter($session->prompts, static fn (array $p): bool => $p['kind'] === 'compact')),
        ];
    }

    /**
     * Liczniki z `message.usage` osobno — suma byłaby w ~99% odczytem z cache (ten sam kontekst przy każdym wywołaniu).
     * Bez filtra agenta i statusu: model zużył tokeny także na wywołania zablokowane.
     *
     * @param array{calls: int, subagentCalls: int, outputPartial: int, output: int, cacheWrite: int, cacheRead: int, input: int, models: array<string, int>} $t
     */
    private static function tokens(array $t): array
    {
        return [
            'model_calls' => $t['calls'],
            'subagent_model_calls' => $t['subagentCalls'],
            'output' => $t['output'],
            // odpowiedzi bez końcowego usage (subagenci w Claude Code ≤ 2.1.300) — `output` jest wtedy dolną granicą
            'output_incomplete_calls' => $t['outputPartial'],
            'cache_write' => $t['cacheWrite'],
            'cache_read' => $t['cacheRead'],
            'input_uncached' => $t['input'],
            // koszt według cennika API (Pricing) — miara porównawcza; `unpriced_calls` = model spoza cennika, nie ma go w koszcie
            'cost_usd' => round($t['usd'], 4),
            'cost_usd_parts' => array_map(static fn (float $v): float => round($v, 4), $t['cost']),
            'unpriced_calls' => $t['unpriced'],
            'models' => $t['models'],
        ];
    }

    /**
     * Najdroższe wyniki narzędzi (Event::carry): tokeny dopisane do kontekstu × dalsze odpowiedzi agenta, które je czytały.
     * Cała sesja, bez filtrów zrzutu — jak `tokens`.
     */
    private static function expensive(Session $session): array
    {
        $events = array_values(array_filter($session->events, static fn (Event $e): bool => ($e->carry['usd'] ?? 0) > 0));
        usort($events, static fn (Event $a, Event $b): int => $b->carry['usd'] <=> $a->carry['usd']);

        return array_map(static fn (Event $e): array => [
            'ts' => $e->ts, 'agent' => $e->agentId, 'tool' => $e->tool, 'summary' => $e->summary,
            'context_tokens' => $e->carry['tokens'], 'later_calls' => $e->carry['calls'], 'cost_usd' => round((float) $e->carry['usd'], 4),
        ], array_slice($events, 0, 15));
    }

    /**
     * Programy: wystąpienia, wywołania z błędem, konteksty, najczęstsze pary w potokach, komendy ryzykowne i interaktywne.
     *
     * @param list<Event> $calls
     */
    private static function commands(array $calls): array
    {
        $programs = [];
        $pairs = [];
        $risky = [];
        $interactive = [];
        foreach ($calls as $e) {
            foreach ($e->commands as $c) {
                $p = $programs[$c->program] ?? ['count' => 0, 'failed_calls' => 0, 'blocked_calls' => 0, 'role' => ToolRoles::role($c->program), 'contexts' => []];
                $p['count']++;
                $p['failed_calls'] += $e->failed && $e->executed() ? 1 : 0;
                $p['blocked_calls'] += $e->executed() ? 0 : 1;
                foreach ([...$c->contexts, ...$c->wrappers] as $ctx) {
                    $p['contexts'][$ctx] = ($p['contexts'][$ctx] ?? 0) + 1;
                }
                $programs[$c->program] = $p;
                if ($c->pipedFrom !== null) {
                    $pairs[$c->pipedFrom . ' | ' . $c->program] = ($pairs[$c->pipedFrom . ' | ' . $c->program] ?? 0) + 1;
                }
                $r = ToolRoles::risky($c);
                if ($r !== null && count($risky) < 20) {
                    $risky[] = Clock::local($e->ts) . ' ' . $r . ': ' . mb_strimwidth($e->summary, 0, 120, '…');
                }
                if (ToolRoles::interactive($c) && count($interactive) < 20) {
                    $interactive[] = Clock::local($e->ts) . ' ' . $c->program . ': ' . mb_strimwidth($e->summary, 0, 120, '…');
                }
            }
        }
        uasort($programs, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        arsort($pairs);
        $roles = [];
        foreach ($programs as $p) {
            $roles[$p['role']] = ($roles[$p['role']] ?? 0) + $p['count'];
        }
        arsort($roles);

        return [
            'occurrences' => array_sum(array_column($programs, 'count')),
            'programs' => count($programs),
            'by_role' => $roles,
            'top' => array_slice($programs, 0, 25, true),
            'top_pipes' => array_slice($pairs, 0, 15, true),
            'risky' => $risky,
            'interactive' => $interactive,
        ];
    }

    /**
     * @param array<string, list<string>> $tags
     * @return array<string, int> rodzaj problemu => liczba wywołań
     */
    private static function problemSummary(array $tags): array
    {
        $out = [];
        foreach ($tags as $reasons) {
            foreach (array_unique(array_map(static fn (string $r): string => explode(':', explode(' (', $r)[0])[0], $reasons)) as $kind) {
                $out[$kind] = ($out[$kind] ?? 0) + 1;
            }
        }
        arsort($out);

        return $out;
    }

    private static function sec(string $iso): float
    {
        try {
            return (float) (new \DateTimeImmutable($iso))->format('U.u');
        } catch (\Exception) {
            return 0.0;
        }
    }
}
