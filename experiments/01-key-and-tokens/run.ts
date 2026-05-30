/**
 * Experiment 01 — key validation + live token meter.
 *
 * Hits GET /token, which (per the brief) validates the key and reports the live
 * token balance/refill WITHOUT spending tokens. Safest possible first real call.
 *
 * Run: bun run 01-key-and-tokens/run.ts
 */
import { get, logMeter, readMeter, defaultDomain } from "../lib/keepa";

const json = await get("token", {});

console.log("=== /token raw ===");
console.log(JSON.stringify(json, null, 2));

console.log("\n=== meter ===");
logMeter("/token", null, json);

const m = readMeter(json);
const minutesToFull =
  m.refillRate > 0 ? Math.ceil((/* assume cap ~= rate*60 */ m.refillRate * 60 - m.tokensLeft) / m.refillRate) : NaN;

console.log("\n=== interpretation ===");
console.log(`marketplace under test : domainId=${defaultDomain()} (3 = amazon.de)`);
console.log(`tokens available now   : ${m.tokensLeft}`);
console.log(`refill rate            : ${m.refillRate} tokens/min`);
console.log(`~minutes to refill cap : ${Number.isFinite(minutesToFull) ? minutesToFull : "n/a"}`);
console.log(
  `\nRule of thumb: 1 token ≈ 1 full product lookup. So you can deep-look ~${m.tokensLeft} ASINs right now,` +
    ` and sustain ~${m.refillRate}/min thereafter.`,
);
