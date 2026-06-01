<?php

declare(strict_types=1);

namespace App\Tests\Creators;

use Amazon\CreatorsAPI\v1\com\amazon\creators\api\DefaultApi;
use Amazon\CreatorsAPI\v1\com\amazon\creators\model\GetItemsRequestContent;
use Amazon\CreatorsAPI\v1\com\amazon\creators\model\GetItemsResponseContent;
use Amazon\CreatorsAPI\v1\ObjectSerializer;
use App\Creators\SdkCreatorsClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SdkCreatorsClientTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../../packages/shared/tests/fixtures/creators/offersv2-amazon-de.dump.json';

    /**
     * The recorded .de fixture: ASIN B08B45VD31 has two listings where the
     * buy-box winner (24,99 €) is *more expensive* than the non-winner
     * (24,49 €). Selecting the winner (not the cheapest) is the whole point.
     */
    public function testSelectsTheBuyBoxWinnerEvenWhenItIsNotTheCheapestListing(): void
    {
        $client = $this->clientReturning($this->fixtureResponse(), ['B08B45VD31', 'B0010AH4BW']);

        $snapshots = $client->fetchSnapshots('B08B45VD31', 'B0010AH4BW');

        self::assertArrayHasKey('B08B45VD31', $snapshots);
        $snap = $snapshots['B08B45VD31'];
        self::assertSame(2499, $snap->priceCents, 'buy-box winner is 24,99 €, not the cheaper 24,49 €');
    }

    public function testMapsAllAttestationFieldsFromTheBuyBoxListing(): void
    {
        $client = $this->clientReturning($this->fixtureResponse(), ['B08B45VD31', 'B0010AH4BW']);

        $snap = $client->fetchSnapshots('B08B45VD31', 'B0010AH4BW')['B08B45VD31'];

        self::assertSame('B08B45VD31', $snap->asin);
        self::assertSame('IN_STOCK', $snap->availability);
        self::assertSame('New', $snap->condition);
        self::assertSame('A3JWKAKR8XB7XF', $snap->merchantId);
        self::assertSame(64, $snap->savingsPercent);
        self::assertSame('LIST_PRICE', $snap->savingBasisType);
        self::assertTrue($snap->hasDealDetails);
        self::assertFalse($snap->violatesMap);
    }

    public function testPassesTheAffiliateDetailPageUrlThroughVerbatim(): void
    {
        $client = $this->clientReturning($this->fixtureResponse(), ['B08B45VD31', 'B0010AH4BW']);

        $snap = $client->fetchSnapshots('B08B45VD31', 'B0010AH4BW')['B08B45VD31'];

        self::assertSame(
            'https://www.amazon.de/dp/B08B45VD31?tag=shahsiahde-21&linkCode=ogi&th=1&psc=1',
            $snap->detailPageUrl,
        );
        self::assertStringContainsString('tag=shahsiahde-21', $snap->detailPageUrl);
    }

    public function testLeavesSavingsFieldsNullWhenTheBuyBoxListingHasNoSavingBasis(): void
    {
        // B0010AH4BW: single buy-box listing, no savingBasis / savings / deal.
        $client = $this->clientReturning($this->fixtureResponse(), ['B08B45VD31', 'B0010AH4BW']);

        $snap = $client->fetchSnapshots('B08B45VD31', 'B0010AH4BW')['B0010AH4BW'];

        self::assertSame(121927, $snap->priceCents);
        self::assertNull($snap->savingsPercent);
        self::assertNull($snap->savingBasisType);
        self::assertFalse($snap->hasDealDetails);
    }

    #[DataProvider('centsCases')]
    public function testConvertsDecimalEurosToExactIntegerCents(float $amount, int $expectedCents): void
    {
        $client = $this->clientReturning(
            $this->responseFor([$this->buyBoxItem('B000000001', $amount)]),
            ['B000000001'],
        );

        $snap = $client->fetchSnapshots('B000000001')['B000000001'];

        self::assertIsInt($snap->priceCents);
        self::assertSame($expectedCents, $snap->priceCents);
    }

    /**
     * @return iterable<string, array{float, int}>
     */
    public static function centsCases(): iterable
    {
        yield 'a .99 amount has no float drift' => [24.99, 2499];
        yield 'a whole-euro amount' => [45.0, 4500];
        yield 'a large amount' => [1219.27, 121927];
    }

    public function testBatchesAsinsAtTenPerGetItemsCall(): void
    {
        $asins = [];
        for ($i = 1; $i <= 23; ++$i) {
            $asins[] = \sprintf('B%09d', $i);
        }

        $calls = [];
        $api = $this->apiSpy($calls);

        (new SdkCreatorsClient($api, 'partner-tag', 'www.amazon.de'))->fetchSnapshots(...$asins);

        self::assertCount(3, $calls, '23 ASINs => 3 GetItems calls');
        self::assertSame(10, \count($calls[0]));
        self::assertSame(10, \count($calls[1]));
        self::assertSame(3, \count($calls[2]));
    }

    public function testRespectsTheConfiguredAsinCap(): void
    {
        $asins = [];
        for ($i = 1; $i <= 23; ++$i) {
            $asins[] = \sprintf('B%09d', $i);
        }

        $calls = [];
        $api = $this->apiSpy($calls);

        (new SdkCreatorsClient($api, 'partner-tag', 'www.amazon.de', 10))->fetchSnapshots(...$asins);

        self::assertCount(1, $calls, 'cap of 10 => a single batch');
        self::assertSame(10, \count($calls[0]));
    }

    public function testAbsentForAsinWithNoBuyBoxWinner(): void
    {
        // One item whose only listing is NOT the buy-box winner.
        $client = $this->clientReturning(
            $this->responseFor([$this->nonWinnerItem('B0NOBUYBOX')]),
            ['B0NOBUYBOX'],
        );

        self::assertArrayNotHasKey('B0NOBUYBOX', $client->fetchSnapshots('B0NOBUYBOX'));
    }

    public function testAbsentForAsinReportedAsAnError(): void
    {
        $json = json_encode([
            'errors' => [['code' => 'ItemNotAccessible', 'message' => 'not accessible']],
            'itemsResult' => ['items' => []],
        ], \JSON_THROW_ON_ERROR);

        $client = $this->clientReturning($this->deserialize($json), ['B0ERRORED1']);

        self::assertSame([], $client->fetchSnapshots('B0ERRORED1'));
    }

    private function fixtureResponse(): GetItemsResponseContent
    {
        $json = file_get_contents(self::FIXTURE);
        self::assertIsString($json);

        return $this->deserialize($json);
    }

    private function deserialize(string $json): GetItemsResponseContent
    {
        /** @var \stdClass $data */
        $data = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        $response = ObjectSerializer::deserialize($data, GetItemsResponseContent::class);
        self::assertInstanceOf(GetItemsResponseContent::class, $response);

        return $response;
    }

    /**
     * @param list<string> $expectedIds
     */
    private function clientReturning(GetItemsResponseContent $response, array $expectedIds): SdkCreatorsClient
    {
        $api = $this->createMock(DefaultApi::class);
        $api->expects(self::once())
            ->method('getItems')
            ->with(
                'www.amazon.de',
                self::callback(static function (GetItemsRequestContent $req) use ($expectedIds): bool {
                    return $req->getItemIds() === $expectedIds && 'partner-tag' === $req->getPartnerTag();
                }),
            )
            ->willReturn($response);

        return new SdkCreatorsClient($api, 'partner-tag', 'www.amazon.de');
    }

    /**
     * @param list<array<string, mixed>> $calls captured request ASIN lists
     */
    private function apiSpy(array &$calls): DefaultApi
    {
        $api = $this->createStub(DefaultApi::class);
        $api->method('getItems')->willReturnCallback(
            static function (string $marketplace, GetItemsRequestContent $req) use (&$calls): GetItemsResponseContent {
                $calls[] = $req->getItemIds();

                return new GetItemsResponseContent();
            },
        );

        return $api;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function responseFor(array $items): GetItemsResponseContent
    {
        $json = json_encode(['itemsResult' => ['items' => $items]], \JSON_THROW_ON_ERROR);

        return $this->deserialize($json);
    }

    /**
     * @return array<string, mixed>
     */
    private function buyBoxItem(string $asin, float $amount): array
    {
        return [
            'asin' => $asin,
            'detailPageURL' => 'https://www.amazon.de/dp/'.$asin.'?tag=t-21&linkCode=ogi',
            'offersV2' => ['listings' => [[
                'isBuyBoxWinner' => true,
                'price' => ['money' => ['amount' => $amount, 'currency' => 'EUR']],
                'availability' => ['type' => 'IN_STOCK'],
                'condition' => ['value' => 'New'],
                'merchantInfo' => ['id' => 'MERCHANT'],
                'violatesMAP' => false,
            ]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nonWinnerItem(string $asin): array
    {
        $item = $this->buyBoxItem($asin, 9.99);
        $item['offersV2']['listings'][0]['isBuyBoxWinner'] = false;

        return $item;
    }
}
