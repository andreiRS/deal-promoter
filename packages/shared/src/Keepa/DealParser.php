<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Keepa;

/**
 * Decodes one raw Keepa deal object (an entry of `deals.dr[]`) into a Candidate.
 *
 * Ported from experiments/lib/keepa.ts. Encodes the documented Keepa quirks so
 * the rest of the pipeline never has to:
 *   - `current` is a 1D array indexed by price type; `avg`/`deltaPercent` are 2D
 *     `[dateRange][priceType]`.
 *   - sentinels differ by array: `current` uses `-1`, `avg`/`delta` use `-2`/`0`.
 *     Any non-positive value means "no data", never a real €0.00 price.
 *   - `image` is an array of US-ASCII char codes for the CDN filename only.
 */
final class DealParser
{
    // Price-type indices (shared by current/avg/deltaPercent). AMAZON is the
    // canonical price the experiments standardised the funnel on.
    private const int PRICE_AMAZON = 0;
    private const int PRICE_SALES_RANK = 3;
    private const int PRICE_RATING = 16;

    // dateRange indices for the 2D deal arrays.
    private const int RANGE_MONTH = 2;
    private const int RANGE_NINETY = 3;

    private const string IMAGE_CDN_BASE = 'https://images-na.ssl-images-amazon.com/images/I/';

    /**
     * @param array<string, mixed> $deal one entry of the Keepa `deals.dr[]` array
     */
    public function parse(array $deal): Candidate
    {
        /** @var list<int> $categories */
        $categories = array_values(array_filter(
            \is_array($deal['categories'] ?? null) ? $deal['categories'] : [],
            static fn (mixed $c): bool => \is_int($c),
        ));

        $asin = $deal['asin'] ?? null;
        $title = $deal['title'] ?? null;
        $rootCat = $deal['rootCat'] ?? null;

        return new Candidate(
            asin: \is_string($asin) ? $asin : '',
            title: \is_string($title) ? $title : '',
            imageUrl: $this->decodeImageUrl($deal['image'] ?? null),
            currentPriceCents: $this->current($deal, self::PRICE_AMAZON),
            avg30Cents: $this->avg($deal, self::RANGE_MONTH, self::PRICE_AMAZON),
            avg90Cents: $this->avg($deal, self::RANGE_NINETY, self::PRICE_AMAZON),
            dropPercent90: $this->avg($deal, self::RANGE_NINETY, self::PRICE_AMAZON, 'deltaPercent'),
            salesRankDrops90: $this->scalar($deal, 'salesRankDrops90'),
            salesRank: $this->current($deal, self::PRICE_SALES_RANK),
            ratingStarsTimesTen: $this->current($deal, self::PRICE_RATING),
            rootCategory: is_numeric($rootCat) ? (int) $rootCat : 0,
            categories: $categories,
        );
    }

    /**
     * Read a 1D `current`/`current`-shaped field at a price type. A non-positive
     * value is the `-1` sentinel and becomes null.
     *
     * @param array<string, mixed> $deal
     */
    private function current(array $deal, int $priceType, string $field = 'current'): ?int
    {
        $row = $deal[$field] ?? null;
        if (!\is_array($row) || !isset($row[$priceType]) || !\is_int($row[$priceType])) {
            return null;
        }

        return $row[$priceType] > 0 ? $row[$priceType] : null;
    }

    /**
     * Read a 2D `[dateRange][priceType]` field (`avg`, `deltaPercent`). A
     * non-positive value is a `-2`/`0` sentinel and becomes null.
     *
     * @param array<string, mixed> $deal
     */
    private function avg(array $deal, int $range, int $priceType, string $field = 'avg'): ?int
    {
        $rows = $deal[$field] ?? null;
        if (!\is_array($rows) || !isset($rows[$range]) || !\is_array($rows[$range])) {
            return null;
        }
        $value = $rows[$range][$priceType] ?? null;
        if (!\is_int($value)) {
            return null;
        }

        return $value > 0 ? $value : null;
    }

    /**
     * Read a scalar deal field (e.g. `salesRankDrops90`). The `-1` sentinel means
     * "no data" and becomes null; a real 0 (no rank drops) is kept.
     *
     * @param array<string, mixed> $deal
     */
    private function scalar(array $deal, string $field): ?int
    {
        $value = $deal[$field] ?? null;
        if (!\is_int($value)) {
            return null;
        }

        return $value >= 0 ? $value : null;
    }

    /**
     * Decode Keepa's `image` char-code array into a full Amazon CDN URL.
     * Returns '' when absent.
     *
     * @param mixed $image array of US-ASCII char codes for the filename
     */
    private function decodeImageUrl(mixed $image): string
    {
        if (!\is_array($image) || [] === $image) {
            return '';
        }
        $codes = array_filter(
            $image,
            static fn (mixed $c): bool => \is_int($c) && $c >= 0 && $c <= 255,
        );
        if ([] === $codes) {
            return '';
        }
        $filename = implode('', array_map(static fn (int $c): string => \chr($c), $codes));

        return self::IMAGE_CDN_BASE.$filename;
    }
}
