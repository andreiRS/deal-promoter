/**
 * Experiment 03 — /product + stats (request shape, stats object, csv/Keepa-time
 * decoding) against live amazon.de ASINs.
 *
 * Confirms:
 *   - the /product query shape: domain + comma-joined asin + stats=90 (no offers)
 *   - the real token cost (brief/README guess "~1/ASIN")
 *   - the `stats` object: type-indexed current/avg/avg30/90/180/365, 2D extremes
 *     (min/max), outOfStockPercentage*, scalar salesRankDrops*
 *   - csv indexing (AMAZON/NEW/USED/SALES_RANK/RATING/COUNT_REVIEWS)
 *   - Keepa-time on /product timestamps + csv points
 *   - the -1 sentinel on /product csv (deals used -2/0)
 *   - RATING ÷10 scaling, productType, parentAsin/variations
 *
 * stats=90 because 90d is the production window; stats also carries
 * avg30/90/180/365 + outOfStockPercentage* regardless. No extra token.
 *
 * Cost: 3 tokens (batch of 3) + 1 token (parent). Run: bun run 03-product/run.ts
 */
import {
  get,
  logMeter,
  readMeter,
  defaultDomain,
  keepaMinuteToDate,
  lastPoint,
  statField,
  decodeExtremePoint,
  formatStatValue,
  CsvType,
} from "../lib/keepa";

const SEEDS = ["B0BFRDGFP6", "B0BX45BQ1B", "B0F14YX5TG"]; // raclette child + 2 others
const PARENT = "B0CGMFY7MV"; // productType=5 parent w/ variations
const CHILD = "B0BFRDGFP6"; // expected child of PARENT
const STATS = 90;

let pass = 0;
let fail = 0;
const ok = (cond: boolean, msg: string) => {
  console.log(`  ${cond ? "✓" : "✗"} ${msg}`);
  cond ? pass++ : fail++;
};

const inRange = (d: Date) => {
  const t = d.getTime();
  return t >= Date.UTC(2010, 0, 1) && t <= Date.now() + 86_400_000;
};

// =========================================================================
// CALL 1 — primary batch (3 seeds), stats=90
// =========================================================================
const before1 = readMeter(await get("token", {})).tokensLeft;
console.log(`[tokens] before primary batch: left=${before1}`);

const batch = await get("product", {
  domain: defaultDomain(),
  asin: SEEDS.join(","),
  stats: STATS,
});

await Bun.write(
  `${import.meta.dir}/out/batch-seeds.dump.json`,
  JSON.stringify(batch, null, 2),
);

console.log("=== meter after primary batch ===");
logMeter("/product batch(3)", before1, batch);

const products: any[] = batch.products ?? [];
console.log(`top-level keys : ${Object.keys(batch).join(", ")}`);
console.log(`products       : ${products.length}`);

// =========================================================================
// CALL 2 — parent alone (productType=5 + variations)
// =========================================================================
const before2 = readMeter(await get("token", {})).tokensLeft;
console.log(`\n[tokens] before parent: left=${before2}`);

const parentResp = await get("product", {
  domain: defaultDomain(),
  asin: PARENT,
  stats: STATS,
});

await Bun.write(
  `${import.meta.dir}/out/parent-${PARENT}.dump.json`,
  JSON.stringify(parentResp, null, 2),
);

console.log("=== meter after parent ===");
logMeter("/product parent(1)", before2, parentResp);

const parent = (parentResp.products ?? [])[0];

// =========================================================================
// VERIFICATION CHECKLIST (dumps already written above)
// =========================================================================
const byAsin: Record<string, any> = {};
for (const p of products) byAsin[p.asin] = p;

console.log("\n=== Check 1: productType per ASIN ===");
for (const p of products) {
  console.log(`  ${p.asin}: productType=${p.productType} title=${String(p.title ?? "").slice(0, 50)}`);
}
ok(byAsin[CHILD]?.productType === 0, `child ${CHILD} productType === 0 (standard)`);
ok(parent?.productType === 5, `parent ${PARENT} productType === 5 (variation parent)`);
const parentVars: any[] = parent?.variations ?? [];
ok(Array.isArray(parentVars) && parentVars.length > 0, `parent has variations[] (${parentVars.length})`);

console.log("\n=== Check 2: parentAsin / variations cross-link ===");
const childObj = byAsin[CHILD];
console.log(`  ${CHILD}.parentAsin = ${childObj?.parentAsin}`);
ok(childObj?.parentAsin === PARENT, `${CHILD}.parentAsin === ${PARENT}`);
const varAsins = parentVars.map((v: any) => v.asin);
console.log(`  parent variations asins: ${varAsins.join(", ").slice(0, 120)}`);
ok(varAsins.includes(CHILD), `parent.variations lists child ${CHILD}`);

