<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CycleRun;
use App\Entity\FoundDeal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the Publish endpoint (P9).
 *
 * Uses the same transaction-rollback isolation pattern as ReviewControllerTest:
 * a DB transaction is opened in setUp on the container's EntityManager and
 * rolled back in tearDown. The in-process client shares the same connection so
 * the in-request flush() is visible within the transaction and gets rolled back.
 *
 * Requires Postgres up and the test schema migrated.
 */
final class PublishControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Disable kernel reboot between requests so all requests in one test share
        // the same container/EM/connection and see the same outer transaction.
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->em->clear();
        parent::tearDown();
    }

    public function testPublishPostRedirectsAndShowsPublishRequested(): void
    {
        $cycle = new CycleRun(new \DateTimeImmutable('2026-06-01T12:00:00+00:00'));
        $deal = new FoundDeal('B000PUB001', 'Publish-Test Deal', new \DateTimeImmutable('2026-06-01T12:00:00+00:00'));
        $deal->setSnapshotPriceCents(1999);
        $cycle->addFoundDeal($deal);

        $this->em->persist($cycle);
        $this->em->flush();

        $id = $deal->getId();
        self::assertNotNull($id, 'FoundDeal must have an id after flush');

        // POST → should redirect (302) to /
        $this->client->request('POST', '/publish/'.$id);
        self::assertResponseRedirects('/');

        // Follow redirect → review page must show "Publish requested" for the row
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Publish requested', $body);

        // Flash message must be present
        self::assertStringContainsString('Publish requested', $body);

        // Entity state: publishRequestedAt must be set after the request.
        // The in-process kernel shares the same EM connection; the FoundDeal object
        // in the identity map was updated by the request's markPublishRequested call.
        $reloaded = $this->em->find(FoundDeal::class, $id);
        self::assertNotNull($reloaded, 'FoundDeal must still exist');
        // Force a refresh so we hit the DB row rather than the cached identity-map copy.
        $this->em->refresh($reloaded);
        self::assertNotNull(
            $reloaded->getPublishRequestedAt(),
            'publishRequestedAt must be set after POST /publish/{id}',
        );
    }

    public function testPublishNonExistentIdReturns404(): void
    {
        $this->client->request('POST', '/publish/999999');
        self::assertResponseStatusCodeSame(404);
    }
}
