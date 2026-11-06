<?php

declare(strict_types=1);

namespace Vow;

use Closure;
use Throwable;

use function error_log;

/**
 * Lives only on rejected promises nobody handled, so the destructor cost never touches the fulfilled path.
 *
 * @internal
 */
final class UnhandledRejection
{
    /** @var (Closure(Throwable): void)|null */
    public static ?Closure $handler = null;

    public function __construct(public ?Throwable $reason)
    {
    }

    public function __destruct()
    {
        if ($this->reason !== null) {
            self::report($this->reason);
        }
    }

    public static function report(Throwable $reason): void
    {
        if (self::$handler !== null) {
            try {
                (self::$handler)($reason);
                return;
            } catch (Throwable $e) {
                // Keep handler exceptions from escaping a destructor.
                error_log('Promise rejection handler threw: ' . $e);
            }
        }
        error_log('Unhandled promise rejection: ' . $reason);
    }
}
