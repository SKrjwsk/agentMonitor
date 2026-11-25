<?php

declare(strict_types=1);

namespace Viz\Model;

/** One tool call (or context loading by the harness). */
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
        /** End of the call (tool_result / hook moment); null = unknown. */
        public ?string $endTs = null,
        /** First line of the error message (the reason, without command output — that could be file content). */
        public ?string $error = null,
        /** @var list<Command> program occurrences in a Bash command */
        public array $commands = [],
        /**
         * Block recorded by Claude Code (the call did not execute): `by` = hook / deny rule / auto mode / safety check / user,
         * `detail` = e.g. hook script and matched rule, or classifier category.
         *
         * @var ?array{by: string, detail: ?string}
         */
        public ?array $denial = null,
        /**
         * Cost of a tool result in the context (estimate from transcript usage; the hook does not know it): `tokens` added to the context,
         * `calls` = later model responses of this agent that read it from cache (until compaction), `usd` = write + those reads
         * per API price list (null = model not in the price list).
         *
         * @var ?array{tokens: int, calls: int, usd: ?float}
         */
        public ?array $carry = null,
        /** System sandbox denied access to a file ("Operation not permitted" in an error result). */
        public bool $osDenied = false,
        /**
         * Full Bash command (Bash only; other tools = null). Solely for matching permission rules (`Permissions\RuleMatcher`) —
         * UI and export show `summary` (200 characters), because the rest may be file content (heredoc).
         */
        public ?string $command = null,
    ) {
    }

    /**
     * Whether the tool actually ran and the result reached the agent.
     * Bash with exit code ≠ 0 has `is_error`, but the command ran; a hook block or permission denial did not.
     */
    public function executed(): bool
    {
        return !$this->failed || ($this->tool === 'Bash' && $this->error !== null && str_starts_with($this->error, 'Exit code'));
    }

    /** A Bash call from which no path was extracted, although something may have read files. */
    public function isBlind(): bool
    {
        return $this->touches === [] && ($this->unresolved !== [] || $this->unknown !== []);
    }
}
