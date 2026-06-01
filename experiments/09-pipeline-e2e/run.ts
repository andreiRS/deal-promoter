/**
 * Experiment 09 — FULL PIPELINE, end to end (Keepa → Creators → HTML).
 * ============================================================================
 *
 * Experiments 01–08 confirmed each half of the pipeline in isolation. This is
 * the first probe that wires both halves together in ONE automated pass and
 * renders the result, exactly as `docs/specs/product.md` describes the headless
 * deal pipeline — minus the DB and the WhatsApp/queue layers, which come next.
 *
 * It is the CANONICAL REFERENCE for the PHP/Symfony port: read it top to bottom
 * and the production service boundaries fall out of the three functions below.
 * Every stage carries a `// PHP port:` note at the spots that will trip a port.
 *
 * The pass (maps 1:1 to the spec's cycle, steps 1→4 — we stop before publish):
 *
 *   discover()    Keepa  ─ source candidates + free glitch pre-filter + rank   [spec step 1]
 *                          (dedup against Postgres — spec step 2 — is stubbed: no DB yet)
 *   validate()    Creators ─ live re-validation of the top-N + the deal gate   [spec steps 3-4]
 *   renderHtml()  local  ─ a dummy branded results table (stand-in for publish) [spec step 5]
 *
 * TWO API calls total. That is the floor: Keepa is the only thing that can
 * *discover* what's on sale (Creators GetItems needs ASINs, it can't browse);
 * Creators is the only *live price truth* + the source of the affiliate link.
 *
 * THE ONE NON-NEGOTIABLE RULE (why validate() exists at all):
 *   never publish a price you have not just re-confirmed live on Creators.
 *   Keepa's averages are glitch-polluted and can carry a persistently-wrong
 *   baseline; the live buy-box price + in-stock flag is the only real backstop.
 *
 * Deliberately NO deep Keepa `/product?stats` stage: exp05 proved it rejected
 * 0/26 ("optional confirmation"), and its only unique signals (OOS%, all-time-min
 * floor) target the persistently-wrong-baseline risk that the LIVE Creators call
 * already covers more authoritatively. So a pass costs ≈ 5 Keepa tokens + 1
 * Creators transaction.
 *
 * Cost: 5 tokens/Keepa-page + 1 Creators transaction. No CLI args (it discovers
 * its own ASINs). Run: bun run 09-pipeline-e2e/run.ts
 */
import {
  get,
  logMeter,
  readMeter,
  centsToEuro,
  dealImageUrl,
  PriceType,
  DateRange,
  SortType,
} from "../lib/keepa";
import { getItems, logCost, Resources } from "../lib/creators";

// --- tunables (all env-overridable; the spec wants criteria to be config) -----
const DOMAIN = Number(process.env.KEEPA_DOMAIN ?? 3); // 3 = amazon.de
const PAGES = Number(process.env.PAGES ?? 1); // Keepa deal pages to pull (5 tokens each)
const CREATORS_BATCH = Number(process.env.CREATORS_BATCH ?? 10); // GetItems hard cap is 10/call

const AMAZON = PriceType.AMAZON; // 0 — we track the Amazon-sold price line
const D90 = DateRange.NINETY; // 3 — 90-day window, our baseline horizon
const D_MONTH = DateRange.MONTH; // 2 — deal.avg[MONTH] ≈ stats.avg30 (exp04)

// --- glitch-guard bounds (exp04 recipe; runs FREE on the deal payload) --------
// PHP port: these belong in the editable criteria config, not in code.
const GUARD = {
  ABS_PRICE_FLOOR: 200, // cents — sub-€2 items are cable/glitch noise
  MIN_VERIFIED_DROP: 0.2, // a real drop must be ≥ 20% off the 90d avg
  MAX_CLAIMED_DROP: 97, // a claimed %-drop above this is almost always a glitch
  SPIKE_RATIO: 3.0, // avg90 > 3×avg30 ⇒ a price spike polluted the baseline
};

