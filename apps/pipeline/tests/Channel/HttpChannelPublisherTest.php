<?php

declare(strict_types=1);

namespace App\Tests\Channel;

use App\Channel\Exception\PublishFailed;
use App\Channel\HttpChannelPublisher;
use App\Entity\PostedDeal;
use DealPromoter\Shared\Amazon\OgImageResolver;
use DealPromoter\Shared\Channel\PublishableDeal;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit tests for HttpChannelPublisher (slice 5).
 *
 * No DB: the EntityManager is mocked and the HTTP client is a MockHttpClient.
 *
 * Verifies:
 *  1. 2xx → exactly one persist of a PostedDeal (asin + priceCents) then flush.
 *  2. Non-2xx (502) → throws PublishFailed; nothing persisted.
 *  3. Transport error → throws PublishFailed; nothing persisted.
 *  4. Null affiliateUrl → throws PublishFailed BEFORE any HTTP call; nothing persisted.
 *  5. Null snapshotPriceCents → throws PublishFailed BEFORE any HTTP call; nothing persisted.
 *  6. Message body is the exact German-formatted string sent as `text`, with
 *     `chatId` set to the configured channel and the X-Internal-Key header present.
 *  7. preview.image is the URL returned by the og:image resolver, which is called
 *     with the deal's ASIN and its Keepa image URL (or '' when that is null).
 */
final class HttpChannelPublisherTest extends TestCase
{
    private const SERVICE_URL = 'http://whatsapp-service:8000';
    private const CHANNEL_ID = '120363426158608543@newsletter';
    private const INTERNAL_KEY = 'test-internal-key';

