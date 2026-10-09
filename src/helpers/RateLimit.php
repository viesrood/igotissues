<?php

declare(strict_types=1);

namespace viesrood\igotissues\helpers;

use Craft;

/**
 * A ceiling on how often one user may ask for something that costs money or
 * creates something elsewhere: a draft from Claude, an issue on GitHub.
 */
final class RateLimit
{
    /**
     * Returns false when this user has had their allowance for the window.
     */
    public static function hit(string $bucket, int $userId, int $max, int $windowSeconds = 60): bool
    {
        $key = 'igotissues:rate:' . $bucket . ':' . $userId;
        $cache = Craft::$app->getCache();

        $used = (int)$cache->get($key);

        if ($used >= $max) {
            return false;
        }

        // Not sliding: the counter simply expires. Precise enough for a ceiling
        // whose job is to stop a loop, not to meter use.
        $cache->set($key, $used + 1, $windowSeconds);

        return true;
    }
}
