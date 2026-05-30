/**
 * Offline re-analysis of exp05 dumps (no API calls) - recomputes the two-stage
 * funnel from out/*.dump.json so the findings rest on real captured values, and
 * the bounds can be re-tuned without re-spending tokens.
 *
 * Stage 1 = deal-payload-only pre-filter; Stage 2 = deep /product?stats guard on
 * the survivors. It also re-checks the two exp04 claims exp05 sharpens: whether
 * deal.avg* is bit-identical to stats.* (it is not - they round differently) and
 * whether verified drop == claimed drop (it does - the divergence check is dead).
 * Run: bun run 05-funnel-dryrun/analyze.ts
 */
import { statField, decodeExtremePoint, PriceType, DateRange, centsToEuro } from "../lib/keepa";

const A = PriceType.AMAZON;
const D90 = DateRange.NINETY;
const DM = DateRange.MONTH; // deal.avg[MONTH] ~ stats.avg30 (only approximately)
const GUARD = {
  ABS_PRICE_FLOOR: 200, MIN_VERIFIED_DROP: 0.2, MAX_CLAIMED_DROP: 97,
  SPIKE_RATIO: 3.0, FLOOR_RATIO: 0.5, MAX_OOS90: 80, MIN_RANK_DROPS90: 1,
};

const dir = import.meta.dir;
const deal = JSON.parse(await Bun.file(`${dir}/out/deal-page-0.dump.json`).text());
const dr: any[] = (deal.deals ?? deal).dr ?? [];

// stitch every product batch back together
const byAsin: Record<string, any> = {};
let bi = 0;
while (await Bun.file(`${dir}/out/product-batch-${bi}.dump.json`).exists()) {
  const p = JSON.parse(await Bun.file(`${dir}/out/product-batch-${bi}.dump.json`).text());
  for (const prod of p.products ?? []) byAsin[prod.asin] = prod;
  bi++;
}

const dealNum = (v: any) => (typeof v === "number" && v >= 0 ? v : null);
const claimed = (d: any) => { const v = d?.deltaPercent?.[D90]?.[A]; return typeof v === "number" && v > 0 ? v : null; };
const dealAvg = (d: any, range: number) => {
  const a = d.avg; if (!Array.isArray(a) || a.length <= range) return null;
  const row = a[range]; if (!Array.isArray(row) || row.length <= A) return null;
  return dealNum(row[A]);
};

// ---- Stage 1: deal-only pre-filter --------------------------------------
function preReasons(d: any): string[] {
  const cur = dealNum(d.current?.[A]);
  const a90 = dealAvg(d, D90);
  const a30 = dealAvg(d, DM);
  const cl = claimed(d);
  const ver = cur != null && a90 != null && a90 > 0 ? (a90 - cur) / a90 : null;
  const rd = typeof d.salesRankDrops90 === "number" ? d.salesRankDrops90 : null;
  const r: string[] = [];
  if (cur == null) r.push("no-live-price");
  if (cur != null && cur < GUARD.ABS_PRICE_FLOOR) r.push("abs-price-floor");
  if (a90 != null && a30 != null && a30 > 0 && a90 > GUARD.SPIKE_RATIO * a30) r.push("spike-polluted-baseline");
  if (ver == null) r.push("unverifiable-drop");
  if (ver != null && ver < GUARD.MIN_VERIFIED_DROP) r.push("weak-real-drop");
  if (cl != null && cl > GUARD.MAX_CLAIMED_DROP) r.push("absurd-claim");
  if (rd != null && rd < GUARD.MIN_RANK_DROPS90) r.push("no-demand");
  return r;
}

const preCounts: Record<string, number> = {};
const preSurvivors = dr.filter((d) => {
  const r = preReasons(d);
  if (r.length) { for (const x of r) preCounts[x] = (preCounts[x] ?? 0) + 1; return false; }
  return true;
});

// ---- Stage 2: deep /product guard on survivors --------------------------
const e = (c: number | null) => (c == null ? "n/a" : centsToEuro(c));
const p = (x: number | null) => (x == null ? "n/a" : `${Math.round(x * 100)}%`);