// --- the deal gate's vocabulary (named so the PHP DealGate enumerates the same) -
// PHP port: model these as an enum; the row's `reasons[]` is the audit trail for
// why something was NOT posted.
const GATE_REASON = {
  NOT_ACCESSIBLE: "not-accessible", // ASIN absent from items[] (came back in errors[])
  NO_BUYBOX: "no-buybox", // no listing won the buy box ⇒ nothing buyable to post
  NOT_NEW: "not-new", // buy-box condition ≠ New
  OUT_OF_STOCK: "out-of-stock", // availability not immediately buyable
  NOT_SOLD_BY_AMAZON: "not-sold-by-amazon", // merchant ≠ Amazon (trust gate)
  MAP_VIOLATION: "map-violation", // price hidden until checkout — not postable
  WEAK_LIVE_DROP: "weak-live-drop", // LIVE price isn't ≥ MIN_VERIFIED_DROP off avg90
} as const;

// The .de "sold by Amazon" seller id (exp08). There is no FBA boolean on the API,
// so this id IS the trusted-merchant gate. PHP port: per-marketplace config value.
const AMAZON_DE_SELLER_ID = "A3JWKAKR8XB7XF";

// availability.type values that mean "buyable right now" (observed in exp08:
// IN_STOCK, IN_STOCK_SCARCE, LEADTIME). LEADTIME = "ships in 2-3 days" ⇒ we treat
// it as NOT a flash-deal-grade in-stock. PHP port: confirm the full enum over time.
const IN_STOCK_TYPES = new Set(["IN_STOCK", "IN_STOCK_SCARCE"]);

// =============================================================================
// Small shared types + helpers
// =============================================================================

/** A ranked discovery candidate, carrying the Keepa baseline validate() needs. */
interface Candidate {
  asin: string;
  title: string;
  keepaCurrent: number; // cents — Keepa's last-seen Amazon price
  avg90: number; // cents — 90d average, the baseline the LIVE drop is measured against
  claimedDrop: number | null; // Keepa's claimed %-drop (corroboration only)
  dealImage: string; // CDN url, for the HTML thumbnail
  score: number; // verifiedDrop × log1p(demand) — ranking key
}

/** A gated result row — one per validated ASIN — that the HTML renders. */
interface Row {
  asin: string;
  title: string;
  detailPageURL: string; // the affiliate link (carries tag=), verbatim from the API
  dealImage: string;
  avg90: number; // cents — Keepa baseline
  liveCents: number | null; // cents — live buy-box price (the number we'd post)
  wasCents: number | null; // cents — savingBasis (gameable LIST_PRICE, display only)
  liveDrop: number | null; // fraction — (avg90 − liveCents) / avg90, the REAL gate
  amazonSavingsPct: number | null; // Amazon's own claimed % (corroboration only)
  dealBadge: string | null; // dealDetails.badge when Amazon flags a deal
  condition: string | null;
  availability: string | null;
  merchant: string | null;
  violatesMAP: boolean | null;
  publish: boolean; // PUBLISH (all gates pass) vs SKIP
  reasons: string[]; // why SKIP (empty ⇒ PUBLISH)
}

// Keepa deal arrays use sentinels (-1 current / -2 avg / 0 delta), never 0-as-real.
const dealNum = (v: unknown): number | null =>
  typeof v === "number" && v >= 0 ? v : null;

// Keepa price.money.amount is a DECIMAL euro number (e.g. 24.99). The whole
// pipeline compares in integer cents. PHP port: round to cents at the boundary
// (BigDecimal/intval(round($amount*100))) — never compare floats.
const moneyToCents = (amount: unknown): number | null =>
  typeof amount === "number" && Number.isFinite(amount) ? Math.round(amount * 100) : null;

const pct = (frac: number | null): string =>
  frac == null ? "—" : `${(frac * 100).toFixed(0)}%`;
const eur = (cents: number | null): string =>
  cents == null ? "—" : centsToEuro(cents);

