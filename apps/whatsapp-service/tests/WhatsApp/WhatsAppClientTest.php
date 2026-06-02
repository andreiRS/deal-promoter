<?php

declare(strict_types=1);

namespace App\Tests\WhatsApp;

use App\WhatsApp\WhatsAppClient;
use App\WhatsApp\WhatsAppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit tests for the single class that talks to the whatsmeow engine,
 * driven by a {@see MockHttpClient} so the HTTP contract is exercised
 * without a live upstream.
 */
final class WhatsAppClientTest extends TestCase
{
    public function testGetSessionStatusReturnsStoppedOn404(): void
    {
        $client = $this->whatsAppClient(new MockResponse('', ['http_code' => 404]));

        self::assertSame('STOPPED', $client->getSessionStatus());
    }

    public function testGetSessionStatusReturnsUpstreamStatusField(): void
    {
        $client = $this->whatsAppClient(
            new MockResponse(
                json_encode(['status' => 'SCAN_QR_CODE'], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['Content-Type' => 'application/json']],
            ),
        );

        self::assertSame('SCAN_QR_CODE', $client->getSessionStatus());
    }

    public function testGetSessionStatusReturnsWorking(): void
    {
        $client = $this->whatsAppClient(
            new MockResponse(
                json_encode(['status' => 'WORKING'], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['Content-Type' => 'application/json']],
            ),
        );

        self::assertSame('WORKING', $client->getSessionStatus());
    }

    public function testGetSessionStatusReturnsUnknownOnServerError(): void
    {
        $client = $this->whatsAppClient(new MockResponse('boom', ['http_code' => 500]));

        self::assertSame('UNKNOWN', $client->getSessionStatus());
    }

    public function testGetSessionStatusCallsEngineSessionPathWithNoApiKey(): void
    {
        $response = new MockResponse(
            json_encode(['status' => 'WORKING'], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['Content-Type' => 'application/json']],
        );
        $http = new MockHttpClient($response);
        $client = new WhatsAppClient($http, 'http://engine:8080');

        $client->getSessionStatus();

        self::assertSame('http://engine:8080/session', $response->getRequestUrl());
        // Engine is keyless — no X-Api-Key header must be sent
        $headers = $response->getRequestOptions()['headers'] ?? [];
        self::assertEmpty(array_filter($headers, static fn (string $h): bool => str_starts_with($h, 'X-Api-Key')));
    }

    public function testStartSessionPostsToEngineStartPath(): void
    {
        $response = new MockResponse('', ['http_code' => 200]);
        $http = new MockHttpClient($response);
        $client = new WhatsAppClient($http, 'http://engine:8080');

        $client->startSession();

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('http://engine:8080/session/start', $response->getRequestUrl());
    }

    public function testStartSessionSucceedsOn2xx(): void
    {
        $client = $this->whatsAppClient(new MockResponse('', ['http_code' => 201]));

        $client->startSession();

        $this->expectNotToPerformAssertions();
    }

    public function testStartSessionThrowsOnNon2xx(): void
    {
        $client = $this->whatsAppClient(new MockResponse('nope', ['http_code' => 500]));

        $this->expectException(WhatsAppException::class);

        $client->startSession();
    }

    public function testLogoutSessionPostsToEngineLogoutPath(): void
    {
        $response = new MockResponse('', ['http_code' => 200]);
        $http = new MockHttpClient($response);
        $client = new WhatsAppClient($http, 'http://engine:8080');

        $client->logoutSession();

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('http://engine:8080/session/logout', $response->getRequestUrl());
    }

    public function testGetQrImageReturnsBytesAndContentTypeFrom2xx(): void
    {
        $response = new MockResponse(
            'PNGBYTES',
            ['response_headers' => ['Content-Type' => 'image/png']],
        );
        $http = new MockHttpClient($response);
        $client = new WhatsAppClient($http, 'http://engine:8080');

        $qr = $client->getQrImage();

        self::assertTrue($qr->ok);
        self::assertSame('PNGBYTES', $qr->body);
        self::assertSame('image/png', $qr->contentType);
        self::assertSame('http://engine:8080/qr', $response->getRequestUrl());
    }

    public function testGetQrImageSignalsUnavailabilityOnNon2xx(): void
    {
        $client = $this->whatsAppClient(new MockResponse('no qr', ['http_code' => 404]));

        $qr = $client->getQrImage();

        self::assertFalse($qr->ok);
        self::assertSame(404, $qr->status);
    }

    // -------------------------------------------------------------------------
    // listOwnedChannels
    // -------------------------------------------------------------------------

