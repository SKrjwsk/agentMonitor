<?php

declare(strict_types=1);

namespace Viz\Extract;

use Viz\Model\Extraction;
use Viz\Model\Touch;
use Viz\Path;

/** Ścieżki z wywołania dowolnego narzędzia Claude Code. */
final class ToolTouches
{
    private BashTouches $bash;

    public function __construct()
    {
        $this->bash = new BashTouches();
    }

    /** @param array<string, mixed> $input */
    public function extract(string $tool, array $input, ?string $cwd): Extraction
    {
        if ($tool === 'Bash') {
            return $this->bash->extract((string) ($input['command'] ?? ''), $cwd);
        }

        $out = new Extraction($cwd);
        match ($tool) {
            'Read' => $this->read($input, $cwd, $out),
            'Write' => $this->file($input['file_path'] ?? null, Touch::WRITE, $cwd, $out),
            'Edit', 'MultiEdit' => $this->file($input['file_path'] ?? null, Touch::EDIT, $cwd, $out),
            'NotebookEdit' => $this->file($input['notebook_path'] ?? null, Touch::EDIT, $cwd, $out),
            'Grep' => $this->file($input['path'] ?? $cwd, Touch::SEARCH, $cwd, $out),
            'Glob' => $this->file($input['path'] ?? $cwd, Touch::LIST, $cwd, $out),
            'LSP' => $this->file($input['filePath'] ?? $input['file_path'] ?? null, Touch::SEARCH, $cwd, $out),
            default => null,
        };

        return $out;
    }

    /** @param array<string, mixed> $input */
    private function read(array $input, ?string $cwd, Extraction $out): void
    {
        $offset = isset($input['offset']) ? (int) $input['offset'] : null;
        $limit = isset($input['limit']) ? (int) $input['limit'] : null;
        $range = null;
        if ($offset !== null || $limit !== null) {
            $from = $offset ?? 1;
            $range = $from . '-' . ($limit !== null ? (string) ($from + $limit - 1) : '');
        }
        $this->file($input['file_path'] ?? null, Touch::READ, $cwd, $out, $range !== null, $range);
    }

    private function file(mixed $path, string $op, ?string $cwd, Extraction $out, bool $partial = false, ?string $range = null): void
    {
        if (!is_string($path) || $path === '') {
            return;
        }
        $abs = Path::resolve($path, $cwd);
        if ($abs === null) {
            $out->unresolved[] = $path . ' — unknown working directory';
            return;
        }
        $out->add(new Touch($abs, $op, file_exists($abs), $partial, $range));
    }
}
