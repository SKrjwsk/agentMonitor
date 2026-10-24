<?php

declare(strict_types=1);

namespace Viz\Model;

/**
 * Jedno wystąpienie programu w komendzie Bash (jednostka analizy komend, M11).
 * Kod wyjścia jest znany tylko dla całego wywołania — program dziedziczy status wywołania.
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
     * @param ?string $sub podkomenda dla narzędzi wielokomendowych (`git push`, `composer precommit`, `php console x:y`)
     * @param list<string> $contexts pipe / subst / xargs / inner / remote / stdin-script
     * @param list<string> $wrappers zdjęte nakładki: sudo, env, timeout, nohup, nice, exec, command
     * @param ?string $pipedFrom program po lewej stronie `|`
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
