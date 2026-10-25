<?php

declare(strict_types=1);

namespace Viz\Source;

use Viz\Extract\ToolTouches;
use Viz\Model\Agent;
use Viz\Model\Event;
use Viz\Model\Session;
use Viz\Model\Touch;
use Viz\Path;
use Viz\Pricing;

/**
 * Czyta transkrypt Claude Code (format wewnętrzny, nieudokumentowany):
 * sesja główna + subagenci z `<session>/subagents/agent-<id>.jsonl` i `.meta.json`.
 */
final class TranscriptReader
{
    /** Typy załączników, w których harness podaje agentowi plik. */
    private const CONTEXT_ATTACHMENTS = [
        'instructions' => 'CLAUDE.md and rules (session start)',
        'nested_memory' => 'CLAUDE.md of a subdirectory',
        'file' => 'file attached by the harness',
        'selected_lines_in_ide' => 'selection in IDE',
        'edited_text_file' => 'file changed outside the agent',
    ];

    /** Limit tekstu poleceń (prompt usera, prompt dla subagenta, SendMessage). */
    private const TEXT_LIMIT = 8000;

    /**
     * Odpowiedź subagenta to tekst modelu, ale zwykle opisuje i cytuje przeczytane pliki — a treści plików nie pokazujemy.
     * Początek wystarcza, żeby ocenić, co subagent zwrócił.
     */
    public const RESULT_LIMIT = 600;

    public function __construct(private ToolTouches $tools = new ToolTouches())
    {
    }

    public static function projectsDir(): string
    {
        return Path::home() . '/.claude/projects';
    }

    /** Ścieżka transkryptu sesji głównej albo null. `$sessionId` musi być już zwalidowany. */
    public static function locate(string $sessionId): ?string
    {
        $found = glob(self::projectsDir() . '/*/' . $sessionId . '.jsonl') ?: [];

        return $found[0] ?? null;
    }

    public function read(string $mainPath): Session
    {
        $sessionId = basename($mainPath, '.jsonl');
        $agents = [Agent::MAIN => new Agent(Agent::MAIN, 'main', 'main session', null)];
        $meta = ['title' => '', 'cwd' => null, 'toolUses' => 0, 'prompts' => [], 'usage' => [], 'messages' => [], 'incidents' => []];
        $events = $this->readFile($mainPath, $sessionId, Agent::MAIN, $meta);

        foreach (glob(substr($mainPath, 0, -6) . '/subagents/agent-*.jsonl') ?: [] as $file) {
            $agentId = substr(basename($file, '.jsonl'), 6);
            $info = json_decode((string) @file_get_contents(substr($file, 0, -6) . '.meta.json'), true) ?: [];
            $agents[$agentId] = new Agent(
                $agentId,
                (string) ($info['agentType'] ?? 'subagent'),
                (string) ($info['description'] ?? $agentId),
                isset($info['toolUseId']) ? (string) $info['toolUseId'] : null,
            );
            $subMeta = ['title' => '', 'cwd' => null, 'toolUses' => 0, 'prompts' => [], 'usage' => [], 'messages' => [], 'incidents' => []];
            array_push($events, ...$this->readFile($file, $sessionId, $agentId, $subMeta));
            $meta['toolUses'] += $subMeta['toolUses'];
            array_push($meta['usage'], ...$subMeta['usage']);
            $meta['messages'] += $subMeta['messages'];
            array_push($meta['incidents'], ...$subMeta['incidents']);
        }
        usort($meta['incidents'], static fn (array $a, array $b): int => strcmp($a['ts'], $b['ts']));

        usort($events, static fn (Event $a, Event $b): int => strcmp($a->ts, $b->ts));
        usort($meta['prompts'], static fn (array $a, array $b): int => strcmp($a['ts'], $b['ts']));

        return new Session(
            $sessionId, $mainPath, ($meta['customTitle'] ?? '') !== '' ? $meta['customTitle'] : $meta['title'], $meta['cwd'], $agents, $events, $meta['toolUses'], $meta['prompts'], $meta['usage'],
            self::messages($meta['prompts'], $meta['messages'], $agents),
            $meta['incidents'],
        );
    }

    /**
     * Prompty usera + polecenia między agentami w jednej liście. Adresat promptu dla subagenta to agent z `toolUseId` = id wywołania Agent;
     * odpowiedź subagenta wraca do agenta, który go uruchomił.
     *
     * @param list<array{ts: string, text: string, kind: string}> $prompts
     * @param array<string, array{ts: string, from: string, to: string, kind: string, text: string, cut: bool}> $calls tool_use id => wiadomość
     * @param array<string, Agent> $agents
     * @return list<array{ts: string, from: string, to: string, kind: string, text: string, cut: bool}>
     */
    private static function messages(array $prompts, array $calls, array $agents): array
    {
        $out = [];
        foreach ($prompts as $pr) {
            if (in_array($pr['kind'], ['prompt', 'command', 'peer'], true)) {
                $out[] = ['ts' => $pr['ts'], 'from' => $pr['kind'] === 'peer' ? 'peer' : 'user', 'to' => Agent::MAIN, 'kind' => $pr['kind'] === 'peer' ? 'peer' : 'user', 'text' => $pr['text'], 'cut' => false];
            }
        }
        $byCall = [];
        foreach ($agents as $agent) {
            if ($agent->parentToolUseId !== null) {
                $byCall[$agent->parentToolUseId] = $agent->id;
            }
        }
        foreach ($calls as $id => $m) {
            if ($m['kind'] === 'task') {
                $m['to'] = $byCall[$id] ?? 'subagent';
            } elseif (str_starts_with($m['from'], '@')) {
                $m['from'] = $byCall[substr($m['from'], 1)] ?? 'subagent';
            } elseif ($m['kind'] === 'result' && $m['to'] === '') {
                // SubagentHandback: adresatem jest agent, który uruchomił nadawcę
                $parent = $agents[$m['from']]->parentToolUseId ?? null;
                $m['to'] = $parent !== null && isset($calls[$parent]) ? $calls[$parent]['from'] : Agent::MAIN;
            }
            $out[] = $m;
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['ts'], $b['ts']));

