<?php

declare(strict_types=1);

namespace Viz;

final class Clock
{
    /** Strefa systemu: PHP CLI często ma `date.timezone=UTC`, a transkrypty zapisują czas w UTC. */
    public static function zone(): \DateTimeZone
    {
        $name = getenv('TZ') ?: '';
        if ($name === '' && is_link('/etc/localtime')) {
            $target = (string) readlink('/etc/localtime');
            $pos = strpos($target, 'zoneinfo/');
            $name = $pos === false ? '' : substr($target, $pos + 9);
        }
        try {
            return new \DateTimeZone($name !== '' ? $name : date_default_timezone_get());
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    public static function local(string $isoUtc, string $format = 'H:i:s'): string
    {
        try {
            return (new \DateTimeImmutable($isoUtc))->setTimezone(self::zone())->format($format);
        } catch (\Exception) {
            return '?';
        }
    }

    public static function nowUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
