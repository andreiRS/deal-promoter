<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
}
