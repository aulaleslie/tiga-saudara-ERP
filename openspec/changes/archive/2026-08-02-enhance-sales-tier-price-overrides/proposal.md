## Why

Sales users can manually negotiate a unit price, but the cart cannot distinguish that deliberate price from an automatically derived tier price. Selecting a customer currently overwrites every non-bundled line, and Sales lacks the editable line-total workflow already available in Purchase. Draft business changes also refresh only tax context even though automatic product prices are business-specific.

## What Changes

- Add an editable Sales **Total Baris** field that reverse-calculates the line unit price using the existing tax and line-discount rules.
- Persist per-sale-line pricing provenance so any committed manual unit-price or line-total edit is protected across save and edit rehydration.
- Reprice only automatic, non-bundled sales lines when the customer changes; retain every manually entered unit price.
- Reprice only automatic, non-bundled sales lines against the target business's `product_prices` row when an authorized user changes a draft sale's business.
- Treat a missing target-business `product_prices` row as a zero automatic price and show a consolidated actionable notification; do not fall back to legacy/global product selling prices for that path.
- Preserve existing bundle pricing, quantity cascade behavior for eligible automatic non-tier lines, tax calculations, discounts, and document-total normalization.

## Capabilities

### New Capabilities

- `sales-manual-line-price-authority`: Durable manual-versus-automatic pricing authority and editable line-total behavior for standard Sales cart lines.

### Modified Capabilities

- `sale-cart-pricing`: Customer-tier repricing must preserve manually priced standard lines and handle missing setting-scoped pricing deterministically.
- `cross-business-purchase-sale-documents`: Sales draft business changes must reprice automatic Sales lines for the selected business while preserving manual pricing; Purchase behavior remains unchanged.

## Impact

- Affects `app/Livewire/Sale/ProductCart.php`, Sales create/edit cart views, Sales edit hydration, `sale_details` schema/model/persistence, and related Livewire feature tests.
- Reuses the Purchase line-total reverse-calculation rules while keeping Sales as the authority for tier and business-scoped price resolution.
- Adds a backward-compatible sale-detail pricing-provenance column and safe treatment for legacy sale details.
