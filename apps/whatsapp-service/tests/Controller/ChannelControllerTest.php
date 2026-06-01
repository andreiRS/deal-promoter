<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Waha\WahaClient;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Functional tests for the open, host-bound channel + send routes (ADR 0002).
 *
 * GET /channels  — lists owned channels via WahaClient::listOwnedChannels.
 * POST /ui/send  — open human send path; guards chatId and text BEFORE any WAHA
 *                  call; routes through WahaClient::sendText on success.
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

        $this->mockWaha(
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

    public function testChannelsReturns502OnWahaFailure(): void
    {
        $client = self::createClient();
        $this->mockWaha(new MockResponse('forbidden', ['http_code' => 403]));

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

        // Give zero mock responses — any WAHA call would throw MockHttpClient out-of-responses.
        $this->mockWaha();

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
        $this->mockWaha();

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
        $this->mockWaha();

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

    public function testUiSendCallsWahaAndReturnsOk(): void
    {
        $client = self::createClient();
        $wahaResponse = json_encode(['id' => 'msg-1'], \JSON_THROW_ON_ERROR);
        $this->mockWaha(
            new MockResponse($wahaResponse, ['response_headers' => ['Content-Type' => 'application/json']]),
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

    public function testUiSendReturns502WhenWahaFails(): void
    {
        $client = self::createClient();
        $this->mockWaha(new MockResponse('server error', ['http_code' => 500]));

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
    // Helpers
    // -------------------------------------------------------------------------

    private function mockWaha(MockResponse ...$responses): void
    {
        self::getContainer()->set(
            WahaClient::class,
            new WahaClient(new MockHttpClient($responses), 'http://waha:3000', 'secret', 'default'),
        );
    }
}
