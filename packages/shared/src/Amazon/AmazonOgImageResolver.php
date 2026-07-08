<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Amazon;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves the WhatsApp-ready preview image for an Amazon product. Amazon
 * composites a framed image behind its `og:image` meta tag, but only serves it
 * to preview bots: a `WhatsApp/...` User-Agent gets HTTP 200 with the
 * composited image, while the older `facebookexternalhit` UA is now blocked.
 *
 * Never throws: it always returns a usable image URL. On the happy path it
 * fetches the canonical product page and returns the parsed og:image. On any
 * failure (non-2xx, no og:image, or a transport error) it returns the caller's
 * plain Keepa image instead. A transient failure (a transport error or HTTP
 * 503) is retried exactly once, with no delay; every other failure falls back
 * on the first try. Each outcome is logged with a machine-readable reason.
 */
final class AmazonOgImageResolver
{
    private const string PRODUCT_URL = 'https://www.amazon.de/dp/';

    // A plausible WhatsApp preview-bot UA. Amazon serves the composited og:image
    // to this UA but blocks facebookexternalhit.
    private const string USER_AGENT = 'WhatsApp/2.23.20.0';

    // One request plus, for a transient failure only, one immediate retry.
    private const int MAX_ATTEMPTS = 2;

    // Keep a hung Amazon response from stalling publish (seconds).
    private const float REQUEST_TIMEOUT = 4.0;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $http,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function resolve(string $asin, string $fallbackUrl): string
    {
        // A transient failure (transport error or HTTP 503) gets exactly one
        // immediate retry; every other failure falls back on the first try.
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            $isLastAttempt = self::MAX_ATTEMPTS === $attempt;

            try {
                $response = $this->http->request('GET', self::PRODUCT_URL.$asin, [
                    'headers' => ['User-Agent' => self::USER_AGENT],
                    'timeout' => self::REQUEST_TIMEOUT,
                ]);
                $status = $response->getStatusCode();
                $html = ($status >= 200 && $status < 300) ? $response->getContent() : '';
            } catch (TransportExceptionInterface) {
                if (!$isLastAttempt) {
                    continue;
                }

                return $this->fallback($asin, $fallbackUrl, 'transport_error');
            }

            if (503 === $status) {
                if (!$isLastAttempt) {
                    continue;
                }

                return $this->fallback($asin, $fallbackUrl, 'http_503');
            }

            if ($status < 200 || $status >= 300) {
                return $this->fallback($asin, $fallbackUrl, 'http_error', $status);
            }

            $ogImage = $this->extractOgImage($html);
            if ('' === $ogImage) {
                return $this->fallback($asin, $fallbackUrl, 'no_og_image');
            }

            $this->logger->info('Resolved Amazon og:image.', ['asin' => $asin, 'og_image' => $ogImage]);

            return $ogImage;
        }

        return $this->fallback($asin, $fallbackUrl, 'transport_error');
    }

    /**
     * Logs the fallback with a machine-readable reason and returns the caller's
     * plain Keepa image so the resolve never throws.
     */
    private function fallback(string $asin, string $fallbackUrl, string $reason, ?int $status = null): string
    {
        $context = ['asin' => $asin, 'reason' => $reason];
        if (null !== $status) {
            $context['status'] = $status;
        }

        $this->logger->warning('Falling back to the Keepa image for Amazon product.', $context);

        return $fallbackUrl;
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
