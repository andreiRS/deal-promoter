<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Tests\PreFilter;

use DealPromoter\Shared\Keepa\KeepaClient;
use DealPromoter\Shared\PreFilter\Criteria;
use DealPromoter\Shared\PreFilter\GuardThresholds;
use DealPromoter\Shared\PreFilter\PreFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Characterization of the default-config Pre-filter over the real ~150-deal
 * fixture. Locks the surviving ASIN set as a golden regression so any future
 * change to the filter logic that moves a candidate across the line is loud.
 */
final class PreFilterFixtureTest extends TestCase
{
    /**
     * The exact, sorted ASINs that survive the DEFAULT config over deal-page.json.
     * Regenerate intentionally (never silently) if the filter logic changes.
     *
     * @var list<string>
     */
    private const array GOLDEN_SURVIVOR_ASINS = [
        '019085894X',
        '019820213X',
        '0198247907',
        '1248007026',
        '1278558039',
        '1295680513',
        '1325430285',
        '1325754188',
        '1325755990',
        '1325773239',
        '1325777145',
        '1325787434',
        '1332071635',
        '1333009321',
        '1333324545',
        '140032260X',
        '1782377646',
        '1986425738',
        '384003342X',
        '9401471843',
        'B0010AH4BW',
        'B003SYWV1G',
        'B003SYZLP4',
        'B003SZ0AJU',
        'B003SZ7AG6',
        'B003SZS1KU',
        'B0064TX4DQ',
        'B00B8VNWBO',
        'B01A8YKW28',
        'B01AYA0N0W',
        'B01GK0WFBA',
        'B06W563HW9',
        'B079SFLXW2',
        'B07B8TVLNK',
        'B07N1B2S6S',
        'B07TJ1MXWB',
        'B082MBRX8G',
        'B084RJNPWX',
        'B084Y55VB1',
        'B086CGB8HV',
        'B086CH6P73',
        'B086CH7YJ2',
        'B086N6FFDY',
        'B086N8DYHY',
        'B08M85KRNQ',
        'B09298J1RR',
        'B0929B52LQ',
        'B09GL16GMH',
        'B09HKMBPPV',
        'B09NCFKZKP',
        'B09R7Y6HPW',
        'B09RKJ472T',
        'B0B4HCLR5F',
        'B0B5JP32QZ',
        'B0B888ZWMT',
        'B0BXLNNH8W',
        'B0C5X28LTS',
        'B0C6FML1W3',
        'B0CBKJXSPW',
        'B0CBSNFM3S',
        'B0CCVQF3MB',
        'B0CLQKKMF1',
        'B0D1RYZ396',
        'B0D2LQ9VY2',
        'B0D2P3F1GG',
        'B0D2P4KR3J',
        'B0D693DGC3',
        'B0DGQ1YZ7S',
        'B0DGQ1ZC73',
        'B0DGQ4W46X',
        'B0DNNF6QHF',
        'B0DP169CXB',
        'B0DPG5K1KM',
        'B0DPGFFSNW',
        'B0DPL9TS7R',
        'B0DTKDJDV9',
        'B0DTV71HWG',
        'B0DV9S9FWD',
        'B0DVZQJF17',
        'B0DWYMJGHQ',
        'B0DX2D7K9W',
        'B0DXF4JMSJ',
        'B0DZ33TTZH',
        'B0F1MZZGKX',
        'B0F1YTVXY7',
        'B0F2G9DLDP',
        'B0F39LFMMZ',
        'B0FD3HMLRQ',
        'B0FG998TS9',
        'B0FK5G33RH',
        'B0G1M3VTZ8',
        'B0G3FV7ZDY',
        'B0GY9KTDGN',
    ];

    /**
     * @return list<\DealPromoter\Shared\Keepa\Candidate>
     */
    private function fixtureCandidates(): array
    {
        $body = file_get_contents(__DIR__.'/../fixtures/keepa/deal-page.json');
        self::assertIsString($body);

        $client = new KeepaClient(new MockHttpClient(new MockResponse($body)), 'test-key', 3);

        return $client->fetchDealPage()->candidates;
    }

    public function testDefaultPreFilterPartitionsTheWholeFixture(): void
    {
        $candidates = $this->fixtureCandidates();
        self::assertCount(150, $candidates);

        $result = (new PreFilter(new Criteria(), new GuardThresholds()))->apply(...$candidates);

        // Survivors + rejections account for every candidate, no overlap.
        self::assertCount(
            \count($candidates),
            [...$result->survivors, ...$result->rejections],
        );

        // Every rejection carries at least one reason.
        foreach ($result->rejections as $rejection) {
            self::assertNotEmpty($rejection->reasons);
        }
    }

    public function testSurvivorsPassEveryCriterionAndGuard(): void
    {
        $result = (new PreFilter(new Criteria(), new GuardThresholds()))->apply(...$this->fixtureCandidates());

        // A survivor re-run on its own must still survive (idempotent, clean).
        foreach ($result->survivors as $survivor) {
            $rerun = (new PreFilter(new Criteria(), new GuardThresholds()))->apply($survivor);
            self::assertSame([], $rerun->rejections, "survivor {$survivor->asin} must have no reasons");
        }
    }

    public function testSurvivingAsinsMatchTheGolden(): void
    {
        $result = (new PreFilter(new Criteria(), new GuardThresholds()))->apply(...$this->fixtureCandidates());

        $asins = array_map(static fn ($c): string => $c->asin, $result->survivors);
        sort($asins);

        self::assertSame(self::GOLDEN_SURVIVOR_ASINS, $asins);
    }
}
