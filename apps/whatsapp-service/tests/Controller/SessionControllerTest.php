<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Waha\WahaClient;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Functional tests for the open pairing routes, driving WAHA through a
 * {@see MockHttpClient} swapped into the container so the real upstream is never
 * contacted.
 */
final class SessionControllerTest extends WebTestCase
{
    public function testSessionReturnsStatusJson(): void
    {
        $client = self::createClient();
        $this->mockWaha(new MockResponse(
            json_encode(['status' => 'SCAN_QR_CODE'], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['Content-Type' => 'application/json']],
        ));

        $client->request('GET', '/session');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            json_encode(['status' => 'SCAN_QR_CODE'], \JSON_THROW_ON_ERROR),
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testSessionReturns502OnTransportFailure(): void
    {
        $client = self::createClient();
        $this->mockWaha(new MockResponse('', ['error' => 'Connection refused']));

        $client->request('GET', '/session');

        self::assertResponseStatusCodeSame(502);
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('UNKNOWN', $data['status']);
        self::assertArrayHasKey('error', $data);
    }

    public function testStartReturnsOkJson(): void
    {
        $client = self::createClient();
        $this->mockWaha(new MockResponse('', ['http_code' => 201]));

        $client->request('POST', '/session/start');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            json_encode(['ok' => true], \JSON_THROW_ON_ERROR),
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testStartTolerates422FromWaha(): void
    {
        $client = self::createClient();
        $this->mockWaha(new MockResponse('already started', ['http_code' => 422]));

        $client->request('POST', '/session/start');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            json_encode(['ok' => true], \JSON_THROW_ON_ERROR),
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testStartReturns502OnWahaError(): void
    {
        $client = self::createClient();
        $this->mockWaha(new MockResponse('nope', ['http_code' => 500]));

        $client->request('POST', '/session/start');

        self::assertResponseStatusCodeSame(502);
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($data['ok']);
        self::assertArrayHasKey('error', $data);
    }

    public function testLogoutReturnsOkJson(): void
    {
        $client = self::createClient();
        $this->mockWaha(new MockResponse('', ['http_code' => 200]));

        $client->request('POST', '/session/logout');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            json_encode(['ok' => true], \JSON_THROW_ON_ERROR),
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testQrStreamsImageWithNoStore(): void
    {
        $client = self::createClient();
        $this->mockWaha(new MockResponse(
            'PNGBYTES',
            ['response_headers' => ['Content-Type' => 'image/png']],
        ));

        $client->request('GET', '/session/qr');
        $response = $client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('PNGBYTES', $response->getContent());
    }

    public function testQrReturnsUpstreamStatusWhenUnavailable(): void
    {
        $client = self::createClient();
        $this->mockWaha(new MockResponse('no qr', ['http_code' => 404]));

        $client->request('GET', '/session/qr');

        self::assertResponseStatusCodeSame(404);
    }

    private function mockWaha(MockResponse ...$responses): void
    {
        self::getContainer()->set(
            WahaClient::class,
            new WahaClient(new MockHttpClient($responses), 'http://waha:3000', 'secret', 'default'),
        );
    }
}
