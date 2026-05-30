/**
 * Experiment 05 - funnel dry run: end-to-end /deal -> glitch-guard pre-filter ->
 * batched /product?stats=90 on survivors only.
 *
 * This is the production funnel shape, measured end-to-end. exp04 proved the
 * glitch-guard's structural checks (spike ratio, abs-price floor, demand gate,
 * absurd-claim cap) are all derivable from the /deal payload alone - no
 * /product needed to reject the junk. So exp05:
 *   1. pulls one (or a few) /deal page(s) - 5 tokens/page, up to 150 deals.
 *   2. runs the glitch-guard as a PRE-FILTER on the deal payload only (no API
 *      calls): current, deltaPercent[90d], avg[dateRange][priceType] (carrying
 *      avg90 + ~avg30), salesRankDrops90 are all there (exp04 Finding 2).
 *   3. batches /product?stats=90 on the SURVIVORS only - the deep re-validation
 *      layer (the OOS guard `outOfStockPercentage90 > 80` + all-time-min floor
 *      need /product; exp04 Finding 2). ~1 token/survivor.
 *   4. logs the live token meter before/after each call (Discipline section).
 *   5. dumps raw responses to out/*.dump.json (gitignored).
 *
 * Question this answers (README row 05): survivors/page, total tokens for the
 * full pass, and throughput vs the 20/min refill - could this run sustainably?
 *
 * vs exp04: exp04 deep-looked-up the TOP 25 by claimed % (5 + 25 = 30 tokens,
 * 2 survivors). exp05 measures what the pre-filter actually saves: the /product
 * batch shrinks from "top 25" to "deal-stage survivors", so the per-pass cost is
 * 5 + (survivors) instead of 5 + 25. The divergence check (exp04 Finding 1) is
 * DROPPED - it added zero information.
 *
 * Cost: 5/page + 1/survivor tokens. Run: bun run 05-funnel-dryrun/run.ts
 */
import {
  get,
  logMeter,
  readMeter,
  defaultDomain,
  centsToEuro,
  statField,
  decodeExtremePoint,
  PriceType,
  DateRange,
  SortType,
} from "../lib/keepa";

const AMAZON = PriceType.AMAZON; // 0
const D90 = DateRange.NINETY; // 3
const D_MONTH = DateRange.MONTH; // 2 - deal.avg[MONTH] ≈ stats.avg30 (exp04)
const PAGES = Number(process.env.PAGES ?? 1); // a few pages max - token discipline
const MAX_SURVIVOR_BATCH = 100; // /product batch cap (exp03)
const DOMAIN = defaultDomain();

// --- Glitch-guard bounds (exp04 recipe; divergence check DROPPED) -------------
const GUARD = {
  ABS_PRICE_FLOOR: 200, // cents - sub-€2 items are cable/glitch noise
  MIN_VERIFIED_DROP: 0.2, // real drop must be >= 20% off the 90d avg
  MAX_CLAIMED_DROP: 97, // claimed% above this is almost always a glitch
  SPIKE_RATIO: 3.0, // avg90 > 3×avg30 ⇒ a price spike polluted the baseline
  FLOOR_RATIO: 0.5, // current < 0.5×all-time-min ⇒ implausible underprice glitch
  MAX_OOS90: 80, // outOfStockPercentage90 above this ⇒ thin/unreliable data
  MIN_RANK_DROPS90: 1, // demand gate: 0 sales-rank drops ⇒ nobody buys it
};

let pass = 0;
let fail = 0;
const ok = (cond: boolean, msg: string) => {
  console.log(`  ${cond ? "✓" : "✗"} ${msg}`);
  cond ? pass++ : fail++;
};

const pct = (x: number | null) => (x == null ? "  n/a" : `${(x * 100).toFixed(0).padStart(3)}%`);
const eur = (c: number | null | undefined) => (c == null || c < 0 ? "n/a" : centsToEuro(c));

