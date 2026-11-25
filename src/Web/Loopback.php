<?php

declare(strict_types=1);

namespace Viz\Web;

/**
 * The app serves only the machine it runs on: `php -S` binds wherever the user tells it to, so the request address is checked as well.
 */
final class Loopback
{
    private const ADDRESSES = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];

    public static function isLoopback(string $remoteAddress): bool
    {
        return in_array($remoteAddress, self::ADDRESSES, true);
    }
}
