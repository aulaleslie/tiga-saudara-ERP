# Return serial registration (unknown serials through returns)

Spec: `openspec/changes/register-unknown-serials-through-returns/`. Code: `app/Support/SalesReturn/SerialRegistration/`.

## 1. Source evidence precedence

A return source (standard: one approved `DispatchDetail`; POS: a line or bundle component) is classified by
`HistoricalFulfillmentClassifier` from **persisted fulfillment evidence only** – never from the current
`products.serial_number_required` flag.

1. Serials created by returns (`return_serial_registrations.registered_serial_id`) are return-time lineage and are
   removed from original-fulfillment evidence. They may carry an operational `dispatch_detail_id` anchor, but never count as
   "the sale recorded a serial".
2. A remaining recorded serial id (dispatch serial rows, dispatch `serial_numbers` list) that cannot be resolved to a
   `ProductSerialNumber` of the source product => `inconsistent` (blocks the unknown-serial path for review).
3. An explicit serialized indicator without resolvable serials => `inconsistent`.
4. Resolved serials => `recorded_serial` (existing serial matching only).
5. No serial evidence, no serialized indicator => `quantity_only`. **Legacy compatibility default**: it does not prove
   what the catalog flag was at sale time; the basis (`no_serial_evidence_legacy_default`) is stored on each claim.

Unknown-serial entry requires `quantity_only` **and** the product currently requiring serials. A non-serial product keeps
the unchanged quantity-only flow. The classification `fingerprint` hashes only original evidence (source key, product,
original serial ids, serialized indicator, quantity), so return registrations do not change it, but an external edit of
the source does; receiving then stops for refresh.

## 2. Lifecycle and invariants

| Phase | What is written |
|---|---|
| Create / Edit (draft) | `return_serial_registrations` rows: stable `claim_uuid`, normalized `proposed_serial`, source key/classification/basis/fingerprint, unit attribution key. **No `ProductSerialNumber`, no stock, no history.** |
| Submit / preview | Validation + (POS) registration *intentions* only. |
| Receiving (single transaction) | Serial created (product-scoped unique), claim gets `registered_serial_id/by/at`, serial history, then the workflow's own custody/stock effects. |
| Retry | Claim already registered => reused (idempotent), no duplicate serial/custody/history. |
| Collision | Serial independently registered first => `SerialRegistrationCollisionException` (409), whole action rolls back, proposed text kept. Never auto-attached. |

Invariants: original sale/dispatch serial lists are never modified; one claim row = one unit; unique `claim_uuid`,
unique `(workflow, unit_attribution_key)`, unique `registered_serial_id`; existing `(product_id, serial_number)`
uniqueness is the final guard; equal text on another product is not a collision.

Example (3 unit untracked sale, product now serial-required): return unit 1 with serial `A1`. After receiving, `A1` is a
registered *returned* unit (held in custody). Units 2 and 3 remain untouched originals: eligibility still reports
`available_quantity = 2`, `serial_total = 0` (registered serials are excluded from original evidence) and
`registration_eligible = true`.

## 3. Standard Sales Return UI and collision recovery

* Create/Edit: scan or type a serial for a row. If it exists for the product the normal lookup/lineage checks apply.
  If it is not found and the source is eligible, it is listed with a **"Belum terdaftar"** badge (proposed). It can be
  removed/corrected until receiving (edit stays blocked once received/approved, as today).
* Receiving (`sale-returns.receive`) registers the serials and puts them in **held custody** (not sellable). Settlement,
  coverage, inspection release and original-goods dispatch then work from the received serial ids.
* Collision: receiving is refused with a message naming the serial; nothing is committed, the return stays *Awaiting
  Receiving*. Look the serial up in product serial inventory; if it is genuinely the same unit, resolve that record's
  lineage through the existing process, otherwise correct the proposed text in a return edit (reject → revise) and receive again.

## 4. POS Return: proposed registration, preview, timing, collision recovery