// =============================================================================
// STAGE 1 — discover(): Keepa fetch + FREE glitch pre-filter + rank
// -----------------------------------------------------------------------------
// One Keepa call per page (5 tokens). We deliberately sort by PERCENT_DELTA
// (sortType 4), which floats price-glitch garbage to the TOP (exp02's €6.99
// cable with a €543 "avg") — then we filter that garbage out ourselves on the
// payload we already paid for. Every guard below is derivable from the /deal
// payload alone (exp04 Finding 2): NO second Keepa call, NO /product.
//
// PHP port: this is the KeepaDealSource. The pre-filter is pure (config + math,
// no I/O) so it ports to a testable DealPreFilter with the GUARD as config. The
// dedup-against-Postgres step (spec step 2) slots in right after the filter,
// before ranking, behind the storage interface — stubbed out here (no DB yet).
// =============================================================================

const dealSelection = (page: number) => ({
  page,
  domainId: DOMAIN,
  priceTypes: [AMAZON],
  dateRange: D90, // sort window matches our 90d guard comparison
  sortType: SortType.PERCENT_DELTA, // 4 — floats glitches to the top on purpose
  isFilterEnabled: false, // raw feed — we filter ourselves (server filters miss glitches, exp04)
});

// Pre-filter from exp05 (run.ts:102). Returns reject reasons; empty ⇒ survivor.
function dealPreFilter(d: any): string[] {
  const cur = dealNum(d.current?.[AMAZON]);
  const avg90 = dealNum(d.avg?.[D90]?.[AMAZON]);
  const avg30 = dealNum(d.avg?.[D_MONTH]?.[AMAZON]); // ≈ stats.avg30 (exp04)
  const claimedRaw = d?.deltaPercent?.[D90]?.[AMAZON];
  const claimed = typeof claimedRaw === "number" && claimedRaw > 0 ? claimedRaw : null;
  const verified = cur != null && avg90 != null && avg90 > 0 ? (avg90 - cur) / avg90 : null;
  const rankDrops90 = typeof d.salesRankDrops90 === "number" ? d.salesRankDrops90 : null;

  const reasons: string[] = [];
  if (cur == null) reasons.push("no-live-price");
  if (cur != null && cur < GUARD.ABS_PRICE_FLOOR) reasons.push("abs-price-floor");
  if (avg90 != null && avg30 != null && avg30 > 0 && avg90 > GUARD.SPIKE_RATIO * avg30)
    reasons.push("spike-polluted-baseline");
  if (verified == null || verified < GUARD.MIN_VERIFIED_DROP) reasons.push("weak-real-drop");
  if (claimed != null && claimed > GUARD.MAX_CLAIMED_DROP) reasons.push("absurd-claim");
  if (rankDrops90 != null && rankDrops90 < 1) reasons.push("no-demand"); // nobody buys it
  return reasons;
}

