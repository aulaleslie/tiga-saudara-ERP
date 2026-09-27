## Context

The ERP system currently enforces a "Tax-by-Default" policy when a business is registered as PKP (Pengusaha Kena Pajak). This is implemented through multiple fallback mechanisms in the purchase and sale carts, as well as hardcoded defaults in the product import logic. While intended as a convenience, it frequently results in incorrect tax assignment for products that should be non-pajak or have different tax rates.

## Goals / Non-Goals

**Goals:**
- Eliminate silent auto-assignment of taxes in all system carts.
- Ensure product imports respect the presence or absence of tax data in CSV files.
- Require explicit user action for tax assignment in PKP mode.

**Non-Goals:**
- Removing the `is_pkp` enforcement logic (users will still be warned/blocked if they save a transaction without tax in PKP mode).
- Modifying the tax calculation formulas themselves.

## Decisions

- **Carts (Livewire/Alpine):** The `resolvePreferredPkpAutoTaxId` method (and its Alpine equivalent) will be refactored to remove the fallback to `resolveDefaultTaxId()`. It will only return a tax ID if explicitly provided by the product definition or the input payload.
- **CSV Import:** The `handleCsvUpload` method in `ProductController` will be updated to default `purchase_tax_id` and `sale_tax_id` to `null` instead of hardcoded `1`.
- **User Interface:** The `TaxSearchDropdown` will continue to show "Wajib Pilih Pajak" (Tax Selection Required) when in PKP mode and the selection is null, but it will no longer be pre-filled.

## Risks / Trade-offs

- **User Friction:** Users who previously relied on the auto-assignment for efficiency will now need to perform an extra click to select their default tax. This is considered acceptable to ensure data integrity.
- **Validation Errors:** Users might encounter more "Tax is required" validation errors during transaction saving if they forget to select a tax in PKP mode.
