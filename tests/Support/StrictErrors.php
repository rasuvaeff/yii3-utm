<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests\Support;

/**
 * Runs a callback with every PHP diagnostic escalated to an exception.
 *
 * Several guards in this package look equivalent to their removal as long as
 * PHP silently falls back to `null`: reading a property on `null` and reading
 * an undefined variable both yield `null` plus an `E_WARNING`, and Testo does
 * not fail a test on a warning. Escalating the warning is what makes the
 * difference observable — without it those code paths cannot be asserted at
 * all, only assumed.
 *
 * The handler is restored in `finally`, so a throwing callback cannot leak it
 * into the rest of the suite.
 *
 * @internal
 */
final readonly class StrictErrors
{
    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        \set_error_handler(
            static function (int $severity, string $message, string $file, int $line): never {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            },
        );

        try {
            return $callback();
        } finally {
            \restore_error_handler();
        }
    }
}
