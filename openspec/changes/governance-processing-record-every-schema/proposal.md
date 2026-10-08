---
kind: code
---

# Keep the GDPR record of processing up to date by itself

## Why

The GDPR (AVG) asks every school and employer for a record of processing activities (Art. 30, the verwerkingsregister). OpenRegister builds that record from the `x-openregister-processing` block on each schema, and stamps every audit entry with the processing activity it belongs to (`AuditTrailMapper::resolveProcessingActivityId()`, openregister `processing-activity-register`). learniq declares the block on 15 of its 162 schemas. 57 more schemas hold personal data, among them `Enrolment`, `GradeEntry`, `ReportCard`, `Submission`, `AssessmentResult`, `LearningPlan`, `SupportRequest`, `ExcuseRequest`, `BpvPlacement` and `ConcernReport`. Their processing never shows in the record, and their audit entries carry no purpose.

So the record is right for the most sensitive data and silent on grades, reports and care. A privacy officer who exports it today hands in an incomplete Art. 30 record.

One row, one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `gov-record-of-processing` | Keep the GDPR record of processing up to date by itself. | `partial`: 15 of 162 schemas carry a processing block; 57 schemas with personal data carry none |

## What changes

- Every schema that holds personal data declares `x-openregister-processing`, pointing at one of a small set of processing activities: the existing ones where they fit, and new ones for teaching and assessment, results and reporting, care and support, work placement, parent contact, and engagement.
- Each new activity states purpose (doelbinding), legal basis (rechtsgrond), data categories, retention and whether reads are logged.
- A check fails the build when a schema with a personal-data field has no processing block, so a new schema cannot slip out of the record again.
- The processing catalogue seed (`avg-verwerkingsregister` requirement "Scholiq MUST ship its processing catalogue as draft seed content") gains the new activities as drafts for the privacy officer to review.

## Capabilities

### Modified capabilities

- `avg-verwerkingsregister`: ADDED requirements for full coverage and the build check.

## Impact

- **Register**: `x-openregister-processing` on 57 schemas; register version bump.
- **Seed**: new draft processing activities in the catalogue.
- **CI**: `tests/validate-processing-coverage.js`, run from `npm run check:register`.
- **Owner**: the row names openregister as owner. OpenRegister's half (the record, the export, the stamping) is built and specified in openregister's `processing-activity-register`. What is missing is learniq's declaration, so the change is here.
