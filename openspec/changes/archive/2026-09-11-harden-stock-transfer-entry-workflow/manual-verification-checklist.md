# Manual Browser Verification Checklist — Harden Stock Transfer Entry Workflow

Automated tests cover the logic; this checklist is for a human to confirm the
real browser experience before sign-off.

## 1. Draft without destination

- [ ] Open "New Transfer". Select an origin location, a stock condition (Baik/Rusak), and add at least one product row.
- [ ] Leave destination empty and click "Simpan Draf".
- [ ] Confirm the transfer saves as `DRAFT` and redirects without error.

## 2. Later submission

- [ ] Reopen the saved draft. Select a destination location.
- [ ] Click "Ajukan Persetujuan".
- [ ] Confirm the transfer transitions to `PENDING` and destination is persisted.
- [ ] Reopen the draft without selecting a destination and click "Ajukan Persetujuan" — confirm it is rejected with a clear Bahasa Indonesia message.

## 3. Searchable locations

- [ ] Origin field: type to search; only active locations owned by your current business appear.
- [ ] Destination field: type to search; locations from other businesses appear, labeled with their company name to disambiguate.

## 4. Destination exclusion

- [ ] Select an origin, then open the destination dropdown — confirm the selected origin location does not appear as a destination option.

## 5. Origin/mode table resets

- [ ] Add product rows, then change the origin location — confirm destination and all rows are cleared.
- [ ] Add product rows, then change the stock condition (Baik ↔ Rusak) — confirm rows are cleared but origin/destination remain set.
- [ ] Re-select the *same* origin (no actual change) — confirm rows are NOT cleared.

## 6. Destination row preservation

- [ ] Add product rows, then change/clear the destination — confirm rows remain intact.

## 7. Good/breakage entry

- [ ] With "Baik" mode selected, confirm product search/scan only surfaces products with good stock at the origin.
- [ ] Switch to "Rusak" mode, confirm product search/scan only surfaces products with broken stock.
- [ ] Scan the same product twice in one mode — confirm it increments the existing row instead of adding a duplicate.
- [ ] Confirm the scan input regains focus after a successful scan and after a rejected/failed scan.

## 8. Edit hydration

- [ ] Open an existing DRAFT transfer for edit — confirm origin, destination, mode, and rows all hydrate correctly.
- [ ] Open a historical transfer with mixed good/broken buckets (pre-existing data) — confirm it displays a warning and no editable rows until a mode is explicitly chosen.

## 9. Origin-gated entry

- [ ] On a fresh "New Transfer" form with no origin selected, confirm the product search/scan input is visibly disabled with guidance text.
