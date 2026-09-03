## Why

Purchases currently accept and persist only base-unit quantities even when suppliers quote and deliver products in configured conversion units, forcing users to convert quantities and prices manually and losing the supplier-facing unit context. Purchase also participates in configurable row-total increment rounding even though users require purchase amounts to remain exact; Sales and POS must retain their existing rounding behavior.

## What Changes

- Let users select either a product's base unit or an eligible configured conversion unit for each Purchase create/edit line, including separate lines for the same product in different units.
- Preserve the entered unit, quantity, unit price, and conversion factor as historical Purchase-detail snapshots while storing operational quantity and unit price in the product's base unit.
- Carry the ordered unit into receiving by default, allow receiving in either the ordered unit or base unit, and convert all receipt, stock, serial, return, and eligibility quantities to base units.
- Support decimal purchase and receiving quantities without silent truncation or rounding, subject to supported precision and whole-base-unit rules for serialized products.
- Establish that a product's base unit is its smallest counting unit and require new or edited conversion factors to be greater than `1`; serialized-product conversion factors must be whole numbers.
- Exclude invalid legacy conversion rows from new transactions without rewriting historical products or documents.
- Remove configurable increment rounding from Purchase calculations only, while preserving existing manual unit-price and manual line-total behavior and leaving Sales and POS rounding intact.
- Add focused tests for the new behavior and directly affected regression paths; a full-suite run is not part of this change's required verification.

## Capabilities

### New Capabilities
- `purchase-conversion-unit-entry`: Purchase create, edit, persistence, receiving, serialization, decimal handling, and downstream base-unit behavior for conversion-unit lines.
- `product-unit-conversion-invariants`: Product conversion validation establishing the base unit as the smallest unit and restricting usable conversion factors.

### Modified Capabilities
- `transaction-row-total-rounding`: Remove Purchase from configurable automatic row-total increment rounding while preserving exact Purchase calculations, manual pricing authority, and unchanged Sales/POS behavior.
- `currency-storage-convention`: Permit decimal(15,6) storage for canonical purchase_details.price and unit_price columns to preserve high-precision base-unit prices without costing drift, while other monetary totals remain decimal(15,2).

## Impact

- Affects Purchase Livewire cart/search screens, controller normalization and persistence, Purchase detail hydration, receiving validation/UI, receipt approval, and directly dependent return/report/print paths.
- Adds nullable historical snapshot fields and decimal-compatible receiving storage while keeping existing rows compatible as implicit base-unit lines.
- Hardens product conversion validation in normal product forms, quick-add, and other conversion-writing entry points.
- Requires decimal-safe conversion arithmetic and stable cart line identity based on product plus selected unit rather than product alone.
- Updates the existing rounding contract and Purchase-specific rounding tests without changing the business rounding setting or its Sales/POS consumers.