async function discover(): Promise<{ candidates: Candidate[]; dealTokens: number; pulled: number; survivors: number }> {
  console.log("\n--- STAGE 1: discover() — Keepa fetch + free pre-filter + rank ---");
  let dealTokens = 0;
  let pulled = 0;
  const survivors: Candidate[] = [];

  for (let page = 0; page < PAGES; page++) {
    const before = readMeter(await get("token", {})).tokensLeft;
    const resp = await get("deal", { selection: JSON.stringify(dealSelection(page)) });
    await Bun.write(
      `${import.meta.dir}/out/deal-page-${page}.dump.json`,
      JSON.stringify(resp, null, 2),
    );
    logMeter(`/deal page(${page})`, before, resp);
    dealTokens += resp.tokensConsumed ?? 0;

    const dr: any[] = (resp.deals ?? resp).dr ?? [];
    pulled += dr.length;
    for (const d of dr) {
      if (dealPreFilter(d).length > 0) continue; // glitch / weak / no-demand — drop
      const cur = dealNum(d.current?.[AMAZON])!;
      const avg90 = dealNum(d.avg?.[D90]?.[AMAZON])!;
      const claimedRaw = d?.deltaPercent?.[D90]?.[AMAZON];
      const rankDrops90 = typeof d.salesRankDrops90 === "number" ? d.salesRankDrops90 : 0;
      const verifiedDrop = (avg90 - cur) / avg90;
      survivors.push({
        asin: d.asin,
        title: String(d.title ?? "").trim(),
        keepaCurrent: cur,
        avg90,
        claimedDrop: typeof claimedRaw === "number" && claimedRaw > 0 ? claimedRaw : null,
        dealImage: dealImageUrl(d.image),
        // Rank by real-drop weighted by demand — both are free on the deal payload.
        // PHP port: this is the only ranking we need; the deep /product score is gone.
        score: verifiedDrop * Math.log1p(Math.max(0, rankDrops90)),
      });
    }
    if (dr.length < 150) break; // last page
  }

  survivors.sort((a, b) => b.score - a.score); // best deals first (spec pacing)
  const candidates = survivors.slice(0, CREATORS_BATCH);
  const dropped = survivors.length - candidates.length;
  console.log(
    `  pulled ${pulled} deals → ${survivors.length} survivors → top ${candidates.length} to validate` +
      (dropped > 0 ? ` (${dropped} survivor(s) dropped by the ${CREATORS_BATCH}-ASIN cap)` : ""),
  );
  return { candidates, dealTokens, pulled, survivors: survivors.length };
}

// =============================================================================
// STAGE 2 — validate(): ONE Creators call + the deal gate
// -----------------------------------------------------------------------------
// One getItems() = ONE transaction regardless of batch size. We request the full
// offersV2 set, pick the BUY-BOX listing (never listings[0] / cheapest — a Used
// listing can undercut the New buy box, exp08), then gate each candidate.
//
// The gate IS the spec's leading deal-gate option. The load-bearing check is the
// LIVE drop (live buy-box price vs the Keepa avg90 baseline) — savingBasis /
// savings% / dealDetails are gameable corroboration, never the gate (exp08).
//
// PHP port: this is the CreatorsValidator + DealGate. Reconcile results to input
// BY ASIN, never by position (errors[] entries have no asin field — exp07).
// =============================================================================

const CREATORS_RESOURCES = [
  Resources.ITEM_INFO_TITLE,
  Resources.OFFERS_V2_LISTINGS_PRICE,
  Resources.OFFERS_V2_LISTINGS_CONDITION,
  Resources.OFFERS_V2_LISTINGS_AVAILABILITY,
  Resources.OFFERS_V2_LISTINGS_MERCHANT_INFO,
  Resources.OFFERS_V2_LISTINGS_DEAL_DETAILS,
  Resources.OFFERS_V2_LISTINGS_IS_BUY_BOX_WINNER,
];

