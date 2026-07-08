<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Tests\Amazon;

use DealPromoter\Shared\Amazon\AmazonOgImageResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AmazonOgImageResolverTest extends TestCase
{
    private const string COMPOSITED_IMAGE = 'https://m.media-amazon.com/images/I/61QKQ9mwV7L._AC_SX679_PIbundle-4,TopRight,0,0_SH20_.jpg';

    public function testResolvesTheOgImageFromAmazonsProductPage(): void
    {
        $html = file_get_contents(__DIR__.'/../fixtures/amazon/product-head.html');
        self::assertIsString($html);

        $resolver = new AmazonOgImageResolver(new MockHttpClient(new MockResponse($html)));

        self::assertSame(
            self::COMPOSITED_IMAGE,
            $resolver->resolve('B0BXYZ1234', 'https://fallback.example/img.jpg'),
        );
    }

    public function testFetchesTheCanonicalProductUrlWithAWhatsAppUserAgent(): void
    {
        $html = file_get_contents(__DIR__.'/../fixtures/amazon/product-head.html');
        self::assertIsString($html);

        $captured = null;
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured, $html): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse($html);
        });

        (new AmazonOgImageResolver($mock))->resolve('B0BXYZ1234', 'https://fallback.example/img.jpg');

        self::assertIsArray($captured);
        self::assertSame('GET', $captured['method']);
        self::assertSame('https://www.amazon.de/dp/B0BXYZ1234', $captured['url']);
        self::assertContains('User-Agent: WhatsApp/2.23.20.0', $captured['headers']);
    }

    public function testExtractsOgImageWhenContentAttributePrecedesProperty(): void
    {
        $html = file_get_contents(__DIR__.'/../fixtures/amazon/product-head-content-first.html');
        self::assertIsString($html);

        $resolver = new AmazonOgImageResolver(new MockHttpClient(new MockResponse($html)));

        self::assertSame(
            self::COMPOSITED_IMAGE,
            $resolver->resolve('B0BXYZ1234', 'https://fallback.example/img.jpg'),
        );
    }
}