// Deal `deltaPercent`/`avg` are 2D [dateRange][priceType] (exp02), NOT
// [priceType][dateRange]. `current` is 1D [priceType].
const dealNum = (v: any): number | null =>
  typeof v === "number" && v >= 0 ? v : null; // deal sentinels: -1/-2/0
const claimedDrop = (d: any): number | null => {
  const v = d?.deltaPercent?.[D90]?.[AMAZON];
  return typeof v === "number" && v > 0 ? v : null;
};

// =========================================================================
// STAGE 1 - /deal pages, sorted by PERCENT_DELTA (glitch-prone, same as exp02/04)
// =========================================================================
const dealSelection = (page: number) => ({
  page,
  domainId: DOMAIN,
  priceTypes: [AMAZON],
  dateRange: D90, // sort by the 90d % delta - matches our 90d guard comparison
  sortType: SortType.PERCENT_DELTA, // 4 - floats glitches to the top on purpose
  isFilterEnabled: false, // raw, unfiltered feed - we filter ourselves
});

interface DealStageResult {
  dealTokens: number;
  totalDeals: number;
  withLivePrice: number;
  preSurvivors: any[]; // deals that passed the deal-only pre-filter
  preReasonCounts: Record<string, number>;
}

// --- the deal-stage pre-filter: every check exp04 proved is derivable from the
// --- deal payload alone. Returns reject reasons (empty ⇒ pre-survivor).
function dealPreFilter(d: any): string[] {
  const cur = dealNum(d.current?.[AMAZON]);
  const avg90 = dealNum(d.avg?.[D90]?.[AMAZON]);
  const avg30 = dealNum(d.avg?.[D_MONTH]?.[AMAZON]); // ≈ stats.avg30 (exp04)
  const claimed = claimedDrop(d);
  const verified = cur != null && avg90 != null && avg90 > 0 ? (avg90 - cur) / avg90 : null;
  const rankDrops90 =
    typeof d.salesRankDrops90 === "number" ? d.salesRankDrops90 : null;

  const reasons: string[] = [];
  if (cur == null) reasons.push("no-live-price");
  if (cur != null && cur < GUARD.ABS_PRICE_FLOOR) reasons.push("abs-price-floor");
  if (avg90 != null && avg30 != null && avg30 > 0 && avg90 > GUARD.SPIKE_RATIO * avg30)
    reasons.push("spike-polluted-baseline");
  if (verified == null) reasons.push("unverifiable-drop");
  if (verified != null && verified < GUARD.MIN_VERIFIED_DROP) reasons.push("weak-real-drop");
  if (claimed != null && claimed > GUARD.MAX_CLAIMED_DROP) reasons.push("absurd-claim");
  // demand gate: deal salesRankDrops90 can read -1 where stats reads 0 (exp04) -
  // both still trip < 1, so the gate works on the deal source too.
  if (rankDrops90 != null && rankDrops90 < GUARD.MIN_RANK_DROPS90) reasons.push("no-demand");
  return reasons;
}

async function runDealStage(): Promise<DealStageResult> {
  let dealTokens = 0;
  let totalDeals = 0;
  let withLivePrice = 0;
  const preSurvivors: any[] = [];
  const preReasonCounts: Record<string, number> = {};

  for (let page = 0; page < PAGES; page++) {
    const before = readMeter(await get("token", {})).tokensLeft;
    console.log(`[tokens] before /deal page(${page}): left=${before}`);

    const dealResp = await get("deal", {
      selection: JSON.stringify(dealSelection(page)),
    });
    await Bun.write(
      `${import.meta.dir}/out/deal-page-${page}.dump.json`,
      JSON.stringify(dealResp, null, 2),
    );
    logMeter(`/deal page(${page})`, before, dealResp);
    dealTokens += dealResp.tokensConsumed ?? 0;

    const dr: any[] = (dealResp.deals ?? dealResp).dr ?? [];
    totalDeals += dr.length;
    console.log(`  deals.dr count: ${dr.length}`);

    for (const d of dr) {
      const cur = dealNum(d.current?.[AMAZON]);
      if (cur != null) withLivePrice++;
      const reasons = dealPreFilter(d);
      if (reasons.length === 0) {
        preSurvivors.push(d);
      } else {
        for (const r of reasons) preReasonCounts[r] = (preReasonCounts[r] ?? 0) + 1;
      }
    }
    if (dr.length < 150) {
      console.log(`  page ${page} returned < 150 - no more pages.`);
      break;
    }
  }

  return { dealTokens, totalDeals, withLivePrice, preSurvivors, preReasonCounts };
}

// =========================================================================
// STAGE 2 - /product?stats=90 on the deal-stage survivors ONLY (deep guard)
// =========================================================================
interface Verdict {
  asin: string;
  title: string;
  current: number | null;
  avg30: number | null;
  avg90: number | null;
  allTimeMin: number | null;
  claimed: number | null;
  verified: number | null;
  oos90: number | null;
  rankDrops90: number | null;
  reasons: string[]; // non-empty ⇒ REJECT at the deep stage
  score: number;
}

// The deep guard re-runs the structural checks on trustworthy stats AND adds the
// two checks that need /product: the OOS ceiling and the all-time-min floor.
function deepGuard(deal: any, product: any): Verdict {
  const stats = product?.stats;
  const sCur = statField(stats, "current", AMAZON);
  const avg30 = statField(stats, "avg30", AMAZON);
  const avg90 = statField(stats, "avg90", AMAZON);
  const allTimeMinPt = decodeExtremePoint(stats, "min", AMAZON);
  const allTimeMin = allTimeMinPt?.value ?? null;
  const oos90 = statField(stats, "outOfStockPercentage90", AMAZON);
  const rankDrops90 =
    typeof stats?.salesRankDrops90 === "number" ? stats.salesRankDrops90 : null;

  const claimed = claimedDrop(deal);
  const verified =
    sCur != null && avg90 != null && avg90 > 0 ? (avg90 - sCur) / avg90 : null;

  const reasons: string[] = [];
  if (sCur == null) reasons.push("no-live-price");
  if (sCur != null && sCur < GUARD.ABS_PRICE_FLOOR) reasons.push("abs-price-floor");
  if (avg90 != null && avg30 != null && avg30 > 0 && avg90 > GUARD.SPIKE_RATIO * avg30)
    reasons.push("spike-polluted-baseline");
  if (sCur != null && allTimeMin != null && sCur < GUARD.FLOOR_RATIO * allTimeMin)
    reasons.push("below-floor-glitch"); // needs /product
  if (verified == null) reasons.push("unverifiable-drop");
  if (verified != null && verified < GUARD.MIN_VERIFIED_DROP) reasons.push("weak-real-drop");
  if (claimed != null && claimed > GUARD.MAX_CLAIMED_DROP) reasons.push("absurd-claim");
  if (oos90 != null && oos90 > GUARD.MAX_OOS90) reasons.push("thin-data-oos"); // needs /product
  if (rankDrops90 != null && rankDrops90 < GUARD.MIN_RANK_DROPS90) reasons.push("no-demand");

  const demand = rankDrops90 != null ? Math.log1p(rankDrops90) : 0;
  const oosPenalty = oos90 != null ? 1 - oos90 / 100 : 1;
  const score = (verified ?? 0) * demand * oosPenalty;

  return {
    asin: deal.asin,
    title: String(deal.title ?? product?.title ?? "").slice(0, 38),
    current: sCur,
    avg30,
    avg90,
    allTimeMin,
    claimed,
    verified,
    oos90,
    rankDrops90,
    reasons,
    score,
  };
}

// =========================================================================
// RUN
// =========================================================================
console.log(`=== exp05 funnel dry run · domain ${DOMAIN} · ${PAGES} page(s) ===\n`);
const startBalance = readMeter(await get("token", {})).tokensLeft;

console.log("--- STAGE 1: /deal + glitch-guard pre-filter (deal payload only) ---");
const s1 = await runDealStage();

