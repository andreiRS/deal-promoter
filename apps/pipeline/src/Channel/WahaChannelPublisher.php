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
 * Real publisher: delivers a deal to the WhatsApp channel via the slice-4 gateway.
 *
 * Lives app-side (not packages/shared) because it depends on Doctrine + PostedDeal
 * (ADR 0003). The gateway is the only component holding WAHA credentials; this
 * publisher knows nothing of the WAHA X-Api-Key (ADR 0002) — it authenticates to
 * the gateway with X-Internal-Key alone.
 *
 * Contract with the gateway (POST /send): JSON {chatId, text}; 2xx {ok:true,...}
 * on success, 401/400/502 otherwise. On a 2xx response a posted_deal row is
 * written; on any failure nothing is persisted and a PublishFailed is thrown.
 */
final readonly class WahaChannelPublisher implements ChannelPublisher
{
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

        $message = $this->formatMessage($deal->getTitle(), $priceCents, $affiliateUrl);

        try {
            $response = $this->http->request('POST', rtrim($this->serviceUrl, '/').'/send', [
                'headers' => ['X-Internal-Key' => $this->internalKey],
                'json' => ['chatId' => $this->channelId, 'text' => $message],
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
     *   "{title}\n{price} €\n\n{affiliateUrl}".
     *
     * Price is euros with a comma decimal and dot thousands separator, 2 decimals
     * (German): 1299 → "12,99 €", 129900 → "1.299,00 €".
     */
    private function formatMessage(string $title, int $priceCents, string $affiliateUrl): string
    {
        $price = number_format($priceCents / 100, 2, ',', '.');

        return \sprintf("%s\n%s €\n\n%s", $title, $price, $affiliateUrl);
    }
}
