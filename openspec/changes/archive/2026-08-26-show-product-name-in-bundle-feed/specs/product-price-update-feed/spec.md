## ADDED Requirements

### Requirement: Bundle feed events identify the parent product and bundle
For every newly recorded bundle-created or bundle-price-updated event, the system SHALL preserve a display identity containing the parent product name and bundle name, SHALL preserve the parent product code when one exists, and SHALL use that stored identity consistently in the Home preview, full history, event-detail modal, and feed search. The presentation SHALL remain snapshot-based and SHALL NOT require the current product or bundle record to exist.

#### Scenario: Bundle creation records product and bundle identity
- **WHEN** a bundle is successfully created for a parent product
- **THEN** the resulting bundle-created feed event stores a display identity containing the parent product name and bundle name and stores the parent product code when available

#### Scenario: Bundle price update records product and bundle identity
- **WHEN** a bundle sale price is successfully changed
- **THEN** the resulting bundle-price-updated feed event stores a display identity containing the parent product name and current bundle name and stores the parent product code when available

#### Scenario: Shared feed surfaces render a bundle event
- **WHEN** a user views a newly recorded authorized bundle event on Home, in full history, or in the detail modal
- **THEN** the event identifies both the parent product and bundle and displays the parent product code when available

#### Scenario: Search matches either bundle context
- **WHEN** a user searches visible feed history using a partial token from the stored parent product name, parent product code, or bundle name
- **THEN** the matching bundle event is included under the existing tokenized all-terms search rules

#### Scenario: Referenced catalog records are later removed
- **WHEN** the referenced parent product or bundle no longer exists
- **THEN** the feed continues to render and search the stored combined identity without resolving the current catalog record

#### Scenario: Historical bundle event predates combined identity capture
- **WHEN** an existing immutable bundle event contains only its previously stored bundle identity
- **THEN** the system continues to render that historical event without rewriting or backfilling it
