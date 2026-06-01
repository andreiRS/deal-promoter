<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CycleRun;
use App\Entity\FoundDeal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the read-only Review page (P8).
 *
 * Uses the transaction-rollback isolation pattern: a DB transaction is opened in
 * setUp on the container's EntityManager and rolled back in tearDown. The client
 * shares the same in-process connection, so uncommitted seed rows are visible to
 * the GET request.
 *
 * Requires Postgres up and the test schema migrated, e.g.:
 *   docker compose run --rm app sh -lc '\
 *     APP_ENV=test php bin/console doctrine:database:create --if-not-exists && \
 *     APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction && \
 *     vendor/bin/phpunit --testsuite app'
 */
final class ReviewControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
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

    public function testEmptyDatabaseShowsEmptyState(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No cycle has run yet');
    }

    public function testShowsLatestCycleAndNotOlderCycle(): void
    {
        $older = new CycleRun(new \DateTimeImmutable('2026-05-30T08:00:00+00:00'));
        $olderDeal = new FoundDeal('B000OLDER1', 'UNIQUE-A-OLD-TITLE', new \DateTimeImmutable('2026-05-30T08:00:00+00:00'));
        $older->addFoundDeal($olderDeal);

        $newer = new CycleRun(new \DateTimeImmutable('2026-06-01T08:00:00+00:00'));
        $newerDeal = new FoundDeal('B000NEWER1', 'UNIQUE-B-NEW-TITLE', new \DateTimeImmutable('2026-06-01T08:00:00+00:00'));
        $newerDeal->setSnapshotPriceCents(2599);
        $newerDeal->setKeepaDropPct(42);
        $newerDeal->setKeepaAvg90Cents(4499);
        $newerDeal->setAmazonSavingsPct(30);
        $newerDeal->setSavingBasisType('WAS_PRICE');
        $newerDeal->setAvailability('IN_STOCK');
        $newerDeal->setCondition('New');
        $newerDeal->setMerchantId('A1MERCHANT');
        $newer->addFoundDeal($newerDeal);

        $this->em->persist($older);
        $this->em->persist($newer);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('UNIQUE-B-NEW-TITLE', $body);
        self::assertStringNotContainsString('UNIQUE-A-OLD-TITLE', $body);
        self::assertStringContainsString('WAS_PRICE', $body);
        self::assertStringContainsString('A1MERCHANT', $body);
        unset($crawler);
    }

    public function testCentsAndAffiliateUrlRenderCorrectly(): void
    {
        $cycle = new CycleRun(new \DateTimeImmutable('2026-06-01T09:00:00+00:00'));
        $deal = new FoundDeal('B000PRICE1', 'PRICED-DEAL', new \DateTimeImmutable('2026-06-01T09:00:00+00:00'));
        $deal->setSnapshotPriceCents(3999);
        $deal->setAffiliateUrl('https://www.amazon.de/dp/B000PRICE1?tag=mytag-21');
        $cycle->addFoundDeal($deal);

        $this->em->persist($cycle);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('39.99', $body);

        $href = $crawler->filter('a[href="https://www.amazon.de/dp/B000PRICE1?tag=mytag-21"]');
        self::assertGreaterThan(0, $href->count(), 'Expected an affiliate link with the stored detailPageURL.');
    }

    public function testCycleWithZeroFoundDealsShowsEmptyState(): void
    {
        $cycle = new CycleRun(new \DateTimeImmutable('2026-06-01T10:00:00+00:00'));
        $this->em->persist($cycle);
        $this->em->flush();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No found deals');
    }
}
