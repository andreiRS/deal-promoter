<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Tests\Amazon;

use DealPromoter\Shared\Amazon\AmazonOgImageResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AmazonOgImageResolverTest extends TestCase
{
    private const string COMPOSITED_IMAGE = 'https://m.media-amazon.com/images/I/61QKQ9mwV7L._AC_SX679_PIbundle-4,TopRight,0,0_SH20_.jpg';

    private const string FALLBACK = 'https://fallback.example/img.jpg';

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

    public function testFallsBackToFallbackUrlOnHttp404WithoutRetrying(): void
    {
        $mock = new MockHttpClient(new MockResponse('not found', ['http_code' => 404]));

        $resolver = new AmazonOgImageResolver($mock);

        self::assertSame(
            self::FALLBACK,
            $resolver->resolve('B0BXYZ1234', self::FALLBACK),
        );
        self::assertSame(1, $mock->getRequestsCount());
    }

    public function testFallsBackWhenA200PageHasNoOgImageWithoutRetrying(): void
    {
        $mock = new MockHttpClient(new MockResponse('<html><head><title>captcha</title></head></html>', ['http_code' => 200]));

        $resolver = new AmazonOgImageResolver($mock);

        self::assertSame(
            self::FALLBACK,
            $resolver->resolve('B0BXYZ1234', self::FALLBACK),
        );
        self::assertSame(1, $mock->getRequestsCount());
    }

    public function testFallsBackWhenBothTheRequestAndTheRetryHitATransportError(): void
    {
        $mock = new MockHttpClient([
            new MockResponse('', ['error' => 'connection refused']),
            new MockResponse('', ['error' => 'connection refused again']),
        ]);

        $resolver = new AmazonOgImageResolver($mock);

        self::assertSame(
            self::FALLBACK,
            $resolver->resolve('B0BXYZ1234', self::FALLBACK),
        );
        self::assertSame(2, $mock->getRequestsCount());
    }

    public function testRetriesOnHttp503AndReturnsTheOgImageFromTheSecondResponse(): void
    {
        $html = file_get_contents(__DIR__.'/../fixtures/amazon/product-head.html');
        self::assertIsString($html);

        $mock = new MockHttpClient([
            new MockResponse('service unavailable', ['http_code' => 503]),
            new MockResponse($html, ['http_code' => 200]),
        ]);

        $resolver = new AmazonOgImageResolver($mock);

        self::assertSame(
            self::COMPOSITED_IMAGE,
            $resolver->resolve('B0BXYZ1234', self::FALLBACK),
        );
        self::assertSame(2, $mock->getRequestsCount());
    }

    public function testRetriesOnATransportErrorAndReturnsTheOgImageFromTheSecondResponse(): void
    {
        $html = file_get_contents(__DIR__.'/../fixtures/amazon/product-head.html');
        self::assertIsString($html);

        $mock = new MockHttpClient([
            new MockResponse('', ['error' => 'connection refused']),
            new MockResponse($html, ['http_code' => 200]),
        ]);

        $resolver = new AmazonOgImageResolver($mock);

        self::assertSame(
            self::COMPOSITED_IMAGE,
            $resolver->resolve('B0BXYZ1234', self::FALLBACK),
        );
        self::assertSame(2, $mock->getRequestsCount());
    }

    public function testLogsAnInfoRecordWhenTheOgImageIsResolved(): void
    {
        $html = file_get_contents(__DIR__.'/../fixtures/amazon/product-head.html');
        self::assertIsString($html);

        $logger = self::recordingLogger();
        $resolver = new AmazonOgImageResolver(new MockHttpClient(new MockResponse($html)), $logger);

        $resolver->resolve('B0BXYZ1234', self::FALLBACK);

        $infos = array_values(array_filter($logger->records, static fn (array $r): bool => LogLevel::INFO === $r['level']));
        self::assertCount(1, $infos);
        self::assertSame('B0BXYZ1234', $infos[0]['context']['asin'] ?? null);
    }

    public function testLogsAWarningWithReasonAndAsinWhenFallingBack(): void
    {
        $logger = self::recordingLogger();
        $mock = new MockHttpClient(new MockResponse('not found', ['http_code' => 404]));
        $resolver = new AmazonOgImageResolver($mock, $logger);

        $resolver->resolve('B0BXYZ1234', self::FALLBACK);

        $warnings = array_values(array_filter($logger->records, static fn (array $r): bool => LogLevel::WARNING === $r['level']));
        self::assertCount(1, $warnings);
        self::assertSame('http_error', $warnings[0]['context']['reason'] ?? null);
        self::assertSame('B0BXYZ1234', $warnings[0]['context']['asin'] ?? null);
    }

    public function testSendsTheRequestWithAShortTimeout(): void
    {
        $html = file_get_contents(__DIR__.'/../fixtures/amazon/product-head.html');
        self::assertIsString($html);

        $captured = null;
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured, $html): MockResponse {
            $captured = $options['timeout'] ?? null;

            return new MockResponse($html);
        });

        (new AmazonOgImageResolver($mock))->resolve('B0BXYZ1234', self::FALLBACK);

        self::assertSame(4.0, $captured);
    }

    /**
     * A minimal in-memory PSR-3 logger that keeps every record for inspection.
     *
     * @return AbstractLogger&object{records: list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}>}
     */
    private static function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };
    }
}
