<?php

declare(strict_types=1);

namespace Viz\Source;

/** List of recent sessions from ~/.claude/projects — without parsing whole transcripts. */
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
        // cheap preliminary selection by mtime, then the exact time of the last event
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
     * Time of the last event recorded in the session (main + subagents), from the file tails.
     * Not mtime: Claude Code can change the mtime of many transcripts at once without appending events.
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

    /** Version for comparison during live refresh: file sizes. */
    public static function version(string $mainPath): string
    {
        $v = (int) @filesize($mainPath);
        foreach (glob(substr($mainPath, 0, -6) . '/subagents/*.jsonl') ?: [] as $f) {
            $v += (int) @filesize($f);
        }

        return (string) $v;
    }

    /**
     * Session name: given by the user (`custom-title`, also the `custom-title.json` file next to the transcript) > `ai-title` >
     * start of the first prompt > list of commands, when the session has no conversation (e.g. just `/model` or `/exit`).
     *
     * With a user-given name, the AI title (if any) returns as a subtitle — several sessions may be named the same.
     *
     * @return array{0: string, 1: bool, 2: string} [name, whether the session has a conversation, subtitle]
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

    /** Working directory of the session from the start of the transcript (without reading the whole file); '' = unknown. */
    public static function cwd(string $f): string
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
