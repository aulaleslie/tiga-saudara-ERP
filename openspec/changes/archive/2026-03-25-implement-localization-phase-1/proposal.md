## Why

The current ERP system contains English user-facing strings in critical service layers and the authentication flow. To improve usability for the target Indonesian users and ensure professional consistency, these strings need to be replaced with Bahasa Indonesia equivalents. Phase 1 focuses on the highest-impact areas: checkout validation, cart operations, inventory posting, and login.

## What Changes

Direct replacement of 45+ English exception and authentication messages with their Indonesian equivalents as defined in the `ENGLISH_STRINGS_INVENTORY.md`. This is a string-level change and does not introduce new localization infrastructure (i.e., no Laravel `trans()` helpers) per the project's direct-replacement strategy.

## Capabilities

### New Capabilities
- `localization-phase-1`: Initial set of critical system messages translated to Bahasa Indonesia.

### Modified Capabilities
- `pos-checkout-finalize`: Exception messages in `FinalizePosCheckoutService.php` updated to Indonesian.
- `pos-cart-management`: Exception messages in `PosCartService.php` updated to Indonesian.
- `pos-inventory-posting`: Exception messages in `InlinePosCheckoutPostingAdapter.php` updated to Indonesian.
- `pos-session-management`: Variance approval messages in `PosSessionFinalizeService.php` updated to Indonesian.
- `user-authentication`: Login flow messages in `LoginController.php` updated to Indonesian.

## Impact

- **Affected Files**: 5 core service and controller files.
- **Dependencies**: No external dependency changes.
- **Testing**: Requires running existing feature tests for POS and Auth to ensure no regression in logic.
