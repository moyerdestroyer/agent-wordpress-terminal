<?php

/**
 * Tiny dependency-free assertion helper for AWPT's bootstrap-free test harness.
 *
 * @package AWPT
 */

declare(strict_types=1);

final class Assert
{
    private static int $passed = 0;

    /**
     * @var list<string>
     */
    private static array $failures = [];

    public static function true(bool $condition, string $message): void
    {
        self::report($condition, $message);
    }

    public static function false(bool $condition, string $message): void
    {
        self::report(!$condition, $message);
    }

    public static function same(mixed $expected, mixed $actual, string $message): void
    {
        $condition = $expected === $actual;

        if (!$condition) {
            $message .= sprintf(
                ' (expected %s, got %s)',
                var_export($expected, true),
                var_export($actual, true),
            );
        }

        self::report($condition, $message);
    }

    public static function instanceOf(string $class, mixed $actual, string $message): void
    {
        $condition = $actual instanceof $class;

        if (!$condition) {
            $message .= sprintf(' (expected instance of %s, got %s)', $class, get_debug_type($actual));
        }

        self::report($condition, $message);
    }

    private static function report(bool $condition, string $message): void
    {
        if ($condition) {
            ++self::$passed;

            return;
        }

        self::$failures[] = $message;
    }

    public static function passed(): int
    {
        return self::$passed;
    }

    /**
     * @return list<string>
     */
    public static function failures(): array
    {
        return self::$failures;
    }
}
