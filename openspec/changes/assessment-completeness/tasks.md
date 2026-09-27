## Implementation Tasks

### Task 1: Add plagiarism-result fields to Submission
- **spec_ref**: `openspec/specs/assessment/spec.md#requirement-submission-carries-a-plagiarism-check-result-landing-spot`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN parsed THEN `Submission.properties` includes `plagiarismStatus` (enum
    `not-requested`/`pending`/`completed`, default `not-requested`), `plagiarismScore` (nullable 0.0-1.0),
    `plagiarismCheckedAt` (nullable date-time)
- [x] Implement
- [x] Test

### Task 2: Add method-test sourceKind and fields to GradeEntry
- **spec_ref**: `openspec/specs/assessment/spec.md#requirement-gradeentry-records-a-method-test-result-per-subject-per-block`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN parsed THEN `GradeEntry.properties.sourceKind.enum` includes `method-test`
    and `GradeEntry.properties` includes nullable `methodName`/`methodBlock`
- [x] Implement
- [x] Test

### Task 3: Add a QTI export action to ItemBankDetail
- **spec_ref**: `openspec/specs/assessment/spec.md#requirement-itembankdetail-exposes-a-qti-export-action`
- **files**: `src/manifest.d/learning.json`
- **acceptance_criteria**:
  - GIVEN the merged manifest WHEN read THEN `ItemBankDetail` declares a header action of `type: "navigate"`
    targeting the existing `/api/assessment/qti-export` route with `itemBankId` resolved from `@objectId`
- [x] Implement
- [x] Test

### Task 4: Add an ExamAccommodation widget to AssessmentDetail
- **spec_ref**: `openspec/specs/assessment/spec.md#requirement-assessmentdetail-surfaces-exam-accommodations`
- **files**: `src/manifest.d/learning.json`
- **acceptance_criteria**:
  - GIVEN the merged manifest WHEN read THEN `AssessmentDetail` declares an `object-list` widget for schema
    `exam-accommodation` filtered by `assessmentId: "@objectId"`, with a layout entry
- [x] Implement
- [x] Test

### Task 5: Validate the merged manifest and OpenSpec artifacts
- **spec_ref**: `openspec/changes/assessment-completeness/design.md`
- **files**: none (validation only)
- **acceptance_criteria**:
  - GIVEN the merged manifest (base + fragments) WHEN Ajv-validated against `app-manifest-v2.schema.json`
    THEN it passes
  - GIVEN `openspec validate assessment-completeness --strict` WHEN run THEN it exits 0
- [x] Implement
- [x] Test

## Verification

- All tasks checked off
- `openspec validate assessment-completeness --strict` passes
- Merged-manifest Ajv validation passes
- `git diff` reviewed against every spec requirement before commit

## Tests (company-wide ADR-009)

- PHPUnit: N/A — no PHP touched; the pre-existing `QtiExportServiceTest`/`LtiAgsScorePollJobTest` already
  cover the backend halves this change wires a UI action / documents as complete
- Newman/Postman: N/A — no new route
- Browser (Playwright MCP): deferred this pass (see proposal Open Questions)
- `composer check:strict` (no PHP touched) and `npm run lint` run once before push per CLAUDE.md

## Documentation (company-wide ADR-010)

- N/A — schema/manifest-level completions of already-documented capabilities, no new user-facing feature
  page

## i18n (company-wide ADR-005)

- New manifest strings (the QTI export action label, the accommodation widget title) use the manifest's
  plain-English title convention (Dutch only in `nl` subject lines/seed content per CLAUDE.md's writing
  skill); no `l10n/` additions needed beyond what `npm run test:l10n` already covers
