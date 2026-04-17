## Context

Inside the POS "sell" interface, product addition is handled by several JavaScript functions (`addProductToCart`, `handleSerialScanResult`, `addBundleToCart`). When a serialized product is identified as a `bundle_parent`, the flow is interrupted to show a bundle selection modal. During this interruption, the scanned serial number context is currently lost because it's not stored in any persistent state or passed through the modal transition.

## Goals / Non-Goals

**Goals:**
- Maintain serial number context through the bundle selection modal.
- Ensure the serial is appended to the correct cart line after bundle or "normal" addition.
- Simplify/unify serial addition logic by centralizing more of it within `addProductToCart`.

**Non-Goals:**
- Modifying backend APIs (the existing `addLine` and `appendSerial` endpoints are sufficient).
- Changing how serial numbers are resolved by the scanner.

## Decisions

### 1. Global State Management
Add a `let pendingBundleSerial = null;` variable to the POS shell script scope. This mirrors the existing `pendingBundleProduct` and `pendingBundleSource` pattern.

### 2. Update `addProductToCart` API
Update the signature to `async function addProductToCart(product, source, options = {})`.
- The `options` object will now support a `serialNumber` key.
- If `is_bundle_parent` is true, it calls `openBundleSelectionModal(product, source, options.serialNumber)`.
- If `is_bundle_parent` is false (or `skipBundleCheck` is true), it appends the serial number immediately after getting a successful response from `cartStoreLineEndpoint`.

### 3. Handoff in `addBundleToCart`
Modify `addBundleToCart` to:
- Read `pendingBundleSerial`.
- After `renderCart(response.cart_snapshot)`, find the new line in the snapshot.
- Call `appendSerialToLine(newLine.line_id, serialToAppend)`.
- Clear `pendingBundleSerial`.

### 4. Cleanup
Add `pendingBundleSerial = null;` to the bundle modal's `hidden.bs.modal` event listener and the `bundleContinueNormal` handler.

## Risks / Trade-offs

**Risks:**
- **Race conditions**: If multiple rapid scans happen involving bundles, there's a risk of stale state. However, the existing POS logic is mostly sequential due to modal blocking, mitigating this risk.
- **Merge collisions**: If a product is added as a bundle but merges with an existing line, we must ensure we append the serial to the correct `line_id`. The current `find` logic on the snapshot should handle this correctly.
