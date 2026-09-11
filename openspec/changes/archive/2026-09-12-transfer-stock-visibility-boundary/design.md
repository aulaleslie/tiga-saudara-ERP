## Context

Stock-transfer create/edit is implemented by nested Livewire components that currently copy `ProductStock` bucket values and computed allocation fields into public arrays. Existing edit mapping repeats those snapshots, serial autocomplete dispatches Eloquent models, the detail page renders requested and lifecycle quantities directly from transfer models, and dispatch drift handling places exact allocation differences in session data. Hiding individual Blade elements would therefore leave the same information available in Livewire snapshots or another lifecycle surface.

The application already has centralized permission configuration in `app/Config/Permissions.php`, database synchronization through `Modules\User\Database\Seeders\PermissionsTableSeeder`, role management backed by Spatie permissions, and a global `Gate::before` rule that grants every ability to `Super Admin`. The change must use these mechanisms. Existing workflow permissions and collaborative draft behavior must remain intact.

## Goals / Non-Goals

**Goals:**

- Establish `stockTransfers.view-system-stock` as the single transfer-specific authority for exposing system-derived stock information.
- Keep operator-entered values in an editable transfer visible to every user who is otherwise authorized to edit that transfer.
- Remove protected information from unauthorized server projections, Livewire state, rendered output, session payloads, and browser-facing feedback.
- Preserve authoritative stock and serial validation without trusting client-provided snapshots or metadata.
- Preserve privileged behavior and the existing Super Admin bypass.

**Non-Goals:**

- Adding movement documents or separate movement preparation/approval permissions.
- Implementing forward or return blind-recount screens.
- Changing inventory mutation timing, transfer lifecycle states, allocation order, PKP policy, or return obligations.
- Introducing per-user ownership for draft rows or preventing authorized editors from seeing one another's entries.
- Reworking general inventory visibility permissions outside stock transfers.

## Decisions

### 1. Register one transfer-specific permission through the existing registry

Add `stockTransfers.view-system-stock` to the `Transfer Stok` group in `app/Config/Permissions.php`. Deployment uses the existing permission seeder, which creates missing permissions and exposes them to existing role management. No migration infers this authority from `stockTransfers.create`, `stockTransfers.edit`, `stockTransfers.show`, `stockTransfers.approval`, `stockTransfers.dispatch`, or `stockTransfers.receive`.

Visibility checks use `Gate::allows(...)`, `Gate::denies(...)`, or `$user->can(...)`. They do not reproduce role-name rules locally. Consequently, the existing global `Gate::before` makes Super Admin privileged without an explicit assignment. The seeder's existing Admin synchronization behavior remains unchanged.

Alternative considered: reuse `inventory.view_remaining_stock` or `adjustments.view-system-stock`. Rejected because their established scopes are general remaining-stock cards and stock opname respectively; granting either should not silently expose transfer manifests, allocations, or serial provenance.

### 2. Classify values by source, not by which editor entered them

The visibility boundary separates operator intent from system-derived facts:

- Operator intent includes selected product identity, requested base quantity, chosen transfer condition, and selected serial number identity stored in the editable draft.
- Protected system information includes available quantities, good/broken and tax/non-tax buckets, computed allocation breakdowns, serial tax/availability provenance, maximums, remaining/shortage figures, approved movement expectations, return obligations, and allocation-drift comparisons.

All otherwise-authorized editors may see shared persisted operator intent. This maintains collaborative editing and avoids a new row-ownership model. Once data is used as an expected manifest in a later recount workflow, it is system expectation for that screen and is protected even if it originated as operator input.

Alternative considered: restrict persisted draft entries to their individual author. Rejected by the agreed collaborative-editing requirement and because current data does not track per-row authorship.

### 3. Build explicit blind and privileged projections before serialization

Transfer UI data is shaped before it reaches Blade or Livewire public properties. Blind projections omit protected keys entirely rather than filling them with zero, null, placeholders, masks, or hashes. Privileged projections retain the operational detail currently shown.

For create/edit Livewire components, blind row state contains only identifiers and operator intent needed to render and mutate the draft. Exact stock snapshots and calculated bucket fields are never retained as trusted client state. Serial search and selection dispatch minimal arrays rather than Eloquent models and exclude tax ID, taxability, condition provenance beyond the already selected transfer condition, and unrelated model attributes.

