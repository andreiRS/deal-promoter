<?php

declare(strict_types=1);

namespace App\Keepa;

use DealPromoter\Shared\Keepa\DealPage;
use DealPromoter\Shared\Keepa\KeepaDiscovery;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Caches Keepa `/deal` page responses so repeat Cycles don't re-spend tokens
 * (and re-walk the same already-filtered products) on pages they already
 * fetched. A transparent decorator over the real {@see KeepaDiscovery}.
 *
 * Within the TTL a page is served from cache (0 Keepa tokens), so a Cycle that
 * paginates spends tokens only on genuinely new, deeper pages until its
 * Amazon-verified target is met. After the TTL the early pages refetch, so fresh top deals still
 * surface — the TTL is the freshness-vs-token-cost dial.
 *
 * One marketplace runs today (amazon.de) with a fixed `/deal` selection, so the
 * cache key is the page number alone. Fold the marketplace/selection into the
 * key if more storefronts or selections are introduced.
 */
final readonly class CachingKeepaDiscovery implements KeepaDiscovery
{
    public function __construct(
        private KeepaDiscovery $inner,
        private CacheItemPoolInterface $cache,
        private int $ttlSeconds,
    ) {
    }

    public function fetchDealPage(int $page = 0): DealPage
    {
        $item = $this->cache->getItem(\sprintf('keepa_deal_page_%d', $page));

        $cached = $item->isHit() ? $item->get() : null;
        if ($cached instanceof DealPage) {
            return $cached;
        }

        $dealPage = $this->inner->fetchDealPage($page);

        $item->set($dealPage);
        $item->expiresAfter($this->ttlSeconds);
        $this->cache->save($item);

        return $dealPage;
    }
}