    public function testSuccessPersistsPostedDealThenFlushes(): void
    {
        $http = new MockHttpClient(new MockResponse('{"ok":true}', ['http_code' => 200]));

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            });
        $em->expects(self::once())->method('flush');

        $publisher = $this->publisher($http, $em);
        $publisher->publish($this->fakeDeal('B000OK0001', 1299, 'https://www.amazon.de/dp/B000OK0001?tag=t-21'));

        self::assertInstanceOf(PostedDeal::class, $persisted);
        self::assertSame('B000OK0001', $persisted->getAsin());
        self::assertSame(1299, $persisted->getPriceCents());
    }

    public function testNon2xxThrowsAndPersistsNothing(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":"bad gateway"}', ['http_code' => 502]));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $publisher = $this->publisher($http, $em);

        $this->expectException(PublishFailed::class);
        $publisher->publish($this->fakeDeal('B000ERR502', 1299, 'https://www.amazon.de/dp/B000ERR502?tag=t-21'));
    }

    public function testTransportErrorThrowsAndPersistsNothing(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', ['error' => 'connection refused']);
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $publisher = $this->publisher($http, $em);

        $this->expectException(PublishFailed::class);
        $publisher->publish($this->fakeDeal('B000TRANS1', 1299, 'https://www.amazon.de/dp/B000TRANS1?tag=t-21'));
    }

    public function testNullAffiliateUrlThrowsBeforeAnyHttpCall(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('No HTTP call must be made when the affiliate URL is missing.');
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $publisher = $this->publisher($http, $em);

        $this->expectException(PublishFailed::class);
        $publisher->publish($this->fakeDeal('B000NOURL1', 1299, null));
    }

    public function testNullPriceThrowsBeforeAnyHttpCall(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('No HTTP call must be made when the price is missing.');
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $publisher = $this->publisher($http, $em);

        $this->expectException(PublishFailed::class);
        $publisher->publish($this->fakeDeal('B000NOPRC1', null, 'https://www.amazon.de/dp/B000NOPRC1?tag=t-21'));
    }

    public function testRequestShapeAndGermanMessageFormat(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured['method'] = $method;
            $captured['url'] = $url;
            $captured['headers'] = $options['headers'] ?? [];
            $captured['body'] = $options['body'] ?? null;

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $publisher = $this->publisher($http, $em);
        $publisher->publish($this->fakeDeal(
            'B000FMT001',
            1299,
            'https://www.amazon.de/dp/B000FMT001?tag=t-21',
            'Cool Gadget',
            'https://images.amazon.com/cool-gadget.jpg',
        ));

        self::assertSame('POST', $captured['method']);
        self::assertStringEndsWith('/send', $captured['url']);

        $headerLine = implode("\n", $this->normalizeHeaders($captured['headers']));
        self::assertStringContainsStringIgnoringCase('X-Internal-Key: '.self::INTERNAL_KEY, $headerLine);

        /** @var array{chatId: string, text: string, preview: array{url: string, title: string, image: string}} $decoded */
        $decoded = json_decode((string) $captured['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(self::CHANNEL_ID, $decoded['chatId']);
        $this->assertMessageShape('12,99 €', 'https://www.amazon.de/dp/B000FMT001?tag=t-21', $decoded['text']);

        self::assertArrayHasKey('preview', $decoded);
        self::assertSame('https://www.amazon.de/dp/B000FMT001?tag=t-21', $decoded['preview']['url']);
        self::assertSame('Cool Gadget', $decoded['preview']['title']);
        self::assertSame('https://images.amazon.com/cool-gadget.jpg', $decoded['preview']['image']);
    }

    public function testRequestOptsIntoHighResPreview(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured['body'] = $options['body'] ?? null;

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $publisher = $this->publisher($http, $em);
        $publisher->publish($this->fakeDeal('B000HR0001', 1299, 'https://www.amazon.de/dp/B000HR0001?tag=t-21'));

        /** @var array{preview: array{highRes: bool}} $decoded */
        $decoded = json_decode((string) $captured['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($decoded['preview']['highRes']);
    }

    public function testGermanThousandsSeparatorInMessage(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured['body'] = $options['body'] ?? null;

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $publisher = $this->publisher($http, $em);
        $publisher->publish($this->fakeDeal('B000THOU01', 129900, 'https://www.amazon.de/dp/B000THOU01?tag=t-21', 'Big Item'));

        /** @var array{text: string} $decoded */
        $decoded = json_decode((string) $captured['body'], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertMessageShape('1.299,00 €', 'https://www.amazon.de/dp/B000THOU01?tag=t-21', $decoded['text']);
    }

    public function testPreviewImageIsTheResolvedOgImage(): void
    {
        // AC1: preview.image carries whatever the og:image resolver returns.
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured['body'] = $options['body'] ?? null;

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $resolver = new RecordingOgImageResolver('https://m.media-amazon.com/images/og/B000OG0001-composited.png');
        $em = $this->createMock(EntityManagerInterface::class);
        $publisher = $this->publisher($http, $em, $resolver);
        $publisher->publish($this->fakeDeal(
            'B000OG0001',
            1299,
            'https://www.amazon.de/dp/B000OG0001?tag=t-21',
            'Cool Gadget',
            'https://images.amazon.com/keepa-photo.jpg',
        ));

        /** @var array{preview: array{image: string}} $decoded */
        $decoded = json_decode((string) $captured['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('https://m.media-amazon.com/images/og/B000OG0001-composited.png', $decoded['preview']['image']);
    }

    public function testResolverReceivesAsinAndKeepaImageAsFallback(): void
    {
        // AC2: the resolver is called with the deal's ASIN and its Keepa image URL.
        $http = new MockHttpClient(new MockResponse('{"ok":true}', ['http_code' => 200]));
        $resolver = new RecordingOgImageResolver('https://m.media-amazon.com/images/og/composited.png');
        $em = $this->createMock(EntityManagerInterface::class);

        $this->publisher($http, $em, $resolver)->publish($this->fakeDeal(
            'B000ASIN01',
            1299,
            'https://www.amazon.de/dp/B000ASIN01?tag=t-21',
            'Cool Gadget',
            'https://images.amazon.com/keepa-photo.jpg',
        ));

        self::assertSame('B000ASIN01', $resolver->asin);
        self::assertSame('https://images.amazon.com/keepa-photo.jpg', $resolver->fallbackUrl);
    }

    public function testResolverFallbackIsEmptyStringWhenImageUrlIsNull(): void
    {
        // AC3: a null Keepa image becomes '' as the resolver's fallback argument.
        $http = new MockHttpClient(new MockResponse('{"ok":true}', ['http_code' => 200]));
        $resolver = new RecordingOgImageResolver('https://m.media-amazon.com/images/og/composited.png');
        $em = $this->createMock(EntityManagerInterface::class);

        $this->publisher($http, $em, $resolver)->publish($this->fakeDeal(
            'B000NOIMG1',
            1299,
            'https://www.amazon.de/dp/B000NOIMG1?tag=t-21',
            'Cool Gadget',
            null,
        ));

        self::assertSame('', $resolver->fallbackUrl);
    }

    /**
     * Assert the message is exactly "{price} € {emoji}\n{url}" where the emoji is
     * one of the sale set. Title is no longer included.
     */
    private function assertMessageShape(string $price, string $url, string $text): void
    {
        $parts = explode("\n", $text, 2);
        self::assertCount(2, $parts, 'Message must be a price line and a URL line.');
        [$priceLine, $urlLine] = $parts;

        self::assertSame($url, $urlLine);
        self::assertStringStartsWith($price.' ', $priceLine);

        $emoji = substr($priceLine, \strlen($price.' '));
        self::assertContains($emoji, HttpChannelPublisher::SALE_EMOJIS);
    }

    private function publisher(
        MockHttpClient $http,
        EntityManagerInterface $em,
        ?OgImageResolver $resolver = null,
    ): HttpChannelPublisher {
        return new HttpChannelPublisher(
            $http,
            $em,
            self::SERVICE_URL,
            self::CHANNEL_ID,
            self::INTERNAL_KEY,
            $resolver ?? new RecordingOgImageResolver(),
        );
    }

    /**
     * Normalize the MockHttpClient headers option (which may be a map or a list
     * of "Name: value" lines) into a flat list of "Name: value" strings.
     *
     * @param array<int|string, string> $headers
     *
     * @return list<string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = \is_int($name) ? $value : $name.': '.$value;
        }

        return $lines;
    }

    private function fakeDeal(
        string $asin,
        ?int $priceCents,
        ?string $affiliateUrl,
        string $title = 'Test Deal Title',
        ?string $imageUrl = 'https://images.amazon.com/sample.jpg',
    ): PublishableDeal {
        return new class($asin, $priceCents, $affiliateUrl, $title, $imageUrl) implements PublishableDeal {
            public function __construct(
                private readonly string $asin,
                private readonly ?int $priceCents,
                private readonly ?string $affiliateUrl,
                private readonly string $title,
                private readonly ?string $imageUrl,
            ) {
            }

            public function getAsin(): string
            {
                return $this->asin;
            }

            public function getTitle(): string
            {
                return $this->title;
            }

            public function getSnapshotPriceCents(): ?int
            {
                return $this->priceCents;
            }

            public function getAffiliateUrl(): ?string
            {
                return $this->affiliateUrl;
            }

            public function getImageUrl(): ?string
            {
                return $this->imageUrl;
            }
        };
    }
}

/**
 * Test double for the og:image seam: returns a fixed URL (or the fallback when
 * none is configured) and records the arguments it was called with, so tests can
 * assert both what the publisher sends and what it forwards to the resolver.
 */
final class RecordingOgImageResolver implements OgImageResolver
{
    public ?string $asin = null;

    public ?string $fallbackUrl = null;

    public function __construct(private readonly ?string $return = null)
    {
    }

    public function resolve(string $asin, string $fallbackUrl): string
    {
        $this->asin = $asin;
        $this->fallbackUrl = $fallbackUrl;

        return $this->return ?? $fallbackUrl;
    }
}
