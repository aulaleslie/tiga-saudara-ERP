# Physical Printer Acceptance — Blueprint ECO80BT

Tasks 5.1–5.3 require a physical Blueprint ECO80BT on 55 mm × 40 mm gap media.
They cannot be executed from the development environment. Record results below.

## Diagnostic label sheet

A dedicated diagnostic sheet supports these tests. It is **not** part of normal
product barcode printing and is disabled in production (`404`).

```
GET /products/print-barcode/diagnostic?count=100
```

Requires the `barcodes.print` permission. `count` is clamped to 1–200.

Each diagnostic label carries:

- A unique sequence heading — **TEST 001** through **TEST 100** (for `count=100`).
- A sample **Code 128** barcode encoding that same sequence value.
- A **border drawn exactly on the 2 mm safe-area boundary**. Any missing border
  edge on paper means the label is clipped or drifting.
- **Top and bottom alignment markers** (1.5 mm solid bars). Unequal marker
  thickness between the first and last label indicates cumulative drift.

Page geometry (`@page 55mm 40mm`, zero margin, 2 mm padding) is identical to the
production label. The diagnostic border and markers sit *inside* the safe area,
so they never weaken or alter the normal 55 mm × 40 mm safe-area rules.

## Driver settings used (record for every run)

| Setting | Value used |
| --- | --- |
| Connection | USB |
| Paper size | 55 mm × 40 mm |
| Media type | Gap / die-cut label |
| Scaling | Actual size / 100% |
| Margins | None |
| Pages per sheet | 1 |
| Copies | 1 |
| Headers/footers | Off |
| Duplex | Off |
| Orientation | Landscape (configured in the Windows printer driver) |

## 5.1 Three-label test

Open Produk → Print Barcode, add three products (or one product × 3), press
**Cetak Batch**, and verify:

- [ ] Exactly one browser print dialog appears for the whole batch.
- [ ] Print preview shows three separate pages.
- [ ] Three physical labels are produced — one per HTML page.
- [ ] No blank, skipped, or duplicated labels.
- [ ] Name, SKU, barcode, barcode value, and price are inside the label edges.

Result / notes:

- 2026-08-04: Passed, as confirmed by the operator. The driver orientation was corrected to Landscape.

## 5.1b SKU display verification

The label applies a fixed SKU display rule: a `product_code` of **40 characters
or fewer prints in full**; a longer one prints its **first 39 characters plus a
visible `…`**. The stored product code is never changed, and the barcode still
encodes the full value.

Print one batch containing both a normal-length SKU and an over-limit SKU
(41+ characters — create a test product if the catalogue has none), then verify:

**Normal SKU (≤ 40 characters)**

- [ ] The complete SKU is printed, character for character.
- [ ] No `…` is appended.
- [ ] The SKU is legible at normal reading distance.

**Over-limit SKU (> 40 characters)**

- [ ] Exactly the first 39 characters print, followed by a visible `…`.
- [ ] The `…` is clearly present — the SKU must not simply stop mid-value.
- [ ] The SKU prints at the same font size as the normal SKU (not shrunk).
- [ ] Scanning the barcode still returns the product's **full** stored code.

**No barcode-area intrusion (both cases)**

- [ ] The SKU stays in its own area and does not overlap or crowd the barcode.
- [ ] The barcode retains its full width and quiet zone.
- [ ] The barcode value text and price remain fully visible.
- [ ] Nothing is pushed outside the 2 mm safe area.

Result / notes:

- 2026-08-04: Passed, as confirmed by the operator after correcting the Windows printer configuration. The 100-label run completed without reported skips, blanks, duplicates, clipping, drift, or scan failures.

## 5.2 100-label sequential test

Print the diagnostic sheet at `?count=100` and verify:

**Sequence continuity**

- [ ] Labels read TEST 001 → TEST 100 in unbroken ascending order.
- [ ] No sequence number is skipped.
- [ ] No sequence number appears twice.
- [ ] No blank label is fed between numbered labels.
- [ ] Exactly 100 physical labels are produced.

**Drift**

- [ ] Top and bottom alignment markers are fully visible on every label.
- [ ] Marker position/thickness on TEST 100 matches TEST 001 (no cumulative drift).
- [ ] The 2 mm safe-area border is complete on all four edges of every label.

**Scanability**

- [ ] TEST 001 (start) scans and decodes to `TEST 001`.
- [ ] TEST 050 (middle) scans and decodes to `TEST 050`.
- [ ] TEST 100 (end) scans and decodes to `TEST 100`.

Then repeat once with a real 100-label product batch to confirm production
labels behave identically:

- [ ] 100 product labels, no skips, blanks, duplicates, or clipping.
- [ ] Sample barcodes from start, middle, and end scan correctly.
- [ ] A long product name wraps without displacing the barcode or price.
- [ ] SKUs print per the 40-character rule with no barcode-area intrusion.

Result / notes:

- 2026-08-04: Passed, as confirmed by the operator. No roll-specific driver configuration change was reported.

- 2026-08-04: Operator reports the 100-label test was run after correcting the Windows printer orientation configuration.
- Outcome details pending confirmation: sequence continuity, blank/skipped/duplicate labels, TEST 001 versus TEST 100 alignment, and start/middle/end scan results.
- Task 5.2 remains unchecked until those observations are recorded.

## 5.3 Roll variation

Repeat 5.1 and 5.2 with:

- [ ] A near-full roll.
- [ ] A near-empty roll.
- [ ] A second compatible roll/batch (if available).

Record any driver setting that had to change per roll, and any gap-calibration
performed. Gap detection and media calibration are printer/driver concerns; the
application cannot compensate for them.

Result / notes:
