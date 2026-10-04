# Spec Delta

## Purpose

Allow an individual purchase payment to retain multiple separate supporting image and document files and make every attachment accessible wherever that payment is viewed.

## ADDED Requirements

### Requirement: Individual purchase payment accepts multiple separate attachments
The system SHALL allow an authorized user to attach zero or more files to one individual purchase payment during creation. It MUST retain each accepted file as a distinct attachment associated with that payment and MUST NOT package files into a ZIP archive. The global purchase payment creation workflow SHALL retain its existing attachment behavior.

#### Scenario: Payment created with several files
- **WHEN** an authorized user submits an individual purchase payment with several accepted files
- **THEN** one payment is created with one separate attachment per file
- **AND** each attachment can be opened independently

#### Scenario: Payment created without files
- **WHEN** an authorized user submits a valid individual purchase payment without attachments
- **THEN** the payment is created without attachments

#### Scenario: Global payment remains unchanged
- **WHEN** a user creates a global purchase payment
- **THEN** its existing upload and allocation behavior remains available

### Requirement: Individual payment upload accepts images and documents
The system SHALL accept supported raster image files (JPEG, PNG, WebP, GIF, BMP) and PDF, Word, Excel, and plain-text documents for individual purchase payment attachments. The system SHALL explicitly exclude SVG files to eliminate browser active-content and script-execution risks. It MUST validate the actual file type, verify OpenXML package structure for DOCX and XLSX documents, and reject disguised or unsupported files. The application SHALL NOT impose a maximum original file size for this workflow; deployment-level transport limits may still prevent a request from reaching the application.

#### Scenario: Supported document types
- **WHEN** a user uploads PDF, DOC, DOCX, XLS, XLSX, or TXT files with matching contents
- **THEN** the files are accepted as separate payment attachments

#### Scenario: SVG is excluded
- **WHEN** a user uploads an SVG file
- **THEN** the upload is rejected and the file is not attached to a payment

#### Scenario: Image outside the compressor's supported formats
- **WHEN** a valid raster image format cannot be compressed by the available image processor
- **THEN** the image is accepted and stored in its original format

#### Scenario: Disguised file
- **WHEN** a file has an allowed extension but its actual content is an unsupported file type, or a generic ZIP renamed to DOCX/XLSX without valid Office package parts
- **THEN** the upload is rejected and it is not attached to a payment

### Requirement: Image compression is bounded and preserves aspect ratio
The system SHALL make at most one compression attempt for a supported image, preserving its aspect ratio. A result below 1 MB is the goal, not a condition for accepting the file. The system MUST NOT repeatedly resize, recursively process, or ZIP the image, and MUST accept an otherwise valid image when the attempt leaves it above 1 MB.

#### Scenario: One pass reduces image size
- **WHEN** compression produces a smaller valid image
- **THEN** the smaller image is stored with its original aspect ratio

#### Scenario: One pass cannot reach 1 MB
- **WHEN** a valid image remains larger than 1 MB after one compression attempt
- **THEN** it remains accepted as an attachment
- **AND** no further compression pass or ZIP packaging is performed

### Requirement: All payment attachments are visible in payment views
The purchase detail payment table, the separate purchase payment list, and the payment detail view SHALL show a distinct, safely labeled link for every attachment on each payment. Each link SHALL retain the current new-tab behavior. Existing payments with zero or one attachment MUST remain viewable.

#### Scenario: Payment has multiple attachments
- **WHEN** a payment with multiple attachments appears in either payment table or its detail view
- **THEN** every attachment has its own link using a readable file label
- **AND** each link opens in a new tab or follows the browser's download behavior for that file type

#### Scenario: Payment has no attachments
- **WHEN** a payment without attachments appears in either payment table
- **THEN** the attachment cell shows a clear empty state

#### Scenario: Stored filename contains markup
- **WHEN** an attachment filename includes HTML-like characters
- **THEN** the label is displayed as text and does not execute markup
