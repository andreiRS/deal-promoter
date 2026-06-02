<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\WhatsApp\WhatsAppClient;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Functional tests for the open, host-bound channel + send routes (ADR 0002).
 *
 * GET /channels  — lists owned channels via WhatsAppClient::listOwnedChannels.
 * POST /ui/send  — open human send path; guards chatId and text BEFORE any engine
 *                  call; routes through WhatsAppClient::sendText on success.
 * POST /send     — machine-facing gated send; requires X-Internal-Key header, then
 *                  shares the same guard + delivery path as /ui/send.
 */
final class ChannelControllerTest extends WebTestCase
{
    // -------------------------------------------------------------------------
    // GET /channels
    // -------------------------------------------------------------------------

    public function testChannelsReturnsFilteredChannelList(): void
    {
        $client = self::createClient();
        $payload = json_encode([
            ['id' => 'abc@newsletter', 'name' => 'My Channel', 'role' => 'OWNER'],
            ['id' => 'def@newsletter', 'name' => 'Admin Chan', 'role' => 'ADMIN'],
            ['id' => 'ghi@newsletter', 'name' => 'Sub Chan',   'role' => 'SUBSCRIBER'],
        ], \JSON_THROW_ON_ERROR);

        $this->mockEngine(
            new MockResponse($payload, ['response_headers' => ['Content-Type' => 'application/json']]),
        );

        $client->request('GET', '/channels');

        self::assertResponseIsSuccessful();
        /** @var list<array{id: string, name: string, role: string}> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(2, $data);
        self::assertSame('abc@newsletter', $data[0]['id']);
        self::assertSame('def@newsletter', $data[1]['id']);
    }

    public function testChannelsReturns502OnEngineFailure(): void
    {
        $client = self::createClient();
        $this->mockEngine(new MockResponse('forbidden', ['http_code' => 403]));

        $client->request('GET', '/channels');

        self::assertResponseStatusCodeSame(502);
        /** @var array<string, string> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    // -------------------------------------------------------------------------
    // POST /ui/send — guard: chatId must end in @newsletter
    // -------------------------------------------------------------------------

    public function testUiSendRejectsMissingChatId(): void
    {
        $client = self::createClient();

        // Give zero mock responses — any engine call would throw MockHttpClient out-of-responses.
        $this->mockEngine();

        $client->request(
            'POST',
            '/ui/send',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['chatId' => '', 'text' => 'Hello'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
        /** @var array<string, string> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    public function testUiSendRejectsNonNewsletterChatId(): void
    {
        $client = self::createClient();
        $this->mockEngine();

        $client->request(
            'POST',
            '/ui/send',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['chatId' => 'abc@g.us', 'text' => 'Hello'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
        /** @var array<string, string> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    public function testUiSendRejectsEmptyText(): void
    {
        $client = self::createClient();
        $this->mockEngine();

        $client->request(
            'POST',
            '/ui/send',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['chatId' => 'abc@newsletter', 'text' => '   '], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
        /** @var array<string, string> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    // -------------------------------------------------------------------------
    // POST /ui/send — happy path
    // -------------------------------------------------------------------------

    public function testUiSendCallsEngineAndReturnsOk(): void
    {
        $client = self::createClient();
        $responsePayload = json_encode(['id' => 'msg-1'], \JSON_THROW_ON_ERROR);
        $this->mockEngine(
            new MockResponse($responsePayload, ['response_headers' => ['Content-Type' => 'application/json']]),
        );

        $client->request(
            'POST',
            '/ui/send',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['chatId' => 'abc@newsletter', 'text' => 'Hello!'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($data['ok']);
    }

    public function testUiSendReturns502WhenEngineFails(): void
    {
        $client = self::createClient();
        $this->mockEngine(new MockResponse('server error', ['http_code' => 500]));

        $client->request(
            'POST',
            '/ui/send',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['chatId' => 'abc@newsletter', 'text' => 'Hello!'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(502);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    // -------------------------------------------------------------------------
    // POST /send — key gate (401 before guards and before any engine call)
    // -------------------------------------------------------------------------

    public function testSendRejects401WhenKeyIsMissing(): void
    {
        $client = self::createClient();
        // Zero mock responses: any engine call would throw.
        $this->mockEngine();

        $client->request(
            'POST',
            '/send',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['chatId' => 'abc@newsletter', 'text' => 'Hello'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testSendRejects401WhenKeyIsWrong(): void
    {
        $client = self::createClient();
        $this->mockEngine();

        $client->request(
            'POST',
            '/send',
            [],
            [],
            ['HTTP_X_INTERNAL_KEY' => 'wrong-key', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['chatId' => 'abc@newsletter', 'text' => 'Hello'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
    }

    // -------------------------------------------------------------------------
    // POST /send — guards (after key passes)
    // -------------------------------------------------------------------------

    public function testSendRejects400OnNonNewsletterChatId(): void
    {
        $client = self::createClient();
        $this->mockEngine();

        $client->request(
            'POST',
            '/send',
            [],
            [],
            ['HTTP_X_INTERNAL_KEY' => 'test-internal-key', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['chatId' => 'abc@g.us', 'text' => 'Hello'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
        /** @var array<string, string> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
        self::assertSame('chatId must be present and end with @newsletter', $data['error']);
    }

    public function testSendRejects400OnEmptyText(): void
    {
        $client = self::createClient();
        $this->mockEngine();

        $client->request(
            'POST',
            '/send',
            [],
            [],
            ['HTTP_X_INTERNAL_KEY' => 'test-internal-key', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['chatId' => 'abc@newsletter', 'text' => '   '], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
        /** @var array<string, string> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
        self::assertSame('text must be non-empty', $data['error']);
    }

    // -------------------------------------------------------------------------
    // POST /send — happy path + engine failure
    // -------------------------------------------------------------------------

    public function testSendCallsEngineAndReturnsOk(): void
    {
        $client = self::createClient();
        $responsePayload = json_encode(['id' => 'msg-2'], \JSON_THROW_ON_ERROR);
        $this->mockEngine(
            new MockResponse($responsePayload, ['response_headers' => ['Content-Type' => 'application/json']]),
        );

        $client->request(
            'POST',
            '/send',
            [],
            [],
            ['HTTP_X_INTERNAL_KEY' => 'test-internal-key', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['chatId' => 'abc@newsletter', 'text' => 'Hello!'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($data['ok']);
    }

    public function testSendReturns502WhenEngineFails(): void
    {
        $client = self::createClient();
        $this->mockEngine(new MockResponse('server error', ['http_code' => 500]));

        $client->request(
            'POST',
            '/send',
            [],
            [],
            ['HTTP_X_INTERNAL_KEY' => 'test-internal-key', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['chatId' => 'abc@newsletter', 'text' => 'Hello!'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(502);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    // -------------------------------------------------------------------------
    // POST /ui/send — preview forwarding
    // -------------------------------------------------------------------------

    public function testUiSendForwardsPreviewToEngine(): void
    {
        $client = self::createClient();
        $responsePayload = json_encode(['id' => 'msg-preview-ui'], \JSON_THROW_ON_ERROR);
        $engineResponse = new MockResponse(
            $responsePayload,
            ['response_headers' => ['Content-Type' => 'application/json']],
        );
        $this->mockEngineCapture($engineResponse);

        $client->request(
            'POST',
            '/ui/send',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'chatId' => 'abc@newsletter',
                'text' => 'Check this deal!',
                'preview' => ['url' => 'https://example.com/deal', 'title' => 'Big Deal', 'image' => 'https://example.com/img.jpg'],
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $body = json_decode($engineResponse->getRequestOptions()['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('https://example.com/deal', $body['preview']['url']);
        self::assertSame('Big Deal', $body['preview']['title']);
        self::assertSame('https://example.com/img.jpg', $body['preview']['image']);
    }

    public function testUiSendWithoutPreviewStillWorks(): void
    {
        $client = self::createClient();
        $responsePayload = json_encode(['id' => 'msg-no-preview'], \JSON_THROW_ON_ERROR);
        $engineResponse = new MockResponse(
            $responsePayload,
            ['response_headers' => ['Content-Type' => 'application/json']],
        );
        $this->mockEngineCapture($engineResponse);

        $client->request(
            'POST',
            '/ui/send',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['chatId' => 'abc@newsletter', 'text' => 'Hello!'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $body = json_decode($engineResponse->getRequestOptions()['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('preview', $body);
    }

    // -------------------------------------------------------------------------
    // POST /send — preview forwarding
    // -------------------------------------------------------------------------

    public function testSendForwardsPreviewToEngine(): void
    {
        $client = self::createClient();
        $responsePayload = json_encode(['id' => 'msg-preview-machine'], \JSON_THROW_ON_ERROR);
        $engineResponse = new MockResponse(
            $responsePayload,
            ['response_headers' => ['Content-Type' => 'application/json']],
        );
        $this->mockEngineCapture($engineResponse);

        $client->request(
            'POST',
            '/send',
            [],
            [],
            ['HTTP_X_INTERNAL_KEY' => 'test-internal-key', 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'chatId' => 'abc@newsletter',
                'text' => 'Machine send with preview',
                'preview' => ['url' => 'https://example.com/p', 'title' => 'Product', 'image' => 'https://example.com/p.jpg'],
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $body = json_decode($engineResponse->getRequestOptions()['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('https://example.com/p', $body['preview']['url']);
        self::assertSame('Product', $body['preview']['title']);
        self::assertSame('https://example.com/p.jpg', $body['preview']['image']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function mockEngine(MockResponse ...$responses): void
    {
        self::getContainer()->set(
            WhatsAppClient::class,
            new WhatsAppClient(new MockHttpClient($responses), 'http://engine:8080'),
        );
    }

    /**
     * Like mockEngine but with a single response that can be inspected after
     * the request to verify what was sent to the engine.
     */
    private function mockEngineCapture(MockResponse $response): void
    {
        self::getContainer()->set(
            WhatsAppClient::class,
            new WhatsAppClient(new MockHttpClient($response), 'http://engine:8080'),
        );
    }
}