console.log(`\n=== Pre-filter outcome ===`);
console.log(`deals pulled        : ${s1.totalDeals} (${s1.withLivePrice} with a live AMAZON price)`);
console.log(`deal-stage survivors: ${s1.preSurvivors.length}`);
console.log("Pre-filter reject reasons (a deal can trip several):");
for (const [r, n] of Object.entries(s1.preReasonCounts).sort((a, b) => b[1] - a[1]))
  console.log(`  ${String(n).padStart(3)} × ${r}`);

// Deep-lookup the survivors only (batched, cap 100/call).
const survivorAsins = s1.preSurvivors.map((d) => d.asin);
console.log(`\n--- STAGE 2: /product?stats=90 on ${survivorAsins.length} deal-stage survivors ---`);

let productTokens = 0;
const products: any[] = [];
for (let i = 0; i < survivorAsins.length; i += MAX_SURVIVOR_BATCH) {
  const chunk = survivorAsins.slice(i, i + MAX_SURVIVOR_BATCH);
  const before = readMeter(await get("token", {})).tokensLeft;
  console.log(`[tokens] before /product batch(${chunk.length}): left=${before}`);
  const prodResp = await get("product", {
    domain: DOMAIN,
    asin: chunk.join(","),
    stats: 90,
  });
  await Bun.write(
    `${import.meta.dir}/out/product-batch-${i / MAX_SURVIVOR_BATCH}.dump.json`,
    JSON.stringify(prodResp, null, 2),
  );
  logMeter(`/product batch(${chunk.length})`, before, prodResp);
  productTokens += prodResp.tokensConsumed ?? 0;
  for (const p of prodResp.products ?? []) products.push(p);
}

const prodByAsin: Record<string, any> = {};
for (const p of products) prodByAsin[p.asin] = p;

const verdicts: Verdict[] = s1.preSurvivors.map((d) => deepGuard(d, prodByAsin[d.asin]));
const finalSurvivors = verdicts
  .filter((v) => v.reasons.length === 0)
  .sort((a, b) => b.score - a.score);
const deepRejected = verdicts.filter((v) => v.reasons.length > 0);

console.log(`\n=== Deep-stage outcome: ${finalSurvivors.length} KEEP / ${deepRejected.length} REJECT of ${verdicts.length} ===`);
const deepReasonCounts: Record<string, number> = {};
for (const v of deepRejected) for (const r of v.reasons) deepReasonCounts[r] = (deepReasonCounts[r] ?? 0) + 1;
console.log("Deep-stage reject reasons (only OOS + all-time-min need /product):");
for (const [r, n] of Object.entries(deepReasonCounts).sort((a, b) => b[1] - a[1]))
  console.log(`  ${String(n).padStart(3)} × ${r}`);

console.log("\n=== Final survivors by ranking score ===");
for (const v of finalSurvivors.slice(0, 15)) {
  console.log(
    `  ${v.asin}  score=${v.score.toFixed(3)}  real-drop=${pct(v.verified)} ` +
      `cur=${eur(v.current)} avg90=${eur(v.avg90)} oos90=${v.oos90 ?? "n/a"}% rnkΔ90=${v.rankDrops90}  ${v.title}`,
  );
}

// =========================================================================
// FUNNEL ECONOMICS - the headline metrics
// =========================================================================
const totalTokens = s1.dealTokens + productTokens;
const pagesPulled = Math.min(PAGES, Math.max(1, Math.ceil(s1.totalDeals / 150)));
const survivorsPerPage = s1.totalDeals > 0 ? (s1.preSurvivors.length / pagesPulled) : 0;
const tokensPerPage = totalTokens / pagesPulled;
// Sustainable cadence: at 20 tokens/min refill, a pass costing T tokens can run
// every T/20 minutes without draining the bucket.
const refillRate = 20; // /min (verified tier; confirmed below from the meter)
const minutesPerPass = totalTokens / refillRate;
const passesPerHour = minutesPerPass > 0 ? 60 / minutesPerPass : Infinity;

