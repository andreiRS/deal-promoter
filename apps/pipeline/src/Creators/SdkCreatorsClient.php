<?php

declare(strict_types=1);

namespace App\Creators;

use Amazon\CreatorsAPI\v1\com\amazon\creators\api\DefaultApi;
use Amazon\CreatorsAPI\v1\com\amazon\creators\model\GetItemsRequestContent;
use Amazon\CreatorsAPI\v1\com\amazon\creators\model\GetItemsResource;
use Amazon\CreatorsAPI\v1\com\amazon\creators\model\GetItemsResponseContent;
use Amazon\CreatorsAPI\v1\com\amazon\creators\model\Item;
use Amazon\CreatorsAPI\v1\com\amazon\creators\model\OfferListingV2;
use DealPromoter\Shared\Creators\CreatorsClient;
use DealPromoter\Shared\Creators\LiveSnapshot;

/**
 * CreatorsClient backed by the official Amazon CreatorsAPI PHP SDK.
 *
 * The SDK is generated code with untyped/nullable getters, so every value is
 * narrowed explicitly here (no blind casts of `mixed`). All money is converted
 * to integer euro-cents at this boundary; the affiliate `detailPageURL` is
 * passed through verbatim.
 */
final readonly class SdkCreatorsClient implements CreatorsClient
{
    /** Amazon's GetItems hard limit on ASINs per call. */
    private const int BATCH_SIZE = 10;

    /**
     * The OffersV2 resources we need for the attestation snapshot. Without these
     * the listings come back without price/availability/condition/merchant.
     *
     * @var list<string>
     */
    private const array RESOURCES = [
        GetItemsResource::OFFERS_V2_LISTINGS_PRICE,
        GetItemsResource::OFFERS_V2_LISTINGS_AVAILABILITY,
        GetItemsResource::OFFERS_V2_LISTINGS_CONDITION,
        GetItemsResource::OFFERS_V2_LISTINGS_MERCHANT_INFO,
        GetItemsResource::OFFERS_V2_LISTINGS_DEAL_DETAILS,
    ];

    public function __construct(
        private DefaultApi $api,
        private string $partnerTag,
        private string $marketplace,
        private ?int $cap = null,
    ) {
    }

    public function fetchSnapshots(string ...$asins): array
    {
        $asins = array_values(array_unique($asins));
        if (null !== $this->cap) {
            $asins = \array_slice($asins, 0, max(0, $this->cap));
        }

        $snapshots = [];
        foreach (array_chunk($asins, self::BATCH_SIZE) as $batch) {
            foreach ($this->fetchBatch($batch) as $asin => $snapshot) {
                $snapshots[$asin] = $snapshot;
            }
        }

        return $snapshots;
    }

    /**
     * @param list<string> $asins
     *
     * @return array<string, LiveSnapshot>
     */
    private function fetchBatch(array $asins): array
    {
        $request = new GetItemsRequestContent();
        $request->setPartnerTag($this->partnerTag);
        $request->setItemIds($asins);
        // The SDK types $resources as GetItemsResource[], but that "enum" is a
        // bag of string constants with no instances — the SDK's own examples
        // pass the string values. So the string list is correct; the PHPDoc is
        // not. (Line comment, not a docblock, so php-cs-fixer cannot strip it.)
        $request->setResources(self::RESOURCES); // @phpstan-ignore argument.type

        $response = $this->api->getItems($this->marketplace, $request);
        if (!$response instanceof GetItemsResponseContent) {
            return [];
        }

        $snapshots = [];
        foreach ($this->items($response) as $item) {
            $snapshot = $this->toSnapshot($item);
            if (null !== $snapshot) {
                $snapshots[$snapshot->asin] = $snapshot;
            }
        }

        return $snapshots;
    }

    /**
     * @return list<Item>
     */
    private function items(GetItemsResponseContent $response): array
    {
        $result = $response->getItemsResult();
        if (null === $result) {
            return [];
        }

        $items = $result->getItems();

        return null === $items ? [] : array_values($items);
    }

    private function toSnapshot(Item $item): ?LiveSnapshot
    {
        $asin = $item->getAsin();
        $url = $item->getDetailPageURL();
        if (!\is_string($asin) || '' === $asin || !\is_string($url)) {
            return null;
        }

        $listing = $this->buyBoxListing($item);
        if (null === $listing) {
            return null;
        }

        $priceCents = $this->priceCents($listing);
        if (null === $priceCents) {
            return null;
        }

        return new LiveSnapshot(
            asin: $asin,
            priceCents: $priceCents,
            availability: $this->availability($listing),
            condition: $this->condition($listing),
            merchantId: $this->merchantId($listing),
            savingsPercent: $this->savingsPercent($listing),
            savingBasisType: $this->savingBasisType($listing),
            hasDealDetails: null !== $listing->getDealDetails(),
            violatesMap: $this->violatesMap($listing),
            detailPageUrl: $url,
        );
    }

    /**
     * The buy-box listing is the one with `isBuyBoxWinner === true` — never the
     * first or the cheapest. The fixture proves the cheapest listing is not the
     * buy box.
     */
    private function buyBoxListing(Item $item): ?OfferListingV2
    {
        $offers = $item->getOffersV2();
        if (null === $offers) {
            return null;
        }

        $listings = $offers->getListings();
        if (null === $listings) {
            return null;
        }

        foreach ($listings as $listing) {
            if (true === $listing->getIsBuyBoxWinner()) {
                return $listing;
            }
        }

        return null;
    }

    private function priceCents(OfferListingV2 $listing): ?int
    {
        $price = $listing->getPrice();
        if (null === $price) {
            return null;
        }

        $money = $price->getMoney();
        if (null === $money) {
            return null;
        }

        $amount = $money->getAmount();
        if (null === $amount) {
            return null;
        }

        // Convert decimal euros to integer cents at the boundary; the float
        // never crosses into the domain.
        return (int) round($amount * 100);
    }

    private function availability(OfferListingV2 $listing): ?string
    {
        $availability = $listing->getAvailability();
        if (null === $availability) {
            return null;
        }

        $type = $availability->getType();

        return \is_string($type) ? $type : null;
    }

    private function condition(OfferListingV2 $listing): ?string
    {
        $condition = $listing->getCondition();
        if (null === $condition) {
            return null;
        }

        $value = $condition->getValue();

        return \is_string($value) ? $value : null;
    }

    private function merchantId(OfferListingV2 $listing): ?string
    {
        $merchant = $listing->getMerchantInfo();
        if (null === $merchant) {
            return null;
        }

        $id = $merchant->getId();

        return \is_string($id) ? $id : null;
    }

    private function savingsPercent(OfferListingV2 $listing): ?int
    {
        $price = $listing->getPrice();
        if (null === $price) {
            return null;
        }

        $savings = $price->getSavings();
        if (null === $savings) {
            return null;
        }

        $percentage = $savings->getPercentage();
        if (null === $percentage) {
            return null;
        }

        return (int) $percentage;
    }

    private function savingBasisType(OfferListingV2 $listing): ?string
    {
        $price = $listing->getPrice();
        if (null === $price) {
            return null;
        }

        $basis = $price->getSavingBasis();
        if (null === $basis) {
            return null;
        }

        // The SDK types this getter as a SavingBasisType *model*, but that class
        // is an enum (it has getAllowableEnumValues), so the SDK's own
        // ObjectSerializer leaves the value as the raw enum string
        // (LIST_PRICE / WAS_PRICE / ...). The PHPDoc therefore lies; we narrow
        // through a mixed seam rather than trusting it.
        return self::stringOrNull($basis->getSavingBasisType());
    }

    private function violatesMap(OfferListingV2 $listing): ?bool
    {
        $violates = $listing->getViolatesMAP();

        return \is_bool($violates) ? $violates : null;
    }

    /**
     * Narrows a value whose SDK PHPDoc cannot be trusted (a generated enum
     * "model" that is really a string) down to a string, via a mixed seam so
     * PHPStan max accepts the runtime check instead of reading it as dead code.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
