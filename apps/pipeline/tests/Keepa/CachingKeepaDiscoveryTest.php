<?php

declare(strict_types=1);

namespace App\Tests\Keepa;

use App\Keepa\CachingKeepaDiscovery;
use DealPromoter\Shared\Keepa\Candidate;
use DealPromoter\Shared\Keepa\DealPage;
use DealPromoter\Shared\Keepa\KeepaDiscovery;
use DealPromoter\Shared\Keepa\TokenMeter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CachingKeepaDiscoveryTest extends TestCase
{
    public function testServesARepeatRequestForTheSamePageFromCache(): void
    {
        $inner = new CountingKeepaDiscovery([
            0 => new DealPage([$this->candidate('A')], new TokenMeter(60, 5, 5, 0)),
        ]);
        $caching = new CachingKeepaDiscovery($inner, new ArrayAdapter(), 3600);

        $first = $caching->fetchDealPage(0);
        $second = $caching->fetchDealPage(0);

        // The inner Keepa client is hit once; the repeat is served from cache.
        self::assertSame(1, $inner->calls);
        self::assertSame(['A'], $this->asins($first));
        self::assertSame(['A'], $this->asins($second));
    }

    public function testCachesEachPageIndependently(): void
    {
        $inner = new CountingKeepaDiscovery([
            0 => new DealPage([$this->candidate('P0')], new TokenMeter(60, 5, 5, 0)),
            1 => new DealPage([$this->candidate('P1')], new TokenMeter(55, 10, 5, 0)),
        ]);
        $caching = new CachingKeepaDiscovery($inner, new ArrayAdapter(), 3600);

        self::assertSame(['P0'], $this->asins($caching->fetchDealPage(0)));
        self::assertSame(['P1'], $this->asins($caching->fetchDealPage(1)));
        self::assertSame(['P0'], $this->asins($caching->fetchDealPage(0))); // cached

        // One fetch per distinct page, no matter how often each is re-requested.
        self::assertSame(2, $inner->calls);
    }

    public function testExpiredEntryRefetchesFromTheInnerClient(): void
    {
        $inner = new CountingKeepaDiscovery([
            0 => new DealPage([$this->candidate('A')], new TokenMeter(60, 5, 5, 0)),
        ]);
        // TTL 0 = already expired, so every call misses and re-fetches.
        $caching = new CachingKeepaDiscovery($inner, new ArrayAdapter(), 0);

        $caching->fetchDealPage(0);
        $caching->fetchDealPage(0);

        self::assertSame(2, $inner->calls);
    }

    /**
     * @return list<string>
     */
    private function asins(DealPage $page): array
    {
        return array_map(static fn (Candidate $c): string => $c->asin, $page->candidates);
    }

    private function candidate(string $asin): Candidate
    {
        return new Candidate($asin, 'title', '', 1, 1, 1, 1, 1, 1, 1, 1, [1]);
    }
}

/**
 * A KeepaDiscovery that counts how often the live client is actually invoked, so
 * the test can prove cache hits skip it.
 */
final class CountingKeepaDiscovery implements KeepaDiscovery
{
    public int $calls = 0;

    /**
     * @param array<int, DealPage> $pages
     */
    public function __construct(private readonly array $pages)
    {
    }

    public function fetchDealPage(int $page = 0): DealPage
    {
        ++$this->calls;

        return $this->pages[$page] ?? new DealPage([], new TokenMeter(0, 0, 0, 0));
    }
}
