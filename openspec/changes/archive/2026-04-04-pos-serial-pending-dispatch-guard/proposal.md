## Why

Serial numbers assigned to a PENDING sale dispatch remain visible and sellable on the POS terminal. When a dispatch is created with status PENDING (awaiting approval), the `ProductSerialNumber` record is not updated — `status` stays `ACTIVE` and `dispatch_detail_id` remains `NULL`. The POS serial availability checks only look at these two fields, so POS operators can sell the same physical item that is already queued for dispatch. This leads to double sales and inventory discrepancies.

## What Changes

- **POS serial availability queries**: Both the serial search (autocomplete) and the serial append (cart assignment) in POS will exclude serials that appear in any PENDING dispatch's `DispatchDetail.serial_numbers` JSON field.
- **Error messaging**: When a POS operator attempts to use a serial that is in a pending dispatch, a clear rejection message will be shown ("Serial number sedang dalam proses pengiriman").

## Capabilities

### New Capabilities
- `pos-serial-dispatch-reservation-guard`: Block POS from searching, selecting, or appending serial numbers that are referenced in any PENDING dispatch, preventing double-sale of dispatched items.

### Modified Capabilities
- `pos-checkout-serial-stock-validation`: The finalize pre-check must also reject assigned serials that have entered a PENDING dispatch between cart assignment and checkout finalization.

## Impact

- **Files**: `Modules/Pos/Services/PosCartService.php` (availableSerialsForProduct, appendSerial), `Modules/Pos/Services/PosCheckoutService.php` or equivalent finalize pre-check
- **Database**: No schema changes. Reads existing `dispatch_details.serial_numbers` JSON + `dispatches.status` via query join.
- **Performance**: One additional query (or subquery) per serial operation in POS. Mitigated by the fact that PENDING dispatches are typically few.
- **Risk**: Low. Additive guard — existing dispatch and approval flows are unchanged.
