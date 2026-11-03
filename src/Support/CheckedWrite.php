<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

use LogicException;

/**
 * Eloquent's `save()`/`delete()` return false — without throwing — when a
 * `saving`/`deleting` listener vetoes the write, and `create()`/`update()`
 * swallow that return. A writer whose contract is «this write commits or the
 * whole step rolls back» must check the bool; this is the single place that
 * turns a vetoed write into an exception (AID-1825 implementation gate,
 * rounds 1-2).
 *
 * @internal
 */
final class CheckedWrite
{
    /**
     * @param  bool|null  $saved  the return of save()/update()/delete()
     * @param  string  $what  what was being written, for the message
     *
     * @throws LogicException when the write was vetoed
     */
    public static function orFail(?bool $saved, string $what): void
    {
        if ($saved !== true) {
            throw new LogicException("The write of {$what} was vetoed by a model listener; the step is rolled back (nothing partial is kept).");
        }
    }
}
