<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Tests\Keepa;

use DealPromoter\Shared\Keepa\Candidate;
use DealPromoter\Shared\Keepa\KeepaClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KeepaClientTest extends TestCase
{
    public function testFetchesAndParsesAFullDealPage(): void
    {
        $body = file_get_contents(__DIR__.'/../fixtures/keepa/deal-page.json');
        self::assertIsString($body);

        $client = new KeepaClient(new MockHttpClient(new MockResponse($body)), 'test-key', 3);
        $page = $client->fetchDealPage();

        self::assertCount(150, $page->candidates);
        self::assertContainsOnlyInstancesOf(Candidate::class, $page->candidates);
        self::assertNotSame('', $page->candidates[0]->asin);
    }

    public function testReadsTheTokenMeterOffTheResponseBody(): void
    {
        $body = file_get_contents(__DIR__.'/../fixtures/keepa/deal-page.json');
        self::assertIsString($body);

        $client = new KeepaClient(new MockHttpClient(new MockResponse($body)), 'test-key', 3);
        $page = $client->fetchDealPage();

        self::assertSame(1195, $page->meter->tokensLeft);
        self::assertSame(5, $page->meter->tokensConsumed);
        self::assertSame(20, $page->meter->refillRate);
    }

    public function testBuildsADealRequestForTheConfiguredKeyAndDomain(): void
    {
        $captured = null;
        $mock = new MockHttpClient(static function (string $method, string $url) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url];

            return new MockResponse('{"deals":{"dr":[]},"tokensLeft":1,"tokensConsumed":5,"refillRate":20,"refillIn":0}');
        });

        (new KeepaClient($mock, 'secret-key', 3))->fetchDealPage();

        self::assertIsArray($captured);
        self::assertSame('GET', $captured['method']);
        self::assertStringStartsWith('https://api.keepa.com/deal', $captured['url']);
        self::assertStringContainsString('key=secret-key', $captured['url']);
        // selection is a URL-encoded JSON blob carrying the domain id.
        self::assertStringContainsString('domainId%22:3', $captured['url']);
        // Discovery is biased to Amazon-sold offers (attestation precondition),
        // which requires the filter block to be enabled.
        self::assertStringContainsString('mustHaveAmazonOffer%22:true', $captured['url']);
        self::assertStringContainsString('isFilterEnabled%22:true', $captured['url']);
    }
}
