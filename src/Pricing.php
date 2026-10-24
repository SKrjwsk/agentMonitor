<?php

declare(strict_types=1);

namespace Viz;

/**
 * Koszt odpowiedzi modelu według cennika API Anthropic — miara porównawcza, nie rachunek (subskrypcja liczy inaczej).
 * Odczyt z cache ma inną stawkę w każdym modelu (Opus 5.5: 0,05× wejścia), więc koszt liczymy per model, nie jedną wagą.
 */
final class Pricing
{
    /**
     * $ za 1M tokenów: wejście, wyjście, odczyt z cache. Zapis do cache = 1,25× wejścia (5 min) albo 2× (1 h).
     * Stan cennika: 2026-10-06. Haiku 5.5: odczyt z cache niepodany — przyjęte 0,1× wejścia; powyżej 100k tokenów promptu Haiku 5.5 jest droższy (pomijamy).
     * Klucz = prefiks id modelu, wygrywa najdłuższy.
     */
    public const MODELS = [
        'claude-fable-5-1' => [10.0, 50.0, 0.25],
        'claude-fable-5' => [10.0, 50.0, 1.0],
        'claude-opus-5-5' => [4.0, 20.0, 0.20],
        'claude-opus-5' => [5.0, 25.0, 0.50],
        'claude-opus-4' => [5.0, 25.0, 0.50],
        'claude-sonnet-5-5' => [2.0, 10.0, 0.20],
        'claude-sonnet-5' => [2.0, 10.0, 0.20],
        'claude-sonnet-4' => [3.0, 15.0, 0.30],
        'claude-haiku-5-5' => [0.10, 0.50, 0.01],
        'claude-haiku-4' => [1.0, 5.0, 0.10],
    ];

    public const WRITE_5M = 1.25;
    public const WRITE_1H = 2.0;

    /** @return ?array{0: float, 1: float, 2: float} stawki $/1M: wejście, wyjście, odczyt z cache; null = model spoza cennika */
    public static function rates(string $model): ?array
    {
        $best = null;
        foreach (self::MODELS as $prefix => $rates) {
            if (str_starts_with($model, $prefix) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best === null ? null : self::MODELS[$best];
    }

    /** $ za 1M tokenów zapisu do cache przy danym podziale na zapis 5-minutowy i godzinny. */
    public static function writeRate(float $inputRate, int $write, int $write1h): float
    {
        return $write > 0 ? $inputRate * (self::WRITE_5M * ($write - $write1h) + self::WRITE_1H * $write1h) / $write : $inputRate * self::WRITE_5M;
    }

    /**
     * Koszt jednej odpowiedzi rozbity na składniki; null = model spoza cennika.
     *
     * @return ?array{input: float, read: float, write: float, output: float}
     */
    public static function cost(string $model, int $input, int $cacheRead, int $cacheWrite, int $write1h, int $output): ?array
    {
        $r = self::rates($model);
        if ($r === null) {
            return null;
        }

        return [
            'input' => $input * $r[0] / 1e6,
            'read' => $cacheRead * $r[2] / 1e6,
            'write' => $cacheWrite * self::writeRate($r[0], $cacheWrite, $write1h) / 1e6,
            'output' => $output * $r[1] / 1e6,
        ];
    }
}
