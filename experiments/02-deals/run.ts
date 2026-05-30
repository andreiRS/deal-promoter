/**
 * Experiment 02 — Browsing Deals (request shape + Deal Object response shape).
 *
 * Confirms, against the live amazon.de marketplace:
 *   - the /deal query JSON we send (domainId + exactly-one priceType are required)
 *   - the real token cost (brief/docs say 5 per request, up to 150 deals)
 *   - the response envelope: { deals: { dr, categoryIds, categoryNames, categoryCount } }
 *   - Deal Object decoding: image char-codes, Keepa-time stamps, the Price-Type
 *     indexed `current` array, and the 2D [dateRange][priceType] delta arrays.
 *
 * Cost: 5 tokens (one page). Run: bun run 02-deals/run.ts
 */
import {
  get,
  logMeter,
  readMeter,
  defaultDomain,
  centsToEuro,
  keepaMinuteToDate,
  dealImageUrl,
  PriceType,
  DateRange,
  SortType,
} from "../lib/keepa";

// --- query --------------------------------------------------------------
// Deliberately conservative: Amazon price, week interval, biggest % drops
// first, light filters. domainId + exactly one priceType are NOT optional.
const PT = PriceType.AMAZON; // 0 -> we read current[0], delta[dr][0]
const DR = DateRange.WEEK; // 1
const selection = {
  page: 0,
  domainId: defaultDomain(), // 3 = amazon.de
  priceTypes: [PT],
  dateRange: DR,
  isRangeEnabled: true,
  deltaPercentRange: [20, 100], // 20-100% drop vs weighted avg
  sortType: SortType.PERCENT_DELTA, // 4
  filterErotic: true,
  isFilterEnabled: false,
};

console.log("=== query (selection) ===");
console.log(JSON.stringify(selection, null, 2));

// --- meter before (free /token call) ------------------------------------
const before = readMeter(await get("token", {})).tokensLeft;
console.log(`\n[tokens] before /deal: left=${before}`);

// --- the call -----------------------------------------------------------
// GET with selection as a URL-encoded JSON string (our get() encodes params).
const json = await get("deal", { selection: JSON.stringify(selection) });

await Bun.write(
  `${import.meta.dir}/out/deal-amazon-de.dump.json`,
  JSON.stringify(json, null, 2),
);

console.log("\n=== meter after ===");
logMeter("/deal", before, json);

// --- envelope -----------------------------------------------------------
const deals = json.deals ?? {};
const dr: any[] = deals.dr ?? [];
console.log("\n=== envelope ===");
console.log(`top-level keys      : ${Object.keys(json).join(", ")}`);
console.log(`deals keys          : ${Object.keys(deals).join(", ")}`);
console.log(`deals returned (dr) : ${dr.length}  (page is full at 150)`);
console.log(`root categories     : ${(deals.categoryNames ?? []).length}`);
if ((deals.categoryNames ?? []).length) {
  const rows = (deals.categoryNames as string[])
    .map((n, i) => `  ${deals.categoryIds[i]}  ${n} (${deals.categoryCount[i]})`)
    .slice(0, 8);
  console.log("top root cats:\n" + rows.join("\n"));
}

// --- decode a few deals -------------------------------------------------
console.log("\n=== first 5 deals (priceType=AMAZON, dateRange=WEEK) ===");
for (const d of dr.slice(0, 5)) {
  const cur = d.current?.[PT];
  const deltaPct = d.deltaPercent?.[DR]?.[PT];
  const avg = d.avg?.[DR]?.[PT];
  const deltaAbs = d.delta?.[DR]?.[PT];
  console.log(
    [
      `\nASIN ${d.asin}${d.parentAsin && d.parentAsin !== d.asin ? ` (parent ${d.parentAsin})` : ""}`,
      `  title    : ${String(d.title ?? "").slice(0, 70)}`,
      `  current  : ${cur != null && cur >= 0 ? centsToEuro(cur) : "n/a"}`,
      `  weightAvg: ${avg != null && avg >= 0 ? centsToEuro(avg) : "n/a"}`,
      `  delta    : ${deltaAbs != null ? centsToEuro(deltaAbs) : "n/a"} (${deltaPct ?? "?"}%)`,
      `  lastUpd  : ${d.lastUpdate != null ? keepaMinuteToDate(d.lastUpdate).toISOString() : "n/a"}`,
      `  rootCat  : ${d.rootCat}`,
      `  image    : ${dealImageUrl(d.image) || "n/a"}`,
    ].join("\n"),
  );
}

console.log(
  `\nRaw dump: 02-deals/out/deal-amazon-de.dump.json (${dr.length} deals captured).`,
);
