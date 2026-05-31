/**
 * Experiment 08 — Creators OffersV2 deep dive (the deal-truth fields).
 *
 * Exp 07 proved GetItems transport/envelope/affiliate-URL but only requested
 * itemInfo.title. This settles the load-bearing question of the validation stage:
 * does the API tell us a product is on a *real* discount? We request the full
 * offersV2.listings.* resource set and observe, against the live amazon.de
 * marketplace:
 *   - the actual response SHAPE + CASING of offersV2.listings[] (the brief
 *     describes these from SDK source in PascalCase; exp 07 proved the envelope is
 *     lowerCamelCase — so capture casing verbatim, do not assume)
 *   - now price (price.money), was/reference (price.savingBasis), and Amazon's
 *     claimed discount (price.savings.percentage)
 *   - dealDetails: present ONLY when the listing is an actual deal (badge/window)
 *   - condition (confirm the brief's NEW-only claim), availability enum, merchant
 *   - multiple listings per item -> pick the buy-box one (isBuyBoxWinner), never [0]
 *
 * Method: ONE call (1 transaction) with TWO ASINs so we contrast shapes directly:
 *   DEAL  = a user-supplied ASIN on an active amazon.de deal RIGHT NOW (deals expire)
 *   BASE  = B0010AH4BW (Bosch, exp-05 survivor) as the no-deal baseline, to confirm
 *           savingBasis / savings / dealDetails are ABSENT when there is no deal.
 *
 * Cost: 1 transaction. Run: bun run 08-creators-offersv2/run.ts <DEAL_ASIN>
 */
import { getItems, logCost, Resources, partnerTag } from "../lib/creators";

const BASE = "B0010AH4BW"; // Bosch Luftmassenmesser — exp-05 survivor, no-deal baseline

const DEAL = process.argv[2];
if (!DEAL || !/^[A-Z0-9]{10}$/.test(DEAL)) {
  // Mirrors lib/creators.ts:reqEnv() — fail loudly with instructions, and keep a
  // real (perishable) deal ASIN out of committed source.
  throw new Error(
    "Pass a current amazon.de deal ASIN as the first arg:\n" +
      "  bun run 08-creators-offersv2/run.ts <DEAL_ASIN>\n" +
      "Grab a live Lightning/Limited-time-deal ASIN from amazon.de/deals — they expire fast.",
  );
}

const itemIds = [DEAL, BASE];
const resources = [
  Resources.ITEM_INFO_TITLE,
  Resources.OFFERS_V2_LISTINGS_PRICE,
  Resources.OFFERS_V2_LISTINGS_CONDITION,
  Resources.OFFERS_V2_LISTINGS_AVAILABILITY,
  Resources.OFFERS_V2_LISTINGS_MERCHANT_INFO,
  Resources.OFFERS_V2_LISTINGS_DEAL_DETAILS,
  Resources.OFFERS_V2_LISTINGS_IS_BUY_BOX_WINNER,
];

console.log("=== request ===");
console.log(JSON.stringify({ itemIds, partnerTag: partnerTag(), resources }, null, 2));

const json = await getItems(itemIds, resources);

await Bun.write(
  `${import.meta.dir}/out/offersv2-amazon-de.dump.json`,
  JSON.stringify(json, null, 2),
);

console.log("\n=== cost ===");
logCost("/offersv2", json);

const result = json.itemsResult ?? {};
const items: any[] = result.items ?? [];
const errors: any[] = json.errors ?? result.errors ?? [];
console.log("\n=== envelope ===");
console.log(`top-level keys   : ${Object.keys(json).join(", ")}`);
console.log(`items returned   : ${items.length} (expected 2)`);
console.log(`errors returned  : ${errors.length} (expected 0)`);

// CONFIRMED (exp 08): the offersV2 envelope is lowerCamelCase end to end (NOT the
// brief's SDK-derived PascalCase). Field names below are the real ones.
const money = (m: any) =>
  m ? `${m.amount} ${m.currency} (${m.displayAmount})` : "n/a";

for (const asin of itemIds) {
  const tag = asin === DEAL ? "DEAL" : "BASE";
  const item = items.find((i) => i.asin === asin);
  console.log(`\n=========== ${asin} [${tag}] ===========`);
  if (!item) {
    const err = errors.find((e) => String(e.message ?? "").includes(asin));
    console.log(`MISSING from items[]. error: code=${err?.code ?? "?"} msg=${err?.message ?? "?"}`);
    continue;
  }

  const listings: any[] = item.offersV2?.listings ?? [];
  console.log(`title            : ${item.itemInfo?.title?.displayValue ?? "n/a"}`);
  console.log(`listings count   : ${listings.length}`);

  // Pick the buy-box listing; never assume [0] or cheapest (a Used listing can
  // undercut the New buy box — see the DEAL item).
  const buyBox = listings.find((l) => l.isBuyBoxWinner === true) ?? listings[0];
  if (!buyBox) {
    console.log("no listings — no eligible offer (treat as 'skip, not a deal')");
    continue;
  }
  console.log(`isBuyBoxWinner   : ${buyBox.isBuyBoxWinner} (of ${listings.length} listing(s))`);

  // --- the discount answer -------------------------------------------------
  const price = buyBox.price;
  console.log(`  now (money)    : ${money(price?.money)}`);
  console.log(
    `  was (basis)    : ${price?.savingBasis ? `${money(price.savingBasis.money)} type=${price.savingBasis.savingBasisType}` : "ABSENT (no reference price)"}`,
  );
  console.log(
    `  savings        : ${price?.savings ? `${money(price.savings.money)} pct=${price.savings.percentage}` : "ABSENT (no claimed discount)"}`,
  );

  // --- Amazon's own deal flag (present only when it IS a deal) -------------
  const deal = buyBox.dealDetails;
  console.log(
    `  dealDetails    : ${deal ? `PRESENT badge="${deal.badge}" access=${deal.accessType} end=${deal.endTime}` : "ABSENT (Amazon does not flag this as a deal)"}`,
  );

  // --- gates ---------------------------------------------------------------
  console.log(`  condition      : ${buyBox.condition?.value ?? "n/a"} (sub=${buyBox.condition?.subCondition ?? "n/a"})`);
  console.log(`  availability   : ${buyBox.availability?.type ?? "n/a"}`);
  console.log(`  merchant       : ${buyBox.merchantInfo?.name ?? "n/a"} (id=${buyBox.merchantInfo?.id ?? "n/a"})`);
  if (buyBox.violatesMAP !== undefined)
    console.log(`  violatesMAP    : ${buyBox.violatesMAP} (if true, price hidden until checkout — not postable)`);
}

console.log(
  "\nNext: read the raw-keys lines above to lock the real casing, then trim " +
    "out/offersv2-amazon-de.dump.json into sample.item.json (deal listing + no-deal " +
    "listing; scrub the tag) and write FINDINGS.md. Fold confirmed casing into the brief §3.",
);
