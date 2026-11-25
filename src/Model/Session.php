<?php

declare(strict_types=1);

namespace Viz\Model;

final readonly class Session
{
    /**
     * @param array<string, Agent> $agents
     * @param list<Event> $events sorted by time
     * @param list<array{ts: string, text: string, kind: string}> $prompts `prompt` / `command` / `notify` / `peer` / `compact`
     * @param list<array{ts: string, agent: string, model: string, tokens: int, input: int, cacheRead: int, cacheWrite: int, write1h: int, output: int, final: bool, cost: ?array{input: float, read: float, write: float, output: float}}> $usage
     *        per model response: `tokens` = context size (input + cacheRead + cacheWrite), the rest are counters from `message.usage`;
     *        `write1h` = part of the cache write with a 1 h lifetime (more expensive); `cost` = $ per API price list (Pricing), null = model not in the price list;
     *        `final` = false → only the stream start was recorded, `output` is understated
     * @param list<array{ts: string, from: string, to: string, kind: string, text: string, cut: bool}> $messages
     *        command flow: `user` (user → main), `task` (prompt for a subagent), `result` (subagent's response, truncated),
     *        `send` (SendMessage), `peer` (message from another session); `from`/`to` = agent id, `user` or an address outside the session
     * @param list<array{ts: string, agent: string, kind: string, text: string}> $incidents
     *        work disruptions: `interrupt` (user interrupted), `limit` (session limit / rate limit), `api-error` (no network, server error, computer sleep)
     * @param list<array{ts: string, mode: string}> $modes permission mode from user messages in the transcript (`permissionMode`), oldest first;
     *        entry only on change; hook and transcripts without the field = []
     */
    public function __construct(
        public string $id,
        public string $path,
        public string $title,
        public ?string $cwd,
        public array $agents,
        public array $events,
        public int $toolUseCount,
        public array $prompts = [],
        public array $usage = [],
        public array $messages = [],
        public array $incidents = [],
        public array $modes = [],
    ) {
    }

    /**
     * Token usage in a range: agent (empty = all), time [from, to) — a turn is the time from a prompt to the next prompt.
     * Counters are not summed into one number: a cache read is the same context read on every call.
     *
     * @return array{calls: int, subagentCalls: int, outputPartial: int, output: int, cacheWrite: int, cacheRead: int, input: int, models: array<string, int>, usd: float, cost: array{input: float, read: float, write: float, output: float}, unpriced: int}
     *         `outputPartial` = responses without final usage, where `output` is understated; `usd` = sum of `cost`;
     *         `unpriced` = model responses not in the price list (not included in `usd`)
     */
    public function tokens(string $agent = '', ?string $from = null, ?string $to = null): array
    {
        $sum = ['calls' => 0, 'subagentCalls' => 0, 'outputPartial' => 0, 'output' => 0, 'cacheWrite' => 0, 'cacheRead' => 0, 'input' => 0, 'models' => [],
            'usd' => 0.0, 'cost' => ['input' => 0.0, 'read' => 0.0, 'write' => 0.0, 'output' => 0.0], 'unpriced' => 0];
        foreach ($this->usage as $u) {
            if (($agent !== '' && $u['agent'] !== $agent) || ($from !== null && $u['ts'] < $from) || ($to !== null && $u['ts'] >= $to)) {
                continue;
            }
            $sum['calls']++;
            $sum['subagentCalls'] += $u['agent'] === Agent::MAIN ? 0 : 1;
            $sum['outputPartial'] += $u['final'] ? 0 : 1;
            foreach (['output', 'cacheWrite', 'cacheRead', 'input'] as $k) {
                $sum[$k] += $u[$k];
            }
            $sum['models'][$u['model']] = ($sum['models'][$u['model']] ?? 0) + 1;
            if ($u['cost'] === null) {
                $sum['unpriced']++;
                continue;
            }
            foreach ($u['cost'] as $k => $v) {
                $sum['cost'][$k] += $v;
                $sum['usd'] += $v;
            }
        }
        arsort($sum['models']);

        return $sum;
    }
}
