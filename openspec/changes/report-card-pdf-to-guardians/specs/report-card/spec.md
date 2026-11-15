## ADDED Requirements

### Requirement: Publishing a report card renders its PDF

After `publishToParents` is saved, learniq MUST render the report card's PDF through filinq when `docudeskRenderStatus` is not `rendered`. A failed render MUST NOT undo or block the publish; it MUST record `docudeskRenderStatus: failed` and `docudeskRenderError`.

#### Scenario: Publish renders the PDF

- **GIVEN** a finalised report card without a PDF and filinq answering with a `documentRef`
- **WHEN** a mentor publishes it to parents
- **THEN** the card is `published-to-parents` and `docudeskRenderStatus` is `rendered`

#### Scenario: filinq is down

- **GIVEN** a finalised report card and filinq not answering
- **WHEN** the mentor publishes it
- **THEN** the card is `published-to-parents`
- **AND** `docudeskRenderStatus` is `failed` with a reason in `docudeskRenderError`

### Requirement: The rendered PDF is a file on the report card

After a successful render learniq MUST attach the PDF to the report card as an OpenRegister file named after the period and the learner, and MUST replace a file attached by an earlier render of the same card.

#### Scenario: First render

- **GIVEN** a report card that was just rendered
- **WHEN** a mentor opens it in learniq
- **THEN** the files widget lists one PDF named after the period and the learner

#### Scenario: A re-render replaces the file

- **GIVEN** a published report card with an attached PDF
- **WHEN** a mentor runs "Render the PDF again"
- **THEN** the card still has one PDF, the new one

### Requirement: A guardian downloads the published report card

The guardian's `parentReportCards` collection MUST declare `filesDownload: true`, so a guardian MUST be able to download the PDF of a published report card of their own child and of no other child. A card that is not `published-to-parents` MUST NOT be downloadable.

#### Scenario: A guardian downloads her child's report card

- **GIVEN** a guardian signed in to the portal and a published report card with a PDF for her child
- **WHEN** she opens her child's report cards and chooses "Download as PDF"
- **THEN** she receives the PDF

#### Scenario: Another child's report card

- **GIVEN** the same guardian and a published report card of a child who is not hers
- **WHEN** a download of that card's file is requested in her session
- **THEN** the portal answers 404

### Requirement: Staff retry a failed render from the page

`ReportCardDetail` MUST show `docudeskRenderStatus` and, when it is `failed`, offer "Render the PDF again" (`rerenderToPdf` on a published card, `renderToPdf` on a finalised one).

#### Scenario: Retry after a failure

- **GIVEN** a published report card with `docudeskRenderStatus: failed` and filinq answering again
- **WHEN** a mentor chooses "Render the PDF again"
- **THEN** `docudeskRenderStatus` is `rendered` and the PDF is attached
