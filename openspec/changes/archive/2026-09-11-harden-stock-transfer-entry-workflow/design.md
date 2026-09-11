## Context

Stock transfers already use a shared Livewire form, authoritative `TransferDraftService`, lifecycle locking, condition-aware serial validation, and non-tax-first allocation. The remaining entry workflow is inconsistent: the form renders product entry without an origin, destination changes emit the same reset event as origin changes, mode is local to product search rather than transfer state, and initial save atomically creates a `PENDING` transfer instead of a reusable draft.

The stock-opname redesign introduced the preferred searchable location control, while adjustment and breakage editors provide the preferred location-gated searchable/scannable product interaction. Transfer entry must reuse those interaction patterns without importing stock-opname reconciliation semantics or weakening transfer-specific cross-business, tax provenance, allocation, conversion, and serial rules.

This change spans Livewire state, shared location selection, persistence, lifecycle validation, and a nullable schema relationship. Existing historical transfers may contain both good and broken buckets in one document, so migration and rendering must remain backward compatible even though new and editable drafts will use one explicit mode.

## Goals / Non-Goals

**Goals:**

- Support saving a valid `DRAFT` transfer without a destination and explicitly submitting it later.
- Make origin selection a hard prerequisite for every product-entry path.
- Use the stock-opname searchable location interaction with transfer-appropriate scopes and filtering.
- Make transfer mode explicit, form-wide, persistent, and mutually exclusive.
- Clear rows only when their stock interpretation becomes invalid: origin or mode changes.
- Preserve hardened transfer validation and lifecycle behavior at server boundaries.
- Cover the changed behavior with focused migration, service, Livewire, and request tests.

**Non-Goals:**

- Changing approval, dispatch, receipt, mandatory tax-return, allocation-priority, serial-availability, or inventory transaction semantics.
- Supporting mixed good and broken rows in newly created or editable drafts.
- Redesigning stock-opname, adjustment, or breakage domain behavior.
- Replacing transfer product storage or rewriting historical transfer movements.
- Adding new external packages or planning a full application test-suite run.
- Automating browser tests; a human will perform browser verification.

## Decisions

### 1. Model save-draft and submit-for-approval as separate commands

The Livewire form will expose explicit `saveDraft` and `submitForApproval` operations rather than passing an action string through one ambiguous submit method. New transfers are created as `DRAFT`; submission invokes an authoritative lifecycle boundary that reloads and validates the locked transfer before transitioning it to `PENDING`.

Draft save requires an active origin owned by the active tenant, one explicit mode, and at least one valid product row. Destination is optional. When present on a draft it must already be active, distinct, and consignment-compatible so invalid data is not knowingly stored. Submission additionally requires destination and revalidates locations, product ownership/activity, quantities, stock, conversions, and serial availability atomically.

Alternative considered: retain atomic create-and-submit and add a second draft button. Rejected because two creation paths would continue to have different validation and lifecycle semantics.

### 2. Make destination nullable and persist one transfer-level mode

Add a nullable `destination_location_id` and a nullable string `stock_condition` with application constants such as `GOOD` and `BREAKAGE`. New records and any editable record saved through the new form must have one valid condition. Form-state DTOs and mappers carry that value explicitly; services reject rows whose bucket/serial condition does not match it.

Historical unambiguous records can be interpreted or backfilled from their populated buckets. Historical mixed-condition records remain readable with a null/legacy interpretation and are not rewritten. If such a record is still editable, the operator must choose a mode, which clears the incompatible rows before it can be saved.

Alternative considered: infer mode from rows forever. Rejected because empty/transient state and historical mixed buckets make inference ambiguous, and mode changes need a stable lifecycle invariant.

### 3. Keep the parent form as the single owner of origin, destination, mode, and rows

`TransferStockForm` owns all four values and sends mode/origin to the search and table children. Child components may resolve products or edit quantities, but they cannot independently change transfer mode. Event names/payloads distinguish these transitions:

```text
origin changed      -> destination = null, rows = []
mode changed        -> rows = []
destination changed -> rows unchanged
```

Reset events will include a reason or be separate named events so destination selection cannot accidentally take the destructive path. Livewire keys may remount search controls for origin/mode changes, but the parent state remains authoritative and receives the empty-row update.

Alternative considered: keep mode in `SearchProduct` and have it tell the table to reset. Rejected because edit hydration, persistence, validation, and multiple child components would have no single source of truth.

### 4. Disable and reject product entry until origin is selected

Before origin selection, the product input renders in a disabled state with Bahasa Indonesia guidance. Search, exact scan, serial selection, and row mutation methods also reject calls lacking a valid active tenant-owned origin, preventing crafted Livewire calls from bypassing the visual guard.

Changing or clearing origin clears the destination and rows because stock snapshots, serial eligibility, and destination exclusion are origin-dependent. Selecting or changing only destination preserves rows because it does not change source availability.

