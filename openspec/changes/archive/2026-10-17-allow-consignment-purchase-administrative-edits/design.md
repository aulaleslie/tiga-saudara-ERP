# Design

## Context

See proposal.md for motivation. Consignment conversion creates a Purchase at RECEIVED with immutable commercial lineage. Purchase::updating currently allows only settlement balances and updated_at. PurchasePolicy and PurchaseSourceGuard separately deny date adjustments. Inline note/reference editors already expose ordinary permission checks but their saves fail at the model guard. Purchase attachment upload already works; deletion explicitly rejects media marked CONSIGNMENT_BILLING.

Standard received Purchase date maintenance uses reporting_date and due_date, with independent permissions and atomic audit records. It does not rewrite the original date. Shared normal/global screens and services must retain that behavior.

## Goals / Non-Goals

**Goals:** Implement the administrative exceptions through existing maintenance paths, with the same validation and authorization as ordinary Purchases, on existing and new converted records.

**Non-Goals:** Commercial editing, receiving/cancellation, corrections, returns, lifecycle reopening, source rewriting, new permissions, new audit systems, or a separate original-date rewrite workflow.

## Decisions

### 1. Narrowly expand header mutability

Extend the consignment header allowlist to include reporting_date, due_date, note, supplier_purchase_number, and tax_ref_no alongside existing settlement fields. Retain the default rejection of all other changed attributes and all PurchaseDetail protections. Request/component entry points continue to select validated fields explicitly; the model allowlist is a backstop, not authorization.

Removing the entire source guard would allow monetary and provenance changes and is rejected. Bypassing model events through bulk updates would leave inconsistent protection and is also rejected.

### 2. Reuse standard date services and policies

Remove the consignment-only exclusions from reporting-date and due-date authorization and the relevant source guard without weakening ordinary status, archive, tenancy, global eligibility, or permission rules. Review both ReportingDateOverrideService and DocumentDateAdjustmentService, including global callers. Preserve Super Admin behavior already defined by the application.

Use the existing reporting-date override for the displayed document date, including set/replace/clear, original-date fallback, reason requirements, immutable audit, and existing report semantics. Due-date changes remain independent explicit replacements, preserving payment_term_id; changing reporting date does not automatically recalculate due date. Combined requests remain atomic with separate authorization for each field. No new date endpoint is needed.

### 3. Reuse inline metadata editing

Keep existing note length, invoice-number uniqueness/normalization, current-setting access, global access, and archive rules. Review all three components and shared views so displayed controls and backend behavior agree. Do not route these changes through full Purchase editing or monetary editing.

### 4. Apply ordinary attachment rules to every Purchase attachment

Remove the CONSIGNMENT_BILLING deletion exception from both backend and any UI conditions. Keep Purchase update permission, current-setting/global access where already supported, media ownership/collection checks, and upload constraints. Original conversion attachments on the Purchase become deletable, as agreed for full parity. Do not delete separate source-confirmation media or change conversion audit payloads, attachment snapshots, allocation evidence, or source links. Review actual media storage/linking before implementing to ensure Purchase deletion does not remove a separate source-owned artifact.

### 5. Preserve original source evidence and clarify current information

No synchronization back to the billed confirmation, allocation snapshots, or conversion audit occurs when Purchase metadata changes. Existing reconciliation may continue using its current source/Purchase fields; this change does not redefine reconciliation history. Update informational copy to describe commercial details as locked while dates, references, notes, and attachments follow ordinary Purchase rules. Apply through shared components to normal and applicable global payment views without adding global features absent from ordinary Purchases.

## Risks / Trade-offs

- A broader model allowlist could accidentally permit commercial changes → Explicitly enumerate administrative fields and verify mixed administrative/commercial updates fail atomically.
- UI and backend guard checks may diverge across normal/global paths → Trace both entry paths and cover permission denial and successful persistence in focused tests.
- Current Purchase references may differ from conversion evidence → Preserve source snapshots and describe them as historical evidence.
- Original Purchase invoice attachments can be removed → This is intentional parity; preserve independent source documents and prevent deleting foreign media.
- Reverting code restores the administrative lock → Existing edits remain persisted; no historical data rewrite is appropriate.

## Migration Plan

No schema migration or backfill is expected. Deploy model, policies, source guards, attachment rules, and shared UI together. Verify old converted records as well as newly converted fixtures. Roll back application code if needed without reverting accepted metadata, audits, or attachment deletions.

## Focused Verification

Run only affected-area Laravel tests with explicit paths or filters. Cover date set/replace/clear and due-date replacement, atomic combined failure, permission/tenant/global/archive rules, metadata validation, attachment upload/deletion/ownership and source preservation, and continued commercial/lifecycle protections. Include a small ordinary-Purchase regression case and unchanged payment behavior. Run OpenSpec strict validation and git diff --check. Do not plan or run the full test suite for this change.