console.log("\n=== Check 3: csv indexing (lastPoint via formatStatValue) ===");
const csvCheckTypes = [CsvType.AMAZON, CsvType.NEW, CsvType.USED, CsvType.SALES_RANK, CsvType.RATING, CsvType.COUNT_REVIEWS];
const names: Record<number, string> = {
  0: "AMAZON", 1: "NEW", 2: "USED", 3: "SALES_RANK", 16: "RATING", 17: "COUNT_REVIEWS",
};
const refCsv = childObj?.csv ?? [];
for (const t of csvCheckTypes) {
  const lp = lastPoint(refCsv[t]);
  console.log(`  csv[${t}] ${names[t].padEnd(13)}: ${lp ? formatStatValue(t, lp.value) : "n/a"}${lp ? ` @ ${lp.at.toISOString().slice(0, 10)}` : ""}`);
}
const amzLp = lastPoint(refCsv[CsvType.AMAZON]);
const newLp = lastPoint(refCsv[CsvType.NEW]);
const rankLp = lastPoint(refCsv[CsvType.SALES_RANK]);
const ratingLp = lastPoint(refCsv[CsvType.RATING]);
ok(amzLp == null || (amzLp.value > 0 && amzLp.value < 1_000_000), "AMAZON last price plausible (< €10,000)");
ok(newLp == null || (newLp.value > 0 && newLp.value < 1_000_000), "NEW last price plausible");
ok(rankLp == null || rankLp.value > 100, "SALES_RANK is a large int");
ok(ratingLp == null || (ratingLp.value >= 0 && ratingLp.value <= 50), "RATING ∈ [0,50]");

console.log("\n=== Check 4: Keepa-time on /product timestamps + csv ===");
const tsFields = ["trackingSince", "listedSince", "lastUpdate", "lastPriceChange"];
let allTimesOk = true;
for (const f of tsFields) {
  const raw = childObj?.[f];
  if (typeof raw === "number" && raw > 0) {
    const d = keepaMinuteToDate(raw);
    const good = inRange(d);
    allTimesOk &&= good;
    console.log(`  ${f.padEnd(16)}: ${d.toISOString()} ${good ? "" : "OUT OF RANGE"}`);
  } else {
    console.log(`  ${f.padEnd(16)}: ${raw} (absent/sentinel)`);
  }
}
if (amzLp) {
  const good = inRange(amzLp.at);
  allTimesOk &&= good;
  console.log(`  csv[AMAZON].last : ${amzLp.at.toISOString()} ${good ? "" : "OUT OF RANGE"}`);
}
ok(allTimesOk, "all decoded timestamps in [2010, now+1d]");
if (typeof childObj?.lastUpdate === "number") {
  const ageH = (Date.now() - keepaMinuteToDate(childObj.lastUpdate).getTime()) / 3_600_000;
  console.log(`  lastUpdate age   : ${ageH.toFixed(1)}h`);
}

console.log("\n=== Check 5: -1 sentinel scan (csv[AMAZON] value slots) ===");
const negSet = new Set<number>();
const rawAmz: number[] = refCsv[CsvType.AMAZON] ?? [];
for (let i = 1; i < rawAmz.length; i += 2) if (rawAmz[i] < 0) negSet.add(rawAmz[i]);
// scan all csv arrays for any negative value sentinels
const allNeg = new Set<number>();
for (const arr of refCsv) {
  if (!Array.isArray(arr)) continue;
  for (let i = 1; i < arr.length; i += 2) if (typeof arr[i] === "number" && arr[i] < 0) allNeg.add(arr[i]);
}
console.log(`  negative sentinels in csv[AMAZON]: ${[...negSet].join(", ") || "none"}`);
console.log(`  negative sentinels across all csv : ${[...allNeg].join(", ") || "none"}`);
ok([...allNeg].every((n) => n === -1), "all csv negative sentinels are -1 only (deals used -2/0)");

console.log("\n=== Check 6: *_SHIPPING 3-wide stride (no offers → absent) ===");
const fbmShip = refCsv[CsvType.NEW_FBM_SHIPPING]; // 7
const bbShip = refCsv[CsvType.BUY_BOX_SHIPPING]; // 18
console.log(`  csv[7]  NEW_FBM_SHIPPING : ${fbmShip == null ? "null/absent" : `present (len ${fbmShip.length})`}`);
console.log(`  csv[18] BUY_BOX_SHIPPING : ${bbShip == null ? "null/absent" : `present (len ${bbShip.length})`}`);
ok(fbmShip == null && bbShip == null, "shipping-bearing csv types null without offers (3-wide stride unverifiable live)");

