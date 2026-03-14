## Context

The project already uses SweetAlert2 in several areas (e.g., brand/category quick add). We will leverage this existing dependency to modernize the POS interaction flow.

## Decisions

### 1. Modal Library Choice: SweetAlert2
- **Rationale**: Already integrated, supports `Swal.fire({ input: 'text' })` for prompts, and matches the "modern/vibrant" aesthetic goals.

### 2. ApprovalManager Modernization
- **Confirmation**: Replace `confirm(...)` with `await Swal.fire({ ... })`.
- **Reason Prompt**: Replace `window.prompt(...)` with `Swal.fire({ input: 'textarea', ... })`.

### 3. Clear Cart Button Logic
- **Location**: `renderCart(snapshot)` function.
- **Logic**:
    ```javascript
    const hasItems = snapshot && snapshot.lines && snapshot.lines.length > 0;
    const isCustomerSelected = snapshot && snapshot.customer && snapshot.customer.resolution_source === 'selected';
    const canClear = hasItems || isCustomerSelected;
    
    if (clearCartButton) {
        clearCartButton.disabled = !canClear;
    }
    ```

### 4. Reusable Confirmation Logic
- For non-interactive components (like Terminal Management list), a small snippet or utility function will be added to handle form submission via SweetAlert.

## Implementation Details

### sell.blade.php
- Modify `ApprovalManager.wrapAction` to be `async` (it already is) and await `Swal.fire`.
- Ensure proper handling of the "Cancel" vs "Empty input" vs "Valid input" states in SweetAlert.

### terminals/index.blade.php
- Add a small `<script>` block that attaches to the deactivation forms to prevent default submission and show the modal first.
