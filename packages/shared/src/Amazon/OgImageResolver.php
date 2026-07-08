<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Amazon;

/**
 * The seam onto Amazon preview-image resolution: turn an ASIN into a
 * WhatsApp-ready preview image URL.
 *
 * The publisher depends on this interface, never on the concrete
 * {@see AmazonOgImageResolver}, so it can be unit-tested with a fake that
 * returns a known URL. The HTTP-backed implementation is
 * {@see AmazonOgImageResolver}: it returns the composited og:image on success
 * and the caller's fallback URL on any failure, and never throws.
 */
interface OgImageResolver
{
    /**
     * Resolve the preview image for a product, falling back to $fallbackUrl on
     * any failure. Never throws.
     */
    public function resolve(string $asin, string $fallbackUrl): string;
}