/** Apply every gate to one candidate + its live item. Empty reasons ⇒ PUBLISH. */
function applyGate(c: Candidate, item: any | undefined): Row {
  const base: Row = {
    asin: c.asin,
    title: c.title,
    detailPageURL: item?.detailPageURL ?? "",
    dealImage: c.dealImage,
    avg90: c.avg90,
    liveCents: null,
    wasCents: null,
    liveDrop: null,
    amazonSavingsPct: null,
    dealBadge: null,
    condition: null,
    availability: null,
    merchant: null,
    violatesMAP: null,
    publish: false,
    reasons: [],
  };

  // ASIN never came back (it was in errors[], e.g. InvalidParameterValue, exp07).
  if (!item) return { ...base, reasons: [GATE_REASON.NOT_ACCESSIBLE] };

  const listings: any[] = item.offersV2?.listings ?? [];
  // Buy-box selection: the one offer the customer actually gets. Never [0].
  const buyBox = listings.find((l) => l.isBuyBoxWinner === true);
  if (!buyBox) return { ...base, reasons: [GATE_REASON.NO_BUYBOX] };

  const liveCents = moneyToCents(buyBox.price?.money?.amount);
  const wasCents = moneyToCents(buyBox.price?.savingBasis?.money?.amount);
  const liveDrop =
    liveCents != null && c.avg90 > 0 ? (c.avg90 - liveCents) / c.avg90 : null;

  const row: Row = {
    ...base,
    liveCents,
    wasCents,
    liveDrop,
    amazonSavingsPct: buyBox.price?.savings?.percentage ?? null,
    dealBadge: buyBox.dealDetails?.badge ?? null,
    condition: buyBox.condition?.value ?? null,
    availability: buyBox.availability?.type ?? null,
    merchant: buyBox.merchantInfo?.name ?? null,
    violatesMAP: buyBox.violatesMAP ?? null,
  };

  // The gate. Each push is one reason this candidate is NOT postable.
  const reasons: string[] = [];
  if (buyBox.condition?.value !== "New") reasons.push(GATE_REASON.NOT_NEW);
  if (!IN_STOCK_TYPES.has(buyBox.availability?.type)) reasons.push(GATE_REASON.OUT_OF_STOCK);
  if (buyBox.merchantInfo?.id !== AMAZON_DE_SELLER_ID) reasons.push(GATE_REASON.NOT_SOLD_BY_AMAZON);
  if (buyBox.violatesMAP === true) reasons.push(GATE_REASON.MAP_VIOLATION);
  // THE rule: the LIVE price must itself clear the drop threshold vs avg90.
  if (liveDrop == null || liveDrop < GUARD.MIN_VERIFIED_DROP) reasons.push(GATE_REASON.WEAK_LIVE_DROP);

  row.reasons = reasons;
  row.publish = reasons.length === 0;
  return row;
}

async function validate(candidates: Candidate[]): Promise<{ rows: Row[]; transactions: number }> {
  console.log(`\n--- STAGE 2: validate() — 1 Creators call on ${candidates.length} ASIN(s) + gate ---`);
  if (candidates.length === 0) return { rows: [], transactions: 0 };

  const asins = candidates.map((c) => c.asin);
  const json = await getItems(asins, CREATORS_RESOURCES);
  await Bun.write(`${import.meta.dir}/out/creators.dump.json`, JSON.stringify(json, null, 2));
  logCost("/validate", json);

  // Reconcile BY ASIN (exp07): build a lookup, then map each candidate to its item.
  const items: any[] = json.itemsResult?.items ?? [];
  const byAsin: Record<string, any> = {};
  for (const it of items) byAsin[it.asin] = it;

  const rows = candidates.map((c) => applyGate(c, byAsin[c.asin]));
  // PUBLISH first, then deepest live drop first (spec: best deals first).
  rows.sort((a, b) => Number(b.publish) - Number(a.publish) || (b.liveDrop ?? 0) - (a.liveDrop ?? 0));
  return { rows, transactions: 1 };
}

// =============================================================================
// STAGE 3 — renderHtml(): a dummy BRANDED results table (publish stand-in)
// -----------------------------------------------------------------------------
// Self-contained, no network, dark-mode-first per Andrei's brand tokens. This is
// throwaway: the production target is publish-to-WhatsApp, recorded in Postgres.
// =============================================================================

