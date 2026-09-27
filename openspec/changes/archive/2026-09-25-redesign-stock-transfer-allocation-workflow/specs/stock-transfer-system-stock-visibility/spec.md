# Spec Delta

## ADDED Requirements

### Requirement: Version 3 authorization follows active-business action permissions
Version 3 SHALL evaluate action permissions in the current active business and SHALL not require the actor to be assigned to participating source or destination businesses. Access permission SHALL allow discovery of all version 3 documents, while show, create, edit/submit, approval, receive, cancellation, and history permissions SHALL remain separate. Every HTTP, Livewire, lookup, and mutation boundary MUST enforce its required permission. The existing application Super Admin bypass SHALL remain authoritative.

#### Scenario: Approver allocates across unassigned businesses
- **WHEN** an actor holds approval permission in the active business but is not assigned to selected source/destination businesses
- **THEN** valid cross-business allocation is permitted

#### Scenario: Discovery does not grant actions
- **WHEN** an actor holds access permission but lacks approval, receive, cancellation, or history permission
- **THEN** discovery does not grant those actions or expose their protected payloads

#### Scenario: Permission changes with active business
- **WHEN** the actor switches to a business where the required action permission is absent
- **THEN** the action and any associated protected data are denied on the next request

### Requirement: Version 3 projections separate goods from approval configuration
Version 3 entry, detail, and receipt surfaces SHALL expose permitted goods identities, quantities, condition, and relevant selected serial identities without source/destination configuration or system-stock provenance. Approval permission SHALL authorize route configuration, source-stock totals needed for allocation, and serial source grouping in the approval workspace and summary, even without the legacy view-system-stock permission. Detailed tax/bucket diagnostics SHALL still require view-system-stock as well as approval authorization. These explicit version 3 rules SHALL take precedence over legacy blind-detail and origin-visibility rules only for version 3.

#### Scenario: Creator or receiver holds stock visibility only
- **WHEN** an actor lacks approval permission but has view-system-stock and opens a version 3 document
- **THEN** source and destination configuration is absent from HTML, JSON, component state, errors, and exports

#### Scenario: Approver lacks legacy stock visibility
- **WHEN** a version 3 approver opens allocation configuration
- **THEN** the workspace provides route choices and eligible source totals needed to allocate, but does not disclose detailed tax/bucket diagnostics

### Requirement: Event history requires dedicated permission and sanitized projection
Version 3 detail history SHALL require stockTransfers.view-history in addition to document-view authority. Events SHALL still be recorded when the actor lacks history-view permission. History responses SHALL omit allocation locations and other restricted metadata unless the actor also has approval authority. Without history permission, no event collection SHALL be emitted through page, API, export, or component payloads.

#### Scenario: View detail without history permission
- **WHEN** an otherwise-authorized viewer lacks view-history
- **THEN** the document renders without a timeline or serialized event data

#### Scenario: History viewer is not an approver
- **WHEN** a viewer has show and view-history but lacks approval permission
- **THEN** the timeline shows actions, actors, times, and reasons without source/destination allocations or embedded inventory metadata

#### Scenario: Actor cannot view own event
- **WHEN** a permitted receiver or canceller lacks history-view permission
- **THEN** the action is recorded normally but its history endpoint remains inaccessible to that actor
