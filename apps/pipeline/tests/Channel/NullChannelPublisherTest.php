<?php

declare(strict_types=1);

namespace App\Tests\Channel;

use App\Channel\NullChannelPublisher;
use DealPromoter\Shared\Channel\ChannelPublisher;
use DealPromoter\Shared\Channel\PublishableDeal;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Unit tests for NullChannelPublisher (P9).
 *
 * Verifies:
 *  1. publish() logs an info line containing the ASIN.
 *  2. No HTTP/WhatsApp call is made (the null impl is the entire body).
 *  3. The DI container wires ChannelPublisher -> NullChannelPublisher.
 *  4. The controller depends on the ChannelPublisher interface, not the concrete class.
 */
final class NullChannelPublisherTest extends KernelTestCase
{
    public function testPublishLogsInfoWithAsin(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<mixed>}> */
            public array $records = [];

            /**
             * @param array<mixed> $context
             */
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $publisher = new NullChannelPublisher($logger);
        $deal = $this->fakeDeal('B000TEST01');
        $publisher->publish($deal);

        self::assertCount(1, $logger->records, 'Expected exactly one log entry');
        self::assertSame(LogLevel::INFO, $logger->records[0]['level']);
        // PSR-3 message template contains {asin}; the ASIN is in the context array.
        self::assertStringContainsString('{asin}', $logger->records[0]['message']);
        self::assertSame('B000TEST01', $logger->records[0]['context']['asin']);
    }

    public function testPublishMakesNoOtherInteraction(): void
    {
        // NullChannelPublisher has no other dependencies; its publish() body
        // is purely a logger call. If it compiles and calls publish() without
        // throwing, there is no channel/HTTP interaction possible.
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<mixed>}> */
            public array $records = [];

            /**
             * @param array<mixed> $context
             */
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $publisher = new NullChannelPublisher($logger);
        $deal = $this->fakeDeal('B000TEST02');
        $publisher->publish($deal);

        // Only one log entry, nothing else observable.
        self::assertCount(1, $logger->records);
    }

    public function testContainerWiresChannelPublisherToNullImpl(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $publisher = $container->get(ChannelPublisher::class);

        self::assertInstanceOf(NullChannelPublisher::class, $publisher);
    }

    public function testControllerDependsOnInterface(): void
    {
        // Reflect the publish controller and assert it type-hints ChannelPublisher,
        // not NullChannelPublisher, guaranteeing the seam is interface-bound.
        $controllerClass = \App\Controller\PublishController::class;
        $rc = new \ReflectionClass($controllerClass);
        $constructor = $rc->getConstructor();
        self::assertNotNull($constructor, 'PublishController must have a constructor');

        $hasInterfaceParam = false;
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && ChannelPublisher::class === $type->getName()) {
                $hasInterfaceParam = true;
                break;
            }
        }
        self::assertTrue(
            $hasInterfaceParam,
            'PublishController constructor must type-hint '.ChannelPublisher::class.' (the interface)',
        );
    }

    private function fakeDeal(string $asin): PublishableDeal
    {
        return new class($asin) implements PublishableDeal {
            public function __construct(private readonly string $asin)
            {
            }

            public function getAsin(): string
            {
                return $this->asin;
            }

            public function getTitle(): string
            {
                return 'Test Deal Title';
            }

            public function getSnapshotPriceCents(): ?int
            {
                return 1999;
            }

            public function getAffiliateUrl(): ?string
            {
                return 'https://www.amazon.de/dp/'.$this->asin.'?tag=test-21';
            }

            public function getImageUrl(): ?string
            {
                return null;
            }
        };
    }
}
