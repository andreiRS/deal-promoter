/**
 * Experiment 07 — Creators GetItems (request + ItemsResult/Errors response shape).
 *
 * The analog of 02-deals / 03-product for the second API. Confirms, against the
 * live amazon.de marketplace, the hot-path primitive of the validation stage:
 *   - the GetItems JSON we send (lowerCamelCase itemIds/resources; marketplace is a header)
 *   - the envelope: itemsResult.items[] with top-level asin + detailPageURL
 *   - that detailPageURL already carries our tag + linkCode (we must not hand-build links)
 *   - that an invalid ASIN lands in errors[], NOT in items[] (reconcile by asin, not position)
 *   - the real transaction cost (one call = one transaction regardless of batch size)
 *
 * Cost: 1 transaction. Run: bun run 07-creators-getitems/run.ts
 *
 * ASINs: GOOD is a Keepa exp-05 survivor (Bosch sensor), so this reruns without
 * touching Keepa. BAD is a deliberately invalid ASIN to exercise Errors[].
 */
import { getItems, logCost, Resources, partnerTag } from "../lib/creators";

const GOOD = "B0010AH4BW"; // Bosch Luftmassenmesser — exp-05 survivor
const BAD = "B000000000"; // not a real ASIN — expected in Errors[]

const itemIds = [GOOD, BAD];
const resources = [Resources.ITEM_INFO_TITLE]; // DetailPageURL returns by default

console.log("=== request ===");
console.log(JSON.stringify({ itemIds, partnerTag: partnerTag(), resources }, null, 2));

const json = await getItems(itemIds, resources);

await Bun.write(
  `${import.meta.dir}/out/getitems-amazon-de.dump.json`,
  JSON.stringify(json, null, 2),
);

console.log("\n=== cost ===");
logCost("/getitems", json);

// --- envelope (lowerCamelCase, per the SDK) -----------------------------
const result = json.itemsResult ?? {};
const items: any[] = result.items ?? [];
const errors: any[] = json.errors ?? result.errors ?? [];
console.log("\n=== envelope ===");
console.log(`top-level keys     : ${Object.keys(json).join(", ")}`);
console.log(`itemsResult keys   : ${Object.keys(result).join(", ")}`);
console.log(`items returned     : ${items.length} (expected 1 — the good ASIN)`);
console.log(`errors returned    : ${errors.length} (expected 1 — the bad ASIN)`);

// --- reconcile by ASIN (never by position) ------------------------------
console.log("\n=== reconcile by ASIN ===");
for (const asin of itemIds) {
  const item = items.find((i) => i.asin === asin);
  const err = errors.find((e) => String(e.asin ?? e.itemId ?? "").includes(asin));
  if (item) {
    const tagged = /[?&]tag=/.test(item.detailPageURL ?? "");
    console.log(
      [
        `\n${asin} -> items[]`,
        `  title          : ${String(item.itemInfo?.title?.displayValue ?? "n/a").slice(0, 70)}`,
        `  detailPageURL  : ${item.detailPageURL ?? "n/a"}`,
        `  carries tag?   : ${tagged} (expected true — API-built affiliate link)`,
      ].join("\n"),
    );
  } else if (err) {
    console.log(
      `\n${asin} -> errors[]  code=${err.code ?? err.Code ?? "?"} msg=${String(err.message ?? err.Message ?? "").slice(0, 100)}`,
    );
  } else {
    console.log(`\n${asin} -> MISSING from both Items[] and Errors[] (surprise — note it)`);
  }
}

console.log(
  "\nNext: trim out/getitems-amazon-de.dump.json into a committed sample.item.json " +
    "(one Items[] record + the Errors[] entry; scrub the tag if needed) and write FINDINGS.md.",
);
