## ADDED Requirements

### Requirement: Received purchase correction has a canonical permission
The system SHALL define `purchases.received.correct` in the canonical purchase permission catalog and SHALL use it consistently for received-purchase correction UI, routes, controllers, and services.

#### Scenario: Role management exposes correction authority
- **WHEN** an administrator configures a role
- **THEN** the purchase permission group SHALL expose `purchases.received.correct` with an unambiguous received-purchase correction label

#### Scenario: Authorization checks use the canonical key
- **WHEN** a received-purchase correction action is evaluated
- **THEN** every authorization layer SHALL enforce `purchases.received.correct` or the established Super Admin authorization path