For server-rendered detail and lifecycle pages, a transfer presentation/projector layer supplies either a blind or privileged representation. The blind representation may include document metadata, product identity, lifecycle status, actors, and other non-stock audit context, but omits exact request/movement quantities, serial manifests, obligations, allocations, and differences. This protects historical pages indefinitely for users lacking permission.

Alternative considered: pass complete models and wrap display cells in `@can`. Rejected because it is fragile across partials, session data, Livewire serialization, and future UI changes.

### 4. Treat all client allocation and provenance fields as untrusted

Server mutation services reload origin, product, conversion, stock, and serial records and derive allocation from the submitted intent. Blind clients do not need system bucket fields to save or submit. Privileged display fields may be returned for presentation, but mutation code must ignore them as authority.

This may require separating the current row mapper into an intent projection and an optional privileged display projection. Parent-child Livewire events must carry only the projection appropriate to the acting user. Exact availability is queried at each scan/add/update boundary as needed and is revalidated during save and submit.

Alternative considered: encrypt or lock stock fields in Livewire state. Rejected because ciphertext or signed snapshots still disclose unnecessary structure, can become stale, and perpetuate dependence on client-round-tripped stock.

### 5. Separate diagnostic detail from browser feedback

Server logs may retain detailed operational diagnostics under existing log-access controls, but browser-facing flashes, validation errors, exception messages, session payloads, and rendered logs are permission-aware. Blind users receive stable non-quantitative Bahasa Indonesia errors that identify the affected entry without giving exact availability, bucket composition, expected values, or differences. Privileged users may receive existing detailed comparisons where operationally useful.

Allocation drift handling stores exact allocation details in browser session state only for a privileged actor. Blind actors receive a neutral failure/retry path with no allocation array or acknowledged hash derived from a visible comparison.

Alternative considered: use identical detailed errors for supportability. Rejected because repeated validation attempts would remain a stock-enumeration channel.

### 6. Verify payload absence as well as visual absence

Focused tests assert both positive compatibility and negative disclosure. Sentinel quantities, tax identifiers, and serial values make leakage detectable in rendered HTML, Livewire component state/snapshots, dispatched browser data, validation bags, and session flashes. Crafted requests verify that hidden fields cannot be supplied as authoritative values. Tests cover good and broken transfers and serialized and non-serialized entries without running the full suite. Human browser verification covers interaction and presentation across representative create, edit, show, and lifecycle pages.

## Risks / Trade-offs

- [Blind users can probe a boolean success/failure boundary by changing requested quantities] → Keep errors neutral, never reveal maxima or remaining values, rate-limit only if later operational evidence shows automated enumeration; authoritative accept/reject remains necessary.
- [A newly added view or Livewire property can reintroduce leakage] → Centralize projections, test key payload surfaces with sentinel data, and avoid passing raw stock/serial models to presentation code.
- [Privileged and blind paths can diverge functionally] → Derive both from the same intent and validation services, varying only presentation fields and error detail.
- [Removing allocation preview can make blind entry less convenient] → Preserve requested quantities, selected serial identities, scanner focus, and neutral row-level feedback while accepting reduced planning information as the intended fraud-control trade-off.
- [Permission synchronization could surprise custom roles] → Do not auto-map existing workflow permissions; use existing role management for explicit grants and document that Super Admin bypasses through the global Gate rule.

## Migration Plan

1. Deploy code containing the centralized permission entry, projections, guarded presentation, and focused tests.
2. Run the existing `PermissionsTableSeeder` so the missing permission is created and standard centralized synchronization occurs.
3. Explicitly grant the permission to any non-Super-Admin operational roles that are authorized to inspect system stock; leave floor-staff roles ungranted.
4. Perform human browser verification using a blind floor-staff account, a specifically privileged account, and a Super Admin account.

Rollback removes the code paths that depend on the permission and then removes the registry entry through the existing synchronization process. No business-data backfill or destructive schema rollback is required.

## Open Questions

None for this delivery. Movement-specific blind recount behavior remains reserved for later deliveries.
