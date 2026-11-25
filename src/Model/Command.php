<?php

declare(strict_types=1);

namespace Viz\Model;

/**
 * One occurrence of a program in a Bash command (unit of command analysis, M11).
 * The exit code is known only for the whole call — the program inherits the call's status.
 */
final readonly class Command
{
    public const PIPE = 'pipe';
    public const SUBST = 'subst';
    public const XARGS = 'xargs';
    public const INNER = 'inner';
    public const REMOTE = 'remote';
    public const STDIN_SCRIPT = 'stdin-script';

    /**
     * @param ?string $sub subcommand for multi-command tools (`git push`, `composer precommit`, `php console x:y`)
     * @param list<string> $contexts pipe / subst / xargs / inner / remote / stdin-script
     * @param list<string> $wrappers stripped wrappers: sudo, env, timeout, nohup, nice, exec, command
     * @param ?string $pipedFrom program on the left side of `|`
     */
    public function __construct(
        public string $program,
        public ?string $sub = null,
        public array $contexts = [],
        public array $wrappers = [],
        public ?string $pipedFrom = null,
    ) {
    }

    public function withContext(string $context): self
    {
        return new self($this->program, $this->sub, array_values(array_unique([...$this->contexts, $context])), $this->wrappers, $this->pipedFrom);
    }

    public function has(string $context): bool
    {
        return in_array($context, $this->contexts, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['program' => $this->program, 'sub' => $this->sub, 'contexts' => $this->contexts, 'wrappers' => $this->wrappers, 'piped_from' => $this->pipedFrom];
    }

    /** @param array<string, mixed> $d */
    public static function fromArray(array $d): self
    {
        return new self((string) $d['program'], isset($d['sub']) ? (string) $d['sub'] : null, (array) ($d['contexts'] ?? []), (array) ($d['wrappers'] ?? []), isset($d['piped_from']) ? (string) $d['piped_from'] : null);
    }
}
