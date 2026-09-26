## ADDED Requirements

### Requirement: Submission carries a plagiarism-check result landing spot

`Submission` MUST carry `plagiarismStatus` (enum: `not-requested`, `pending`, `completed`; default
`not-requested`), a nullable `plagiarismScore` (0.0–1.0), and a nullable `plagiarismCheckedAt` (date-time) —
the data-model landing spot for a `ProvidesPlagiarismCheck` provider's `getSimilarityScore()` result (finding
6.11). No provider implementation ships with this change; these fields exist so one can write its result
somewhere once configured.

#### Scenario: A Submission with no plagiarism check requested defaults to not-requested

- **GIVEN** a `Submission` created without explicit plagiarism fields
- **WHEN** the row is read
- **THEN** `plagiarismStatus` resolves to `"not-requested"` and `plagiarismScore`/`plagiarismCheckedAt` are
  null

#### Scenario: A completed check's score is stored in range

- **GIVEN** a `Submission` whose `Assignment.plagiarismProvider` is set
- **WHEN** a provider (out of scope for this change) writes `plagiarismStatus: "completed"`,
  `plagiarismScore: 0.12`, `plagiarismCheckedAt: <timestamp>`
- **THEN** the schema accepts a `plagiarismScore` value between 0.0 and 1.0 inclusive

### Requirement: GradeEntry records a method-test result per subject per block

`GradeEntry.sourceKind` MUST include `method-test` alongside its existing origins, and `GradeEntry` MUST
carry nullable `methodName` (the teaching method, e.g. "Wereld in Getallen", "Snappet") and `methodBlock`
(e.g. "blok 3") properties, populated only when `sourceKind` is `method-test` — finding 6.6 ("no method-test
model"; PO methodetoetsen scored per subject per block).

#### Scenario: A method-test mark carries its method and block

- **GIVEN** a `GradeEntry` with `sourceKind: "method-test"`
- **WHEN** `methodName` and `methodBlock` are set
- **THEN** the entry is valid and distinguishable from every other `sourceKind` by those two fields being
  populated

#### Scenario: A non-method-test entry leaves methodName/methodBlock null

- **GIVEN** a `GradeEntry` with `sourceKind: "assignment-submission"` (or any non-`method-test` value)
- **WHEN** the row is read
- **THEN** `methodName` and `methodBlock` are null — the fields are additive and do not affect any existing
  source kind

### Requirement: ItemBankDetail exposes a QTI export action

`ItemBankDetail` MUST declare a header action that downloads the item bank's QTI 3.0 export package via the
already-built `GET /api/assessment/qti-export` route (finding 6.3 — the backend existed with no UI trigger).

#### Scenario: A coordinator exports an item bank as QTI

<!-- @e2e exclude the backend export path (QtiExportController/QtiExportService) is already covered by
     QtiExportServiceTest; this requirement adds only a manifest-declared UI trigger to an existing,
     unit-tested endpoint, verified structurally by the merged-manifest Ajv validation this change runs, not
     a new browser scenario -->

- **GIVEN** an `ItemBank` with items
- **WHEN** a coordinator clicks the "Export QTI package" action on `ItemBankDetail`
- **THEN** a QTI 3.0 ZIP download begins, sourced from the existing `/api/assessment/qti-export` endpoint

### Requirement: AssessmentDetail surfaces exam accommodations

`AssessmentDetail` MUST include an `object-list` widget listing `ExamAccommodation` rows scoped to the
assessment being viewed (`filter: { assessmentId: "@objectId" }`) — finding 6.4 ("no proctoring/
accommodation surface on the assessment itself").

#### Scenario: A teacher sees a learner's approved accommodation on the assessment

- **GIVEN** an approved `ExamAccommodation` naming a specific `assessmentId`
- **WHEN** a teacher opens that `Assessment`'s detail page
- **THEN** the accommodation appears in the assessment's accommodations list
