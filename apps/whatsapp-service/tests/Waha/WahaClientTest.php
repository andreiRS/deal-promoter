<?php

declare(strict_types=1);

namespace App\Tests\Waha;

use App\Waha\WahaClient;
use App\Waha\WahaException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit tests for the single class that talks to WAHA, driven by a
 * {@see MockHttpClient} so the WAHA HTTP contract is exercised without a live
 * upstream.
 */
final class WahaClientTest extends TestCase
{
    public function testGetSessionStatusReturnsStoppedOn404(): void
    {
        $client = $this->wahaClient(new MockResponse('', ['http_code' => 404]));

        self::assertSame('STOPPED', $client->getSessionStatus());
    }

    public function testGetSessionStatusReturnsUpstreamStatusField(): void
    {
        $client = $this->wahaClient(
            new MockResponse(
                json_encode(['status' => 'SCAN_QR_CODE'], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['Content-Type' => 'application/json']],
            ),
        );

        self::assertSame('SCAN_QR_CODE', $client->getSessionStatus());
    }

    public function testGetSessionStatusReturnsWorking(): void
    {
        $client = $this->wahaClient(
            new MockResponse(
                json_encode(['status' => 'WORKING'], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['Content-Type' => 'application/json']],
            ),
        );

        self::assertSame('WORKING', $client->getSessionStatus());
    }

    public function testGetSessionStatusReturnsUnknownOnServerError(): void
    {
        $client = $this->wahaClient(new MockResponse('boom', ['http_code' => 500]));

        self::assertSame('UNKNOWN', $client->getSessionStatus());
    }

    public function testGetSessionStatusSendsApiKeyHeaderToPluralSessionsPath(): void
    {
        $response = new MockResponse(
            json_encode(['status' => 'WORKING'], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['Content-Type' => 'application/json']],
        );
        $http = new MockHttpClient($response);
        $client = new WahaClient($http, 'http://waha:3000', 'secret-key', 'default');

        $client->getSessionStatus();

        self::assertSame('http://waha:3000/api/sessions/default', $response->getRequestUrl());
        self::assertContains('X-Api-Key: secret-key', $response->getRequestOptions()['headers']);
    }

    public function testStartSessionPostsToStartPath(): void
    {
        $response = new MockResponse('', ['http_code' => 201]);
        $http = new MockHttpClient($response);
        $client = new WahaClient($http, 'http://waha:3000', 'secret-key', 'default');

        $client->startSession();

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('http://waha:3000/api/sessions/default/start', $response->getRequestUrl());
    }

    public function testStartSessionTreats422AsAlreadyStarted(): void
    {
        $client = $this->wahaClient(new MockResponse('already started', ['http_code' => 422]));

        $client->startSession();

        $this->expectNotToPerformAssertions();
    }

    public function testStartSessionThrowsOnOtherErrors(): void
    {
        $client = $this->wahaClient(new MockResponse('nope', ['http_code' => 500]));

        $this->expectException(WahaException::class);

        $client->startSession();
    }

    public function testLogoutSessionPostsToLogoutPath(): void
    {
        $response = new MockResponse('', ['http_code' => 200]);
        $http = new MockHttpClient($response);
        $client = new WahaClient($http, 'http://waha:3000', 'secret-key', 'default');

        $client->logoutSession();

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('http://waha:3000/api/sessions/default/logout', $response->getRequestUrl());
    }

    public function testGetQrImageReturnsBytesAndContentTypeFromSingularPath(): void
    {
        $response = new MockResponse(
            'PNGBYTES',
            ['response_headers' => ['Content-Type' => 'image/png']],
        );
        $http = new MockHttpClient($response);
        $client = new WahaClient($http, 'http://waha:3000', 'secret-key', 'default');

        $qr = $client->getQrImage();

        self::assertTrue($qr->ok);
        self::assertSame('PNGBYTES', $qr->body);
        self::assertSame('image/png', $qr->contentType);
        self::assertSame(
            'http://waha:3000/api/default/auth/qr?format=image',
            $response->getRequestUrl(),
        );
    }

    public function testGetQrImageSignalsUnavailabilityWithUpstreamStatus(): void
    {
        $client = $this->wahaClient(new MockResponse('no qr', ['http_code' => 404]));

        $qr = $client->getQrImage();

        self::assertFalse($qr->ok);
        self::assertSame(404, $qr->status);
    }

    private function wahaClient(MockResponse $response): WahaClient
    {
        return new WahaClient(
            new MockHttpClient($response),
            'http://waha:3000',
            'secret-key',
            'default',
        );
    }
}
