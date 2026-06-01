<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Tests\Keepa;

use DealPromoter\Shared\Keepa\Candidate;
use DealPromoter\Shared\Keepa\DealParser;
use PHPUnit\Framework\TestCase;

final class DealParserTest extends TestCase
{
    /** @return array<string, mixed> */
    private function sampleDeal(): array
    {
        $json = file_get_contents(__DIR__.'/../fixtures/keepa/sample-deal.json');
        self::assertIsString($json);
        $deal = json_decode($json, true);
        self::assertIsArray($deal);

        return $deal;
    }

    public function testParsesTheCoreFactsOfADeal(): void
    {
        $candidate = (new DealParser())->parse($this->sampleDeal());

        self::assertInstanceOf(Candidate::class, $candidate);
        self::assertSame('B0GJFS1HB5', $candidate->asin);
        self::assertStringStartsWith('12-cm-HDMI-Verlängerungskabel', $candidate->title);
        self::assertSame(699, $candidate->currentPriceCents);
        self::assertSame(87831, $candidate->avg30Cents);
        self::assertSame(91892, $candidate->avg90Cents);
        self::assertSame(99, $candidate->dropPercent90);
        self::assertSame(562066, $candidate->rootCategory);
        self::assertSame([1382696031], $candidate->categories);
        self::assertSame(45, $candidate->ratingStarsTimesTen);
    }

    public function testSentinelValuesBecomeNullNotRealPrices(): void
    {
        // current uses the -1 sentinel; avg/deltaPercent use -2 and 0.
        $deal = [
            'asin' => 'B000000000',
            'title' => 'Out of stock everywhere',
            'current' => [-1, -1, -1, -1],
            'avg' => [
                2 => [0, 0],   // month: 0 sentinel
                3 => [-2, -2], // 90d: -2 sentinel
            ],
            'deltaPercent' => [3 => [-2, -2]],
            'salesRankDrops90' => -1,
            'rootCat' => 0,
            'categories' => [],
        ];

        $candidate = (new DealParser())->parse($deal);

        self::assertNull($candidate->currentPriceCents);
        self::assertNull($candidate->avg30Cents);
        self::assertNull($candidate->avg90Cents);
        self::assertNull($candidate->dropPercent90);
        self::assertNull($candidate->salesRankDrops90);
        self::assertNull($candidate->salesRank);
    }

    public function testAbsentImageYieldsEmptyString(): void
    {
        $deal = ['asin' => 'B000000000', 'title' => 'No image', 'rootCat' => 0, 'categories' => []];

        self::assertSame('', (new DealParser())->parse($deal)->imageUrl);
    }

    public function testDecodesTheImageCharCodeArrayIntoACdnUrl(): void
    {
        $candidate = (new DealParser())->parse($this->sampleDeal());

        self::assertSame(
            'https://images-na.ssl-images-amazon.com/images/I/111tNa5a-tL.jpg',
            $candidate->imageUrl,
        );
    }
}
