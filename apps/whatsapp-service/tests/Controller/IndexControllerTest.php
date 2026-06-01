<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Waha\WahaClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Smoke test for the gateway shell (Slice 1): the index route boots the app and
 * returns 200. No WhatsApp behavior is exercised yet.
 */
final class IndexControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testIndexReturns200(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    public function testIndexRendersPairingShell(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Connect WhatsApp');
        // The client JS drives the pairing flow against the open /session routes.
        self::assertStringContainsString('/session/start', (string) $this->client->getResponse()->getContent());
    }

    public function testPairingPageNeverLeaksTheWahaApiKey(): void
    {
        // Configure the gateway with a known secret, then assert the rendered
        // page (driven by client JS hitting same-origin routes) never contains
        // it. The X-Api-Key lives only in WahaClient — see ADR 0002.
        $secret = 'super-secret-waha-key-9f3a';
        self::getContainer()->set(
            WahaClient::class,
            new WahaClient(new MockHttpClient(new MockResponse('', ['http_code' => 404])), 'http://waha:3000', $secret, 'default'),
        );

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->getContent());
    }
}
