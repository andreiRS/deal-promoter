/**
 * Thin Amazon Creators API client (OAuth 2.0 + REST), shared across experiments.
 *
 * This is the second integration client in this suite (Keepa is the first, in
 * lib/keepa.ts). It is deliberately minimal: enough to do the OAuth
 * client-credentials exchange, cache the bearer token, and make a real GetItems
 * call against amazon.de. The production Deal Promoter is PHP and will use the
 * official SDK (thewirecutter/paapi5-php-sdk v2.x), so treat this as a reference
 * port target and a way to settle the brief's CONFIRM items, NOT shippable code.
 *
 * Docs basis: docs/research/creators-api.md
 *
 * Several constants below are marked CONFIRM: the brief documents the token
 * endpoints but not the product-API host, and the exact auth path depends on our
 * credential Version. Experiment 06 confirms the auth path; 07 confirms the
 * product host + response shape. Correct the constants here as findings land.
 */

// ---------------------------------------------------------------------------
// Env getters (throw on unset/placeholder, mirroring lib/keepa.ts:apiKey()).
// ---------------------------------------------------------------------------

function reqEnv(name: string, placeholder: string): string {
  const v = process.env[name];
  if (!v || v === placeholder) {
    throw new Error(
      `${name} is not set. Copy experiments/.env.example to experiments/.env and fill it in.`,
    );
  }
  return v;
}

export function credentialId(): string {
  return reqEnv("CREATORS_CREDENTIAL_ID", "your_credential_id_here");
}

export function credentialSecret(): string {
  return reqEnv("CREATORS_CREDENTIAL_SECRET", "your_credential_secret_here");
}

/** Credential Version, e.g. "2.2" (Cognito) or "3.2" (Login with Amazon). */
export function version(): string {
  return reqEnv("CREATORS_VERSION", "your_version_here");
}

export function marketplace(): string {
  return process.env.CREATORS_MARKETPLACE ?? "www.amazon.de";
}

export function partnerTag(): string {
  return reqEnv("CREATORS_PARTNER_TAG", "your_partner_tag_here");
}

/**
 * Major version number (2 or 3) — selects the auth path. The credential page
 * writes the Version with a leading "v" (e.g. "v3.2"), so strip non-digits
 * before parsing or a v2.x credential would silently fall through to the 3.x path.
 */
export function versionMajor(): number {
  return Number(version().replace(/[^\d.]/g, "").split(".")[0]);
}

// ---------------------------------------------------------------------------
// OAuth 2.0 client-credentials, with an in-memory token cache.
//
// Path depends on the credential Version major (CONFIRM exact host/scope/suffix
// in experiment 06):
//   2.x -> Cognito:           form-encoded body, scope "creatorsapi/default",
//                             Authorization product header needs ", Version <n>".
//   3.x -> Login with Amazon: JSON body, scope "creatorsapi::default",
//                             no Version suffix on the product header.
// ---------------------------------------------------------------------------

const TOKEN_ENDPOINT_COGNITO =
  "https://creatorsapi.auth.eu-south-2.amazoncognito.com/oauth2/token"; // v2.x, EU
const TOKEN_ENDPOINT_LWA = "https://api.amazon.co.uk/auth/o2/token"; // v3.x, EU

interface CachedToken {
  accessToken: string;
  expiresAt: number; // epoch ms when we should stop trusting it
}
let cachedToken: CachedToken | null = null;

/** True for v2.x credentials, which use Cognito + the ", Version <n>" suffix. */
export function isCognito(): boolean {
  return versionMajor() === 2;
}

export function tokenEndpoint(): string {
  return isCognito() ? TOKEN_ENDPOINT_COGNITO : TOKEN_ENDPOINT_LWA;
}

export function scope(): string {
  return isCognito() ? "creatorsapi/default" : "creatorsapi::default";
}

/**
 * Raw token exchange (always hits the network). Returns the full parsed token
 * response so a probe can inspect expires_in / token_type / scope. Prefer
 * getToken() for normal use — it caches.
 */
export async function fetchTokenResponse(): Promise<any> {
  const endpoint = tokenEndpoint();
  let res: Response;
  if (isCognito()) {
    // Cognito: form-encoded, client creds in the body.
    const body = new URLSearchParams({
      grant_type: "client_credentials",
      scope: scope(),
      client_id: credentialId(),
      client_secret: credentialSecret(),
    });
    res = await fetch(endpoint, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body,
    });
  } else {
    // Login with Amazon: JSON body.
    res = await fetch(endpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        grant_type: "client_credentials",
        scope: scope(),
        client_id: credentialId(),
        client_secret: credentialSecret(),
      }),
    });
  }
  const text = await res.text();
  if (!res.ok) {
    throw new Error(`Creators token HTTP ${res.status}: ${text.slice(0, 500)}`);
  }
  try {
    return JSON.parse(text);
  } catch {
    throw new Error(`Creators token returned non-JSON: ${text.slice(0, 500)}`);
  }
}

/**
 * Cached bearer token. Re-uses the in-memory token until ~5 min before its
 * stated expiry (token life is ~3600s; the SDK README warns the cache is
 * per-instance, which is why the production CLI keeps one instance per cycle).
 */
