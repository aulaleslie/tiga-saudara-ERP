## 1. Centralized Permission

- [x] 1.1 Add `stockTransfers.view-system-stock` with a human-readable label to the Transfer Stok group in `app/Config/Permissions.php` and verify the existing permission seeder discovers it.
- [x] 1.2 Add focused authorization coverage proving ordinary transfer permissions do not imply visibility, an explicit grant enables visibility, and the existing Super Admin Gate bypass enables visibility without direct assignment.

## 2. Projection and Trust Boundary

- [x] 2.1 Introduce a reusable transfer visibility decision and explicit blind/privileged projection boundary that uses the existing Gate authorization mechanism and omits protected keys for blind users.
- [x] 2.2 Refactor transfer form-state mapping so shared editable intent is distinct from current system stock and optional privileged display data.
- [x] 2.3 Refactor transfer mutation preparation so product stock, allocation, conversion, condition, and serial provenance are always reloaded or derived server-side and client-supplied protected metadata is ignored or rejected.

## 3. Create and Edit Livewire Surfaces

- [x] 3.1 Update transfer product search and scan results to send minimal explicit product projections and no raw Eloquent models or protected stock attributes to blind clients.
- [x] 3.2 Update the transfer product table and parent form so blind public state contains shared product, requested-quantity, condition, and selected-serial intent but no stock snapshots, allocation buckets, maximums, remaining values, or serial provenance.
- [x] 3.3 Preserve exact stock and allocation presentation for explicitly privileged users while ensuring privileged round-tripped display fields are never trusted by mutation services.
- [x] 3.4 Update serial autocomplete and selection events used by transfers to omit tax, availability, condition provenance, and unrelated model fields for blind users while preserving authorized serial entry.
- [x] 3.5 Replace quantitative or provenance-revealing create/edit feedback with neutral Bahasa Indonesia messages for blind users while retaining permission-appropriate detail for privileged users.

## 4. Detail and Existing Lifecycle Surfaces

- [x] 4.1 Add permission-aware transfer detail presentation that preserves document, product-identity, lifecycle, actor, and timestamp context but omits protected request, dispatch, return, obligation, allocation, and serial-manifest data for blind viewers.
- [x] 4.2 Apply the same projection and feedback boundary to existing dispatch, receipt, return dispatch, return receipt, rejection/correction, archive, and browser-accessible audit or export surfaces in scope.
- [x] 4.3 Refactor allocation-drift handling so exact allocations, differences, hashes derived from visible comparison, and quantitative exception detail enter session/browser data only for privileged users; provide a neutral blind retry/failure path.
- [x] 4.4 Review transfer-specific browser-directed logging, flashes, validation bags, badges, summaries, and component events and remove equivalent indirect system-stock disclosures from blind responses.

## 5. Focused Verification

- [x] 5.1 Add focused Livewire tests with distinctive sentinel values proving blind create and edit HTML, public properties, snapshots, events, validation data, and serial results omit protected keys and values while retaining shared editable intent.
- [x] 5.2 Add focused feature tests proving blind detail and lifecycle HTML and session data omit quantities, buckets, obligations, expected serials, allocation drift, and revealing errors while privileged and Super Admin views retain authorized detail.
- [x] 5.3 Add focused crafted-request tests proving omitted or injected stock, allocation, tax, conversion, condition-provenance, and serial-state fields cannot control persistence or bypass current authoritative validation.
- [x] 5.4 Run focused stock-transfer permission, Livewire, controller, and service tests for good and broken conditions and serialized and non-serialized products; do not require the full application test suite for this delivery.
- [x] 5.5 Perform and record human browser verification with blind floor-staff, explicitly privileged, and Super Admin accounts across representative create, edit, detail, and applicable lifecycle interactions. Verified by the user in browser.
