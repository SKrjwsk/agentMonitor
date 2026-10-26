<?php

declare(strict_types=1);

namespace Viz;

use Viz\Model\Event;
use Viz\Model\Touch;

/**
 * Oznaczanie wywołań, które wskazują na problem — podstawa filtra „tylko problemy” i metryk.
 * Reguły są deterministyczne; to sygnały do obejrzenia, nie werdykty.
 */
final class Problems
{
    /** Odczyt pliku liczy się jako problem od drugiego razu, gdy plik czytano łącznie co najmniej tyle razy. */
    public const REREAD_MIN = 3;
    public const RETRY_SECONDS = 120;

    /**
     * @param list<Event> $events posortowane po czasie
     * @return array<string, list<string>> toolUseId => powody
     */
    public static function tag(array $events): array
    {
        $totalReads = [];
        $context = [];
        foreach ($events as $e) {
            if (!$e->executed()) {
                continue;
            }
            foreach ($e->touches as $t) {
                if ($t->op === Touch::READ) {
                    $totalReads[Path::key($t->path)] = ($totalReads[Path::key($t->path)] ?? 0) + 1;
                }
            }
        }

        $tags = [];
        $seenReads = [];
        $known = [];
        $lastFailed = [];
        foreach ($events as $e) {
            // ryzykowne komendy nie są tu oznaczane: pilnują ich hook i permissions.deny, a skutek widać w statusie „blocked”
            $reasons = [];
            // ta sama komenda tego agenta po błędzie, w ciągu RETRY_SECONDS
            $key = $e->agentId . '|' . $e->tool . '|' . $e->summary;
            if (isset($lastFailed[$key]) && self::seconds($lastFailed[$key], $e->ts) <= self::RETRY_SECONDS) {
                $reasons[] = 'retry after error';
            }
            if ($e->failed) {
                $lastFailed[$key] = $e->endTs ?? $e->ts;
            }
            if ($e->failed) {
                // blokada to decyzja zabezpieczeń, nie błąd agenta — osobny status „blocked”
                if ($e->denial === null) {
                    $reasons[] = 'error' . ($e->error !== null ? ': ' . mb_strimwidth($e->error, 0, 80, '…') : '');
                }
            } elseif ($e->tool === 'Bash' && $e->isBlind()) {
                $reasons[] = 'blind spot';
            } elseif ($e->unresolved !== []) {
                $reasons[] = 'partial parse';
            }
            if ($e->executed()) {
                foreach ($e->touches as $t) {
                    $key = Path::key($t->path);
                    if ($t->op === Touch::CONTEXT) {
                        $context[$e->agentId][$key] = true;
                        $known[$e->agentId][$key] = true;
                        continue;
                    }
                    if ($t->op === Touch::READ) {
                        if (isset($context[$e->agentId][$key])) {
                            $reasons[] = 'read of a file already in context: ' . basename($t->path);
                        }
                        $seenReads[$key] = ($seenReads[$key] ?? 0) + 1;
                        if ($seenReads[$key] > 1 && ($totalReads[$key] ?? 0) >= self::REREAD_MIN) {
                            $reasons[] = 're-read (' . $seenReads[$key] . '/' . $totalReads[$key] . '): ' . basename($t->path);
                        }
                    }
                    if ($t->op === Touch::EDIT && !isset($known[$e->agentId][$key])) {
                        $reasons[] = 'edit without prior read: ' . basename($t->path);
                    }
                    if (in_array($t->op, [Touch::READ, Touch::EDIT, Touch::WRITE], true)) {
                        $known[$e->agentId][$key] = true;
                    }
                }
            }
            if ($reasons !== []) {
                $tags[$e->toolUseId] = array_values(array_unique($reasons));
            }
        }

        return $tags;
    }

    /** Powód problemu (zapisany po angielsku) w bieżącym języku — tylko do wyświetlenia, eksport używa surowego tekstu. */
    public static function reasonText(string $reason): string
    {
        foreach (['retry after error', 'blind spot', 'partial parse'] as $plain) {
            if ($reason === $plain) {
                return t($plain);
            }
        }
        if (preg_match('/^error(?:: (.*))?$/s', $reason, $m)) {
            return isset($m[1]) ? t('error: {msg}', ['msg' => $m[1]]) : t('error');
        }
        if (preg_match('/^read of a file already in context: (.*)$/s', $reason, $m)) {
            return t('read of a file already in context: {file}', ['file' => $m[1]]);
        }
        if (preg_match('/^re-read \((\d+)\/(\d+)\): (.*)$/s', $reason, $m)) {
            return t('re-read ({a}/{b}): {file}', ['a' => $m[1], 'b' => $m[2], 'file' => $m[3]]);
        }
        if (preg_match('/^edit without prior read: (.*)$/s', $reason, $m)) {
            return t('edit without prior read: {file}', ['file' => $m[1]]);
        }

        return $reason;
    }

    private static function seconds(string $from, string $to): float
    {
        try {
            return (float) (new \DateTimeImmutable($to))->format('U.u') - (float) (new \DateTimeImmutable($from))->format('U.u');
        } catch (\Exception) {
            return PHP_FLOAT_MAX;
        }
    }
}
