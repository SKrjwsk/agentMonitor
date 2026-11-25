<?php

declare(strict_types=1);

namespace Viz\Model;

/** One touch of a file or directory by a tool call. */
final readonly class Touch
{
    public const READ = 'read';
    public const SEARCH = 'search';
    public const LIST = 'list';
    public const EDIT = 'edit';
    public const WRITE = 'write';
    /** File loaded by the harness (CLAUDE.md, rules, @file), not by a tool. */
    public const CONTEXT = 'context';
    /** Script/program run by the agent or a file passed to it as an argument — the content does not reach the agent. */
    public const EXEC = 'exec';

    public function __construct(
        public string $path,
        public string $op,
        public bool $exists,
        public bool $partial = false,
        public ?string $range = null,
    ) {
    }
}
