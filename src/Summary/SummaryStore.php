<?php

declare(strict_types=1);

namespace Viz\Summary;

use Viz\Model\Event;
use Viz\Model\Touch;
use Viz\Path;
use Viz\Source\HookLog;
use Viz\Source\SessionIndex;
use Viz\Source\TranscriptReader;

/**
 * Agregaty per sesja w var/summary/<session_id>.json: ścieżka → liczniki operacji.
 * Przeliczane, gdy transkrypt urósł; zostają po usunięciu transkryptu przez Claude Code (cleanupPeriodDays),
 * więc są też archiwum. Bez treści plików — tylko ścieżki i liczniki.
 */
final class SummaryStore
{
    /**
     * Wersja reguł parsera. Podbij przy zmianie parsera/modelu: agregaty sesji z transkryptem przeliczą się same,
     * a archiwalne (transkrypt usunięty) zostaną. Nigdy nie usuwaj var/summary, żeby „przeliczyć” — to archiwum.
     */
    public const PARSER = '6';

    public const OPS = [Touch::READ, Touch::CONTEXT, Touch::EDIT, Touch::WRITE, Touch::SEARCH, Touch::LIST, Touch::EXEC];

    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/var/summary';
    }

    /** Przelicza sesje, których transkrypt się zmienił; zwraca liczbę przeliczonych. */
    public function refresh(): int
    {
        if (!is_dir(self::dir())) {
            mkdir(self::dir(), 0700, true);
        }
        $reader = new TranscriptReader();
        $done = 0;
        foreach (glob(TranscriptReader::projectsDir() . '/*/*.jsonl') ?: [] as $path) {
            $id = basename($path, '.jsonl');
            if (!HookLog::validSessionId($id)) {
                continue;
            }
            $version = self::PARSER . ':' . SessionIndex::version($path);
            $file = self::dir() . '/' . $id . '.json';
            $cached = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (is_array($cached) && ($cached['version'] ?? '') === $version) {
                continue;
            }
            file_put_contents($file, (string) json_encode(self::aggregate($reader->read($path), $version), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            chmod($file, 0600);
            $done++;
        }

        return $done;
    }

    /** @return list<array<string, mixed>> wszystkie agregaty, także sesji bez transkryptu */
    public function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            $d = json_decode((string) file_get_contents($file), true);
            if (is_array($d) && HookLog::validSessionId((string) ($d['session_id'] ?? ''))) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /**
     * @param ?string $cutoff ISO UTC — tylko zdarzenia od tej chwili (okna „5 min”, „1 h”); null = cała sesja
     */
    public static function aggregate(\Viz\Model\Session $session, string $version, ?string $cutoff = null): array
    {
        $files = [];
        $events = $cutoff === null ? $session->events : array_values(array_filter($session->events, static fn (Event $e): bool => $e->ts >= $cutoff));
        $calls = array_values(array_filter($events, static fn (Event $e): bool => $e->tool !== Event::TOOL_CONTEXT));
        $active = [];
        $commands = [];
        $blocked = [];
        foreach ($events as $e) {
            $active[$e->agentId] = true;
            if ($e->denial !== null) {
                // log blokad: komenda (tekst wywołania, bez wyniku) + kto i jaką regułą zablokował
                $blocked[] = ['ts' => $e->ts, 'agent' => $e->agentId, 'tool' => $e->tool, 'summary' => $e->summary, 'by' => $e->denial['by'], 'detail' => $e->denial['detail']];
            }
            foreach ($e->commands as $c) {
                $row = $commands[$c->program] ?? ['count' => 0, 'failed' => 0, 'blocked' => 0, 'agents' => [], 'last' => '', 'from' => [], 'subs' => [], 'contexts' => []];
                $row['count']++;
                $row['failed'] += $e->failed && $e->executed() ? 1 : 0;
                $row['blocked'] += $e->executed() ? 0 : 1;
                $row['agents'][$e->agentId] = true;
                $row['last'] = max($row['last'], $e->ts);
                if ($c->pipedFrom !== null) {
                    $row['from'][$c->pipedFrom] = ($row['from'][$c->pipedFrom] ?? 0) + 1;
                }
                if ($c->sub !== null) {
                    $row['subs'][$c->sub] = ($row['subs'][$c->sub] ?? 0) + 1;
                }
                foreach ([...$c->contexts, ...$c->wrappers] as $ctx) {
                    $row['contexts'][$ctx] = ($row['contexts'][$ctx] ?? 0) + 1;
                }
                $commands[$c->program] = $row;
            }
            if (!$e->executed()) {
                continue;
            }
            foreach ($e->touches as $t) {
                $key = Path::key($t->path);
                $f = $files[$key] ?? ['path' => $t->path, 'ops' => [], 'agents' => [], 'last' => ''];
                $f['ops'][$t->op] = ($f['ops'][$t->op] ?? 0) + 1;
                $f['agents'][$e->agentId] = true;
                $f['last'] = max($f['last'], $e->ts);
                $files[$key] = $f;
            }
        }

        return [
            'session_id' => $session->id,
            'version' => $version,
            'parser' => self::PARSER,
            'title' => $session->title,
            'cwd' => $session->cwd,
            'first' => $calls[0]->ts ?? null,
            'last' => $calls === [] ? null : end($calls)->ts,
            'calls' => count($calls),
            'agents' => count($session->agents),
            'agents_active' => count($active),
            'blocked' => $blocked,
            'tokens' => self::tokens($session, $cutoff),
            'commands' => array_map(static fn (array $c): array => ['agents' => count($c['agents'])] + $c, $commands),
            'files' => array_values(array_map(static fn (array $f): array => [
                'path' => $f['path'], 'ops' => $f['ops'], 'agents' => count($f['agents']), 'last' => $f['last'],
            ], $files)),
        ];
    }

    /**
     * Zużycie tokenów sesji (od `cutoff`): suma i rozbicie per model. Liczniki osobno — patrz Session::tokens.
     * `output_partial` = odpowiedzi bez końcowego usage (wyjście zaniżone, subagenci w Claude Code ≤ 2.1.300).
     *
     * @return array{total: array<string, int>, models: array<string, array<string, int>>}
     */
    private static function tokens(\Viz\Model\Session $session, ?string $cutoff): array
    {
        $zero = ['calls' => 0, 'subagent_calls' => 0, 'output_partial' => 0, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0, 'input' => 0];
        $total = $zero;
        $models = [];
        foreach ($session->usage as $u) {
            if ($cutoff !== null && $u['ts'] < $cutoff) {
                continue;
            }
            $row = [
                'calls' => 1, 'subagent_calls' => $u['agent'] === \Viz\Model\Agent::MAIN ? 0 : 1, 'output_partial' => $u['final'] ? 0 : 1,
                'output' => $u['output'], 'cache_write' => $u['cacheWrite'], 'cache_read' => $u['cacheRead'], 'input' => $u['input'],
            ];
            $models[$u['model']] ??= $zero;
            foreach ($row as $k => $v) {
                $total[$k] += $v;
                $models[$u['model']][$k] += $v;
            }
        }
        uasort($models, static fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);

        return ['total' => $total, 'models' => $models];
    }
}