    public function testListOwnedChannelsFiltersToNewsletterOwnerAndAdmin(): void
    {
        $payload = json_encode([
            ['id' => 'abc@newsletter', 'name' => 'My Channel', 'role' => 'OWNER'],
            ['id' => 'def@newsletter', 'name' => 'Admin Chan', 'role' => 'ADMIN'],
            ['id' => 'ghi@newsletter', 'name' => 'Sub Chan',   'role' => 'SUBSCRIBER'],
            ['id' => 'jkl@g.us',       'name' => 'A Group',    'role' => 'OWNER'],
        ], \JSON_THROW_ON_ERROR);

        $client = $this->whatsAppClient(
            new MockResponse($payload, ['response_headers' => ['Content-Type' => 'application/json']]),
        );

        $channels = $client->listOwnedChannels();

        self::assertCount(2, $channels);
        self::assertSame('abc@newsletter', $channels[0]['id']);
        self::assertSame('OWNER', $channels[0]['role']);
        self::assertSame('def@newsletter', $channels[1]['id']);
        self::assertSame('ADMIN', $channels[1]['role']);
    }

    public function testListOwnedChannelsCallsEngineChannelsPath(): void
    {
        $response = new MockResponse(
            json_encode([], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['Content-Type' => 'application/json']],
        );
        $http = new MockHttpClient($response);
        $client = new WhatsAppClient($http, 'http://engine:8080');

        $client->listOwnedChannels();

        self::assertSame('http://engine:8080/channels', $response->getRequestUrl());
        // Engine is keyless — no X-Api-Key header
        self::assertNotContains('X-Api-Key: unused-key', $response->getRequestOptions()['headers'] ?? []);
    }

    public function testListOwnedChannelsThrowsOnNonOkResponse(): void
    {
        $client = $this->whatsAppClient(new MockResponse('forbidden', ['http_code' => 403]));

        $this->expectException(WhatsAppException::class);

        $client->listOwnedChannels();
    }

    // -------------------------------------------------------------------------
    // sendText
    // -------------------------------------------------------------------------

    public function testSendTextPostsToSendEndpointWithChatIdAndText(): void
    {
        $responsePayload = json_encode(['id' => 'msg-1'], \JSON_THROW_ON_ERROR);
        $response = new MockResponse(
            $responsePayload,
            ['response_headers' => ['Content-Type' => 'application/json']],
        );
        $http = new MockHttpClient($response);
        $client = new WhatsAppClient($http, 'http://engine:8080');

        $result = $client->sendText('abc@newsletter', 'Hello!');

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('http://engine:8080/send', $response->getRequestUrl());

        $body = json_decode($response->getRequestOptions()['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('abc@newsletter', $body['chatId']);
        self::assertSame('Hello!', $body['text']);
        // No session field in the new engine contract
        self::assertArrayNotHasKey('session', $body);

        self::assertTrue($result['ok']);
        self::assertSame(200, $result['status']);
    }

    public function testSendTextWithPreviewPostsPreviewBlock(): void
    {
        $responsePayload = json_encode(['id' => 'msg-2'], \JSON_THROW_ON_ERROR);
        $response = new MockResponse(
            $responsePayload,
            ['response_headers' => ['Content-Type' => 'application/json']],
        );
        $http = new MockHttpClient($response);
        $client = new WhatsAppClient($http, 'http://engine:8080');

        $preview = ['url' => 'https://example.com/deal', 'title' => 'Great Deal', 'image' => 'https://example.com/img.jpg'];
        $result = $client->sendText('abc@newsletter', 'Check this!', $preview);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('http://engine:8080/send', $response->getRequestUrl());

        $body = json_decode($response->getRequestOptions()['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('abc@newsletter', $body['chatId']);
        self::assertSame('Check this!', $body['text']);
        self::assertSame('https://example.com/deal', $body['preview']['url']);
        self::assertSame('Great Deal', $body['preview']['title']);
        self::assertSame('https://example.com/img.jpg', $body['preview']['image']);

        self::assertTrue($result['ok']);
    }

    public function testSendTextWithoutPreviewDoesNotSendPreviewKey(): void
    {
        $responsePayload = json_encode(['id' => 'msg-3'], \JSON_THROW_ON_ERROR);
        $response = new MockResponse(
            $responsePayload,
            ['response_headers' => ['Content-Type' => 'application/json']],
        );
        $http = new MockHttpClient($response);
        $client = new WhatsAppClient($http, 'http://engine:8080');

        $client->sendText('abc@newsletter', 'No preview');

        $body = json_decode($response->getRequestOptions()['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('preview', $body);
    }

    public function testSendTextReturnsNotOkOnUpstreamError(): void
    {
        $client = $this->whatsAppClient(new MockResponse('bad', ['http_code' => 500]));

        $result = $client->sendText('abc@newsletter', 'Hi');

        self::assertFalse($result['ok']);
        self::assertSame(500, $result['status']);
    }

    private function whatsAppClient(MockResponse $response): WhatsAppClient
    {
        return new WhatsAppClient(
            new MockHttpClient($response),
            'http://engine:8080',
        );
    }
}
