## Context

The POS sell page (`Modules/Pos/Resources/views/sell.blade.php`) uses a JavaScript function `renderCart` to update the UI based on the current cart state. This function currently calculates a `canCheckout` boolean based on:
1.  Presence of items in the cart.
2.  Grand total > 0.
3.  Customer resolution (selected or default).
4.  Price validity.
5.  Serial number validity (if required).

The `btnCheckout` ("Pilih Pembayaran") button is disabled if `canCheckout` is false. However, the `saveDraftButton` ("Simpan dan Buka Baru") button is currently independent of this state, which allows users to attempt saving a transaction that is logically invalid according to checkout rules.

## Goals / Non-Goals

**Goals:**
-   Ensure the "Simpan dan Buka Baru" button is disabled whenever "Pilih Pembayaran" is disabled.
-   Provide a consistent activation experience for both primary actions on the POS sell page.

**Non-Goals:**
-   Changing the validation logic itself (only syncing its application).
-   Modifying server-side validation (though it already exists, this is a frontend consistency fix).

## Decisions

-   **Sync via `renderCart`**: The most architectural place to fix this is inside the `renderCart` function where `canCheckout` is already computed.
-   **Conditional sync**: Only sync if `saveDraftButton` exists (since it's conditionally rendered based on permissions).

## Risks / Trade-offs

-   **User surprise**: Users who were used to saving empty drafts or drafts with missing serials (if they somehow bypassed server checks) might find the button disabled. However, this is intended as it prevents invalid state from being "saved".
