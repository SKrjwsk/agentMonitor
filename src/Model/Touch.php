<?php

declare(strict_types=1);

namespace Viz\Model;

/** Jedno dotknięcie pliku lub katalogu przez wywołanie narzędzia. */
final readonly class Touch
{
    public const READ = 'read';
    public const SEARCH = 'search';
    public const LIST = 'list';
    public const EDIT = 'edit';
    public const WRITE = 'write';
    /** Plik wczytany przez harness (CLAUDE.md, rules, @plik), nie przez narzędzie. */
    public const CONTEXT = 'context';
    /** Skrypt/program uruchomiony przez agenta albo plik przekazany mu jako argument — treść nie trafia do agenta. */
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
