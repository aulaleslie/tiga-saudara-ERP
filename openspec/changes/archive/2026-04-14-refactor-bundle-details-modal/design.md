## Context

The system currently renders bundle options directly via hidden collapse table rows (`tr.collapse`) directly underneath each item within the Product Cart on the POS and Sales screens. This approach creates layout jumps and DOM bloat, especially noticeable when many products with bundles are added. Transitioning to a single, detached Livewire-driven modal will result in a more sophisticated and stable user interface. 

## Goals / Non-Goals

**Goals:**
- Move bundle items presentation exclusively to a modal overlay.
- Keep the table layout stable.
- Utilize existing Livewire data structures without deep architectural state shifts in the cart logic.

**Non-Goals:**
- Allowing editing of bundle components within the cart (view-only remains).
- Applying strict business capability changes or altering bundle pricing logic.

## Decisions

- **Single Modal Model**: Instead of instantiating numerous modals inside the `@foreach` Cartesian loop on the Blade template, a single modal defined at the bottom of the cart layout will be used. 
- **Trigger**: Click event `wire:click="viewBundleDetails(rowId)"` to a new method on the `ProductCart` component.
- **State Property Storage**: Add `$selectedBundle` to `ProductCart.php` which will momentarily hold bundle metrics derived directly from the instantiated cart items and trigger `$this->dispatch('open-bundle-details-modal')`.

## Risks / Trade-offs

- Overly large bundles could generate a massive modal content string, but a `.table-responsive` wrapping will mitigate horizontal layout issues.
- Slight increase in server trips upon modal load due to `wire:click`, which is acceptable given the overall performance standard required for localized Intranet deployments.
