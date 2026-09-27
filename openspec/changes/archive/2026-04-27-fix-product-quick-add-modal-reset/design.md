## Context

The system utilizes several "Quick Add" modals (e.g., `ProductQuickAddModal`, `SupplierQuickAddModal`) to allow users to create auxiliary records without leaving the main Purchase or Sale creation forms. Currently, these modals suffer from state retention issues where Alpine.js-managed components (specifically currency formatting fields) do not reset their visual display after a successful record creation or modal closure. This is because the modal DOM is often persistent (`wire:ignore.self`) and Alpine components do not re-initialize on Livewire re-renders unless their specific DOM element is replaced.

## Goals / Non-Goals

**Goals:**
- Ensure all input fields in Quick Add modals are completely cleared/reset after successful creation.
- Guarantee that both Livewire (server-side) and Alpine.js (client-side) states are in sync after a reset.
- Support high-volume data entry by making modals immediately ready for subsequent additions.

**Non-Goals:**
- Redesigning the modal layouts or adding new fields (unless required for standardized behavior).
- Changing the underlying creation services (e.g., `ProductCreator`).

## Decisions

### 1. Robust Server-Side Reset
All Quick Add Livewire components will implement a `resetForm()` method that explicitly resets all public properties, clears the Error Bag, and increments a `$formResetVersion` (or similar) counter.

### 2. DOM-Based Alpine.js Re-initialization (The "Keying" Strategy)
Instead of complex event listeners to reset Alpine.js state, we will leverage Livewire's `wire:key` feature. By including `$formResetVersion` in the `wire:key` of input containers or the Alpine-managed components themselves, we force Livewire to destroy and recreate the DOM section when the form resets. This naturally triggers Alpine's `init()` cycle, ensuring a fresh start for every field.

### 3. Progressive Rollout to All Quick Add Modals
While the Product modal is the most affected (due to multiple currency fields), the same pattern will be applied to:
- `SupplierQuickAddModal`
- `TaxQuickAddModal`
- `PaymentTermQuickAddModal`
This ensures a consistent UX across the entire application's mini-add ecosystem.

## Risks / Trade-offs

- **[Risk] DOM Rebuilding Performance** → Re-keying large sections of the modal can be more expensive than simple property updates.
  - **Mitigation:** The modals are relatively lightweight, and the re-render happens during a transition (closing or after saving), making the impact imperceptible to the user.
- **[Risk] Focus Loss on Re-keying** → If a re-key happens while the user is typing, it could cause focus loss.
  - **Mitigation:** Resets are triggered by `closeModal()` or after a successful `save()`, not during active input.