export async function getToken(): Promise<string> {
  if (cachedToken && Date.now() < cachedToken.expiresAt) {
    return cachedToken.accessToken;
  }
  const json = await fetchTokenResponse();
  const expiresInSec = Number(json.expires_in ?? 3600);
  cachedToken = {
    accessToken: json.access_token,
    expiresAt: Date.now() + (expiresInSec - 300) * 1000, // 5 min safety buffer
  };
  return cachedToken.accessToken;
}

/** True if the in-memory cache currently holds a still-valid token. */
export function hasCachedToken(): boolean {
  return !!cachedToken && Date.now() < cachedToken.expiresAt;
}

// ---------------------------------------------------------------------------
// Product API (GetItems). One call = one transaction regardless of payload, so
// batch up to 10 ASINs per call. lowerCamelCase params (ItemIds/Resources in
// PascalCase are rejected). CONFIRM the product host/path in experiment 07.
// ---------------------------------------------------------------------------

// CONFIRMED from the official SDK (thewirecutter/paapi5-php-sdk v2.x, exp 07):
// single global host on the dotless `.amazon` gTLD, marketplace is the header.
const PRODUCT_BASE = "https://creatorsapi.amazon";
const GET_ITEMS_PATH = "/catalog/v1/getItems";

/**
 * Resource strings — Creators uses **lowercase-dotted camelCase** (NOT the
 * PA-API 5.0 PascalCase `OffersV2.Listings.Price`). detailPageURL returns by
 * default with no resource string. (`parentASIN` is the one uppercase exception.)
 */
export const Resources = {
  ITEM_INFO_TITLE: "itemInfo.title",
  OFFERS_V2_LISTINGS_PRICE: "offersV2.listings.price",
  OFFERS_V2_LISTINGS_CONDITION: "offersV2.listings.condition",
  OFFERS_V2_LISTINGS_AVAILABILITY: "offersV2.listings.availability",
  OFFERS_V2_LISTINGS_MERCHANT_INFO: "offersV2.listings.merchantInfo",
  OFFERS_V2_LISTINGS_DEAL_DETAILS: "offersV2.listings.dealDetails",
  OFFERS_V2_LISTINGS_IS_BUY_BOX_WINNER: "offersV2.listings.isBuyBoxWinner",
} as const;

/** Rate-limit info captured from the last product response headers (CONFIRM). */
export let lastRateInfo: Record<string, string> = {};

const RATE_HEADER_HINTS = ["rate", "throttle", "quota", "tps", "tpd"];

/**
 * GetItems for up to 10 ASINs. Returns parsed JSON (ItemsResult.Items[] +
 * Errors[]). Always reconcile results to input by the ASIN field, never by
 * position, and read Errors[]. Captures any rate-ish response headers into
 * lastRateInfo for logCost().
 */
export async function getItems(
  itemIds: string[],
  resources: string[] = [Resources.ITEM_INFO_TITLE],
): Promise<any> {
  if (itemIds.length > 10) {
    throw new Error(`GetItems batch limit is 10 ASINs, got ${itemIds.length}`);
  }
  const token = await getToken();
  const authValue = isCognito()
    ? `Bearer ${token}, Version ${version().replace(/[^\d.]/g, "")}`
    : `Bearer ${token}`;
  const res = await fetch(`${PRODUCT_BASE}${GET_ITEMS_PATH}`, {
    method: "POST",
    headers: {
      Authorization: authValue,
      "Content-Type": "application/json",
      "x-marketplace": marketplace(),
    },
    body: JSON.stringify({
      // lowerCamelCase keys. marketplace is the x-marketplace HEADER only — it is
      // NOT a body field, and there is no itemIdType. partnerTag + itemIds required.
      partnerTag: partnerTag(),
      itemIds,
      resources,
    }),
  });

  lastRateInfo = {};
  res.headers.forEach((v, k) => {
    if (RATE_HEADER_HINTS.some((h) => k.toLowerCase().includes(h))) {
      lastRateInfo[k] = v;
    }
  });

  const text = await res.text();
  if (!res.ok) {
    throw new Error(`Creators GetItems HTTP ${res.status}: ${text.slice(0, 700)}`);
  }
  try {
    return JSON.parse(text);
  } catch {
    throw new Error(`Creators GetItems returned non-JSON: ${text.slice(0, 700)}`);
  }
}

/**
 * Cost logger (analog of lib/keepa.ts:logMeter). One product call = ONE
 * transaction regardless of how many ASINs were in the batch. Also surfaces any
 * rate-limit headers we managed to capture and the Items/Errors split.
 */
export function logCost(label: string, json: any): void {
  // Response envelope is lowerCamelCase (itemsResult.items[] / errors[]).
  const items = json?.itemsResult?.items?.length ?? 0;
  const errors = json?.errors?.length ?? 0;
  const rate = Object.keys(lastRateInfo).length
    ? ` rate={${Object.entries(lastRateInfo)
        .map(([k, v]) => `${k}=${v}`)
        .join(", ")}}`
    : "";
  console.log(
    `[cost] ${label}: 1 transaction (one call = one transaction) ` +
      `items=${items} errors=${errors}${rate}`,
  );
}
