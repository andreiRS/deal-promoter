# 4. The cross-run page cursor lives on CycleRun

Date: 2026-06-01
Status: Accepted

## Context

`RunCycleCommand` sweeps a fixed `pagesPerCycle` (=2) of Keepa `/deal` pages each
Cycle. Until now it started at page 0 every run, so back-to-back Cycles re-scanned
pages 0–1 and never reached deeper (less-discounted) pages unless the early pages
churned. Duplicate *publishes* are already prevented by the Already-Posted Guard,
so this is purely a discovery-coverage and Keepa-token-efficiency concern, not
double-posting.

To page deeper across runs, a Cycle needs to remember where the previous one
stopped — a single integer, the next start page. The question is where that
cursor lives. Options considered:

1. A `next_start_page` column on `CycleRun` (one row already persisted per Cycle).
2. A dedicated single-row `PipelineCursor` key-value entity holding just the
   integer.
3. Derive it implicitly from the last `CycleRun`'s start page plus the pages it
   fetched, storing nothing new.

Option 3 stores no explicit cursor, but the wrap rule (reset on end-of-feed) and
budget-truncation rule (advance by pages *actually* fetched, which can be fewer
than `pagesPerCycle`) are not reconstructable from the funnel counts alone — the
Cycle would have to also persist "did I hit the end of the feed" and "how many
pages did I fetch," which is most of a cursor anyway. Option 2 is clean
separation of run-history from pipeline-state, but adds an entity, a repository,
and a migration to hold one integer.

## Decision

Store the cursor as a `next_start_page` integer column on `CycleRun`.

- **Read:** at Cycle start, `CycleRunRepository::findLatest()?->getNextStartPage()
  ?? 0` — the existing "latest Cycle" query, defaulting to 0 on a fresh DB.
- **Write:** the Cycle sets `next_start_page` on its own `CycleRun` and it rides
  the existing single `flush()`. The run-lock (`LOCK_KEY`, non-blocking) already
  serialises Cycles, so the cursor has exactly one writer with no extra locking.
- **Advance by pages actually fetched:** `next_start_page = startPage +
  pagesFetched`, never `startPage + pagesPerCycle`. A Cycle that stops early
  because `tokensLeft < KEEPA_PAGE_COST` resumes after its last real page, not
  after a page it never reached.
- **Wrap to 0 only on end-of-feed:** when a fetched page comes back empty, the
  sweep `break`s and the cursor resets to 0. Keepa's `/deal` feed reorders
  constantly, so "deeper pages" is a soft concept; the feed's true depth drives
  the wrap with no interval to tune. Periodic wrap (every N Cycles) was rejected
  because it would re-scan page 0 before deeper pages are exhausted, defeating the
  point.

## Consequences

- No new entity, repository, or read path — the cursor reuses `CycleRun`'s flush
  and `findLatest()`. One migration adds the column (`INT NOT NULL DEFAULT 0`), so
  existing rows resume from the top.
- The cursor's history is implicit in the Cycle log: each `CycleRun` row shows
  where the next one resumed, which doubles as an audit trail of paging
  progression.
- Coupling: pipeline paging-state now lives on the run-history entity rather than
  in a dedicated state row. If a second independent cursor ever appears (e.g. a
  parallel non-`/deal` feed), this column won't generalise and a `PipelineCursor`
  abstraction should be reconsidered — at which point this ADR is the thing to
  supersede.
- The `pagesPerCycle` ctor arg stays the volume/cost dial; the cursor is
  orthogonal to it and they compose (truncated sweeps simply advance the cursor
  less).
