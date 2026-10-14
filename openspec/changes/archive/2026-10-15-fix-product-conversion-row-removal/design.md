# Design

## Context

See proposal.md for motivation. Both full product forms embed UnitConfiguration within an ordinary HTTP form and restore conversions from old input after validation failure. Row fields combine parent-rendered inputs with nested UnitSearchDropdown components. Removal compacts indexes while nested children are reused by stable keys.

The integer/string key mismatch was reproduced by invoking the actual initialization and removal methods: integer ID 7 remains after removal with string argument "7"; string ID "7" and generated keys remove successfully. The nested dropdown name mismatch is supported by the component lifecycle but has not yet been reproduced in a browser. It is not established that the confirmed type mismatch explains every reported create-after-validation failure.

## Goals / Non-Goals

**Goals:** Maintain stable row identity independently from current form position; preserve coherent payloads and surviving edits across parent renders and validation redirects.

**Non-Goals:** Rewrite the editor as a Livewire submission flow, change conversion business rules or formatting, add migrations, or expand into unrelated product widgets.

## Decisions

### Normalize identities while retaining strict lookup

Normalize preserved and persisted keys to strings and generate unique keys for missing/null/empty identities. Keep existing identities stable across updates. Use the existing bundle editor's string normalization as a local precedent. Retain strict lookup rather than using loose comparisons, which would hide type inconsistencies and introduce ambiguous matches. Preserve conversion database IDs separately in the submitted payload.

### Make the nested dropdown name reactive

Keep the child keyed by stable row identity and make its `name` input reactive so index changes propagate to its rendered hidden field. Preserve child selection state instead of remounting the dropdown with an index-dependent key: selection currently belongs to the child and may differ from the parent's initial conversion value. Verify compatibility with existing dropdown consumers through focused tests.

### Verify browser payloads as well as component state

Extend focused component regressions for saved integer IDs, old-input string/null IDs, generated keys, and first/middle/last removal. Exercise nested names after compaction and preserve selected units. Perform a narrow browser check on create and edit after server validation, inspecting submitted field names and values. State-only assertions cannot establish browser payload correctness or survival of ordinary unbound input edits.

Run only the affected component/dropdown tests and any targeted request regressions needed for this flow. Do not plan a full test suite or an unfiltered fresh-SQLite suite.

## Risks / Trade-offs

- Reactive name changes affect a shared dropdown → keep selection ownership unchanged and run focused dropdown coverage.
- Ordinary factor/barcode inputs lack parent data binding and price formatting has DOM lifecycle state → verify surviving user edits during the browser removal check; address only proven loss in the affected flow.
- Production intermittency may have another trigger → record request/console evidence if the targeted browser case still fails rather than treating all reports as explained.

## Migration Plan

Deploy the component/view changes through the normal application release process; no data migration is needed. Rollback by reverting this change. Existing persisted conversion IDs and request field structure remain compatible.
