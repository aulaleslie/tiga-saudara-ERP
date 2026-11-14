# Proposal

## Why

Buyout tab 3 shows receipt-level prices instead of an editable supplier invoice. Operators need one product row with one agreed price across the document, and later need ordinary monetary editing on generated consignment and buyout Purchases.

## What Changes

- Keep the existing three-tab buyout wizard. Make tab 3 the complete financial entry form using normal consignment controls: unit price, fixed/percentage row discount, tax, editable line total, global discount, invoice references, dates, due date/payment term, notes and attachments.
- Show one row per product with combined selected quantity. Keep receipt, sold/unsold, serial, location and ownership allocations in expandable supporting details. One product has one price, discount and invoice tax across this buyout.
- Prefill a price when contributing receipt prices agree; otherwise require explicit entry. A final line-total override recalculates the displayed price; changing price, discount or tax clears the override.
- Let draft and rejected buyouts reopen the same wizard with saved selections and financial inputs. Keep approval locking both goods and financial terms; existing finalization uses those saved terms with no separate financial editor.
- Generate one Purchase detail per product for newly prepared buyouts while preserving exact original and accepted source lineage.
- Enable the existing Purchase monetary editor for existing and future consignment-billing and buyout Purchases, under standard status, permission, tenant and payment safeguards. Keep historical Purchase rows intact.
- Keep the normal consignment conversion screen unchanged. This change does not extend cost recalculation, supplier returns, cancellation, deletion or reversal. Monetary edits do not change stock, acquisition cost or source evidence; existing administrative and payment capabilities remain.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `consignment-buyout-preparation`: Product-grouped financial entry in tab 3 and reopening drafts in the same wizard.
- `consignment-stock-buyout`: One commercial product row and one generated Purchase detail per product, retaining internal acquisition slices and existing approval/finalization behavior.
- `consignment-supplier-billing`: A narrow monetary-edit exception for both generated Purchase types while preserving other source restrictions and historical provenance.

## Impact

Primarily Consignment buyout preparation controller/views/routes, pricing adapter, draft lifecycle and detail generation; Purchase edit eligibility, source/model guards and the existing monetary editor. Reuse the current billing calculator, Purchase attachments, date/payment-term patterns and financial controls. Preserve existing inventory, costing, reservation and reversal services. No new cost ledger, execution adapter, acquisition-only approval hash or replacement lifecycle is planned.
