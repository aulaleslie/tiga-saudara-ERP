## 1. Modal Structure

- [x] 1.1 Add a read-only POS bundle detail modal include with fields for bundle name, parent product, cart quantity, price composition, item list, and empty-state messaging
- [x] 1.2 Include the new bundle detail modal from the POS sell page alongside the existing POS modal includes

## 2. Cart Row Trigger

- [x] 2.1 Change the rendered `Paket: <bundle name>` bundle label into a keyboard-accessible button-style trigger scoped with a bundle detail class and line id
- [x] 2.2 Preserve the existing compact cart row layout and avoid adding a new action column or inline detail rows

## 3. Dialog Rendering

- [x] 3.1 Add JavaScript references for the bundle detail modal elements
- [x] 3.2 Add delegated cart click handling that finds the selected line from `currentSnapshot.lines` by `line_id`
- [x] 3.3 Render parent product, bundle name, cart quantity, bundled item names, item quantities, and serial-required indicators from the cart line snapshot
- [x] 3.4 Render price composition using derived base product price, `bundle_price`, `unit_price`, and `line_total`
- [x] 3.5 Ensure tax and discount breakdowns are not displayed in the bundle detail dialog
- [x] 3.6 Handle missing or empty `bundle_items` gracefully without JavaScript errors

## 4. Verification

- [x] 4.1 Add or update feature/view coverage that confirms bundled cart snapshots expose the fields needed by the dialog
- [x] 4.2 Add or update frontend-oriented coverage, if available in the project, for opening the dialog from a bundled cart row and rendering price composition
- [x] 4.3 Manually verify the POS cart with a bundled line: clicking `Paket: <bundle name>` opens the dialog, shows item and price details, omits tax/discount details, and leaves the cart table stable
