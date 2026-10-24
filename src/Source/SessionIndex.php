<?php

declare(strict_types=1);

namespace Viz\Source;

/** Lista ostatnich sesji z ~/.claude/projects — bez parsowania całych transkryptów. */
final class SessionIndex
{
    /** @return list<array{id: string, path: string, mtime: int, title: string, subtitle: string, empty: bool, cwd: string, hook: bool}> */
    public static function recent(int $limit = 40): array
    {
        $files = glob(TranscriptReader::projectsDir() . '/*/*.jsonl') ?: [];
        $mtimes = [];
        foreach ($files as $f) {
            if (HookLog::validSessionId(basename($f, '.jsonl'))) {
                $mtimes[$f] = (int) @filemtime($f);
            }
        }
        // tani wstępny wybór po mtime, potem dokładny czas ostatniego zdarzenia
        arsort($mtimes);
        $mtimes = array_slice($mtimes, 0, $limit * 2, true);
        foreach (array_keys($mtimes) as $f) {
            $mtimes[$f] = self::mtime($f);
        }
        arsort($mtimes);
        $out = [];
        foreach (array_slice($mtimes, 0, $limit, true) as $f => $mtime) {
            $id = basename($f, '.jsonl');
            [$title, $talk, $subtitle] = self::title($f);
            $out[] = [
                'id' => $id,
                'path' => $f,
                'mtime' => $mtime,
                'title' => $title,
                'subtitle' => $subtitle,
                'empty' => !$talk,
                'cwd' => self::cwd($f),
                'hook' => is_file(HookLog::path($id)),
            ];
        }

        return $out;
    }

    /**
     * Czas ostatniego zdarzenia zapisanego w sesji (główna + subagenci), z końcówki plików.
     * Nie mtime: Claude Code potrafi zmienić mtime wielu transkryptów naraz bez dopisania zdarzeń.
     */
    public static function mtime(string $mainPath): int
    {
        $t = self::lastTimestamp($mainPath);
        foreach (glob(substr($mainPath, 0, -6) . '/subagents/*.jsonl') ?: [] as $f) {
            $t = max($t, self::lastTimestamp($f));
        }

        return $t > 0 ? $t : (int) @filemtime($mainPath);
    }

    private static function lastTimestamp(string $file): int
    {
        if (!preg_match_all('/"timestamp":"(\d{4}-\d\d-\d\dT[0-9:.]+Z)"/', self::chunk($file, -65536), $m) || $m[1] === []) {
            return 0;
        }

        return (int) strtotime(max($m[1]));
    }

    /** Wersja do porównań przy odświeżaniu na żywo: rozmiary plików. */
    public static function version(string $mainPath): string
    {
        $v = (int) @filesize($mainPath);
        foreach (glob(substr($mainPath, 0, -6) . '/subagents/*.jsonl') ?: [] as $f) {
            $v += (int) @filesize($f);
        }

        return (string) $v;
    }

    /**
     * Nazwa sesji: nadana przez usera (`custom-title`, także plik `custom-title.json` obok transkryptu) > `ai-title` >
     * początek pierwszego promptu > lista komend, gdy sesja nie ma rozmowy (np. samo `/model` albo `/exit`).
     *
     * Przy nazwie nadanej przez usera tytuł AI (jeśli jest) wraca jako podtytuł — kilka sesji bywa nazwanych tak samo.
     *
     * @return array{0: string, 1: bool, 2: string} [nazwa, czy sesja ma rozmowę, podtytuł]
     */
    private static function title(string $f): array
    {
        $tail = self::chunk($f, -524288);
        $last = static fn (string $field): string => preg_match_all('/"' . $field . '":"((?:[^"\\\\]|\\\\.)*)"/', $tail, $m) && $m[1] !== []
            ? (string) json_decode('"' . end($m[1]) . '"')
            : '';
        $custom = $last('customTitle');
        if ($custom === '') {
            $sidecar = json_decode((string) @file_get_contents(substr($f, 0, -6) . '/custom-title.json'), true);
            $custom = is_array($sidecar) && is_string($sidecar['customTitle'] ?? null) ? $sidecar['customTitle'] : '';
        }
        $ai = $last('aiTitle');
        if ($custom !== '') {
            return [$custom, true, $ai !== $custom ? $ai : ''];
        }
        if ($ai !== '') {
            return [$ai, true, ''];
        }
        $commands = [];
        foreach (explode("\n", self::chunk($f, 0)) as $line) {
            if (!str_contains($line, '"type":"user"')) {
                continue;
            }
            $prompt = TranscriptReader::prompt(json_decode($line, true) ?: []);
            if ($prompt === null) {
                continue;
            }
            if ($prompt['kind'] === 'prompt') {
                return [mb_strimwidth((string) preg_replace('/\s+/', ' ', $prompt['text']), 0, 80, '…'), true, ''];
            }
            if ($prompt['kind'] === 'command') {
                $commands[] = $prompt['text'];
            }
        }

        return [$commands !== [] ? implode(' ', array_unique($commands)) : '', false, ''];
    }

    private static function cwd(string $f): string
    {
        if (preg_match('/"cwd":"((?:[^"\\\\]|\\\\.)*)"/', self::chunk($f, 0), $m)) {
            return (string) json_decode('"' . $m[1] . '"');
        }

        return '';
    }

    private static function chunk(string $f, int $offset): string
    {
        $h = @fopen($f, 'rb');
        if ($h === false) {
            return '';
        }
        $size = (int) filesize($f);
        fseek($h, $offset < 0 ? max(0, $size + $offset) : $offset);
        $data = (string) fread($h, 524288);
        fclose($h);

        return $data;
    }
}
