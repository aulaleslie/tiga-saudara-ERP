## Context

The standard Sales product search endpoint and Livewire fallback both limit results to stock-managed products. `SaleService::validateStock`, dispatch aggregation and validation, and dispatch approval likewise assume every Sales line represents stock. This conflicts with the existing product flag and cost-snapshot behavior, which already recognize non-stock-managed products as service lines with zero cost.

The change affects a manual Sales document from product selection through dispatch approval. It must preserve established document pricing, tax, payment, invoice, reporting, bundle, and inventory controls.

## Goals / Non-Goals

**Goals:**

- Make an active, saleable non-stock product selectable and persistable in standard Sales.
- Keep a non-stock line financially equivalent to a stock line while creating no inventory obligation or stock side effect.
- Allow mixed service-and-goods Sales documents to reach dispatch completion after all stock-managed demand is fulfilled.
- Retain stock and serial controls for stock-managed lines and stock-managed bundle components.

**Non-Goals:**

- No Repair Work Order, device intake, technician, service-completion, POS, or Sales Return workflow.
- No new product type, Sales status, database table, or migration.
- No change to historical Sales, POS, imports, or stock-managed product behavior.

## Decisions

### Use existing product flags as the line classification

Selection eligibility is `is_sold = true`; inventory fulfillment eligibility is `stock_managed = true`. This retains the existing catalog model and permits a product to be saleable without being inventory-managed. A new `type=service` field was rejected because it duplicates the established stock-management classification and expands catalog migration and compatibility scope.

### Apply inventory rules only at the inventory boundary

The Sales cart, normalizer, detail persistence, pricing, discounts, tax, payment, invoice, reports, and cost snapshot remain product-line agnostic. Stock validation, dispatch aggregation, location/serial validation, dispatch detail creation, stock decrement, and inventory transaction recording must branch on the persisted product's `stock_managed` state. This keeps financial behavior shared and prevents service-specific copies of Sales logic.

For bundles, each component is evaluated independently: a non-stock parent has no own inventory demand, while any stock-managed selected component remains dispatchable and stock-validated. This matches existing POS bundle intent and avoids concealing physical goods inside a service/bundle row.

### Treat non-stock lines as already fulfilled for dispatch progress

The dispatch page shall list only inventory-fulfilled demand. Status computation compares approved dispatch quantities only to the sum of stock-managed demand, including eligible bundle components. A service-only Sale therefore has zero dispatch demand and stays `APPROVED`; a mixed Sale becomes `DISPATCHED` once its stock-managed demand is fully approved. Introducing a new service-completion status was rejected because it represents repair operations, which are outside this narrowly commercial Sales change.

### Preserve server-side enforcement

UI filtering is not trusted. The create/update service and dispatch submission/approval paths must reload product classification server-side so crafted requests cannot require stock for a service or bypass inventory checks for a stock-managed product.

## Risks / Trade-offs

- [A mixed document can contain a service that has not operationally been performed while becoming `DISPATCHED` after goods ship] → `DISPATCHED` remains an inventory-fulfillment status; repair completion is deferred to a future work-order capability.
- [A bundle may mix service and physical components] → classify and validate each component at the stock boundary, with focused tests for non-stock parent and stock-managed child combinations.
- [Existing status aggregation currently counts every detail] → change aggregation consistently in display, submission validation, and final status calculation, with service-only, goods-only, and mixed-document regression tests.
- [A product's classification can change after a Sale is drafted] → use the current persisted product classification at validation/dispatch time, matching current Sales inventory behavior; no historical reclassification migration is introduced.

## Migration Plan

Deploy as application-only behavior with no schema migration. Existing historical documents remain unchanged. Rollback is code rollback; documents created with non-stock lines remain valid financial records and retain their zero-cost snapshots, while their inventory side effects are intentionally absent.

## Open Questions

None for this narrow scope. Future repair workflow requirements will determine whether service completion needs a separate operational state.
