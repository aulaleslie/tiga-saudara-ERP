## 1. Setup Global State

- [x] 1.1 In `sell.blade.php`, declare `let pendingBundleSerial = null;` near other pending bundle state variables.
- [x] 1.2 Update the bundle modal's `hidden.bs.modal` event listener to reset `pendingBundleSerial = null;`.

## 2. Refactor Product Addition Logic

- [x] 2.1 Update `openBundleSelectionModal` to accept `serialNumber` as a third argument and assign it to `pendingBundleSerial`.
- [x] 2.2 Update `addProductToCart` to accept `options.serialNumber` and propagate it to `openBundleSelectionModal`.
- [x] 2.3 Implement atomic serial appending in `addProductToCart` for non-bundle paths (after the `cartStoreLine` API call).

## 3. Integrated Flow Update

- [x] 3.1 Update `handleSerialScanResult` to pass the scanned serial to `addProductToCart` and remove redundant manual appending logic.
- [x] 3.2 Update `addBundleToCart` to check for `pendingBundleSerial` after successful addition and call `appendSerialToLine`.
- [x] 3.3 Update the `bundleContinueNormal` click handler to propagate the pending serial back into `addProductToCart` with `skipBundleCheck: true`.

## 4. Verification

- [x] 4.1 Verify scanning a serialized bundle parent correctly shows the bundle modal.
- [x] 4.2 Verify selecting a bundle automatically appends the serial number.
- [x] 4.3 Verify choosing "Continue Normal" automatically appends the serial number.
- [x] 4.4 Verify regular non-bundle serialized product scans still work as expected.
