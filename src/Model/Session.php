<?php

declare(strict_types=1);

namespace Viz\Model;

final readonly class Session
{
    /**
     * @param array<string, Agent> $agents
     * @param list<Event> $events posortowane po czasie
     * @param list<array{ts: string, text: string, kind: string}> $prompts `prompt` / `command` / `notify` / `peer` / `compact`
     * @param list<array{ts: string, agent: string, model: string, tokens: int, input: int, cacheRead: int, cacheWrite: int, output: int, final: bool}> $usage
     *        per odpowiedź modelu: `tokens` = rozmiar kontekstu (input + cacheRead + cacheWrite), reszta to liczniki z `message.usage`;
     *        `final` = false → zapisany tylko start strumienia, `output` zaniżone
     * @param list<array{ts: string, from: string, to: string, kind: string, text: string, cut: bool}> $messages
     *        przepływ poleceń: `user` (user → main), `task` (prompt dla subagenta), `result` (odpowiedź subagenta, przycięta),
     *        `send` (SendMessage), `peer` (wiadomość z innej sesji); `from`/`to` = id agenta, `user` albo adres spoza sesji
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
    ) {
    }

    /**
     * Zużycie tokenów w zakresie: agent (pusty = wszyscy), czas [od, do) — tura to czas od promptu do następnego promptu.
     * Liczników nie sumujemy w jedną liczbę: odczyt z cache to ten sam kontekst czytany przy każdym wywołaniu.
     *
     * @return array{calls: int, subagentCalls: int, outputPartial: int, output: int, cacheWrite: int, cacheRead: int, input: int, models: array<string, int>}
     *         `outputPartial` = odpowiedzi bez końcowego usage, w których `output` jest zaniżone
     */
    public function tokens(string $agent = '', ?string $from = null, ?string $to = null): array
    {
        $sum = ['calls' => 0, 'subagentCalls' => 0, 'outputPartial' => 0, 'output' => 0, 'cacheWrite' => 0, 'cacheRead' => 0, 'input' => 0, 'models' => []];
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
        }
        arsort($sum['models']);

        return $sum;
    }
}
