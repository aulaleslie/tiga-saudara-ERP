## Why

Standard Sales can sell non-stock products such as repair services, but the current Dispatch flow excludes them and considers only inventory demand when calculating completion. Teams therefore lack an approved, auditable acknowledgement that a service quantity was completed, and mixed goods-and-service Sales can become fully dispatched before all service work is delivered.

## What Changes

- Include non-stock parent products and non-stock bundle components in the standard Sales Dispatch quantity workflow.
- Record a non-stock Dispatch detail as a completion/delivery acknowledgement, using the existing Dispatch submission, approval, rejection, notification, and audit workflow.
- Apply location, stock, serial, product-stock mutation, and inventory transaction behavior only to stock-managed Dispatch details.
- Calculate Sales Dispatch progress across every Dispatch obligation: non-stock acknowledgements and stock-managed parent/component fulfilment.
- Keep bundle parent and component obligations independent: a non-stock service parent is acknowledged by quantity, while its stock-managed components are normally dispatched and deducted; neither substitutes for the other.
- Preserve existing stock-managed standard Sales behavior and all POS behavior.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `sales-non-stock-product-lines`: Non-stock Sales lines move from Dispatch exclusion to approved, quantity-based completion acknowledgement without inventory side effects.
- `standard-sale-document-lines`: Dispatch aggregation and completion include non-stock parent/component obligations while retaining independent bundle-component fulfilment.

## Impact

- Affected standard Sales code: Dispatch aggregation, Dispatch form rendering and validation, Dispatch-detail persistence, approval inventory branching, and Sales status recalculation in `Modules/Sale` and `app/Livewire/Sale`.
- Affected tests: the current non-stock Dispatch-exclusion assertions will be replaced with acknowledgement, partial-completion, mixed Sale, bundle, rejection, and no-inventory-side-effect coverage.
- No new workflow, API, product type, or POS modification is introduced. The existing `dispatches`/`dispatch_details` approval and audit records remain the execution and audit mechanism.
