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

/** CsvType index table (subset we care about). Full table in the Java backend. */
export const CsvType = {
  AMAZON: 0,
  NEW: 1,
  USED: 2,
  SALES_RANK: 3,
  LISTPRICE: 4,
  NEW_FBA: 10,
  BUY_BOX_SHIPPING: 18,
  COUNT_NEW: 11,
  RATING: 16,
  COUNT_REVIEWS: 17,
} as const;

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
