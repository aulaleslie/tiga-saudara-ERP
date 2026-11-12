# Verification (2026-11-12)

## Query counts (preparation GET, representative SQLite data)

| Dataset | Before (old screen, `propose=all`) | After |
|---|---|---|
| 8 receipts sharing one pool, 12 attributed sold sources | 2,811 | 338 |
| 16 receipts, 40 attributed sold sources | not measured | 898 |

Measured with `ConsignmentBuyoutPreparationPerformanceTest` (`BUYOUT_PREP_SIZE=16,40` for the larger set). The "after" count grows linearly with receipts and sold sources; the repeated per-pool and per-source evidence reads are gone. The ~7,985-query figure came from the reviewed environment's data and was not reproduced here.

## Automated

- `php artisan test --filter=ConsignmentBuyoutPreparationScreenTest`: 9 passed (complete/partial selection, serials by number, local destination, blocked rows, old-input rehydration, row-key flash, prices/limits, permissions/gate).
- `ConsignmentRemoteBuyoutTest::the_preparation_save_applies_one_destination_per_holding_business_to_local_and_remote_lines`: passed.
- `ConsignmentBuyoutPreparationPerformanceTest`: 2 passed (budget; memo scoped, mutation-shaped reads fresh).
- `bash scripts/test-consignment-baseline.sh`: 1,044 passed, 0 failed (5,213 assertions).
- Client-side selection, pool/source limits, bucket derivation and totals were run under Node with a stub config. They have not been tested in a browser.

## Not done

- Human UAT (task 5.1) is still open. Neither this change nor the existing ones are archived.

# Step 2 refinement verification (2026-11-12)

## Real data: TOP IT (setting 2) / CANDRA (supplier 11), local MySQL
These counts are for the read-model call (`prepare` under `readOnlyEvidence`). A full logged-in HTTP request was not measured; see the browser note below.

| | Queries |
|---|---|
| Same read model with the memo disabled | 5,027 |
| First iteration (before return-evidence reuse) | 1,624 |
| Now | 1,580 |

Result: receipt `TPI-CRN-2026-08-00001`, 2 products (AXIOO HYPE 1 / HYPE 2):
- 24 eligible sold units, 24 distinct serials, none duplicated. Each shows its POS receipt (e.g. `POS TNC-RCP-2026-10-01776`), the Sales reference (`TPI-JL-2026-10-00170`) and "dijual oleh CV TIGA NUSA COMPUTER". The sale was rung up at TIGA NUSA's POS against TOP IT stock. The link goes to `sales.show`, because the POS checkout belongs to another business.
- 39 fully billed sales are summarised, not shown as blocked units.
- Unsold stock (2 rows) is blocked: sellable stock (4) is lower than the receipt residual (7). This is shown with the reconciliation action.
- There is no remote custody in this data (0 provenance events, 0 bindings), so remote stock was checked with fixtures.

Query growth here is linear in sold sources (64 at this location, about 25 queries each), mostly from shared provenance and remote-sale lineage reads. I left those services unchanged.

## Browser
Headless Chrome (Playwright) drove fixture-rendered pages: real app HTML from feature requests, with assets served by the local dev server. Covered:
- Grouping order: receipt → product → Belum terjual → Terjual belum ditagih.
- Each serial shown once as a checkbox.
- Select-all, clear-all, product-level and receipt-level select/deselect, with partial indicators after unticking one serial or reducing a quantity.
- Sales links shown with `sales.show`.
- Destination guard, then back-navigation with the selection kept.
- Review totals, and the posted fields (row keys, identities, serial ids, quantities).
- Non-serialized split of one sale across two receipts.
- Failed-save recovery (step 3, destinations and partial quantities restored).
- Remote location / acquiring-business groups and per-business destination selectors.

Bugs found and fixed in the browser:
- Invalid Alpine `@submit` expression.
- Unreadable receipt heading.
- A phantom duplicate unsold row from repeated custody entries at one location. Fixed in the proposal and covered by a regression test.

A logged-in browser session against the real TOP IT / CANDRA data could not be created from this session (minting a session was refused), so the real-data check above is at read-model level. This is still open under human UAT 6.1.

## Automated
- `ConsignmentBuyoutPreparationScreenTest`: 12 passed.
- `ConsignmentRemoteBuyoutTest`: 16 passed.
- `ConsignmentBuyoutPreparationPerformanceTest`: 2 passed; 318 queries on the 8×12 fixture (was 338).
- `bash scripts/test-consignment-baseline.sh`: 1,048 passed, 0 failed (5,241 assertions).
