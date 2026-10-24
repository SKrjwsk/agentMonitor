<?php

declare(strict_types=1);

namespace Viz\Source;

use Viz\Clock;
use Viz\Extract\ToolTouches;
use Viz\Model\Agent;
use Viz\Model\Touch;

/**
 * Zapis jednego zdarzenia z hooka PostToolUse / PostToolUseFailure do var/events/<session_id>.jsonl.
 * Nie czyta treści plików, nie zapisuje tool_response ani treści edycji.
 */
final class HookRecorder
{
    /** Pola tool_input potrzebne do ustalenia ścieżek; reszta (treść Write/Edit) nie jest zapisywana. */
    private const INPUT_FIELDS = ['command', 'file_path', 'notebook_path', 'path', 'pattern', 'glob', 'offset', 'limit', 'filePath', 'description', 'subagent_type', 'skill'];

    public function __construct(private string $dir = '', private ToolTouches $tools = new ToolTouches())
    {
        $this->dir = $dir !== '' ? $dir : HookLog::dir();
    }

    /** @param array<string, mixed> $payload */
    public function record(array $payload): void
    {
        $sessionId = (string) ($payload['session_id'] ?? '');
        if (!HookLog::validSessionId($sessionId)) {
            return;
        }
        $agentId = (string) ($payload['agent_id'] ?? Agent::MAIN);
        if ($agentId !== Agent::MAIN && !preg_match('/^[a-f0-9]{1,40}$/', $agentId)) {
            return;
        }
        $tool = (string) ($payload['tool_name'] ?? '');
        $input = array_intersect_key((array) ($payload['tool_input'] ?? []), array_flip(self::INPUT_FIELDS));
        $cwdAfter = isset($payload['cwd']) ? (string) $payload['cwd'] : null;
        $file = $this->dir . '/' . $sessionId . '.jsonl';

        // cwd w payloadzie to katalog PO wykonaniu (M0). Katalog PRZED: linia tool_use w transkrypcie,
        // awaryjnie cwd_after poprzedniego zdarzenia tego agenta
        $cwdBefore = $this->transcriptCwd((string) ($payload['transcript_path'] ?? ''), $agentId, (string) ($payload['tool_use_id'] ?? ''))
            ?? $this->lastCwd($file, $agentId)
            ?? $cwdAfter;
        $x = $this->tools->extract($tool, $input, $cwdBefore);

        $line = [
            'ts' => Clock::nowUtc(),
            'session_id' => $sessionId,
            'agent_id' => $agentId,
            'agent_type' => (string) ($payload['agent_type'] ?? ($agentId === Agent::MAIN ? 'main' : 'subagent')),
            'tool' => $tool,
            'tool_use_id' => (string) ($payload['tool_use_id'] ?? ''),
            'duration_ms' => (int) ($payload['duration_ms'] ?? 0),
            'input' => $input,
            'cwd_before' => $cwdBefore,
            'cwd_after' => $cwdAfter,
            'failed' => ($payload['hook_event_name'] ?? '') === 'PostToolUseFailure',
            // tylko pierwsza linia — dalej bywa wyjście komendy, czyli potencjalnie treść pliku
            'error' => isset($payload['error']) ? mb_strimwidth(trim(strtok((string) $payload['error'], "\n") ?: ''), 0, 200, '…') : null,
            'touches' => array_map(static fn (Touch $t): array => [
                'path' => $t->path, 'op' => $t->op, 'exists' => $t->exists, 'partial' => $t->partial, 'range' => $t->range,
            ], $x->touches),
            'unresolved' => $x->unresolved,
            'indirect' => $x->indirect,
            'unknown' => $x->unknown,
            'commands' => array_map(static fn (\Viz\Model\Command $c): array => $c->toArray(), $x->commands),
        ];

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0700, true);
        }
        $new = !is_file($file);
        file_put_contents($file, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        if ($new) {
            chmod($file, 0600);
        }
    }

    /** cwd z linii tool_use w transkrypcie (sesja główna albo plik subagenta) — czyta tylko koniec pliku. */
    private function transcriptCwd(string $transcriptPath, string $agentId, string $toolUseId): ?string
    {
        if ($toolUseId === '' || !str_ends_with($transcriptPath, '.jsonl')
            || !str_starts_with($transcriptPath, TranscriptReader::projectsDir() . '/') || str_contains($transcriptPath, '..')) {
            return null;
        }
        $file = $agentId === Agent::MAIN ? $transcriptPath : substr($transcriptPath, 0, -6) . '/subagents/agent-' . $agentId . '.jsonl';
        foreach (array_reverse(explode("\n", self::tail($file, 524288))) as $line) {
            if (!str_contains($line, $toolUseId) || !str_contains($line, '"tool_use"')) {
                continue;
            }
            $d = json_decode($line, true);
            if (is_array($d) && ($d['type'] ?? '') === 'assistant' && isset($d['cwd'])) {
                return (string) $d['cwd'];
            }
        }

        return null;
    }

    private static function tail(string $file, int $bytes): string
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return '';
        }
        fseek($handle, max(0, (int) filesize($file) - $bytes));
        $data = (string) stream_get_contents($handle);
        fclose($handle);

        return $data;
    }

    /** cwd_after ostatniego zdarzenia tego agenta — czyta tylko koniec pliku. */
    private function lastCwd(string $file, string $agentId): ?string
    {
        foreach (array_reverse(explode("\n", trim(self::tail($file, 65536)))) as $line) {
            $d = json_decode($line, true);
            if (is_array($d) && ($d['agent_id'] ?? '') === $agentId && isset($d['cwd_after'])) {
                return (string) $d['cwd_after'];
            }
        }

        return null;
    }
}
