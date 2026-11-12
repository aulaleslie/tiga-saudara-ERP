# Proposal

## Why

Transaction 11364 stores 20 units at Rp1,750 with a correct Rp35,000 total, but its receipt prints `20 PACK(S) @ Rp175`. Receipt formatting divides an authoritative unit-price override by the retained conversion factor and labels base quantities as packs.

## What Changes

- Render `LINE_UNIT_PRICE_OVERRIDE` rows using their recorded unit price without conversion-factor division.
- Match the printed unit to the quantity basis, using recorded base-unit labels for conversion-capable overridden rows.
- Keep draft/loaded, checkout snapshot, historical, and completed projection formatting consistent while preserving current Sale-derived price authority.
- Add focused regressions for the reported case and normal packed pricing; preserve stored amounts and existing receipt financial behavior.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-receipt`: Explicitly require overridden receipt prices and labels to match the recorded quantity basis across receipt mappings.

## Impact

Primarily `Modules/Pos/Services/PosReceiptService.php`, with matching unit-label handling in `PosReceiptReprintProjectionService.php` and focused feature tests in `Modules/Pos/Tests/Feature/`. No schema, checkout arithmetic, permission, or financial data changes. Complements the existing `pos-line-unit-price-override` authority contract.