console.log("\n=== FUNNEL ECONOMICS ===");
console.log(`deal pages pulled        : ${pagesPulled}`);
console.log(`deal tokens              : ${s1.dealTokens} (${5} × ${pagesPulled} page(s))`);
console.log(`product tokens           : ${productTokens} (1 × ${survivorAsins.length} survivor(s))`);
console.log(`TOTAL tokens / pass      : ${totalTokens}`);
console.log(`survivors / page (deal)  : ${survivorsPerPage.toFixed(1)}`);
console.log(`final survivors / page   : ${(finalSurvivors.length / pagesPulled).toFixed(1)}`);
console.log(`tokens / page            : ${tokensPerPage.toFixed(1)}`);
console.log(`\nThroughput vs ${refillRate}/min refill:`);
console.log(`  cost ${totalTokens} tokens ÷ ${refillRate}/min = ${minutesPerPass.toFixed(2)} min of refill per pass`);
console.log(`  → sustainable cadence: ~1 pass every ${minutesPerPass.toFixed(1)} min (${passesPerHour.toFixed(0)} passes/hour)`);

// What exp04 would have spent on the same page (top-25 deep lookup, no pre-filter)
const exp04Cost = s1.dealTokens + Math.min(25, s1.withLivePrice) * pagesPulled;
const saved = exp04Cost - totalTokens;
console.log(`\nPre-filter savings vs exp04 "top-25 deep lookup":`);
console.log(`  exp04-style cost: ${exp04Cost} tokens; exp05 pre-filtered: ${totalTokens} tokens → saved ${saved} token(s)`);

// =========================================================================
// CHECKS - funnel invariants
// =========================================================================
console.log("\n=== Checks ===");
ok(s1.totalDeals > 0, `pulled at least one deal (${s1.totalDeals})`);
ok(s1.dealTokens === 5 * pagesPulled, `deal stage cost 5×pages = ${5 * pagesPulled} tokens (got ${s1.dealTokens})`);
ok(productTokens === survivorAsins.length, `product stage cost 1/survivor = ${survivorAsins.length} tokens (got ${productTokens})`);
ok(products.length === survivorAsins.length, `/product returned all survivors (${products.length}/${survivorAsins.length})`);
ok(verdicts.length === s1.preSurvivors.length, `every survivor got a deep verdict (${verdicts.length}/${s1.preSurvivors.length})`);
ok(
  s1.preSurvivors.length <= s1.withLivePrice,
  `pre-filter never grows the candidate set (${s1.preSurvivors.length} ≤ ${s1.withLivePrice})`,
);
ok(
  totalTokens <= 5 * pagesPulled + s1.withLivePrice,
  `total token cost ≤ deal + 1/live-price-deal upper bound (${totalTokens} ≤ ${5 * pagesPulled + s1.withLivePrice})`,
);
// Final-survivor invariants - what the production ranker relies on.
ok(
  finalSurvivors.every((v) => v.verified != null && v.verified >= GUARD.MIN_VERIFIED_DROP),
  `every final survivor has a verified drop ≥ ${GUARD.MIN_VERIFIED_DROP * 100}%`,
);
ok(
  finalSurvivors.every((v) => v.current != null && v.current >= GUARD.ABS_PRICE_FLOOR),
  `every final survivor priced ≥ ${centsToEuro(GUARD.ABS_PRICE_FLOOR)}`,
);
ok(
  finalSurvivors.every((v) => v.oos90 == null || v.oos90 <= GUARD.MAX_OOS90),
  `no final survivor exceeds the OOS ceiling (${GUARD.MAX_OOS90}%)`,
);
ok(
  finalSurvivors.every((v) => v.rankDrops90 != null && v.rankDrops90 >= GUARD.MIN_RANK_DROPS90),
  `every final survivor passes the demand gate (salesRankDrops90 ≥ ${GUARD.MIN_RANK_DROPS90})`,
);

console.log(`\n=== RESULT: ${fail === 0 ? "PASS" : "FAIL"} (${pass} ✓ / ${fail} ✗) ===`);
console.log(`Final balance: ${readMeter(await get("token", {})).tokensLeft} (started ${startBalance})`);
console.log("Dumps: 05-funnel-dryrun/out/deal-page-*.dump.json, out/product-batch-*.dump.json");
