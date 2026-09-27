## Context

The current `+ Serial` implementation uses `window.prompt()`, which is a blocking browser utility. This is suboptimal for high-speed scanning and mobile usage. We need an in-app modal that allows for continuous scanning and better visual management of serials.

## Goals / Non-Goals

**Goals:**
- Eliminate `window.prompt()` for serial entry.
- Provide a persistent input field in a modal for "burst" scanning.
- Improve the visual layout of serial chips in the cart line.
- Auto-focus the modal input upon opening.

**Non-Goals:**
- Changing backend validation or storage logic for serials.
- Implementing "suggested" serials beyond what the `/search` API provides.

## Decisions

1.  **UI Framework**: Use standard Bootstrap 4/5 modal (consistent with existing POS modals like search results and customer creation).
2.  **Modal Structure**:
    - Header: Product name and required serial count info.
    - Body: 
        - Numeric/Text input field for scanner/manual entry.
        - "Status" area for success/error feedback during bursts.
        - List of currently added serials for the line (to allow quick review/removal without closing modal).
    - Footer: Close button (saves are optimistic/real-time via existing API).
3.  **Keyboard Handling**:
    - `Enter` key on the modal input triggers `appendSerialToLine()`.
    - Modal remains open after success to allow the NEXT scan.
    - Input is cleared and re-focused after each successful append.
4.  **Cart Integration**:
    - The `+ Serial` button will now trigger `$('#pos-serial-modal').modal('show')` and set the target `lineId`.
    - Update the serial chip layout in `renderCart()` to be more compact and aligned with the quantity value.

## Risks / Trade-offs

- **Risk**: Cashiers might forget to close the modal.
- **Mitigation**: Ensure the modal is lightweight and has a clear "Selesai" or "Close" button. The primary workflow remains the cart.
- **Trade-off**: Adding a modal adds one more click (to close) compared to prompt (which closes on enter), BUT it saves many clicks for multiple serials (burst scanning).
