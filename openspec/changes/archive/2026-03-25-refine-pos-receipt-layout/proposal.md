## Why

The current POS receipt implementation is overly simplistic and does not meet the user's branding and layout requirements. Specifically:
- The visual layout is basic and doesn't match the desired professional "thermal printer" look.
- There is a regression/bug where multi-payment nominals are displayed as 0 due to an incorrect property reference in the receipt service.
- Itemized unit conversion breakdowns (e.g., selling by BOX vs RIM with detailed conversion info) are missing, which are critical for retail operations.
## Proposed Changes

### POS Receipt Layout Refinement

#### [MODIFY] [PosReceiptService.php](file:///home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/Modules/Pos/Services/PosReceiptService.php)
- Update `loadMissing` to include `transaction.lines.product.unit`.
- Update item loop to fallback to product base unit if `conversion` is missing.

#### [MODIFY] [receipt.blade.php](file:///home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/Modules/Pos/Resources/views/receipt.blade.php)
- Remove "Pajak" (Tax) section.
- Remove "Subtotal" section (redundant with Total).
- Ensure "Kembalian" (Change) is clearly visible and prioritized after payments.

## Verification Plan

### Automated Tests
- Update `POSReceiptGenerationTest.php` to assert absence of Tax/Subtotal and presence of base units for standard products.

## What Changes

- **Visual Redesign**: Redesign `receipt.blade.php` to match the reference image provided, including centered headers, dashed dividers, and specific item/total alignments.
- **Data Preparation Fix**: Update `PosReceiptService` to correctly extract payment amounts using the `amount` accessor instead of an invalid `amount_paid` property.
- **Unit Conversion Breakdown**: Enhance the receipt data pipeline to include unit conversion details (name and rate) for each sale line, sourced from `PosTransactionLine`.

## Capabilities

### New Capabilities
- `pos-professional-receipt`: Standardized, professional thermal receipt layout with support for multi-payment breakdowns and unit conversion details.

### Modified Capabilities
- `pos-checkout-finalize-integration`: Modified to ensure finalization data includes sufficient snapshots for the professional receipt generation.

## Impact

- **Modules/Pos**: Primary impact area for service and view changes.
- **Templates**: `receipt.blade.php` will be significantly restructured.
- **Services**: `PosReceiptService` logic will be updated to fetch deeper relationship data from transactions.
