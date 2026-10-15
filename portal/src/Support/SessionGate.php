<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * PHP holds an exclusive session lock for the whole request.
 * Catalog and material lookups must not keep that lock, or cart and currency
 * clicks queue behind them.
 */
final class SessionGate
{
    public static function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * Run $work without the session lock, then resume the same session.
     * $_SESSION stays readable inside $work. Writes inside $work are discarded
     * when the session resumes.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function withoutLock(callable $work): mixed
    {
        $wasActive = session_status() === PHP_SESSION_ACTIVE;
        if ($wasActive) {
            session_write_close();
        }

        try {
            return $work();
        } finally {
            if ($wasActive && session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
        }
    }
}
