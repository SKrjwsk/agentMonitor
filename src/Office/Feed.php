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
 * Wspólne dane widoków przeglądowych (`GET /feed.json`): sesje aktywne w oknie `LiveState::WINDOW` w zakresie projektów —
 * agenci (stan, wywołania, kontekst, koszt), granica, wiadomości. Widok w przeglądarce liczy z tego, co pokazuje (np. Live);
 * nowy widok nie potrzebuje kodu serwera.
 *
 * Pamięć podręczna `var/cache/feed-<zakres>.json` (`0700`/`0600`): ten sam zakres, te same pliki (rozmiar i mtime transkryptów
 * i subagentów, konfiguracja granicy) i wynik nie starszy niż `MAX_AGE` s — kilka kart i widoków odpytujących co 1 s dzieli
 * jedno parsowanie. Starszy wynik liczony od nowa, bo stany zależą od czasu (okna, progi „zgoda?”, koszt z 5 min).
 */
final class Feed
{
    public const MAX_AGE = 1;

    /** @return array<string, mixed> wynik `LiveState::merge` */
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

    /** Klucz `processes` dochodzi poza pamięcią podręczną feedu: procesy zmieniają się szybciej niż sesje i mają własną (`Processes::cached`). */
    private static function withProcesses(array $data, Scope $scope, int $now, Boundary $limits): array
    {
        $data['processes'] = ProcessView::build(Processes::cached($now), $scope, $limits);

        return $data;
    }

    /**
     * Transkrypty zmienione w oknie (mtime jako tani nadzbiór) z sesji w zakresie; projekt z `cwd` na początku pliku, bez czytania całości.
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

    /** Zapis przez plik tymczasowy i `rename` — równoległe żądanie nie przeczyta połowy pliku. Błąd zapisu nie psuje odpowiedzi. */
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
