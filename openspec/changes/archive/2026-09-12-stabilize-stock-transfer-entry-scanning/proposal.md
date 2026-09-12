## Why

Stock-transfer entry already supports product barcodes, unit-conversion barcodes, serials, and tokenized search, but its intermediate Livewire mutations do not consistently reconstruct and validate intent from authoritative server data. Rapid scans, crafted component calls, invalid conversion factors, and serial state changes can therefore produce ambiguous row state or defer failures until final draft validation.

## What Changes

- Characterize the existing product-barcode, conversion-barcode, serial-scan, and tokenized-search behavior with focused automated coverage.
- Process rapid scanner submissions deterministically so every captured scan is applied at most once, in capture order, against the state produced by preceding scans.
- Replace scan event payloads as mutation authority with minimal intent identifiers and authoritative server-side product, conversion, stock, condition, and serial resolution at each mutation boundary.
- Accept conversion barcodes only when their current conversion factor is positive and represents a whole base-unit quantity; never silently truncate, round, or coerce an invalid factor.
- Reject duplicate or ineligible serial selections immediately without changing row quantity, while preserving final save, submit, and dispatch revalidation.
- Enforce good-stock and broken-stock isolation consistently across exact scanning, search selection, row mutation, and final validation.
- Preserve the archived `stockTransfers.view-system-stock` boundary: blind users receive minimal projections and neutral non-quantitative feedback, while authorized users retain appropriate operational detail.
- Prefer focused tests and narrow corrections; do not replace the existing transfer scan resolver without demonstrated need.

## Capabilities

### New Capabilities

<!-- None. This change stabilizes an existing capability. -->

### Modified Capabilities

- `stock-transfer-entry-scanning`: Tighten deterministic rapid-scan handling, authoritative per-mutation validation, conversion normalization, duplicate handling, condition isolation, and blind-safe feedback for the existing entry workflow.

## Impact

- Transfer Livewire product search, scanner input, product table, and parent form coordination under `app/Livewire/Transfer` and `resources/views/livewire/transfer`.
- Transfer-specific scan resolution and existing stock/allocation/serial validation services in `Modules/Adjustment`.
- Focused Laravel feature, Livewire component, crafted-request, and browser-interaction tests for stock-transfer entry.
- No schema change, new permission, movement-document redesign, inventory mutation timing change, PKP route-policy change, or full-suite verification requirement.