        return $out;
    }

    /**
     * Koszt wyników narzędzi w kontekście jednego agenta (szacunek — transkrypt nie podaje tokenów per wynik).
     * Zapis do cache odpowiedzi N to to, co doszło od odpowiedzi N-1: jej wyjście + wyniki narzędzi; ten zapis bez wyjścia
     * dzielimy na wyniki proporcjonalnie do długości. Gdy zapis jest dużo większy niż te wyniki, kontekst był zapisywany
     * od nowa (wygasły cache, zmiana modelu, kompaktowanie) — wtedy wynik szacujemy z długości (≈ 3 znaki na token).
     * Wynik zostaje w kontekście i jest czytany z cache przy każdej dalszej odpowiedzi — do kompaktowania (kontekst spada o > 40%).
     *
     * @param list<array<string, mixed>> $usage odpowiedzi agenta w kolejności z transkryptu, z `results`
     * @return array<string, array{tokens: int, calls: int, usd: ?float}> tool_use_id → koszt
     */
    private static function carry(array $usage): array
    {
        $n = count($usage);
        // indeks pierwszej odpowiedzi po kompaktowaniu, licząc od każdej odpowiedzi
        $nextDrop = [];
        $next = $n;
        for ($j = $n - 1; $j >= 1; $j--) {
            $nextDrop[$j] = $next;
            if ($usage[$j]['tokens'] < 0.6 * $usage[$j - 1]['tokens']) {
                $next = $j;
            }
        }
        $out = [];
        for ($i = 1; $i < $n; $i++) {
            $u = $usage[$i];
            $chars = array_sum(array_column($u['results'], 1));
            if ($u['results'] === [] || $chars === 0) {
                continue;
            }
            $prevOut = $usage[$i - 1]['output'];
            $rebuild = $u['cacheWrite'] > 20000 && $u['cacheWrite'] > 2 * ($chars / 3 + $prevOut + 3000);
            $base = max(0, $u['cacheWrite'] - $prevOut);
            $calls = $nextDrop[$i] - $i - 1;
            $rates = Pricing::rates($u['model']);
            foreach ($u['results'] as [$id, $len]) {
                $tokens = (int) round($rebuild ? $len / 3 : $base * $len / $chars);
                $out[$id] = [
                    'tokens' => $tokens,
                    'calls' => $calls,
                    'usd' => $rates === null ? null : $tokens * (Pricing::writeRate($rates[0], $u['cacheWrite'], $u['write1h']) + $rates[2] * $calls) / 1e6,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array{title: string, cwd: ?string, toolUses: int} $meta
     * @return list<Event>
     */
    private function readFile(string $path, string $sessionId, string $agentId, array &$meta): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $pending = [];
        $failed = [];
        $ended = [];
        $errors = [];
        $denials = [];
        $osDenied = [];
        $usage = [];
        // wyniki narzędzi od ostatniej odpowiedzi modelu: [tool_use_id, długość] — trafiają do zapisu cache następnej odpowiedzi
        $sinceResponse = [];
        $events = [];
        while (($line = fgets($handle)) !== false) {
            if (!str_contains($line, '"tool_use"') && !str_contains($line, '"tool_result"')
                && !str_contains($line, '"attachment"') && !str_contains($line, '"ai-title"') && !str_contains($line, '"custom-title"')
                && !str_contains($line, '"type":"assistant"') && !str_contains($line, '"type":"user"')) {
                continue;
            }
            $d = json_decode($line, true);
            if (!is_array($d)) {
                continue;
            }
            $type = $d['type'] ?? '';
            // nazwa nadana przez usera (/rename, --name) ma pierwszeństwo; przy niej Claude Code nie generuje ai-title
            if ($type === 'custom-title') {
                $meta['customTitle'] = (string) ($d['customTitle'] ?? '');
                continue;
            }
            if ($type === 'ai-title') {
                $meta['title'] = (string) ($d['aiTitle'] ?? $meta['title']);
                continue;
            }
            $meta['cwd'] ??= isset($d['cwd']) ? (string) $d['cwd'] : null;
            if ($type === 'attachment' && $agentId === Agent::MAIN && ($d['attachment']['type'] ?? '') === 'queued_command') {
                // wiadomość wpisana w trakcie pracy agenta (albo powiadomienie zadania w tle)
                $prompt = self::prompt(['timestamp' => $d['attachment']['timestamp'] ?? $d['timestamp'] ?? '', 'message' => ['content' => $d['attachment']['prompt'] ?? '']]);
                if ($prompt !== null && (($d['attachment']['origin']['kind'] ?? '') === 'peer')) {
                    $prompt['kind'] = 'peer';
                }
                if ($prompt !== null) {
                    $meta['prompts'][] = $prompt;
                }
                continue;
            }
            if ($type === 'attachment') {
                $event = $this->contextEvent($d, $sessionId, $agentId);
                if ($event !== null) {
                    $events[] = $event;
                }
                continue;
            }
            $incident = self::incident($d, $agentId);
            if ($incident !== null) {
                $meta['incidents'][] = $incident;
            }
            // jedna odpowiedź modelu = kilka linii z tym samym message.id; wejście jest w nich takie samo,
            // ale output_tokens bywa w pierwszej linii niepełne (16 zamiast 1706) — bierzemy największe
            // `<synthetic>` to komunikat harnessu z zerowym usage, nie wywołanie modelu
            // bez `stop_reason` w żadnej linii usage jest ze startu strumienia: output_tokens zaniżone (subagenci w Claude Code ≤ 2.1.300)
            if ($type === 'assistant' && isset($d['message']['usage'], $d['message']['id']) && ($d['message']['model'] ?? '') !== '<synthetic>') {
                $u = $d['message']['usage'];
                $id = (string) $d['message']['id'];
                if (!isset($usage[$id])) {
                    $usage[$id] = ['results' => $sinceResponse];
                    $sinceResponse = [];
                }
                $usage[$id] += [
                    'ts' => (string) ($d['timestamp'] ?? ''),
                    'agent' => $agentId,
                    'model' => (string) ($d['message']['model'] ?? ''),
                    'tokens' => (int) ($u['input_tokens'] ?? 0) + (int) ($u['cache_read_input_tokens'] ?? 0) + (int) ($u['cache_creation_input_tokens'] ?? 0),
                    'input' => (int) ($u['input_tokens'] ?? 0),
                    'cacheRead' => (int) ($u['cache_read_input_tokens'] ?? 0),
                    'cacheWrite' => (int) ($u['cache_creation_input_tokens'] ?? 0),
                    'write1h' => (int) ($u['cache_creation']['ephemeral_1h_input_tokens'] ?? 0),
                    'output' => 0,
                    'final' => false,
                ];
                $usage[$id]['output'] = max($usage[$id]['output'], (int) ($u['output_tokens'] ?? 0));
                $usage[$id]['final'] = $usage[$id]['final'] || ($d['message']['stop_reason'] ?? null) !== null;
            }
            if ($type === 'user' && $agentId === Agent::MAIN) {
                $prompt = self::prompt($d);
                if ($prompt !== null) {
                    $meta['prompts'][] = $prompt;
                }
            }
            $content = $d['message']['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (!is_array($block)) {
                    continue;
                }
                if ($type === 'assistant' && ($block['type'] ?? '') === 'tool_use') {
                    $meta['toolUses']++;
                    // cwd linii tool_use = katalog PRZED wykonaniem (sprawdzone w M0)
                    $pending[(string) $block['id']] = [(string) ($d['timestamp'] ?? ''), $d['cwd'] ?? null, (string) $block['name'], (array) ($block['input'] ?? [])];
                    $message = self::callMessage((string) $block['name'], (array) ($block['input'] ?? []), (string) ($d['timestamp'] ?? ''), $agentId);
                    if ($message !== null) {
                        $meta['messages'][(string) $block['id']] = $message;
                    }
                }
                if ($type === 'user' && ($block['type'] ?? '') === 'tool_result') {
                    $failed[(string) ($block['tool_use_id'] ?? '')] = ($block['is_error'] ?? false) === true;
                    $ended[(string) ($block['tool_use_id'] ?? '')] = (string) ($d['timestamp'] ?? '');
                    // tylko długość wyniku (do szacunku kosztu), nie treść
                    $sinceResponse[] = [(string) ($block['tool_use_id'] ?? ''), is_string($block['content'] ?? null) ? strlen($block['content']) : strlen((string) json_encode($block['content'] ?? ''))];
                    if (($block['is_error'] ?? false) === true) {
                        $errors[(string) ($block['tool_use_id'] ?? '')] = self::firstLine($block['content'] ?? '');
                        $denials[(string) ($block['tool_use_id'] ?? '')] = self::denial($d, $errors[(string) ($block['tool_use_id'] ?? '')]);
                        // odmowa sandboxa: Bash „się wykonał” (kod ≠ 0), ale plik nie został otwarty — tylko flaga, treść wyniku nie jest zapisywana
                        $osDenied[(string) ($block['tool_use_id'] ?? '')] = str_contains(self::text($block['content'] ?? ''), 'Operation not permitted');
                    }
                    // subagent synchroniczny: jego odpowiedź jest wynikiem wywołania Agent (asynchroniczny odsyła ją przez SubagentHandback)
                    $callId = (string) ($block['tool_use_id'] ?? '');
                    if (in_array($pending[$callId][2] ?? '', ['Agent', 'Task'], true) && ($block['is_error'] ?? false) !== true) {
                        $text = self::text($block['content'] ?? '');
                        if ($text !== '' && !str_starts_with($text, 'Async agent launched')) {
                            $meta['messages'][$callId . ':result'] = ['ts' => (string) ($d['timestamp'] ?? ''), 'from' => '@' . $callId, 'to' => $agentId] + self::cut($text, self::RESULT_LIMIT) + ['kind' => 'result'];
                        }
                    }
                }
            }
        }
        fclose($handle);
        $usage = array_values($usage);
        $carry = self::carry($usage);
        foreach ($usage as $u) {
            unset($u['results']);
            $u['cost'] = Pricing::cost($u['model'], $u['input'], $u['cacheRead'], $u['cacheWrite'], $u['write1h'], $u['output']);
            $meta['usage'][] = $u;
        }

        foreach ($pending as $id => [$ts, $cwd, $tool, $input]) {
            $x = $this->tools->extract($tool, $input, $cwd);
            $events[] = new Event(
                $ts, $sessionId, $agentId, $tool, $id, self::summary($tool, $input), $cwd,
                $failed[$id] ?? false, $x->touches, $x->unresolved, $x->indirect, $x->unknown,
                endTs: $ended[$id] ?? null,
                error: $errors[$id] ?? null,
                commands: $x->commands,
                denial: $denials[$id] ?? null,
                carry: $carry[$id] ?? null,
                osDenied: $osDenied[$id] ?? false,
            );
        }

        return $events;
    }

    /**
     * Polecenie wysłane wywołaniem narzędzia: prompt dla subagenta (Agent/Task) albo SendMessage.
     *
     * @param array<string, mixed> $input
     * @return ?array{ts: string, from: string, to: string, kind: string, text: string, cut: bool}
     */
    private static function callMessage(string $tool, array $input, string $ts, string $agentId): ?array
    {
        [$kind, $to, $text] = match ($tool) {
            'Agent', 'Task' => ['task', '', $input['prompt'] ?? $input['description'] ?? ''],
            'SendMessage' => ['send', (string) ($input['to'] ?? $input['recipient'] ?? ''), $input['message'] ?? $input['content'] ?? $input['summary'] ?? ''],
            'SubagentHandback' => ['result', '', $input['message'] ?? ''],
            default => [null, '', ''],
        };
        if ($kind === null) {
            return null;
        }
        $text = is_string($text) ? $text : (string) json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return ['ts' => $ts, 'from' => $agentId, 'to' => $to, 'kind' => $kind] + self::cut($text, $kind === 'result' ? self::RESULT_LIMIT : self::TEXT_LIMIT);
    }

    /** @return array{text: string, cut: bool} */
    private static function cut(string $text, int $limit): array
    {
        $text = trim($text);

        return ['text' => mb_strimwidth($text, 0, $limit, '…'), 'cut' => mb_strlen($text) > $limit];
    }

    private static function text(mixed $content): string
    {
        if (is_array($content)) {
            $content = implode("\n", array_map(static fn ($b): string => is_array($b) ? (string) ($b['text'] ?? '') : (string) $b, $content));
        }

        return trim((string) $content);
    }

    /** @param array<string, mixed> $d */
    private function contextEvent(array $d, string $sessionId, string $agentId): ?Event
    {
        $a = $d['attachment'] ?? [];
        $kind = $a['type'] ?? '';
        if (!isset(self::CONTEXT_ATTACHMENTS[$kind])) {
            return null;
        }
        $touches = [];
        if ($kind === 'instructions') {
            foreach ((array) ($a['files'] ?? []) as $f) {
                if (isset($f['path'])) {
                    $touches[] = new Touch(Path::normalize((string) $f['path']), Touch::CONTEXT, file_exists((string) $f['path']));
                }
            }
        } else {
            $file = (string) ($a['path'] ?? $a['filename'] ?? '');
            if ($file === '' || !str_starts_with($file, '/')) {
                return null;
            }
            $partial = in_array($kind, ['selected_lines_in_ide', 'edited_text_file'], true);
            $range = isset($a['lineStart']) ? $a['lineStart'] . '-' . ($a['lineEnd'] ?? '') : null;
            $touches[] = new Touch(Path::normalize($file), Touch::CONTEXT, file_exists($file), $partial, $range);
        }
        if ($touches === []) {
            return null;
        }

        return new Event(
            (string) ($d['timestamp'] ?? ''), $sessionId, $agentId, Event::TOOL_CONTEXT, (string) ($d['uuid'] ?? ''),
            self::CONTEXT_ATTACHMENTS[$kind], $d['cwd'] ?? null, false, $touches,
        );
    }

    /**
     * Zakłócenie pracy agenta zapisane w transkrypcie: przerwanie przez usera (`[Request interrupted by user…]`),
     * limit sesji albo inny błąd API (linia z `isApiErrorMessage` — komunikat Claude Code, nie treść pliku).
     *
     * @param array<string, mixed> $d
     * @return ?array{ts: string, agent: string, kind: string, text: string}
     */
    public static function incident(array $d, string $agentId): ?array
    {
        $type = $d['type'] ?? '';
        $content = $d['message']['content'] ?? null;
        $text = is_string($content) ? $content : '';
        if (is_array($content)) {
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text') {
                    $text .= (string) ($block['text'] ?? '');
                }
            }
        }
        $ts = (string) ($d['timestamp'] ?? '');
        if ($type === 'user' && str_starts_with(ltrim($text), '[Request interrupted by user')) {
            return ['ts' => $ts, 'agent' => $agentId, 'kind' => 'interrupt', 'text' => str_contains($text, 'for tool use') ? 'interrupted during a tool call' : 'interrupted'];
        }
        if ($type === 'assistant' && ($d['isApiErrorMessage'] ?? false) === true) {
            $first = mb_strimwidth(trim(strtok($text, "\n") ?: ''), 0, 160, '…');

            return ['ts' => $ts, 'agent' => $agentId, 'kind' => stripos($text, 'limit') !== false ? 'limit' : 'api-error', 'text' => $first !== '' ? $first : (string) ($d['error'] ?? 'API error')];
        }

        return null;
    }

    /**
     * Wiadomość usera (`prompt`), komenda (`command`), powiadomienie zadania w tle (`notify`) albo kompaktowanie (`compact`);
     * null dla wyników narzędzi i wpisów technicznych.
     *
     * @param array<string, mixed> $d
     * @return ?array{ts: string, text: string, kind: string}
     */
    public static function prompt(array $d): ?array
    {
        $ts = (string) ($d['timestamp'] ?? '');
        if (($d['isCompactSummary'] ?? false) === true) {
            return ['ts' => $ts, 'text' => 'context compaction', 'kind' => 'compact'];
        }
        if (($d['isMeta'] ?? false) === true) {
            return null;
        }
        $content = $d['message']['content'] ?? null;
        $text = '';
        if (is_string($content)) {
            $text = $content;
        } elseif (is_array($content)) {
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'tool_result') {
                    return null;
                }
                if (($block['type'] ?? '') === 'text') {
                    $text .= ' ' . $block['text'];
                }
            }
        }
        $kind = 'prompt';
        if (preg_match('/<(cross-session-message|agent-message)\b[^>]*?(?:from-name|from)="([^"]*)"/', $text, $m)) {
            // wiadomość od innej sesji albo subagenta — nie prompt usera
            return ['ts' => $ts, 'text' => 'message from: ' . $m[2], 'kind' => 'peer'];
        }
        if (str_contains($text, '<local-command-stdout>') || str_contains($text, '<local-command-stderr>')) {
            // wynik komendy wpisanej przez usera (/model, /exit, /context) — nie wiadomość usera
            return null;
        }
        if (str_contains($text, '<task-notification>')) {
            $kind = 'notify';
            $text = preg_match('/<summary>(.*?)<\/summary>/s', $text, $m) ? $m[1] : 'background task finished';
        } elseif (preg_match('/<command-name>(.*?)<\/command-name>/s', $text, $m)) {
            $kind = 'command';
            $text = $m[1];
        }
        $text = trim((string) preg_replace('/<[^>]+>/', ' ', $text));
        if ($text === '' || str_starts_with($text, '[Request interrupted')) {
            return null;
        }

        // pełny tekst (panel „prompts”), z rozsądnym limitem na wklejone dokumenty
        return ['ts' => $ts, 'text' => mb_strimwidth(trim((string) preg_replace('/[ \t]+/', ' ', $text)), 0, self::TEXT_LIMIT, '…'), 'kind' => $kind];
    }

    /**
     * Kto zablokował wywołanie — z decyzji zapisanej przez Claude Code w linii `tool_result`
     * (`toolDenialKind`, `permissionDecision`), a w starszych transkryptach z treści błędu. Bez własnego dopasowywania reguł:
     * nowa reguła w settings.json albo nowy hook pojawi się tu sam, gdy pierwszy raz coś zablokuje.
     *
     * @param array<string, mixed> $d linia tool_result
     * @return ?array{by: string, detail: ?string}
     */
    public static function denial(array $d, string $error): ?array
    {
        $kind = (string) ($d['toolDenialKind'] ?? '');
        $pd = is_array($d['permissionDecision'] ?? null) ? $d['permissionDecision'] : [];
        $text = (string) preg_replace('/^Error:\s*/', '', $error);
        $by = match (true) {
            $kind === 'user-rejected' || str_starts_with($text, "The user doesn't want to proceed") => 'user',
            ($pd['decision'] ?? '') === 'reject' && ($pd['source'] ?? '') === 'hook', str_contains($text, 'hook error:') && str_contains($text, 'BLOCKED') => 'hook',
            str_starts_with($kind, 'automode') || (($pd['decision'] ?? '') === 'reject' && ($pd['reasonType'] ?? '') === 'classifier') || str_contains($text, 'auto mode classifier') => 'auto mode',
            ($pd['reasonType'] ?? '') === 'safetyCheck' || str_contains($text, 'built-in Claude Code safety check') => 'safety check',
            $kind !== '' || ($pd['decision'] ?? '') === 'reject' || (str_starts_with($text, 'Permission to use ') && str_contains($text, 'has been denied')) => 'deny rule',
            default => null,
        };
        if ($by === null) {
            return null;
        }
        $detail = null;
        if ($by === 'hook') {
            $script = preg_match('/\[(?:bash\s+)?"?([^"\]]+?)"?\]/', $text, $m) ? basename($m[1]) : 'hook';
            $rule = preg_match("/rule matching '([^']*)'/", $text, $m) ? "'" . $m[1] . "'" : (preg_match('/protected path (\S+)/', $text, $m) ? $m[1] : null);
            $detail = $script . ($rule !== null ? ': ' . $rule : '');
        } elseif ($by === 'auto mode') {
            $detail = preg_match('/Reason: \[([^\]]+)\]/', $text, $m) ? $m[1] : ($kind === 'automode-unavailable' || str_contains($text, 'no verdict') ? 'no verdict' : null);
        }

        return ['by' => $by, 'detail' => $detail];
    }

    private static function firstLine(mixed $content): string
    {
        if (is_array($content)) {
            $content = implode("\n", array_map(static fn ($b): string => is_array($b) ? (string) ($b['text'] ?? '') : (string) $b, $content));
        }

        return mb_strimwidth(trim(strtok((string) $content, "\n") ?: ''), 0, 200, '…');
    }

    /** @param array<string, mixed> $input */
    public static function summary(string $tool, array $input): string
    {
        $text = match ($tool) {
            'Bash' => (string) ($input['command'] ?? ''),
            'Read', 'Write', 'Edit', 'MultiEdit' => (string) ($input['file_path'] ?? ''),
            'Grep' => '"' . ($input['pattern'] ?? '') . '" ' . ($input['path'] ?? ''),
            'Glob' => (string) ($input['pattern'] ?? '') . ' ' . ($input['path'] ?? ''),
            'Agent', 'Task' => ($input['subagent_type'] ?? 'agent') . ': ' . ($input['description'] ?? ''),
            'Skill' => (string) ($input['skill'] ?? ''),
            default => (string) json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };

        return mb_strimwidth((string) preg_replace('/\s+/', ' ', $text), 0, 200, '…');
    }
}
