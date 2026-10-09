<?php

declare(strict_types=1);

namespace viesrood\igotissues\helpers;

/**
 * Removes secrets and installation paths from text before it leaves the site.
 *
 * Everything a report carries (an error message, a stack trace, a page URL)
 * ends up at GitHub and, for a draft, at the Claude API. None of it should
 * carry a credential or reveal where the site lives on disk.
 */
final class Scrubber
{
    public const REMOVED = '[removed]';

    /** Names of env vars whose value is a secret. */
    private const SECRET_ENV = '/(key|token|secret|passw|pwd|dsn|credential)/i';

    /** Names of query parameters whose value is a secret. `code` only as a whole name. */
    private const SECRET_PARAM = '/(key|token|secret|passw|pwd|signature|credential)|^(code|sig|auth)$/i';

    /** A value shorter than this is too likely to be an ordinary word. */
    private const MIN_SECRET_LENGTH = 8;

    /**
     * @param string[] $secrets Literal values to remove, see secretsFrom().
     * @param string $basePath The project root; made relative when present.
     */
    public static function text(string $text, array $secrets = [], string $basePath = ''): string
    {
        foreach ($secrets as $secret) {
            if (mb_strlen($secret) >= self::MIN_SECRET_LENGTH) {
                $text = str_replace($secret, self::REMOVED, $text);
            }
        }

        $text = (string)preg_replace('/\b(Bearer|Basic|token)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 ' . self::REMOVED, $text);
        $text = (string)preg_replace('#(://[^/\s:@]+):[^@\s/]+@#', '$1:' . self::REMOVED . '@', $text);
        $text = (string)preg_replace_callback(
            '/([?&;]|\b)([A-Za-z0-9_.\-\[\]]+)=([^&\s#"\'<>]+)/',
            static fn(array $m): string => preg_match(self::SECRET_PARAM, $m[2]) === 1
                ? $m[1] . $m[2] . '=' . self::REMOVED
                : $m[0],
            $text,
        );

        $basePath = rtrim($basePath, '/');

        if ($basePath !== '') {
            $text = str_replace($basePath . '/', '', $text);
        }

        return $text;
    }

    /**
     * The values of every env var whose name says it holds a secret.
     *
     * @param array<array-key, mixed> $env
     * @return string[]
     */
    public static function secretsFrom(array $env): array
    {
        $secrets = [];

        foreach ($env as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                continue;
            }

            if (preg_match(self::SECRET_ENV, $name) === 1 && mb_strlen($value) >= self::MIN_SECRET_LENGTH) {
                $secrets[] = $value;
            }
        }

        // Longest first, so a secret that contains another is removed whole.
        usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return array_values(array_unique($secrets));
    }

    /**
     * Cut to a length without splitting a multibyte character.
     */
    public static function limit(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, max(0, $length - 1)) . '…' : $text;
    }
}
