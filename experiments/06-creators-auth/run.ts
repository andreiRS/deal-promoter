/**
 * Experiment 06 — Creators API auth + token (OAuth 2.0 client-credentials).
 *
 * The analog of 01-key-and-tokens for the second API. Confirms, against our
 * real amazon.de credential:
 *   - which auth path our credential Version implies (2.x Cognito vs 3.x LWA)
 *   - the live token endpoint, scope string, and request body format we send
 *   - the token response: token_type, expires_in, scope echo
 *   - that a second getToken() re-uses the in-memory cache (no second exchange)
 *
 * Cost: ~0 — the token endpoint is rate-limited (~300 req / 5 min), not metered.
 * Run: bun run 06-creators-auth/run.ts
 *
 * DO NOT commit the token response: access_token is a live bearer credential.
 * Record confirmed endpoint/scope/expiry in FINDINGS.md, not a sample.json.
 */
import {
  version,
  versionMajor,
  isCognito,
  tokenEndpoint,
  scope,
  fetchTokenResponse,
  getToken,
  hasCachedToken,
} from "../lib/creators";

console.log("=== credential / auth path ===");
console.log(`version          : ${version()} (major ${versionMajor()})`);
console.log(`auth path        : ${isCognito() ? "2.x Cognito" : "3.x Login with Amazon"}`);
console.log(`token endpoint   : ${tokenEndpoint()}`);
console.log(`scope            : ${scope()}`);
console.log(`body format      : ${isCognito() ? "form-encoded" : "JSON"}`);
console.log(`product auth hdr : Bearer <token>${isCognito() ? `, Version ${version()}` : " (no Version suffix)"}`);

// --- raw token exchange (network) ---------------------------------------
console.log("\n=== raw token exchange ===");
const json = await fetchTokenResponse();

// Redact the bearer before printing — never log/commit the full token.
const access = String(json.access_token ?? "");
const redacted = {
  ...json,
  access_token: access ? `${access.slice(0, 6)}…(${access.length} chars, redacted)` : undefined,
};
console.log(JSON.stringify(redacted, null, 2));

console.log("\n=== token shape ===");
console.log(`token_type       : ${json.token_type ?? "n/a"}`);
console.log(`expires_in       : ${json.expires_in ?? "n/a"}s`);
console.log(`scope echoed     : ${json.scope ?? "n/a"}`);
console.log(`access_token len : ${access.length} chars`);

// --- caching check ------------------------------------------------------
console.log("\n=== cache check ===");
console.log(`cache populated? : ${hasCachedToken()} (expected false before getToken)`);
const t1 = await getToken();
const t2 = await getToken();
console.log(`cache populated? : ${hasCachedToken()} (expected true after getToken)`);
console.log(`two getToken() return same token? : ${t1 === t2} (expected true — cached, no 2nd exchange)`);

console.log(
  "\nIf the endpoint/scope/body above were rejected, the brief's auth path is wrong " +
    "for our Version — fix the constants in lib/creators.ts and record it in FINDINGS.md.",
);
