# dual-origin-file-access Specification

## Purpose

Ensure browser-facing images and downloadable files remain accessible through whichever supported LAN or Cloudflare application origin the user is currently using.

## Requirements

### Requirement: Origin-Relative Public Media URLs
The system SHALL expose locally stored public media through origin-relative URLs without changing the files' storage locations or media associations.

#### Scenario: Product image is viewed locally
- **WHEN** a user views a product image through the on-premise application origin
- **THEN** the image URL uses that same origin and the existing public storage path

#### Scenario: Product image is viewed through Cloudflare
- **WHEN** a user views the same product image through the Cloudflare application origin
- **THEN** the image URL uses that same Cloudflare origin and the existing public storage path

#### Scenario: Avatar or Media Library attachment is rendered
- **WHEN** the application renders an avatar, payment attachment, document attachment, POS payment image, or return attachment stored as public media
- **THEN** the browser-facing URL is origin-relative and does not embed the fallback application URL

### Requirement: Origin-Relative Direct File Links
The system SHALL generate browser-facing links for locally stored proof and attachment files against the user's current application origin and the disk on which the file is publicly available.

#### Scenario: Settlement proof is opened from either origin
- **WHEN** a user opens an authorized settlement proof from the LAN origin or Cloudflare origin
- **THEN** the file is requested from the same origin through its valid public path

#### Scenario: Existing stored file is exposed after the change
- **WHEN** an existing media or proof record references a valid file
- **THEN** the file remains accessible without relocating the file or rewriting its stored media association

### Requirement: Dual-Origin File Access Preserves Authorization
Changing URL origin behavior MUST NOT broaden access to files beyond the access controls and public-storage exposure already established by the application.

#### Scenario: URL generation changes
- **WHEN** an image or file URL becomes origin-relative
- **THEN** its existing route, filesystem visibility, and authorization behavior remain unchanged
