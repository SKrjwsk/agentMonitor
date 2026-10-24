<?php

declare(strict_types=1);

namespace Viz;

use Viz\Model\Event;
use Viz\Model\Touch;

/** Agregaty liczone przy każdym renderze — nigdy nie zapisywane. */
final class Stats
{
    public int $calls = 0;
    public int $failed = 0;
    public int $bashCalls = 0;
    public int $blind = 0;
    /** Wywołania Bash z wyciągniętymi ścieżkami, ale z częścią nierozpoznaną. */
    public int $incomplete = 0;
    /** @var array<string, int> */
    public array $perTool = [];
    /** @var array<string, int> liczba plików per operacja */
    public array $filesPerOp = [];
    /**
     * @var array<string, array{path: string, ops: array<string, int>, partialOnly: bool, agents: array<string, true>, exists: bool}>
     */
    public array $files = [];
    /** Pliki wstrzyknięte przez harness (CLAUDE.md, rules, @plik) vs doczytane przez agenta narzędziem. */
    public int $contextFiles = 0;
    public int $selfReadFiles = 0;
    /** Pliki, które agent doczytał narzędziem, choć harness już mu je wstrzyknął. */
    public int $contextReread = 0;
    /** @var array<string, int> */
    public array $indirect = [];
    /** @var array<string, int> */
    public array $unknown = [];

    /** @param list<Event> $events */
    public static function of(array $events): self
    {
        $s = new self();
        foreach ($events as $e) {
            if ($e->tool !== Event::TOOL_CONTEXT) {
                $s->calls++;
                $s->perTool[$e->tool] = ($s->perTool[$e->tool] ?? 0) + 1;
            }
            if ($e->failed) {
                $s->failed++;
            }
            if (!$e->executed()) {
                continue;
            }
            if ($e->tool === 'Bash') {
                $s->bashCalls++;
                $s->blind += $e->isBlind() ? 1 : 0;
                $s->incomplete += !$e->isBlind() && $e->unresolved !== [] ? 1 : 0;
            }
            foreach ($e->indirect as $name) {
                $s->indirect[$name] = ($s->indirect[$name] ?? 0) + 1;
            }
            foreach ($e->unknown as $name) {
                $s->unknown[$name] = ($s->unknown[$name] ?? 0) + 1;
            }
            foreach ($e->touches as $t) {
                $key = Path::key($t->path);
                $f = $s->files[$key] ?? ['path' => $t->path, 'ops' => [], 'partialOnly' => false, 'fullRead' => false, 'agents' => [], 'exists' => $t->exists];
                $f['ops'][$t->op] = ($f['ops'][$t->op] ?? 0) + 1;
                if (in_array($t->op, [Touch::READ, Touch::CONTEXT], true)) {
                    $f['fullRead'] = $f['fullRead'] || !$t->partial;
                    $f['partialOnly'] = !$f['fullRead'];
                }
                $f['agents'][$e->agentId] = true;
                $s->files[$key] = $f;
            }
        }
        foreach ($s->files as $f) {
            $s->contextFiles += isset($f['ops'][Touch::CONTEXT]) ? 1 : 0;
            $s->selfReadFiles += isset($f['ops'][Touch::READ]) ? 1 : 0;
            $s->contextReread += isset($f['ops'][Touch::CONTEXT], $f['ops'][Touch::READ]) ? 1 : 0;
            foreach (array_keys($f['ops']) as $op) {
                $s->filesPerOp[$op] = ($s->filesPerOp[$op] ?? 0) + 1;
            }
        }
        arsort($s->perTool);
        arsort($s->indirect);
        arsort($s->unknown);
        ksort($s->files);

        return $s;
    }
}
