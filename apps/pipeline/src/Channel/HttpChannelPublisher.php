<?php

declare(strict_types=1);

namespace App\Channel;

use App\Channel\Exception\PublishFailed;
use App\Entity\PostedDeal;
use DealPromoter\Shared\Channel\ChannelPublisher;
use DealPromoter\Shared\Channel\PublishableDeal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Real publisher: delivers a deal to a configured gateway over HTTP (slice-4).
 *
 * Lives app-side (not packages/shared) because it depends on Doctrine + PostedDeal
 * (ADR 0003). It knows nothing about the downstream channel; the gateway is the
 * only component holding channel credentials (ADR 0002) — this publisher
 * authenticates to it with X-Internal-Key alone and could point at a different
 * gateway by changing $serviceUrl.
 *
 * Contract with the gateway (POST /send): JSON {chatId, text}; 2xx {ok:true,...}
 * on success, 401/400/502 otherwise. On a 2xx response a posted_deal row is
 * written; on any failure nothing is persisted and a PublishFailed is thrown.
 */
final readonly class HttpChannelPublisher implements ChannelPublisher
{
    /**
     * Sale emojis; one is picked at random per message to keep posts lively.
     *
     * @var list<string>
     */
    public const array SALE_EMOJIS = ['🔥', '💰', '🏷️', '⚡', '🎉'];

    public function __construct(
        private HttpClientInterface $http,
        private EntityManagerInterface $em,
        private string $serviceUrl,
        private string $channelId,
        private string $internalKey,
    ) {
    }

    public function publish(PublishableDeal $deal): void
    {
        // Single product gate: an affiliate link is the whole point of a post.
        $affiliateUrl = $deal->getAffiliateUrl();
        if (null === $affiliateUrl || '' === $affiliateUrl) {
            throw new PublishFailed(\sprintf('Cannot publish %s: no affiliate link.', $deal->getAsin()));
        }

        // Defensive: without a price the message can be neither formatted nor recorded.
        $priceCents = $deal->getSnapshotPriceCents();
        if (null === $priceCents) {
            throw new PublishFailed(\sprintf('Cannot publish %s: no snapshot price.', $deal->getAsin()));
        }

        $message = $this->formatMessage($priceCents, $affiliateUrl);

        try {
            $response = $this->http->request('POST', rtrim($this->serviceUrl, '/').'/send', [
                'headers' => ['X-Internal-Key' => $this->internalKey],
                'json' => [
                    'chatId' => $this->channelId,
                    'text' => $message,
                    'preview' => [
                        'url' => $affiliateUrl,
                        'title' => $deal->getTitle(),
                        'image' => (string) ($deal->getImageUrl() ?? ''),
                    ],
                ],
            ]);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new PublishFailed(\sprintf('Publish transport error for %s: %s', $deal->getAsin(), $e->getMessage()), 0, $e);
        }

        if ($status < 200 || $status >= 300) {
            throw new PublishFailed(\sprintf('Gateway rejected publish for %s (HTTP %d).', $deal->getAsin(), $status));
        }

        $this->em->persist(new PostedDeal($deal->getAsin(), $priceCents, new \DateTimeImmutable()));
        $this->em->flush();
    }

    /**
     * Build the German channel message body, exactly:
     *   "{price} € {emoji}\n{affiliateUrl}".
     *
     * Price is euros with a comma decimal and dot thousands separator, 2 decimals
     * (German): 1299 → "12,99 €", 129900 → "1.299,00 €". The emoji is a random
     * pick from {@see self::SALE_EMOJIS}.
     */
    private function formatMessage(int $priceCents, string $affiliateUrl): string
    {
        $price = number_format($priceCents / 100, 2, ',', '.');
        $emoji = self::SALE_EMOJIS[array_rand(self::SALE_EMOJIS)];

        return \sprintf("%s € %s\n%s", $price, $emoji, $affiliateUrl);
    }
}
