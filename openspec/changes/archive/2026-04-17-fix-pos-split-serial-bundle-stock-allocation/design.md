## Context

POS checkout finalization currently validates stock before posting by building resolver keys for each cart line. Bundle-aware lines produce a parent key such as `0_P` and child keys such as `0_C_0`. The resolver can successfully allocate stock for both the serial-tracked parent and stock-managed bundle children.

When split posting is enabled, `PosCheckoutSplitPlannerService` converts the cart snapshot into per-source groups before delegating each group to the inline posting adapter. For non-serial parent lines, the planner rebuilds a usable allocation under both the grouped numeric line index and `"{groupLineIndex}_P"`. For serial parent lines, it currently emits an empty grouped allocation. The inline posting adapter treats that as missing stock allocation and fails with `STOCK_UNAVAILABLE`.

The bundle child path has a second issue: child allocations from the original cart line are copied wholesale into every split group. If a serial-tracked parent is split across multiple source groups, each group can receive the full child allocation, creating a risk of over-deducting bundle child stock after the parent allocation issue is corrected.

## Goals / Non-Goals

**Goals:**

- Preserve serial-tracked parent allocations when split planning creates grouped checkout lines.
- Ensure each split group receives only the bundle child allocation quantity that corresponds to that group's parent quantity.
- Keep source location, source setting, tax bucket, serial numbers, and tax policy snapshots aligned from resolver through posting.
- Add regression tests for the failing bundled serial checkout and for multi-source serial parent allocation with bundle children.
- Keep checkout response shape, payment behavior, receipt behavior, and existing non-bundle checkout behavior unchanged.

**Non-Goals:**

- Do not change product stock schema, bundle schema, or serial number schema.
- Do not introduce bundle child serial assignment support in POS.
- Do not change how bundle prices, cart totals, taxes, or payment allocation are calculated.
- Do not change the cashier-facing bundle selection workflow.

## Decisions

### Preserve parent allocations inside split planner

The split planner will emit a concrete allocation for serial-tracked grouped lines, equivalent in shape to the non-serial grouped allocation. The allocation must include `source_location_id`, `source_setting_id`, `allocated_qty`, `tax_bucket_used`, `tax_policy_snapshot`, and serial metadata needed by posting.

Rationale: the inline posting adapter already has a stable contract: it expects `allocations["{$index}_P"]` or `allocations[$index]` for a stock-managed parent line. Preserving that contract keeps the fix local to split planning and avoids weakening posting validation.

Alternative considered: make the inline posting adapter infer serial allocations directly from `assigned_serials` when allocations are empty. This would duplicate resolver/planner logic inside posting and could reintroduce bucket mismatches.

### Partition bundle child allocations by grouped parent quantity

For each grouped line, bundle child required quantity will be derived from the grouped parent quantity and the child `quantity` per bundle. The planner will provide child allocations for that grouped line only, not the original full-cart child allocation.

Rationale: split groups are posted independently. Each group must carry only the stock movement needed for its grouped lines, otherwise child stock can be decremented once per group instead of once per sold bundle unit.

Alternative considered: keep child allocations at checkout-level context and let the split adapter post child stock once globally. That would require a larger posting architecture change and would diverge from the current per-group inline posting model.

### Reuse resolver allocation shape

Grouped allocations should retain the resolver's allocation shape and not invent a separate split-only payload. If grouped allocations need to include assigned serials, use the existing `allocated_serials`/`serial_numbers` concepts consistently so `recordStockMovement()` can update serial lifecycle without re-querying allocation decisions.

Rationale: existing bucket-alignment requirements depend on `tax_bucket_used` being the source of truth for stock decrement. Reusing the same shape makes the final posting path easier to reason about and test.

Alternative considered: store only split key and quantity in grouped allocations. That would force downstream code to re-resolve location, owner, and bucket, which is the class of bug this change is avoiding.

### Cover both single-source and multi-source cases

Regression coverage should include a single assigned serial with a bundle child and two assigned serials from different source locations with a bundle child.

Rationale: the single-source case reproduces the reported `STOCK_UNAVAILABLE` failure. The multi-source case protects against child allocation duplication after parent allocations are restored.

## Risks / Trade-offs

- Risk: Partitioning bundle child allocations incorrectly could under-deduct child stock for split groups. Mitigation: assert final `product_stocks` quantities and `transactions` quantities for parent and child products in tests.
- Risk: Serial lifecycle updates could miss grouped serial numbers if the payload names are inconsistent. Mitigation: assert `product_serial_numbers.status`, `dispatch_detail_id`, and dispatch `serial_numbers` for posted split groups.
- Risk: Existing non-serial split behavior could regress if shared allocation code is changed too broadly. Mitigation: keep non-serial path behavior intact and run the existing POS split posting and stock allocation tests.
- Risk: Multi-source parent split with child stock sourced from a single location can create cross-source child movement inside a group. Mitigation: preserve `source_location_id` and `source_setting_id` from child resolver allocations and only partition quantities, not ownership metadata.

