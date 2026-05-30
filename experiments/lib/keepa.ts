/**
 * Thin Keepa API client + decoders, shared across experiments.
 *
 * This is deliberately minimal: just enough to make real calls, always surface
 * the live token meter, and decode the two things that trip everyone up
 * (Keepa-time and the delta-CSV price arrays). The production Deal Promoter is
 * PHP, so treat this as a reference port target, not shippable code.
 *
 * Docs basis: docs/research/keepa.md
 */

const BASE = "https://api.keepa.com";

export function apiKey(): string {
  const k = process.env.KEEPA_API_KEY;
  if (!k || k === "your_keepa_api_key_here") {
    throw new Error(
      "KEEPA_API_KEY is not set. Copy experiments/.env.example to experiments/.env and fill it in.",
    );
  }
  return k;
}

export function defaultDomain(): number {
  return Number(process.env.KEEPA_DOMAIN ?? 3); // 3 = amazon.de
}

/** Live token meter that rides on (nearly) every Keepa response. */
export interface TokenMeter {
  tokensLeft: number;
  refillIn: number; // ms until next refill tick
  refillRate: number; // tokens added per minute
  tokensConsumed?: number; // tokens this specific call cost (when present)
  timestamp: number; // server timestamp (unix ms)
}

export function readMeter(json: any): TokenMeter {
  return {
    tokensLeft: json.tokensLeft,
    refillIn: json.refillIn,
    refillRate: json.refillRate,
    tokensConsumed: json.tokensConsumed,
    timestamp: json.timestamp,
  };
}

export function logMeter(label: string, before: number | null, json: any): void {
  const m = readMeter(json);
  const spent =
    m.tokensConsumed ??
    (before !== null && m.tokensLeft != null ? before - m.tokensLeft : undefined);
  console.log(
    `[tokens] ${label}: left=${m.tokensLeft} spent=${spent ?? "?"} ` +
      `refillRate=${m.refillRate}/min refillIn=${m.refillIn}ms`,
  );
}

/** Generic GET against a Keepa endpoint. Returns parsed JSON. */
export async function get(
  endpoint: string,
  params: Record<string, string | number | undefined>,
): Promise<any> {
  const url = new URL(`${BASE}/${endpoint}`);
  url.searchParams.set("key", apiKey());
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined) url.searchParams.set(k, String(v));
  }
  const res = await fetch(url, { headers: { "User-Agent": "deal-promoter-research/0.1" } });
  const text = await res.text();
  if (!res.ok) {
    throw new Error(`Keepa ${endpoint} HTTP ${res.status}: ${text.slice(0, 500)}`);
  }
  try {
    return JSON.parse(text);
  } catch {
    throw new Error(`Keepa ${endpoint} returned non-JSON: ${text.slice(0, 500)}`);
  }
}

// ---------------------------------------------------------------------------
// Keepa-time: timestamps are "Keepa minutes" = unix-epoch-minutes - 21564000.
// ---------------------------------------------------------------------------
const KEEPA_TIME_OFFSET_MIN = 21564000;

export function keepaMinuteToDate(min: number): Date {
  return new Date((min + KEEPA_TIME_OFFSET_MIN) * 60_000);
}

export function dateToKeepaMinute(d: Date): number {
  return Math.floor(d.getTime() / 60_000) - KEEPA_TIME_OFFSET_MIN;
}

// ---------------------------------------------------------------------------
// Delta-CSV decoding. Each csv[type] is a flat [time, value, time, value, ...]
// series. value === -1 means "no data / out of stock" and must be dropped.
// Prices are integers in the marketplace minor unit (euro cents for amazon.de).
// ---------------------------------------------------------------------------
export interface PricePoint {
  at: Date;
  value: number; // raw integer (cents for prices, 0..50 for RATING, etc.)
}

export function decodeCsv(series: number[] | null | undefined): PricePoint[] {
  if (!series || series.length === 0) return [];
  const out: PricePoint[] = [];
  for (let i = 0; i + 1 < series.length; i += 2) {
    const t = series[i];
    const v = series[i + 1];
    if (v === -1) continue; // sentinel: no data / OOS
    out.push({ at: keepaMinuteToDate(t), value: v });
  }
  return out;
}

export function lastPoint(series: number[] | null | undefined): PricePoint | null {
  const pts = decodeCsv(series);
  return pts.length ? pts[pts.length - 1] : null;
}

export function centsToEuro(cents: number): string {
  return (cents / 100).toLocaleString("de-DE", { style: "currency", currency: "EUR" });
}

/**
 * CsvType index table. Prices, ranks, counts and rating all share this one index
 * space (on product `csv`, on deal `current`, and on the `stats` arrays). Most
 * indices are euro-cents prices, but SALES_RANK, the COUNT_* family, RATING and
 * EXTRA_INFO_UPDATES are NOT prices — see isPriceType / formatStatValue.
 */
export const CsvType = {
  AMAZON: 0,
  NEW: 1,
  USED: 2,
  SALES_RANK: 3,
  LISTPRICE: 4,
  COLLECTIBLE: 5,
  REFURBISHED: 6,
  NEW_FBM_SHIPPING: 7,
  LIGHTNING_DEAL: 8,
  WAREHOUSE: 9,
  NEW_FBA: 10,
  COUNT_NEW: 11,
  COUNT_USED: 12,
  COUNT_REFURBISHED: 13,
  COUNT_COLLECTIBLE: 14,
  EXTRA_INFO_UPDATES: 15,
  RATING: 16,
  COUNT_REVIEWS: 17,
  BUY_BOX_SHIPPING: 18,
  TRADE_IN: 30,
} as const;

