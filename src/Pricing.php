<?php

declare(strict_types=1);

namespace Viz;

/**
 * Cost of a model response per Anthropic API price list — a comparative measure, not a bill (a subscription counts differently).
 * Cache read has a different rate in every model (Opus 5.5: 0.05× input), so cost is computed per model, not with one weight.
 */
final class Pricing
{
    /**
     * $ per 1M tokens: input, output, cache read. Cache write = 1.25× input (5 min) or 2× (1 h).
     * Price list as of: 2026-10-06. Haiku 5.5: cache read not given — assumed 0.1× input; above 100k prompt tokens Haiku 5.5 is more expensive (ignored).
     * Key = model id prefix, the longest wins.
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

    /** @return ?array{0: float, 1: float, 2: float} rates $/1M: input, output, cache read; null = model not in the price list */
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

    /** $ per 1M cache write tokens for a given split between 5-minute and 1-hour writes. */
    public static function writeRate(float $inputRate, int $write, int $write1h): float
    {
        return $write > 0 ? $inputRate * (self::WRITE_5M * ($write - $write1h) + self::WRITE_1H * $write1h) / $write : $inputRate * self::WRITE_5M;
    }

    /**
     * Cost of one response broken down into components; null = model not in the price list.
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
