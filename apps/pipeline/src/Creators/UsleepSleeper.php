<?php

declare(strict_types=1);

namespace App\Creators;

/**
 * The production Sleeper: an actual {@see usleep}. Zero/negative waits are a
 * no-op (throttling can be disabled by configuring a 0 interval).
 */
final readonly class UsleepSleeper implements Sleeper
{
    public function sleep(int $micros): void
    {
        if ($micros > 0) {
            usleep($micros);
        }
    }
}
