<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Amazon;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves the WhatsApp-ready preview image for an Amazon product. Amazon
 * composites a framed image behind its `og:image` meta tag, but only serves it
 * to preview bots: a `WhatsApp/...` User-Agent gets HTTP 200 with the
 * composited image, while the older `facebookexternalhit` UA is now blocked.
 *
 * This slice covers the happy path only: fetch the canonical product page and
 * return the parsed og:image URL. Failure, fallback and retry handling arrive
 * in a later slice.
 */
final class AmazonOgImageResolver
{
    private const string PRODUCT_URL = 'https://www.amazon.de/dp/';

    // A plausible WhatsApp preview-bot UA. Amazon serves the composited og:image
    // to this UA but blocks facebookexternalhit.
    private const string USER_AGENT = 'WhatsApp/2.23.20.0';

    public function __construct(
        private readonly HttpClientInterface $http,
    ) {
    }

    public function resolve(string $asin, string $fallbackUrl): string
    {
        $html = $this->http->request('GET', self::PRODUCT_URL.$asin, [
            'headers' => ['User-Agent' => self::USER_AGENT],
        ])->getContent();

        return $this->extractOgImage($html);
    }

    /**
     * Pulls the `content` of the `<meta property="og:image">` tag. Attribute
     * order varies, so each `<meta>` tag is isolated first, then `property` and
     * `content` are read from it independently.
     */
    private function extractOgImage(string $html): string
    {
        if (false === preg_match_all('/<meta\b[^>]*>/i', $html, $tags)) {
            return '';
        }

        foreach ($tags[0] as $tag) {
            $isOgImage = 1 === preg_match('/\bproperty\s*=\s*(["\'])og:image\1/i', $tag);
            if ($isOgImage && 1 === preg_match('/\bcontent\s*=\s*(["\'])(.*?)\1/is', $tag, $m)) {
                return $m[2];
            }
        }

        return '';
    }
}
