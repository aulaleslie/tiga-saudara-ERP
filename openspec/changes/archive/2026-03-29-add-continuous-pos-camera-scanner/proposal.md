## Why

The POS sell page already unifies hardware-scanner, Enter-key, helper-button, and camera-decoded submissions through the same scan resolver, but the current camera session still behaves like a one-shot utility. For tablet cashiers who rely on the device camera as their primary scanner, closing the modal after every decode breaks scanning rhythm and makes the camera feel unlike the dedicated barcode scanners used on desktop lanes.

## What Changes

- Change the POS camera scan flow from single-decode session behavior to a continuous scanning session that stays open across multiple accepted scans until the cashier explicitly closes it.
- Preserve existing scan-resolution semantics by continuing to route camera-decoded values through the shared POS scan resolver used by Enter and helper-action submissions.
- Add virtual-scanner session rules for duplicate suppression, short re-arm cooldown, and single in-flight submission so continuous camera scanning behaves like a discrete hardware scanner instead of repeating the same code rapidly.
- Upgrade the mobile-first scanner surface with persistent in-session feedback for readiness, accepted scans, warnings, and retryable failures while keeping desktop hardware-scanner flow unchanged.
- Improve the camera scanning guide experience for tablet use with a dedicated scan lane overlay and session controls appropriate for repeated product scans.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-scan-input-actions`: Extend camera scan requirements from first-decode-close behavior to explicit continuous-session behavior, including virtual scanner parity, duplicate suppression, persistent session feedback, and explicit cashier-controlled session exit.

## Impact

- Affected frontend: `Modules/Pos/Resources/views/sell.blade.php` camera scanner modal structure, scan action rail copy/feedback, and shared scan status presentation.
- Affected frontend logic: `public/js/pos-camera-scanner.js` state machine, decode acceptance rules, duplicate suppression, session lifecycle, and mobile-first scanner UI behavior.
- Affected tests: POS sell shell UI coverage and new scanner-session behavior coverage for continuous camera scanning, duplicate prevention, and resolver parity.
- APIs/data model: no new backend endpoints or schema changes; existing POS scan resolve and cart-add flows remain authoritative.