function esc(s: string): string {
  return s.replace(/[&<>"]/g, (ch) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[ch]!));
}

function renderRow(r: Row): string {
  const verdict = r.publish
    ? `<span class="badge ok">PUBLISH</span>`
    : `<span class="badge skip">SKIP</span> <span class="reasons">${esc(r.reasons.join(", "))}</span>`;
  const link = r.detailPageURL
    ? `<a href="${esc(r.detailPageURL)}" target="_blank" rel="noopener">open ↗</a>`
    : "—";
  const badge = r.dealBadge ? `<span class="dealbadge">${esc(r.dealBadge)}</span>` : "";
  return `<tr class="${r.publish ? "pub" : "skp"}">
    <td>${r.dealImage ? `<img src="${esc(r.dealImage)}" alt="" loading="lazy">` : ""}</td>
    <td class="title">${esc(r.title || r.asin)}<div class="asin">${esc(r.asin)}</div></td>
    <td class="num live">${eur(r.liveCents)}</td>
    <td class="num was">${eur(r.wasCents)}</td>
    <td class="num drop">${pct(r.liveDrop)}</td>
    <td class="num">${r.amazonSavingsPct != null ? r.amazonSavingsPct + "%" : "—"} ${badge}</td>
    <td>${esc(r.condition ?? "—")}</td>
    <td>${esc(r.availability ?? "—")}</td>
    <td>${esc(r.merchant ?? "—")}</td>
    <td>${r.violatesMAP == null ? "—" : r.violatesMAP ? "yes" : "no"}</td>
    <td>${verdict}</td>
    <td>${link}</td>
  </tr>`;
}

interface Summary {
  pulled: number;
  survivors: number;
  validated: number;
  published: number;
  dealTokens: number;
  transactions: number;
}

function renderHtml(rows: Row[], s: Summary): string {
  const stat = (label: string, value: string | number) =>
    `<div class="stat"><div class="v">${value}</div><div class="l">${label}</div></div>`;
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Deal Promoter — exp09 results</title>
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=inter:400,600,700|jetbrains-mono:400" rel="stylesheet">
<style>
:root{
  --bg:#1e1e1e;--surface:#252525;--border:#333;--text:#fff;
  --success:#4caf7d;--info:#6b9bd2;--danger:#e05c4b;--highlight:#c9970a;
}
@media (prefers-color-scheme: light){:root{
  --bg:#f5f0e8;--surface:#ede8df;--border:#ccc6bb;--text:#1a1a1a;
  --success:#3a8c63;--info:#4f7cb0;--danger:#c0432f;--highlight:#a87c08;
}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);
  font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6;padding:2rem}
h1{font-weight:700;margin:0 0 .25rem}
.sub{color:var(--info);margin:0 0 1.5rem;font-size:.9rem}
.stats{display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:2rem}
.stat{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:1rem 1.25rem;min-width:120px}
.stat .v{font-size:1.6rem;font-weight:700}
.stat .l{font-size:.75rem;color:var(--info);text-transform:uppercase;letter-spacing:.04em}
table{width:100%;border-collapse:collapse;background:var(--surface);border:1px solid var(--border);border-radius:8px;overflow:hidden}
th,td{padding:.6rem .75rem;text-align:left;border-bottom:1px solid var(--border);vertical-align:middle;font-size:.85rem}
th{font-weight:600;text-transform:uppercase;letter-spacing:.03em;font-size:.7rem;color:var(--info)}
tr:last-child td{border-bottom:none}
td.num{text-align:right;font-family:"JetBrains Mono",monospace}
td.live{font-weight:700}
td.was{color:var(--info);text-decoration:line-through}
td.drop{color:var(--highlight);font-weight:700}
td.title{max-width:280px}
.asin{font-family:"JetBrains Mono",monospace;font-size:.7rem;color:var(--info)}
img{width:44px;height:44px;object-fit:contain;background:#fff;border-radius:4px}
.badge{display:inline-block;padding:.1rem .5rem;border-radius:4px;font-size:.7rem;font-weight:700}
.badge.ok{background:var(--success);color:#fff}
.badge.skip{background:var(--danger);color:#fff}
.reasons{font-size:.7rem;color:var(--danger)}
.dealbadge{display:inline-block;font-size:.65rem;color:var(--highlight);border:1px solid var(--highlight);border-radius:3px;padding:0 .3rem}
tr.skp{opacity:.6}
a{color:var(--info)}
@media (max-width:640px){body{padding:1rem}td.title{max-width:140px}}
</style>
</head>
<body>
<h1>Deal Promoter — full pipeline (exp09)</h1>
<p class="sub">Keepa discovery → Creators live re-validation → deal gate. Dummy render; no DB / WhatsApp yet.</p>
<div class="stats">
${stat("Deals pulled", s.pulled)}
${stat("Keepa survivors", s.survivors)}
${stat("Live-validated", s.validated)}
${stat("PUBLISH", `<span style="color:var(--success)">${s.published}</span>`)}
${stat("Keepa tokens", s.dealTokens)}
${stat("Creators tx", s.transactions)}
</div>
<table>
<thead><tr>
<th></th><th>Product</th><th>Live</th><th>Was</th><th>Drop vs avg90</th><th>Amazon %</th>
<th>Condition</th><th>Availability</th><th>Merchant</th><th>MAP</th><th>Verdict</th><th>Link</th>
</tr></thead>
<tbody>
${rows.map(renderRow).join("\n")}
</tbody>
</table>
</body>
</html>`;
}

// =============================================================================
// RUN — three functions, two API calls.
// =============================================================================
console.log(`=== exp09 full pipeline · domain ${DOMAIN} · ${PAGES} page(s) · top ${CREATORS_BATCH} ===`);
const startBalance = readMeter(await get("token", {})).tokensLeft;

const { candidates, dealTokens, pulled, survivors } = await discover();
const { rows, transactions } = await validate(candidates);

const summary: Summary = {
  pulled,
  survivors,
  validated: rows.length,
  published: rows.filter((r) => r.publish).length,
  dealTokens,
  transactions,
};
const htmlPath = `${import.meta.dir}/out/results.html`;
await Bun.write(htmlPath, renderHtml(rows, summary));

// --- per-ASIN verdicts to the console (the same data the HTML shows) ---------
console.log(`\n=== Gate verdicts (${summary.published} PUBLISH / ${rows.length - summary.published} SKIP) ===`);
for (const r of rows) {
  const tag = r.publish ? "PUBLISH" : `SKIP   `;
  console.log(
    `  ${tag} ${r.asin}  live=${eur(r.liveCents)} drop=${pct(r.liveDrop)} ` +
      `${r.condition ?? "?"}/${r.availability ?? "?"}/${r.merchant ?? "?"}` +
      (r.publish ? "" : `  [${r.reasons.join(", ")}]`),
  );
}

// =============================================================================
// CHECKS — pipeline invariants (exp05-style ok())
// =============================================================================
let pass = 0,
  fail = 0;
const ok = (cond: boolean, msg: string) => {
  console.log(`  ${cond ? "✓" : "✗"} ${msg}`);
  cond ? pass++ : fail++;
};

console.log("\n=== Checks ===");
ok(transactions === (candidates.length > 0 ? 1 : 0), `Creators cost = 1 transaction for the batch (got ${transactions})`);
ok(rows.length === candidates.length, `every candidate got a verdict (${rows.length}/${candidates.length})`);
ok(candidates.length <= CREATORS_BATCH, `never exceed the ${CREATORS_BATCH}-ASIN GetItems cap (${candidates.length})`);
ok(
  rows.every((r) => !r.publish || (r.liveDrop != null && r.liveDrop >= GUARD.MIN_VERIFIED_DROP)),
  `every PUBLISH row clears the LIVE drop gate (≥ ${GUARD.MIN_VERIFIED_DROP * 100}%)`,
);
ok(
  rows.every((r) => !r.publish || (r.condition === "New" && r.merchant != null)),
  `every PUBLISH row is New + sold-by-Amazon`,
);
ok(
  rows.every((r) => !r.publish || /[?&]tag=/.test(r.detailPageURL)),
  `every PUBLISH row links through a tag=-carrying affiliate URL`,
);

console.log(`\n=== RESULT: ${fail === 0 ? "PASS" : "FAIL"} (${pass} ✓ / ${fail} ✗) ===`);
console.log(`Keepa balance: ${readMeter(await get("token", {})).tokensLeft} (started ${startBalance})`);
console.log(`\nOpen the results:\n  file://${htmlPath}`);
