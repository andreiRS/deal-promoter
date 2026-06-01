<?php

declare(strict_types=1);

namespace App\Creators;

/**
 * A pausing seam. The Creators client must space its GetItems calls (Amazon
 * throttles bursts against a ~1 TPS floor quota) and back off on a 429, but
 * tests must do neither for real — so the wait goes through this interface.
 */
interface Sleeper
{
    public function sleep(int $micros): void;
}
