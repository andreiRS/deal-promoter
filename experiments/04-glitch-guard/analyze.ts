/**
 * Offline re-analysis of exp04 dumps (no API calls) — recomputes the glitch-guard
 * verdict table from out/*.dump.json so findings rest on real captured values.
 * Run: bun run 04-glitch-guard/analyze.ts
 */
import { statField, decodeExtremePoint, PriceType, DateRange, centsToEuro } from "../lib/keepa";

const AMAZON = PriceType.AMAZON;
const D90 = DateRange.NINETY;
const GUARD = {
  ABS_PRICE_FLOOR: 200, MIN_VERIFIED_DROP: 0.2, MAX_CLAIM_DIVERGENCE: 25,
  MAX_CLAIMED_DROP: 97, SPIKE_RATIO: 3.0, FLOOR_RATIO: 0.5, MAX_OOS90: 80, MIN_RANK_DROPS90: 1,
};

const dir = import.meta.dir;
const deal = JSON.parse(await Bun.file(`${dir}/out/deal-page.dump.json`).text());
const prod = JSON.parse(await Bun.file(`${dir}/out/product-batch.dump.json`).text());
const dr: any[] = (deal.deals ?? deal).dr ?? [];
const prods: any[] = prod.products ?? [];
const byAsin: Record<string, any> = {};
for (const p of prods) byAsin[p.asin] = p;

const claimed = (d: any) => { const v = d?.deltaPercent?.[D90]?.[AMAZON]; return typeof v === "number" && v > 0 ? v : null; };
const cand = dr.filter((d) => typeof d.current?.[AMAZON] === "number" && d.current[AMAZON] >= 0).slice(0, 25);

const rows = cand.map((d) => {
  const s = byAsin[d.asin]?.stats;
  const cur = statField(s, "current", AMAZON);
  const a30 = statField(s, "avg30", AMAZON);
  const a90 = statField(s, "avg90", AMAZON);
  const minPt = decodeExtremePoint(s, "min", AMAZON);
  const oos = statField(s, "outOfStockPercentage90", AMAZON);
  const rd = typeof s?.salesRankDrops90 === "number" ? s.salesRankDrops90 : null;
  const cl = claimed(d);
  const ver = cur != null && a90 != null && a90 > 0 ? (a90 - cur) / a90 : null;
  const div = cl != null && ver != null ? cl - ver * 100 : null;
  const r: string[] = [];
  if (cur == null) r.push("no-live-price");
  if (cur != null && cur < GUARD.ABS_PRICE_FLOOR) r.push("abs-price-floor");
  if (a90 != null && a30 != null && a30 > 0 && a90 > GUARD.SPIKE_RATIO * a30) r.push("spike-polluted-baseline");
  if (cur != null && minPt && cur < GUARD.FLOOR_RATIO * minPt.value) r.push("below-floor-glitch");
  if (ver == null) r.push("unverifiable-drop");
  if (ver != null && ver < GUARD.MIN_VERIFIED_DROP) r.push("weak-real-drop");
  if (div != null && div > GUARD.MAX_CLAIM_DIVERGENCE) r.push("claim-divergence");
  if (cl != null && cl > GUARD.MAX_CLAIMED_DROP) r.push("absurd-claim");
  if (oos != null && oos > GUARD.MAX_OOS90) r.push("thin-data-oos");
  if (rd != null && rd < GUARD.MIN_RANK_DROPS90) r.push("no-demand");
  const demand = rd != null ? Math.log1p(rd) : 0;
  const score = (ver ?? 0) * demand * (oos != null ? 1 - oos / 100 : 1);
  return { asin: d.asin, title: String(d.title ?? "").slice(0, 44), claimed: cl, verified: ver, div,
    cur, a90, a30, min: minPt?.value ?? null, oos, rd, reasons: r, keep: r.length === 0, score };
});

const e = (c: number | null) => (c == null ? "n/a" : centsToEuro(c));
const p = (x: number | null) => (x == null ? "n/a" : `${Math.round(x * 100)}%`);
console.log("ASIN | claimed | verified | div | cur | avg90 | avg30 | min | oos | rnkD90 | verdict | title");
for (const x of rows) {
  console.log([x.asin, x.claimed != null ? x.claimed + "%" : "n/a", p(x.verified),
    x.div != null ? Math.round(x.div) : "n/a", e(x.cur), e(x.a90), e(x.a30), e(x.min),
    x.oos != null ? x.oos + "%" : "n/a", x.rd ?? "n/a", x.keep ? "KEEP" : "REJECT:" + x.reasons.join("+"),
    x.title].join(" | "));
}
const keep = rows.filter((x) => x.keep).sort((a, b) => b.score - a.score);
console.log(`\nKEEP=${keep.length} REJECT=${rows.length - keep.length}`);
console.log("SURVIVORS (by score):");
for (const x of keep) console.log(`  ${x.asin} score=${x.score.toFixed(3)} drop=${p(x.verified)} cur=${e(x.cur)} avg90=${e(x.a90)} rnkD90=${x.rd} | ${x.title}`);