Alternative considered: hide product input entirely. Rejected because a visible disabled control explains the required sequence and produces a more stable layout.

### 5. Extend the stock-opname location dropdown through explicit reusable filters

Use `Modules\Setting\Livewire\LocationSearchDropdown` for both fields and extend it with explicit configuration rather than maintaining the older transfer-only autocomplete. Required configuration includes active-only lookup, optional tenant scoping, excluded location IDs, business-aware display labels, distinct field names, and targeted selection events.

Origin is restricted to active locations owned by the active tenant. Destination can search active permitted locations across businesses, excludes the selected origin, and displays both location and company to disambiguate duplicate names. Consignment compatibility is filtered when practical and always validated authoritatively at save/submit.

The component must not accept a selected ID that falls outside its configured scope merely because the client sends it. The draft and lifecycle services remain the final authorization boundary.

Alternative considered: restyle `LocationBusinessLoader`. Rejected because stock opname is the requested and newer shared interaction, while the older loader omits active filtering on search/selection paths.

### 6. Reuse adjustment/breakage entry interaction while retaining the transfer resolver

Transfer search/scanning will adopt the hardened interaction conventions from adjustment and breakage: location gating, clear feedback, deterministic duplicate handling, scanner focus restoration, and serialized processing of rapid scans where the shared pattern provides it. `TransferScanResolverService`, allocation preview, transfer DTO mapping, and authoritative draft validation remain responsible for transfer-specific domain rules.

Good mode queries and accepts only saleable stock/serials. Breakage mode queries and accepts only broken stock/available-broken serials. Rows cannot carry the opposite condition, and mode is rechecked during mapping and persistence rather than trusted from browser payloads.

Alternative considered: directly reuse adjustment or breakage domain components. Rejected because their counting and reconciliation rules differ from transfer allocation and scanning.

### 7. Preserve compatibility routes but converge them on the same commands

The visible Livewire form is the primary workflow. Any retained resource-controller `store`/`update` paths must call the same draft-save and submit services or explicitly reject payloads they cannot safely represent; they must not continue an independent immediate-pending contract. Request validation will reflect nullable destination for draft save and mandatory destination for submission.

Existing approval and later lifecycle actions must guard against a null destination even if invoked outside the UI. Detail/list views render an unset draft destination safely.

Alternative considered: leave the legacy HTTP path unchanged because the Livewire page does not post to it. Rejected because route-level callers would retain conflicting behavior and become a validation bypass.

### 8. Use focused automated verification and human browser validation

Focused tests will cover schema migration, draft persistence without destination, submission guards and atomicity, tenant/location scope, mode enforcement, origin/mode/destination reset behavior, disabled entry, edit hydration, and compatibility endpoints. Existing focused allocation and serial tests will be run where touched. Browser behavior will be verified manually by a human; no full-suite or automated browser run is required for this phase.

## Risks / Trade-offs

- [Risk] Making a foreign key nullable can be database-driver-sensitive, especially for SQLite table reconstruction. → Follow existing driver-aware migration patterns and add focused migration/schema tests.
- [Risk] Historical mixed-condition transfers cannot map cleanly to one mode. → Preserve them unchanged for read-only history and require explicit operator normalization only if an editable legacy record is saved.
- [Risk] Extending a shared dropdown could regress stock-opname callers. → Make new filters opt-in, preserve current defaults, and add focused component coverage for existing tenant-scoped behavior.
- [Risk] A destination or serial may become invalid after draft save. → Revalidate all authoritative state under the locked submission transaction and leave the draft unchanged on failure.
- [Risk] Clearing rows can cause intentional data loss when origin or mode is clicked accidentally. → Only clear after an actual value change, use clear Bahasa Indonesia feedback/confirmation where consistent with existing UI conventions, and never clear for destination-only changes.
- [Risk] Concurrent edits or submission may race. → Reuse transfer revision checks and row locks, and perform validation plus transition atomically.

## Migration Plan

1. Add a driver-compatible migration making `destination_location_id` nullable and adding nullable `stock_condition`; preserve its foreign key and indexes.
2. Classify unambiguous historical transfers where safe, leaving mixed legacy records unchanged rather than rewriting inventory history.
3. Deploy model constants/casts, DTO mapping, draft validation, and locked submission validation before switching the UI to nullable destinations.
4. Extend the searchable location component with backward-compatible optional filters, then migrate the transfer form to it.
5. Move mode and reset orchestration into the parent form and adapt product entry behavior.
6. Converge compatibility controller/request paths and add null-safe list/detail handling.
7. Run focused affected tests and provide a concise human browser checklist.

Rollback requires reverting the form and service code before restoring a non-null destination constraint. Any destination-less drafts created after deployment must first be completed or safely removed by an explicit operational decision; migrations must not silently delete them. The added mode column can remain harmlessly additive during a forward fix.

## Open Questions

None for implementation. The first phase assumes a saved draft must contain at least one valid product row and that each new/editable transfer has exactly one stock condition.
