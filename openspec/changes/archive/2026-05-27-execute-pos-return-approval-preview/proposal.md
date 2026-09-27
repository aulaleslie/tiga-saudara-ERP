## Why

POS Return approval preview now provides a reliable read-only execution plan, but approvers still cannot persist that plan as the actual return execution. The next step is to turn a ready preview into a one-click, audited execution path that keeps inventory, serials, Sales Return records, original Sale documents, dispatch quantities, and payments consistent.

## What Changes

- Add final approval execution from the POS Return approval preview page when the preview has zero blockers and zero warnings.
- Persist the preview plan into linked owner/sale/location/tax-aligned Sales Return headers and details, including resolution-sensitive bundle movement: cash returns include proportional component reversals, while product replacement keeps components informational and executes only the parent product replacement.
- Execute approval as one atomic operation using `pos.returns.approve`: approve, receive returned stock/serials, settle cash-return effects, dispatch replacements, complete linked Sales Returns, and complete the POS Return.
- For cash-return lines, modify the original Sale to reflect the corrected commercial outcome: reduce customer-facing Sale detail quantity/amount, reduce active dispatch quantity, split/invalidate Sale payments purchase-style, create refund evidence, and archive the Sale as returned when both Sale quantity and active dispatch quantity reach zero.
- For product-replacement lines, preserve the original Sale quantity and money while receiving the returned item, keeping the original serial visible as returned, creating an approved replacement dispatch from the original source owner/location, and showing the replacement serial as replacement lineage.
- Extend Sale payment records with purchase-style invalidation metadata so POS cash returns can keep active Sale payments aligned with the modified Sale total.
- Extend Sale serial/dispatch display lineage so returned original serials remain red and replacement serials appear blue in the Sale document.
- Enforce bundle return rules: components cannot be returned alone; cash-returning a bundle parent automatically includes proportional parent and component reversals, while replacing a bundle parent receives and dispatches only the parent product and leaves components as read-only composition context.

## Capabilities

### New Capabilities
- `pos-return-approval-execution`: Final execution of a ready POS Return approval preview into stock, serial, Sales Return, Sale, dispatch, and payment mutations.
- `sale-payment-invalidation`: Purchase-style active/invalidated Sale payment state needed to split and neutralize payments after Sale totals are modified by cash returns.
- `sale-return-serial-lineage-display`: Sale document display of returned original serials and replacement serials after POS Return execution.

### Modified Capabilities
- `pos-return-approval-preview`: Approval preview changes from preview-only to a gated execution surface that exposes final approval only when blockers and warnings are both absent.

## Impact

- `Modules/Pos`: `PosReturnController`, approval preview route/view, `PosReturnApprovalPreviewPlannerService`, `PosReturnLifecycleService`, POS Return models, migrations, and focused POS Return tests.
- `Modules/SalesReturn`: linked `SaleReturn` and `SaleReturnDetail` creation/completion, payment/refund records, and existing receiving/settlement/dispatch compatibility.
- `Modules/Sale`: `Sale`, `SaleDetails`, `Dispatch`, `DispatchDetail`, `SalePayment`, `SalesOrderSerialTracking`, Sale show rendering, Sale payment datatable behavior, and migrations for payment invalidation/dispatch lineage.
- `Modules/Product`: `ProductStock`, `ProductSerialNumber`, `Transaction`, and `SerialNumberHistory` mutation behavior for returned and replacement serials.
- Verification must cover atomic rollback, mixed cash/replacement lines, serial and non-serial products, cash-return bundle parent/component movement, parent-only bundle replacement, split-owner sales, Sale payment splitting, Sale archival, and Sale serial badge display.