export type CsvTypeName = keyof typeof CsvType;
export type CsvIndex = (typeof CsvType)[CsvTypeName];

// ---------------------------------------------------------------------------
// Type classification. The stats/csv index space mixes prices, ranks, counts
// and rating. A PHP port must centralize this so "cents" formatting never leaks
// onto a sales rank or a review count.
// ---------------------------------------------------------------------------

/** Indices whose value is a sales rank (plain integer, lower = better). */
export const RANK_TYPES = new Set<number>([CsvType.SALES_RANK]); // 3

/**
 * Indices whose value is a count, not a price. 11–14 = offer counts per
 * condition, 17 = review count, 34/35 = (rating count / buy-box-ish counts seen
 * in newer csv tables) — kept here so the classifier stays exhaustive.
 */
export const COUNT_TYPES = new Set<number>([11, 12, 13, 14, 17, 34, 35]);

/** RATING is an int 0..50 (45 = 4.5 stars); divide by 10 to get stars. */
export const RATING_TYPE = CsvType.RATING; // 16

/** True when arr[type] is a euro-cents price (the common case). */
export function isPriceType(type: number): boolean {
  return (
    !RANK_TYPES.has(type) &&
    !COUNT_TYPES.has(type) &&
    type !== RATING_TYPE &&
    type !== CsvType.EXTRA_INFO_UPDATES
  );
}

/**
 * Format a raw stat/csv value for display, given its index type. Returns "n/a"
 * for null/negative (the -1 sentinel). Decoders themselves return raw ints;
 * formatting lives only here.
 */
export function formatStatValue(type: number, v: number | null | undefined): string {
  if (v == null || v < 0) return "n/a";
  if (type === RATING_TYPE) return `${(v / 10).toFixed(1)}★`;
  if (RANK_TYPES.has(type)) return `#${v.toLocaleString("de-DE")}`;
  if (COUNT_TYPES.has(type)) return v.toLocaleString("de-DE");
  return centsToEuro(v);
}

// ---------------------------------------------------------------------------
// stats object accessors (from /product?stats=N). The `stats` object holds
// price-type-indexed arrays computed server-side, so we can validate "is this
// the lowest in 90 days?" without hand-decoding the full csv history.
//   - 1D fields (current, avg, avg30/90/180/365, atIntervalStart,
//     outOfStockPercentage*): arr[type] is a plain number, -1 = no data.
//   - 2D extreme fields (min, max, minInInterval, maxInInterval): arr[type] is
//     null OR [keepaMinute, value].
// salesRankDrops30/90/180/365 are SCALARS on stats (not type-indexed arrays).
// ---------------------------------------------------------------------------

export interface StatPoint {
  at: Date;
  value: number;
}

/**
 * Read a 1D type-indexed stats field (current, avg, avg30/90/180/365,
 * atIntervalStart, outOfStockPercentage30/90/180/365). Returns the raw int when
 * present and >= 0, else null. Do NOT use for salesRankDrops* (those are scalars).
 */
export function statField(
  stats: any,
  field: string,
  type: number,
): number | null {
  const arr = stats?.[field];
  if (!Array.isArray(arr)) return null;
  const v = arr[type];
  return typeof v === "number" && v >= 0 ? v : null;
}

/**
 * Read a 2D extreme stats field (min, max, minInInterval, maxInInterval).
 * arr[type] is null OR [keepaMinute, value]; returns the decoded point or null.
 */
export function decodeExtremePoint(
  stats: any,
  field: string,
  type: number,
): StatPoint | null {
  const arr = stats?.[field];
  if (!Array.isArray(arr)) return null;
  const slot = arr[type];
  if (!Array.isArray(slot) || slot.length < 2) return null;
  const [t, value] = slot;
  if (typeof value !== "number" || value < 0) return null;
  return { at: keepaMinuteToDate(t), value };
}

// ---------------------------------------------------------------------------
// Deal-specific decoders (Browsing Deals / Deal Object).
// ---------------------------------------------------------------------------

/**
 * Deal price/value arrays (current, delta, etc.) are indexed by Price Type,
 * the SAME index space as csv on the product object. priceTypes in the query is
 * the subset you're sorting/filtering on, but `current` carries every type.
 */
export const PriceType = CsvType;

/** dateRange index for the 2D deal arrays (delta/deltaPercent/avg) and query. */
export const DateRange = {
  DAY: 0, // last 24h (avg index 0 is actually a 48h average — see Deal Object docs)
  WEEK: 1, // last 7d
  MONTH: 2, // last 31d
  NINETY: 3, // last 90d
} as const;

/** sortType for the deal query. Negate to invert (except 1, deal age). */
export const SortType = {
  DEAL_AGE: 1, // newest first; not invertible
  ABSOLUTE_DELTA: 2, // highest delta first
  SALES_RANK: 3, // lowest rank first
  PERCENT_DELTA: 4, // highest percent first
} as const;

/**
 * Deal `image` is an array of US-ASCII char codes for the image filename only.
 * e.g. [54,49,...] -> "61k3Lay7JUL.jpg". Returns "" when absent.
 */
export function decodeDealImage(image: number[] | null | undefined): string {
  if (!image || image.length === 0) return "";
  return String.fromCharCode(...image);
}

/** Full Amazon CDN URL for a decoded deal image filename. "" when absent. */
export function dealImageUrl(image: number[] | null | undefined): string {
  const name = decodeDealImage(image);
  return name ? `https://images-na.ssl-images-amazon.com/images/I/${name}` : "";
}
