<?php

declare(strict_types=1);

namespace DealPromoter\Shared\Keepa;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Hand-rolled Keepa client (no official SDK exists). Ported from
 * experiments/lib/keepa.ts. Discovery only: fetches one `/deal` page of up to
 * 150 raw Candidates for a flat 5 tokens and decodes them via DealParser.
 */
final class KeepaClient implements KeepaDiscovery
{
    private const string BASE_URL = 'https://api.keepa.com';

    // sortType 4 = highest percent-drop first (the experiments' discovery query).
    private const int SORT_PERCENT_DELTA = 4;

    private readonly DealParser $parser;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        private readonly int $domain,
    ) {
        $this->parser = new DealParser();
    }

    public function fetchDealPage(int $page = 0): DealPage
    {
        $response = $this->http->request('GET', self::BASE_URL.'/deal', [
            'query' => [
                'key' => $this->apiKey,
                'selection' => json_encode($this->selection($page), \JSON_THROW_ON_ERROR),
            ],
        ]);

        /** @var array<string, mixed> $body */
        $body = $response->toArray();

        return new DealPage(
            candidates: $this->parseCandidates($body),
            meter: TokenMeter::fromResponse($body),
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<Candidate>
     */
    private function parseCandidates(array $body): array
    {
        $deals = $body['deals'] ?? null;
        $rows = \is_array($deals) ? ($deals['dr'] ?? null) : null;
        if (!\is_array($rows)) {
            return [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            if (\is_array($row)) {
                $candidates[] = $this->parser->parse($row);
            }
        }

        return $candidates;
    }

    /**
     * The `/deal` query selection. domainId and exactly one priceType are
     * required by Keepa.
     *
     * @return array<string, mixed>
     */
    private function selection(int $page): array
    {
        return [
            'page' => $page,
            'domainId' => $this->domain,
            'priceTypes' => [0], // AMAZON
            'dateRange' => 1,    // WEEK
            'isRangeEnabled' => true,
            'deltaPercentRange' => [20, 100],
            'sortType' => self::SORT_PERCENT_DELTA,
            'filterErotic' => true,
            // Bias discovery toward deals that can be verified: "sold and fulfilled
            // by Amazon" is a precondition for an Amazon WAS_PRICE / dealDetails, so
            // requiring it raises the Amazon-verified hit-rate and cuts wasted Live
            // Snapshot calls. It is NOT verification itself — the Live Snapshot
            // still has the final say (see RunCycleCommand's verification gate).
            'mustHaveAmazonOffer' => true,
            'isFilterEnabled' => true, // required for mustHaveAmazonOffer to apply
        ];
    }
}
