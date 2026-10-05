# Proposal

## Why

After a cashier picks a Cari Produk result that needs a unit or bundle choice, every later click on any result card is silently ignored until the page reloads (seen in the browser: select a serial-number bundle-parent product → "Harga Normal" → remove the line → re-select gives no row and no error). The block added by `2026-10-05-keep-pos-search-open-for-simple-products` is only cleared on the `show.bs.modal` event. The POS layout drives modals with CoreUI 3.4.0, which fires `*.coreui.modal` events instead, so that event never arrives.

## What Changes

- Clear the "unit/bundle selection pending" block on signals POS controls directly: when the selection operation ends (success, error, or cancel) and when the cashier opens Cari Produk. Do not rely only on a modal lifecycle event.
- Make the Cari Produk, unit-selection, and bundle-selection modal lifecycle listeners in the POS sell view respond to the CoreUI event names (`show/shown/hidden.coreui.modal`) as well as the Bootstrap names. This restores:
  - keyword focus and keyboard setup when Cari Produk opens
  - scanner refocus when Cari Produk closes
  - cancelling the active selection when the unit or bundle dialog is dismissed without a choice
- Update the source-level feature test that currently asserts the `show.bs.modal` binding.

## Capabilities

### New Capabilities

### Modified Capabilities
- `pos-product-search-lifecycle`: The simple-product selection requirement gains explicit scenarios. Results stay selectable after a unit or bundle flow completes or is cancelled and Cari Produk is reopened, including re-selecting the same product after its line is removed. Dismissing a unit or bundle dialog cancels the pending selection.

## Impact

- `Modules/Pos/Resources/views/sell.blade.php`: the search-result click guard, the release points, and the modal event bindings for the search, unit, and bundle modals.
- `Modules/Pos/Tests/Feature/PosSearchResultSimpleProductStaysOpenTest.php`: update the assertions on the source.
- No backend, route, or data changes. Browser verification is done by a person.
