<?php

declare(strict_types=1);

namespace Viz\Office;

use Viz\Boundary;
use Viz\Source\Processes;
use Viz\Source\SessionIndex;
use Viz\Source\TranscriptReader;
use Viz\Summary\LiveSummary;
use Viz\Web\Scope;

/**
 * Shared data of the overview views (`GET /feed.json`): sessions active in the `LiveState::WINDOW` window within the project scope —
 * agents (state, calls, context, cost), boundary, messages. The browser view computes from this what it shows (e.g. Live);
 * a new view needs no server code.
 *
 * Cache `var/cache/feed-<scope>.json` (`0700`/`0600`): the same scope, the same files (size and mtime of transcripts
 * and subagents, boundary configuration) and a result not older than `MAX_AGE` s — several tabs and views polling every 1 s share
 * one parse. An older result is recomputed, because states depend on time (windows, "permission?" thresholds, cost over 5 min).
 */
final class Feed
{
    public const MAX_AGE = 1;

    /** @return array<string, mixed> result of `LiveState::merge` */
    public static function build(Scope $scope, int $now, Boundary $limits): array
    {
        $paths = self::paths($scope, LiveState::WINDOW);
        $signature = sha1((string) json_encode([$scope->value(), $limits->fingerprint(), array_map(
            static fn (string $p): array => [$p, (int) @filemtime($p), SessionIndex::version($p)],
            $paths,
        )]));
        $file = self::dir() . '/feed-' . substr(sha1($scope->value()), 0, 12) . '.json';
        $cached = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (is_array($cached) && ($cached['signature'] ?? '') === $signature && $now - (int) ($cached['at'] ?? 0) <= self::MAX_AGE && is_array($cached['data'] ?? null)) {
            return self::withProcesses($cached['data'], $scope, $now, $limits);
        }
        $reader = new TranscriptReader();
        $data = LiveState::merge(
            array_values(array_filter(array_map(static fn (string $p): ?array => LiveState::session($reader->read($p), $now, $limits), $paths))),
            $now,
            $limits,
        );
        self::save($file, ['signature' => $signature, 'at' => $now, 'data' => $data]);

        return self::withProcesses($data, $scope, $now, $limits);
    }

    /** The `processes` key is added outside the feed cache: processes change faster than sessions and have their own (`Processes::cached`). */
    private static function withProcesses(array $data, Scope $scope, int $now, Boundary $limits): array
    {
        $data['processes'] = ProcessView::build(Processes::cached($now), $scope, $limits);

        return $data;
    }

    /**
     * Transcripts changed in the window (mtime as a cheap superset) from sessions in scope; project from `cwd` at the start of the file, without reading it whole.
     *
     * @return list<string>
     */
    public static function paths(Scope $scope, int $window): array
    {
        return array_values(array_filter(LiveSummary::candidates($window), static fn (string $p): bool => $scope->includes(SessionIndex::cwd($p))));
    }

    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/var/cache';
    }

    /** Write via a temporary file and `rename` — a parallel request will not read half a file. A write error does not break the response. */
    private static function save(string $file, array $payload): void
    {
        if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0700, true)) {
            return;
        }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            return;
        }
        @chmod($tmp, 0600);
        @rename($tmp, $file);
    }
}
