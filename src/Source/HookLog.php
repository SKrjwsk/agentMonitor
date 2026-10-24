<?php

declare(strict_types=1);

namespace Viz\Source;

use Viz\Model\Agent;
use Viz\Model\Event;
use Viz\Model\Session;
use Viz\Model\Touch;

/** Odczyt zdarzeń zapisanych przez hook. Ścieżki są zamrożone w chwili zdarzenia. */
final class HookLog
{
    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/var/events';
    }

    public static function validSessionId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1;
    }

    /** `$sessionId` musi być zwalidowany przez validSessionId(). */
    public static function path(string $sessionId): string
    {
        return self::dir() . '/' . $sessionId . '.jsonl';
    }

    public function read(string $sessionId): Session
    {
        $file = self::path($sessionId);
        $agents = [Agent::MAIN => new Agent(Agent::MAIN, 'main', 'main session', null)];
        $meta = $this->subagentMeta($sessionId);
        $events = [];
        $cwd = null;
        $handle = @fopen($file, 'rb');
        while ($handle !== false && ($line = fgets($handle)) !== false) {
            $d = json_decode($line, true);
            if (!is_array($d)) {
                continue;
            }
            $agentId = (string) ($d['agent_id'] ?? Agent::MAIN);
            if (!isset($agents[$agentId])) {
                $agents[$agentId] = new Agent(
                    $agentId,
                    (string) ($meta[$agentId]['agentType'] ?? $d['agent_type'] ?? 'subagent'),
                    (string) ($meta[$agentId]['description'] ?? $agentId),
                    isset($meta[$agentId]['toolUseId']) ? (string) $meta[$agentId]['toolUseId'] : null,
                );
            }
            $cwd ??= $d['cwd_before'] ?? null;
            // zdarzenie hooka powstaje po wykonaniu: ts = koniec, start = koniec − duration_ms
            $end = (string) $d['ts'];
            $start = $end;
            if (($d['duration_ms'] ?? 0) > 0) {
                $start = (new \DateTimeImmutable($end))->modify('-' . (int) $d['duration_ms'] . ' milliseconds')->format('Y-m-d\TH:i:s.v\Z');
            }
            $events[] = new Event(
                $start, $sessionId, $agentId, (string) $d['tool'], (string) ($d['tool_use_id'] ?? ''),
                TranscriptReader::summary((string) $d['tool'], (array) ($d['input'] ?? [])),
                $d['cwd_before'] ?? null, (bool) ($d['failed'] ?? false),
                array_map(static fn (array $t): Touch => new Touch(
                    (string) $t['path'], (string) $t['op'], (bool) $t['exists'], (bool) ($t['partial'] ?? false), $t['range'] ?? null,
                ), (array) ($d['touches'] ?? [])),
                (array) ($d['unresolved'] ?? []), (array) ($d['indirect'] ?? []), (array) ($d['unknown'] ?? []),
                endTs: $end,
                error: isset($d['error']) ? (string) $d['error'] : null,
                commands: array_map(static fn (array $c): \Viz\Model\Command => \Viz\Model\Command::fromArray($c), (array) ($d['commands'] ?? [])),
            );
        }
        if ($handle !== false) {
            fclose($handle);
        }

        return new Session($sessionId, $file, '', $cwd, $agents, $events, count($events));
    }

    /** @return array<string, array<string, mixed>> opisy subagentów z transkryptu, o ile istnieje */
    private function subagentMeta(string $sessionId): array
    {
        $main = TranscriptReader::locate($sessionId);
        if ($main === null) {
            return [];
        }
        $out = [];
        foreach (glob(substr($main, 0, -6) . '/subagents/agent-*.meta.json') ?: [] as $f) {
            $out[substr(basename($f, '.meta.json'), 6)] = json_decode((string) file_get_contents($f), true) ?: [];
        }

        return $out;
    }
}