const rows = preSurvivors.map((d) => {
  const s = byAsin[d.asin]?.stats;
  const cur = statField(s, "current", A);
  const a30 = statField(s, "avg30", A);
  const a90 = statField(s, "avg90", A);
  const minPt = decodeExtremePoint(s, "min", A);
  const oos = statField(s, "outOfStockPercentage90", A);
  const rd = typeof s?.salesRankDrops90 === "number" ? s.salesRankDrops90 : null;
  const cl = claimed(d);
  const ver = cur != null && a90 != null && a90 > 0 ? (a90 - cur) / a90 : null;
  const r: string[] = [];
  if (cur == null) r.push("no-live-price");
  if (cur != null && cur < GUARD.ABS_PRICE_FLOOR) r.push("abs-price-floor");
  if (a90 != null && a30 != null && a30 > 0 && a90 > GUARD.SPIKE_RATIO * a30) r.push("spike-polluted-baseline");
  if (cur != null && minPt && cur < GUARD.FLOOR_RATIO * minPt.value) r.push("below-floor-glitch");
  if (ver == null) r.push("unverifiable-drop");
  if (ver != null && ver < GUARD.MIN_VERIFIED_DROP) r.push("weak-real-drop");
  if (cl != null && cl > GUARD.MAX_CLAIMED_DROP) r.push("absurd-claim");
  if (oos != null && oos > GUARD.MAX_OOS90) r.push("thin-data-oos");
  if (rd != null && rd < GUARD.MIN_RANK_DROPS90) r.push("no-demand");
  const demand = rd != null ? Math.log1p(rd) : 0;
  const score = (ver ?? 0) * demand * (oos != null ? 1 - oos / 100 : 1);
  return { asin: d.asin, title: String(d.title ?? "").slice(0, 40), claimed: cl, verified: ver,
    cur, a90, a30, dealA90: dealAvg(d, D90), dealA30: dealAvg(d, DM),
    min: minPt?.value ?? null, oos, rd, reasons: r, keep: r.length === 0, score };
});

console.log(`Stage 1 (deal-only pre-filter): ${dr.length} deals -> ${preSurvivors.length} survivors`);
console.log("Pre-filter reject reasons:");
for (const [r, n] of Object.entries(preCounts).sort((a, b) => b[1] - a[1])) console.log(`  ${String(n).padStart(3)} x ${r}`);

console.log("\nStage 2 (deep /product guard on survivors):");
console.log("ASIN | claimed | verified | cur | stats.avg90 | stats.avg30 | min | oos | rnkD90 | verdict | title");
for (const x of rows) {
  console.log([x.asin, x.claimed != null ? x.claimed + "%" : "n/a", p(x.verified), e(x.cur), e(x.a90), e(x.a30),
    e(x.min), x.oos != null ? x.oos + "%" : "n/a", x.rd ?? "n/a",
    x.keep ? "KEEP" : "REJECT:" + x.reasons.join("+"), x.title].join(" | "));
}

// exp04 said deal.avg[90]==stats.avg90 exactly; exp05 checks the equality + divergence.
console.log("\nDeal-payload vs /product-stats agreement (the exp04 claims, re-checked):");
let exactA90 = 0, divZero = 0;
for (const x of rows) {
  const dealRatio = x.dealA90 && x.dealA30 ? (x.dealA90 / x.dealA30).toFixed(2) : "n/a";
  const statRatio = x.a90 && x.a30 ? (x.a90 / x.a30).toFixed(2) : "n/a";
  const a90Exact = x.dealA90 === x.a90;
  const a90Cent = x.dealA90 != null && x.a90 != null ? Math.abs(x.dealA90 - x.a90) <= 1 : false;
  if (a90Exact) exactA90++;
  // verified (from stats) vs claimed (from deal) - same rounded percent?
  const verPct = x.verified != null ? Math.round(x.verified * 100) : null;
  const sameDrop = verPct != null && x.claimed != null && verPct === x.claimed;
  if (sameDrop) divZero++;
  const straddle = (Number(statRatio) > GUARD.SPIKE_RATIO) !== (Number(dealRatio) > GUARD.SPIKE_RATIO);
  console.log(`  ${x.asin}: deal.avg90=${e(x.dealA90)} stats.avg90=${e(x.a90)} ` +
    `(exact=${a90Exact} within1c=${a90Cent}) | spike-ratio deal=${dealRatio} stats=${statRatio}` +
    `${straddle ? "  <-- STRADDLES THRESHOLD" : ""} | verified=${p(x.verified)} claimed=${x.claimed}% (same=${sameDrop})`);
}
console.log(`  -> deal.avg90 bit-exact to stats.avg90: ${exactA90}/${rows.length}; verified==claimed: ${divZero}/${rows.length}`);

const keep = rows.filter((x) => x.keep).sort((a, b) => b.score - a.score);
console.log(`\nFINAL: ${keep.length} KEEP / ${rows.length - keep.length} deep-REJECT of ${rows.length} pre-survivors`);
for (const x of keep) console.log(`  ${x.asin} score=${x.score.toFixed(3)} drop=${p(x.verified)} cur=${e(x.cur)} avg90=${e(x.a90)} oos90=${x.oos ?? "n/a"}% rnkD90=${x.rd} | ${x.title}`);
