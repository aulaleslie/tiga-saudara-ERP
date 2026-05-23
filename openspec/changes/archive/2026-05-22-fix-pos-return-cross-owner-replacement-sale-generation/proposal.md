## Why

POS Return product replacement currently treats the replacement item as fulfillment for the original Sale owner. When a replacement serial comes from another business/location owner, approval can attach that serial to the original owner Sale and dispatch lineage, which corrupts ownership, stock, serial, and payment reporting.

The workflow needs to preserve the original owner Sale correction while generating a new owner-aligned Sale for the replacement serial owner when the replacement stock belongs to a different setting.

## What Changes

- Detect the replacement serial owner from `product_serial_numbers.location_id -> locations.setting_id` during preview and final approval.
- Keep same-owner product replacement behavior owner-aligned, but branch cross-owner replacement into a derived replacement-owner Sale.
- For cross-owner replacement, adjust the original Sale commercially like a returned item, including Sale detail, dispatch quantity, active payment reconciliation, refund/settlement evidence, returned serial lineage, and stock receiving.
- Create a new Sale under the replacement serial owner with the same original product ID, copied original Sale date/header/customer/payment context, generated owner-specific reference, adjusted Sale detail, adjusted Sale payment, approved dispatch, and replacement serial lineage.
- Keep replacement matching strict: replacement serial must belong to the same `product_id` as the returned line.
- Attribute replacement stock mutations and serial dispatch to the replacement serial owner/location, not to the original Sale owner.
- Surface replacement serial owner and cross-owner execution effects in approval preview so approvers can see the generated replacement-owner Sale before approval.
- Preserve atomic approval execution: if original Sale correction, derived Sale creation, payment adjustment, dispatch, stock, serial, or audit mutation fails, the entire approval rolls back.
- Ensure required schema is present for POS return approval execution, including the existing `sale_return_details.execution_context` column used by approval plan persistence.

## Capabilities

### New Capabilities
- `pos-return-cross-owner-replacement`: Defines how POS Return product replacement behaves when the replacement serial is owned by a different setting than the original returned Sale line.

### Modified Capabilities
- `pos-return-approval-preview`: Approval preview must show replacement serial owner and the planned cross-owner generated Sale effects.

## Impact

- `Modules/Pos/Livewire/PosReturn/*` replacement serial lookup and validation.
- `Modules/Pos/Services/PosReturnReplacementGuard.php`.
- `Modules/Pos/Services/PosReturnApprovalPreviewPlannerService.php`.
- `Modules/Pos/Services/PosReturnApprovalPlanPersistenceService.php`.
- `Modules/Pos/Services/PosReturnLifecycleService.php`.
- Sale, dispatch, SalePayment, ProductStock, ProductSerialNumber, stock transaction, and serial lineage behavior touched by POS Return approval.
- POS Return approval preview/detail Blade views that present planned replacement effects.
- Focused feature tests for same-owner replacement, cross-owner serial replacement, payment reallocation, serial lineage, stock transaction ownership, and rollback.
