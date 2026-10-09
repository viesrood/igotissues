<?php

declare(strict_types=1);

namespace viesrood\igotissues\helpers;

use Throwable;

/**
 * Decides when two errors are the same error.
 *
 * The fingerprint is built from where the error was thrown and from the first
 * frame of the site's own code that led there. The message is left out on
 * purpose: it usually carries an id or a path that differs per request. So is
 * the line of the calling frame: the same bug reached from two lines of one
 * function is one bug.
 */
final class Fingerprint
{
    public static function of(Throwable $exception, string $basePath): string
    {
        $root = self::root($exception);

        return self::hash(
            $root::class,
            self::relative($root->getFile(), $basePath),
            $root->getLine(),
            self::firstOwnFrame($root->getTrace(), $basePath),
        );
    }

    public static function hash(string $class, string $file, int $line, string $ownFrame): string
    {
        return sha1(implode('|', [$class, $file, (string)$line, $ownFrame]));
    }

    /**
     * The innermost exception: a Twig or Yii wrapper says where the error
     * surfaced, the cause says what went wrong.
     */
    public static function root(Throwable $exception): Throwable
    {
        $seen = 0;

        while ($exception->getPrevious() !== null && $seen++ < 20) {
            $exception = $exception->getPrevious();
        }

        return $exception;
    }

    public static function relative(string $file, string $basePath): string
    {
        $basePath = rtrim($basePath, '/');

        if ($basePath !== '' && str_starts_with($file, $basePath . '/')) {
            return substr($file, strlen($basePath) + 1);
        }

        return $file;
    }

    /**
     * "file:function" of the first frame outside vendor/, or '' when the whole
     * trace is third-party code.
     *
     * @param array<int, array<string, mixed>> $trace
     */
    public static function firstOwnFrame(array $trace, string $basePath): string
    {
        foreach ($trace as $frame) {
            $file = is_string($frame['file'] ?? null) ? self::relative($frame['file'], $basePath) : '';

            if ($file === '' || str_starts_with($file, '/') || str_starts_with($file, 'vendor/')) {
                continue;
            }

            // A compiled template has a new name after every cache clear.
            if (str_contains($file, 'compiled_templates/')) {
                continue;
            }

            $function = is_string($frame['function'] ?? null) ? $frame['function'] : '';
            $class = is_string($frame['class'] ?? null) ? $frame['class'] . '::' : '';

            return $file . ':' . $class . $function;
        }

        return '';
    }

    /**
     * "Class: message" for the exception and everything it wraps, outermost
     * first. A wrapper often says what the cause leaves out: Yii's database
     * exception carries the SQL, Twig's the template and line.
     *
     * @return string[]
     */
    public static function chain(Throwable $exception, int $length = 600): array
    {
        $lines = [];
        $seen = 0;

        do {
            $lines[] = Scrubber::limit($exception::class . ': ' . $exception->getMessage(), $length);
            $exception = $exception->getPrevious();
        } while ($exception !== null && $seen++ < 20);

        return $lines;
    }

    /**
     * A stack trace without arguments: an argument can be a password.
     */
    public static function trace(Throwable $exception, string $basePath, int $frames = 30): string
    {
        $root = self::root($exception);
        $lines = [sprintf('#0 %s:%d', self::relative($root->getFile(), $basePath), $root->getLine())];

        foreach (array_slice($root->getTrace(), 0, $frames) as $index => $frame) {
            $file = is_string($frame['file'] ?? null) ? self::relative($frame['file'], $basePath) : '[internal]';
            $line = is_int($frame['line'] ?? null) ? ':' . $frame['line'] : '';
            $class = is_string($frame['class'] ?? null) ? $frame['class'] . '::' : '';
            $function = is_string($frame['function'] ?? null) ? $frame['function'] : '';

            $lines[] = sprintf('#%d %s%s %s%s()', $index + 1, $file, $line, $class, $function);
        }

        return implode("\n", $lines);
    }
}
