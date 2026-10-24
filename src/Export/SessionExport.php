<?php

declare(strict_types=1);

namespace Viz\Export;

use Viz\Clock;
use Viz\Model\Agent;
use Viz\Model\Event;
use Viz\Model\Session;
use Viz\Model\Touch;
use Viz\Stats;

/**
 * Zrzut sesji do dalszej analizy (skill `analiza-sesji`, skrypt, arkusz): folder w var/session_dump albo ZIP.
 * Zawiera komendy, ścieżki i prompty usera — nigdy treść plików.
 */
final class SessionExport
{
    public const FORMAT = 'agent-viz-export/2';

    public static function dumpRoot(): string
    {
        return dirname(__DIR__, 2) . '/var/session_dump';
    }

    public static function dumpName(Session $session, string $agentFilter): string
    {
        return Clock::local(Clock::nowUtc(), 'Ymd-His') . '-' . substr($session->id, 0, 8)
            . ($agentFilter !== '' ? '-' . substr($agentFilter, 0, 12) : '');
    }

    public static function validDumpName(string $name): bool
    {
        return preg_match('/^\d{8}-\d{6}-[0-9a-f]{8}(-(main|[a-f0-9]{1,12}))?$/', $name) === 1;
    }

    /** @param list<Event> $events */
    /** @param array<string, mixed> $filters filtry widoku zapisywane w manifeście */
    public function writeDir(string $dir, Session $session, array $events, Stats $stats, string $title, string $source, string $agentFilter, array $filters = []): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        foreach ($this->files($session, $events, $stats, $title, $source, $agentFilter, $filters) as $name => $content) {
            file_put_contents($dir . '/' . $name, $content);
            chmod($dir . '/' . $name, 0600);
        }
    }

    /** @param list<Event> $events */
    public function writeZip(string $zipPath, Session $session, array $events, Stats $stats, string $title, string $source, string $agentFilter): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create ' . $zipPath);
        }
        foreach ($this->files($session, $events, $stats, $title, $source, $agentFilter) as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    /**
     * @param list<Event> $events
     * @return array<string, string>
     */
    private function files(Session $session, array $events, Stats $stats, string $title, string $source, string $agentFilter, array $filters = []): array
    {
        $json = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $files = [];

        $files['manifest.json'] = (string) json_encode([
            'format' => self::FORMAT,
            'generated_at' => Clock::nowUtc(),
            'session_id' => $session->id,
            'title' => $title,
            'cwd' => $session->cwd,
            'source' => $source,
            'transcript_path' => $source === 'transcript' ? $session->path : null,
            'agent_filter' => $agentFilter !== '' ? $agentFilter : null,
            'filters' => $filters === [] ? null : $filters,
            'counts' => [
                'calls' => $stats->calls,
                'files' => count($stats->files),
                'files_per_op' => $stats->filesPerOp,
                'per_tool' => $stats->perTool,
                'context_files' => $stats->contextFiles,
                'self_read_files' => $stats->selfReadFiles,
                'context_reread' => $stats->contextReread,
                'bash_calls' => $stats->bashCalls,
                'blind' => $stats->blind,
                'incomplete' => $stats->incomplete,
                'failed' => $stats->failed,
            ],
            'files_in_package' => [
                'summary.md' => 'text summary (like bin/dump.php --blind)',
                'metrics.json' => 'deterministic metrics: time, errors, re-reads, prompt turns, agents, commands, token usage (whole session, per agent, per turn)',
                'prompts.json' => 'user messages, commands, background task notifications, compactions (local time)',
                'events.jsonl' => 'one tool call per line, with resolved paths and commands',
                'files.csv' => 'one file per row: operation counts, agents',
                'commands.csv' => 'one program occurrence per row: time, agent, program, subcommand, contexts (pipe/subst/xargs/inner/remote/stdin-script), wrappers, pipe, call status, role',
                'agents.json' => 'session agents (main + subagents)',
            ],
            'notes' => '`error` is the first line of the error message (no command output). `peer` prompts are messages from other sessions/subagents, not from the user. Times in events.jsonl are UTC, in metrics/prompts local. Call duration (ts → end_ts) includes waiting for user approval, so a long duration does not have to mean a slow command. Paths are resolved heuristically; blind spots = commands without resolved paths. Contains commands and prompts — treat like terminal history.',
        ], $json);

        $files['summary.md'] = TextReport::render($session, $events, $stats, $title, true);
        $files['metrics.json'] = (string) json_encode(Metrics::of($session, $events), $json);
        $files['prompts.json'] = (string) json_encode(array_map(static fn (array $p): array => [
            'at' => Clock::local($p['ts'], 'Y-m-d H:i:s'), 'kind' => $p['kind'], 'text' => $p['text'],
        ], $session->prompts), $json);

        $lines = '';
        foreach ($events as $e) {
            $lines .= json_encode([
                'ts' => $e->ts,
                'end_ts' => $e->endTs,
                'agent_id' => $e->agentId,
                'tool' => $e->tool,
                'tool_use_id' => $e->toolUseId,
                'summary' => $e->summary,
                'cwd' => $e->cwd,
                'failed' => $e->failed,
                'error' => $e->error,
                'blind' => $e->tool === 'Bash' && $e->isBlind(),
                'touches' => array_map(static fn (Touch $t): array => [
                    'path' => $t->path, 'op' => $t->op, 'exists' => $t->exists, 'partial' => $t->partial, 'range' => $t->range,
                ], $e->touches),
                'unresolved' => $e->unresolved,
                'indirect' => $e->indirect,
                'unknown' => $e->unknown,
                'commands' => array_map(static fn (\Viz\Model\Command $c): array => $c->toArray(), $e->commands),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }
        $files['events.jsonl'] = $lines;

        $ops = [Touch::READ, Touch::CONTEXT, Touch::SEARCH, Touch::LIST, Touch::EDIT, Touch::WRITE];
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['path', ...$ops, 'partial_only', 'exists', 'agents'], ',', '"', '');
        foreach ($stats->files as $f) {
            fputcsv($csv, [
                $f['path'],
                ...array_map(static fn (string $op): int => $f['ops'][$op] ?? 0, $ops),
                $f['partialOnly'] ? 1 : 0,
                $f['exists'] ? 1 : 0,
                implode(' ', array_keys($f['agents'])),
            ], ',', '"', '');
        }
        rewind($csv);
        $files['files.csv'] = (string) stream_get_contents($csv);
        fclose($csv);

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['ts', 'agent_id', 'program', 'sub', 'contexts', 'wrappers', 'piped_from', 'call_status', 'role', 'tool_use_id'], ',', '"', '');
        foreach ($events as $e) {
            foreach ($e->commands as $c) {
                fputcsv($csv, [
                    $e->ts, $e->agentId, $c->program, (string) $c->sub, implode(' ', $c->contexts), implode(' ', $c->wrappers), (string) $c->pipedFrom,
                    !$e->failed ? 'ok' : ($e->executed() ? 'failed' : 'blocked'), \Viz\Extract\ToolRoles::role($c->program), $e->toolUseId,
                ], ',', '"', '');
            }
        }
        rewind($csv);
        $files['commands.csv'] = (string) stream_get_contents($csv);
        fclose($csv);

        $files['agents.json'] = (string) json_encode(array_values(array_map(static fn (Agent $a): array => [
            'id' => $a->id, 'type' => $a->type, 'description' => $a->description, 'parent_tool_use_id' => $a->parentToolUseId,
        ], $session->agents)), $json);

        return $files;
    }
}