console.log("\n=== Check 7: stats vs hand-decoded last csv point ===");
const stats = childObj?.stats;
ok(stats != null, "child has stats object");
if (stats) {
  for (const t of [CsvType.AMAZON, CsvType.NEW]) {
    const cur = statField(stats, "current", t);
    const lp = lastPoint(refCsv[t]);
    const delta = cur != null && lp != null ? cur - lp.value : null;
    console.log(`  ${names[t]}: stats.current=${cur != null ? formatStatValue(t, cur) : "n/a"} vs csv.last=${lp ? formatStatValue(t, lp.value) : "n/a"}${delta != null ? ` (Δ ${delta}c)` : ""}`);
  }
  for (const t of [CsvType.AMAZON, CsvType.NEW]) {
    const cur = statField(stats, "current", t);
    const avg90 = statField(stats, "avg90", t);
    const mn = decodeExtremePoint(stats, "min", t);
    const mx = decodeExtremePoint(stats, "max", t);
    console.log(`  ${names[t]}: min=${mn ? formatStatValue(t, mn.value) : "n/a"}@${mn?.at.toISOString().slice(0, 10) ?? ""} max=${mx ? formatStatValue(t, mx.value) : "n/a"}@${mx?.at.toISOString().slice(0, 10) ?? ""} avg90=${avg90 != null ? formatStatValue(t, avg90) : "n/a"} current=${cur != null ? formatStatValue(t, cur) : "n/a"}`);
    if (cur != null && mn != null && mx != null) {
      ok(mn.value <= cur && cur <= mx.value, `${names[t]}: min ≤ current ≤ max`);
    }
    if (avg90 != null && mn != null && mx != null) {
      ok(mn.value <= avg90 && avg90 <= mx.value, `${names[t]}: min ≤ avg90 ≤ max`);
    }
  }
}

console.log("\n=== Check 8: interval-shortening (trackingSince vs 90d) ===");
if (typeof childObj?.trackingSince === "number") {
  const ageDays = (Date.now() - keepaMinuteToDate(childObj.trackingSince).getTime()) / 86_400_000;
  console.log(`  tracked age: ${ageDays.toFixed(0)}d (requested window ${STATS}d)`);
  if (ageDays < STATS) {
    console.log(`  → interval CLAMPED to tracked age (< ${STATS}d)`);
  }
  const ais = statField(stats, "atIntervalStart", CsvType.AMAZON);
  console.log(`  atIntervalStart[AMAZON]: ${ais != null ? formatStatValue(CsvType.AMAZON, ais) : "n/a"}`);
}

console.log("\n=== Check 9: RATING scaling lock ===");
const rawRating = stats?.current?.[CsvType.RATING];
console.log(`  raw stats.current[16] = ${rawRating} → ${formatStatValue(CsvType.RATING, rawRating)}`);
if (typeof rawRating === "number" && rawRating >= 0) {
  ok(rawRating / 10 >= 0 && rawRating / 10 <= 5, "RATING ÷10 ∈ [0,5]");
} else {
  console.log("  (no rating on this ASIN — skipped)");
}
console.log("\n  salesRankDrops* (scalars on stats, NOT statField):");
for (const w of [30, 90, 180, 365]) {
  console.log(`    salesRankDrops${w} = ${stats?.[`salesRankDrops${w}`]}`);
}
console.log("  offers-gated stats subfields (expect absent w/o offers/buybox/stock):");
for (const f of ["buyBoxPrice", "buyBoxIsFBA", "totalOfferCount", "outOfStockPercentageInInterval"]) {
  console.log(`    stats.${f} = ${stats?.[f] === undefined ? "ABSENT" : JSON.stringify(stats?.[f]).slice(0, 40)}`);
}

console.log("\n=== Check 10: token meter ===");
ok(batch.tokensConsumed === 3, `primary batch tokensConsumed === 3 (got ${batch.tokensConsumed})`);
ok(parentResp.tokensConsumed === 1, `parent tokensConsumed === 1 (got ${parentResp.tokensConsumed})`);
console.log(`  tokenFlowReduction: batch=${batch.tokenFlowReduction} parent=${parentResp.tokenFlowReduction}`);
console.log(`  refillRate=${batch.refillRate}/min refillIn=${batch.refillIn}ms tokensLeft=${parentResp.tokensLeft}`);

console.log(`\n=== RESULT: ${fail === 0 ? "PASS" : "FAIL"} (${pass} ✓ / ${fail} ✗) ===`);
console.log("Dumps: 03-product/out/batch-seeds.dump.json, out/parent-" + PARENT + ".dump.json");