* Create/Edit (`PosReturnCreateForm` / `PosReturnEditForm`, trait `ManagesPendingSerials`): a quantity-style source row whose
  snapshot says `registration_eligible` shows a per-unit serial input once a resolution is chosen. The quantity is the number
  of proposed serials. Whole-bundle cash return additionally shows one input per unit of every eligible component.
* Persistence: one `PosReturnLine` per proposed unit (`quantity = 1`, `returned_serial_id = null`) plus one
  `return_serial_registrations` claim on that line. Replacement lines are tagged `line_meta.execution_mode =
  serial_inventory_replacement` **at submission** (never switched later) and need a replacement serial per unit; ordinary
  non-registration replacements keep `non_serial_note_only`.
* Server-side authority: `PosReturnSubmissionService::validateDraftLines` re-derives eligibility from the resolved dispatch
  line (`registrationEligibility`), requires a serial for every unit of an eligible source, rejects proposals on serialized or
  inconsistent sources, normalized duplicates, already-registered texts, and caps units at the remaining entitlement
  (counting customer-held registered units once).
* Approval preview (`PosReturnApprovalPreviewPlannerService`) only **reads**: each pending line shows `registration_serial`,
  `registration_claim_uuid` and `serial_movement_intent = serial_baru_didaftarkan_saat_receiving…`; a missing serial id is not a
  blocker. The claim uuid is persisted in the plan's `execution_context`.
* Timing: POS approval executes receiving in the same transaction (`executeApprovalFromPreview` → `receiveLinkedSaleReturns`),
  so registration commits with approval/receiving; the stand-alone `receive()` path registers the same way. Standard
  inspection is **not** added to POS: registered units follow the existing POS receiving (stock bucket by receiving owner,
  serial `ACTIVE` at the receiving location with the owner's tax identity).
* Collision recovery: the whole approval aborts (`SerialRegistrationCollisionException`, message names the serial). The return
  stays *Pending approval* with its proposals intact. Look the serial up in product serial inventory; reject the return, correct
  the proposed text in the edit form (or resolve the existing record's lineage through the existing process), resubmit.
* Scope limit: registration for bundle components is implemented for whole-bundle cash return (server-synthesized component
  lines with the component's own product, owner, location and tax). An *individual* component replacement of an eligible
  component keeps today's note-only behavior (no receiving, hence no registration); explicit component lines that carry a
  proposed serial are rejected by the server.

## 5. Unit attribution and repeat-return examples (maintainers)

A registration claim is attributed to **one unit** of its quantity-based source: `unit_attribution_key = <source_key>#<claim_uuid>`.
Initial claims consume source quantity exactly once; later serial occurrences of the same unit never consume it again.

**Standard – repair then return again.** Sale of 3 untracked units, product now serial-required.
1. Return A: 1 unit with proposed `R1`; received → `R1` registered, held. Source `available_quantity = 2`.
2. Settlement `REPAIR`, original-goods dispatch → `R1` is `SOLD` again (anchored to the same dispatch line).
3. Return B: `R1` is selectable (`RegisteredOccurrences::isReReturnable`: registered by a return, back with the customer, not in
   an unreceived return). `consumedUnits` counts `R1` once across A and B → `available_quantity` stays 2; B may claim `R1` +
   two untouched units (3 total) but not a fourth.

**POS – replacement then refund.** Sale of 3 untracked units.
1. Return 1: proposed `ORIG-9` replaced by existing `REPL-9`; approval registers `ORIG-9` (stock +1, `ACTIVE`), dispatches
   `REPL-9`. Completed replacements do not consume quantity (3 still returnable).
2. Lookup now shows the untouched quantity row (3) **and** a serial row for `REPL-9` (`registration_origin_serial_id = ORIG-9`),
   resolved through `PosReturnSerialReplacementChainResolver`.
3. Cash return of `REPL-9`: counted against the same 3 units (the unit cannot also be claimed through the quantity row),
   reduces the original dispatch line to 2 (`applyReceivedDispatchQuantityAdjustments`) and the replacement row to 0.

Initial quantity claims = rows with `pending_serial` / unknown text. Subsequent occurrences = rows addressed by a serial id.
