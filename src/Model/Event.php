<?php

declare(strict_types=1);

namespace Viz\Model;

/** Jedno wywołanie narzędzia (albo wczytanie kontekstu przez harness). */
final readonly class Event
{
    public const TOOL_CONTEXT = '@context';

    /**
     * @param list<Touch> $touches
     * @param list<string> $unresolved
     * @param list<string> $indirect
     * @param list<string> $unknown
     */
    public function __construct(
        public string $ts,
        public string $sessionId,
        public string $agentId,
        public string $tool,
        public string $toolUseId,
        public string $summary,
        public ?string $cwd,
        public bool $failed,
        public array $touches,
        public array $unresolved = [],
        public array $indirect = [],
        public array $unknown = [],
        /** Koniec wywołania (tool_result / chwila hooka); null = nieznany. */
        public ?string $endTs = null,
        /** Pierwsza linia komunikatu błędu (powód, bez wyjścia komendy — to mogłaby być treść pliku). */
        public ?string $error = null,
        /** @var list<Command> wystąpienia programów w komendzie Bash */
        public array $commands = [],
        /**
         * Blokada zapisana przez Claude Code (wywołanie się nie wykonało): `by` = hook / deny rule / auto mode / safety check / user,
         * `detail` = np. skrypt hooka i dopasowana reguła albo kategoria klasyfikatora.
         *
         * @var ?array{by: string, detail: ?string}
         */
        public ?array $denial = null,
    ) {
    }

    /**
     * Czy narzędzie faktycznie zadziałało i wynik trafił do agenta.
     * Bash z kodem wyjścia ≠ 0 ma `is_error`, ale komenda się wykonała; blokada hooka albo odmowa uprawnień — nie.
     */
    public function executed(): bool
    {
        return !$this->failed || ($this->tool === 'Bash' && $this->error !== null && str_starts_with($this->error, 'Exit code'));
    }

    /** Wywołanie Bash, z którego nie wyciągnięto żadnej ścieżki, choć coś mogło czytać pliki. */
    public function isBlind(): bool
    {
        return $this->touches === [] && ($this->unresolved !== [] || $this->unknown !== []);
    }
}
