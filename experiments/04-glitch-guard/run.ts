/**
 * Experiment 04 — glitch-guard: separate real deals from price-glitch artifacts.
 *
 * Chains the two cheap, now-verified endpoints:
 *   1. one /deal page sorted by PERCENT_DELTA (sortType 4) — the glitchy feed
 *      exp02 flagged: it floats price-glitch artifacts (polluted-baseline % drops)
 *      to the top. 5 tokens.
 *   2. /product?stats=90 on the top-N candidates — stats is trustworthy (exp03),
 *      so we re-derive the *real* drop from stats and compare it to the deal's
 *      *claimed* drop. ~1 token/ASIN.
 *
 * Deliverable: a concrete, documented glitch-guard recipe (filter bounds +
 * ranking) the production ranker will port to PHP. We tune the bounds against the
 * sampled page and report precision/recall qualitatively in FINDINGS.md.
 *
 * Core idea — two drops, one divergence:
 *   claimed drop  = deal.deltaPercent[90d][AMAZON]  (headline; baseline may be a
 *                   polluted weighted average → the glitch)
 *   verified drop = (stats.avg90 - stats.current)/stats.avg90  (from trustworthy
 *                   /product stats)
 * A glitch shows claimed >> verified. We also spike-test the stats baseline
 * itself (avg90 vs avg30) and floor-test current vs the all-time min, so a
 * polluted *stats* avg can't sneak a fake "verified" drop through.
 *
 * Array-shape landmines (exp02): deal `deltaPercent`/`delta`/`avg` are 2D
 * [dateRange][priceType]; `current` is 1D [priceType].
 *
 * Cost: 5 (deal page) + N (product batch) tokens. Run: bun run 04-glitch-guard/run.ts
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
const N_CANDIDATES = 25; // top-N off the glitchy feed (respects 1200 cap / 20-min refill)
const DOMAIN = defaultDomain();

// --- Glitch-guard bounds (the recipe; tuned against the sampled page) ---------
const GUARD = {
  ABS_PRICE_FLOOR: 200, // cents — sub-€2 items are cable/glitch noise
  MIN_VERIFIED_DROP: 0.2, // real drop must be >= 20% off the 90d avg
  MAX_CLAIM_DIVERGENCE: 25, // claimed% - verified% > this ⇒ polluted baseline
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
const eur = (c: number | null | undefined) =>
  c == null || c < 0 ? "n/a" : centsToEuro(c);

// Deal `deltaPercent`/`delta`/`avg` are 2D [dateRange][priceType] (exp02), NOT
// [priceType][dateRange]. `current` is 1D [priceType].
const claimedDrop = (d: any): number | null => {
  const v = d?.deltaPercent?.[D90]?.[AMAZON];
  return typeof v === "number" && v > 0 ? v : null; // deals: -2/0 sentinels
};

// =========================================================================
// CALL 1 — fresh /deal page, sorted by PERCENT_DELTA (glitch-prone)
// =========================================================================
const selection = {
  page: 0,
  domainId: DOMAIN,
  priceTypes: [AMAZON],
  dateRange: D90, // sort by the 90d % delta — matches our 90d stats comparison
  sortType: SortType.PERCENT_DELTA, // 4 — floats glitches to the top on purpose
  isFilterEnabled: false, // raw, unfiltered feed — we filter ourselves
};

const before1 = readMeter(await get("token", {})).tokensLeft;
console.log(`[tokens] before deal page: left=${before1}`);

const dealResp = await get("deal", { selection: JSON.stringify(selection) });
await Bun.write(
  `${import.meta.dir}/out/deal-page.dump.json`,
  JSON.stringify(dealResp, null, 2),
);
console.log("=== meter after deal page ===");
logMeter("/deal page(0)", before1, dealResp);

const dealsRoot = dealResp.deals ?? dealResp;
const dr: any[] = dealsRoot.dr ?? [];
console.log(`deals.dr count : ${dr.length}`);

// Take the top-N candidates that actually carry a live AMAZON price.
const candidates = dr
  .filter((d) => typeof d.current?.[AMAZON] === "number" && d.current[AMAZON] >= 0)
  .slice(0, N_CANDIDATES);
const asins = candidates.map((d) => d.asin);
console.log(`candidates (with live AMAZON price): ${candidates.length}`);

// =========================================================================
// CALL 2 — /product?stats=90 batch on the candidates (trustworthy stats)
// =========================================================================
const before2 = readMeter(await get("token", {})).tokensLeft;
console.log(`\n[tokens] before product batch: left=${before2}`);

const prodResp = await get("product", {
  domain: DOMAIN,
  asin: asins.join(","),
  stats: 90,
});
await Bun.write(
  `${import.meta.dir}/out/product-batch.dump.json`,
  JSON.stringify(prodResp, null, 2),
);
console.log("=== meter after product batch ===");
logMeter(`/product batch(${asins.length})`, before2, prodResp);

const products: any[] = prodResp.products ?? [];
const prodByAsin: Record<string, any> = {};
for (const p of products) prodByAsin[p.asin] = p;

// =========================================================================
// THE GLITCH-GUARD RECIPE
// =========================================================================
interface Verdict {
  asin: string;
  title: string;
  current: number | null; // trustworthy stats.current[AMAZON]
  dealCurrent: number | null; // deal's claimed current price
  avg30: number | null;
  avg90: number | null;
  allTimeMin: number | null;
  claimed: number | null; // claimed drop % (deal headline)
  verified: number | null; // verified drop fraction (from stats)
  divergence: number | null; // claimed% - verified%
  oos90: number | null;
  rankDrops90: number | null;
  reasons: string[]; // non-empty ⇒ REJECT
  score: number; // ranking score for survivors
}

function glitchGuard(deal: any, product: any): Verdict {
  const stats = product?.stats;
  const sCur = statField(stats, "current", AMAZON);
  const avg30 = statField(stats, "avg30", AMAZON);
  const avg90 = statField(stats, "avg90", AMAZON);
  // `minInInterval`/`maxInInterval` are the 90d-window extremes; `min`/`max` are
  // all-time (exp03 saw a max dated >90d back). Use all-time min as the hard floor.
  const allTimeMinPt = decodeExtremePoint(stats, "min", AMAZON);
  const allTimeMin = allTimeMinPt?.value ?? null;
  const oos90 = statField(stats, "outOfStockPercentage90", AMAZON);
  const rankDrops90 =
    typeof stats?.salesRankDrops90 === "number" ? stats.salesRankDrops90 : null;

  const claimed = claimedDrop(deal);
  const verified =
    sCur != null && avg90 != null && avg90 > 0 ? (avg90 - sCur) / avg90 : null;
  const divergence =
    claimed != null && verified != null ? claimed - verified * 100 : null;

  const reasons: string[] = [];
  if (sCur == null) reasons.push("no-live-price");
  if (sCur != null && sCur < GUARD.ABS_PRICE_FLOOR) reasons.push("abs-price-floor");
  if (avg90 != null && avg30 != null && avg30 > 0 && avg90 > GUARD.SPIKE_RATIO * avg30)
    reasons.push("spike-polluted-baseline");
  if (sCur != null && allTimeMin != null && sCur < GUARD.FLOOR_RATIO * allTimeMin)
    reasons.push("below-floor-glitch");
  if (verified == null) reasons.push("unverifiable-drop");
  if (verified != null && verified < GUARD.MIN_VERIFIED_DROP) reasons.push("weak-real-drop");
  if (divergence != null && divergence > GUARD.MAX_CLAIM_DIVERGENCE)
    reasons.push("claim-divergence");
  if (claimed != null && claimed > GUARD.MAX_CLAIMED_DROP) reasons.push("absurd-claim");
  if (oos90 != null && oos90 > GUARD.MAX_OOS90) reasons.push("thin-data-oos");
  if (rankDrops90 != null && rankDrops90 < GUARD.MIN_RANK_DROPS90)
    reasons.push("no-demand");

  // Ranking score (survivors only): real drop × demand, penalized by OOS.
  const demand = rankDrops90 != null ? Math.log1p(rankDrops90) : 0;
  const oosPenalty = oos90 != null ? 1 - oos90 / 100 : 1;
  const score = (verified ?? 0) * demand * oosPenalty;

  return {
    asin: deal.asin,
    title: String(deal.title ?? product?.title ?? "").slice(0, 38),
    current: sCur,
    dealCurrent: typeof deal.current?.[AMAZON] === "number" ? deal.current[AMAZON] : null,
    avg30,
    avg90,
    allTimeMin,
    claimed,
    verified,
    divergence,
    oos90,
    rankDrops90,
    reasons,
    score,
  };
}

const verdicts: Verdict[] = candidates.map((d) =>
  glitchGuard(d, prodByAsin[d.asin]),
);

// =========================================================================
// REPORT
// =========================================================================
console.log("\n=== Per-candidate signals (in glitchy-feed order) ===");
console.log(
  "  " +
    ["asin".padEnd(10), "claim", "verif", " div", "   current", "     avg90", "     avg30", " oos", " rnkΔ", "verdict"].join(
      "  ",
    ),
);
for (const v of verdicts) {
  const verdict = v.reasons.length ? `REJECT ${v.reasons[0]}` : "KEEP";
  console.log(
    "  " +
      [
        v.asin.padEnd(10),
        (v.claimed != null ? `${v.claimed}%` : "n/a").padStart(5),
        pct(v.verified),
        (v.divergence != null ? `${v.divergence.toFixed(0)}` : "n/a").padStart(4),
        eur(v.current).padStart(10),
        eur(v.avg90).padStart(10),
        eur(v.avg30).padStart(10),
        (v.oos90 != null ? `${v.oos90}%` : "n/a").padStart(4),
        String(v.rankDrops90 ?? "n/a").padStart(5),
        verdict,
      ].join("  "),
  );
}

const survivors = verdicts.filter((v) => v.reasons.length === 0).sort((a, b) => b.score - a.score);
const rejected = verdicts.filter((v) => v.reasons.length > 0);

console.log(`\n=== Filter outcome: ${survivors.length} KEEP / ${rejected.length} REJECT of ${verdicts.length} ===`);
const reasonCounts: Record<string, number> = {};
for (const v of rejected) for (const r of v.reasons) reasonCounts[r] = (reasonCounts[r] ?? 0) + 1;
console.log("Reject reasons (a deal can trip several):");
for (const [r, n] of Object.entries(reasonCounts).sort((a, b) => b[1] - a[1]))
  console.log(`  ${String(n).padStart(3)} × ${r}`);

console.log("\n=== Top survivors by ranking score ===");
for (const v of survivors.slice(0, 10)) {
  console.log(
    `  ${v.asin}  score=${v.score.toFixed(3)}  real-drop=${pct(v.verified)} ` +
      `cur=${eur(v.current)} avg90=${eur(v.avg90)} rnkΔ90=${v.rankDrops90}  ${v.title}`,
  );
}

console.log("\n=== Clearest glitches caught (highest claimed-vs-verified divergence) ===");
const byDivergence = [...rejected]
  .filter((v) => v.divergence != null)
  .sort((a, b) => (b.divergence ?? 0) - (a.divergence ?? 0))
  .slice(0, 5);
for (const v of byDivergence) {
  console.log(
    `  ${v.asin}  claimed=${v.claimed}% but verified=${pct(v.verified)} ` +
      `(cur=${eur(v.current)} vs avg90=${eur(v.avg90)}, avg30=${eur(v.avg30)})  → ${v.reasons.join(",")}`,
  );
}

// =========================================================================
// VERIFICATION — recipe invariants (the bounds must actually hold on survivors)
// =========================================================================
console.log("\n=== Checks ===");
ok(verdicts.length === candidates.length, `every candidate got a verdict (${verdicts.length}/${candidates.length})`);
ok(products.length === asins.length, `/product returned all candidates (${products.length}/${asins.length})`);
ok(dealResp.tokensConsumed === 5, `deal page cost 5 tokens (got ${dealResp.tokensConsumed})`);
ok(prodResp.tokensConsumed === asins.length, `product batch cost ${asins.length} tokens (got ${prodResp.tokensConsumed})`);

// Survivor invariants — these are what the production ranker relies on.
ok(
  survivors.every((v) => v.verified != null && v.verified >= GUARD.MIN_VERIFIED_DROP),
  `every survivor has a verified drop ≥ ${GUARD.MIN_VERIFIED_DROP * 100}%`,
);
ok(
  survivors.every((v) => v.current != null && v.current >= GUARD.ABS_PRICE_FLOOR),
  `every survivor priced ≥ ${centsToEuro(GUARD.ABS_PRICE_FLOOR)}`,
);
ok(
  survivors.every((v) => v.divergence == null || v.divergence <= GUARD.MAX_CLAIM_DIVERGENCE),
  `every survivor's claim divergence ≤ ${GUARD.MAX_CLAIM_DIVERGENCE} pts`,
);
ok(
  survivors.every((v) => v.rankDrops90 != null && v.rankDrops90 >= GUARD.MIN_RANK_DROPS90),
  `every survivor passes the demand gate (salesRankDrops90 ≥ ${GUARD.MIN_RANK_DROPS90})`,
);
ok(
  survivors.every(
    (v) =>
      !(v.avg90 != null && v.avg30 != null && v.avg30 > 0 && v.avg90 > GUARD.SPIKE_RATIO * v.avg30),
  ),
  "no survivor has a spike-polluted baseline (avg90 ≤ 3×avg30)",
);
ok(
  survivors.every((v) => v.oos90 == null || v.oos90 <= GUARD.MAX_OOS90),
  `no survivor exceeds the OOS ceiling (${GUARD.MAX_OOS90}%)`,
);

// The glitchy feed should yield at least one reject — else the guard is inert here.
ok(rejected.length > 0, "guard rejected at least one candidate from the sortType=4 feed");

console.log(`\n=== RESULT: ${fail === 0 ? "PASS" : "FAIL"} (${pass} ✓ / ${fail} ✗) ===`);
console.log("Dumps: 04-glitch-guard/out/deal-page.dump.json, out/product-batch.dump.json");
