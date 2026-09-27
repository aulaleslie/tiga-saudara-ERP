# Design

## Context

The report's product query already supports exact serial-number matching through the product-to-serial relationship, but the row view model contains only product and stock totals. It does not carry the matching serial's location or operational condition, so the Blade view cannot identify a specific stock cell. Business columns can switch between aggregated business subtotals and per-location detail, and the same query service also builds export rows.

## Goals / Non-Goals

**Goals:**

- Derive marker metadata only for a complete exact serial match.
- Reuse the report's established operational Good/Bad serial eligibility rules so the marker points to a truthful current-stock cell.
- Make the same metadata work for both collapsed business totals and expanded location cells.
- Keep the presentation additive and accessible without changing cell contents or interactions.

**Non-Goals:**

- Changing which products search returns.
- Highlighting product-name, code, barcode, category, brand, or partial serial matches.
- Marking unavailable/historical serial states as current inventory.
- Opening or changing the serial dialog.
- Adding marker styling to Excel exports.
- Adding schema, dependency, permission, or API changes.

## Decisions

### Resolve marker context separately from product filtering

The report query service will normalize the complete search value consistently with serial identity handling, look up an exact matching serial, and derive a compact marker context containing product ID, setting ID, location ID, and condition. The existing product-filter query remains responsible for result inclusion.

This separation avoids complicating the product query or changing its OR semantics. An alternative was to join serial and location tables into the main product query, but that risks duplicate product rows and pagination changes.

### Use operational dialog eligibility as the marker boundary

A serial is marked Good only when it satisfies the canonical sellable rules already used by the Good dialog, and marked Bad only when it satisfies the available-broken rules used by the Bad dialog. Unavailable, dispatched, and return-in-process serials receive no marker even though their exact identity can still resolve the product row.

This prevents a marker from implying that a historical or unavailable serial contributes to current Good or Bad stock. The alternative—marking a location regardless of status—would be visually helpful but semantically misleading.

### Attach marker flags to on-screen row stock data

The on-screen row builder will compare the marker context with each row/business/location and expose booleans for the matching Good or Bad cell. In collapsed mode the business-and-condition match is sufficient; in expanded mode the location-and-condition must also match.

This keeps Blade conditions simple and avoids issuing database queries from the view. Export construction will not request or apply marker context, preserving current export output.

### Apply a dedicated visual class to the cell

Blade will add a dedicated marker class only to the matching `<td>`. The class will use a soft yellow background with sufficient text contrast and must coexist with existing borders, icons, tooltips, buttons, sticky behavior, and hover styling.

Marking the cell rather than the row or rewriting its contents best matches the requested stabilo effect while leaving all information unchanged.

## Risks / Trade-offs

- [Search filtering and marker lookup could interpret serial eligibility differently] → Centralize or reuse the existing Good/Bad serial scopes and add focused tests for both conditions.
- [Table hover styles could obscure the marker] → Give the dedicated marker class sufficient CSS specificity and verify it in the rendered Livewire output.
- [A serial outside selected businesses can still resolve a product under existing search behavior] → Preserve result behavior but omit the marker because no truthful visible target cell exists.
- [Legacy duplicate serial identities could produce ambiguous marker targets] → Use deterministic exact-match lookup consistent with current search behavior; do not broaden this small change into serial-data remediation.

## Migration Plan

No data migration is required. Deploy the query/view-model and Blade styling changes together. Rollback consists of reverting those additive changes; stored data and exports are unaffected.
