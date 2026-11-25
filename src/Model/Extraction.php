<?php

declare(strict_types=1);

namespace Viz\Model;

/** Result of extracting paths from one tool call. */
final class Extraction
{
    /** @var list<Touch> */
    public array $touches = [];
    /** @var list<string> command fragments from which no path could be determined */
    public array $unresolved = [];
    /** @var list<string> programs that read files themselves (composer, php script, python -) */
    public array $indirect = [];
    /** @var list<string> commands outside the parser's rules */
    public array $unknown = [];
    /** @var list<Command> program occurrences (M11) */
    public array $commands = [];
    /** @var array<int, string> last program at a given nesting level — for pipe pairs */
    public array $lastByDepth = [];

    public function __construct(public ?string $cwdAfter = null)
    {
    }

    public function add(Touch $touch): void
    {
        $this->touches[] = $touch;
    }
}
