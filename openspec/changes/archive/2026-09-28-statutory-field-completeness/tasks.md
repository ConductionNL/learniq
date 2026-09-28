## Implementation Tasks

### Task 1: Add age-derived self-service-rights fields to LearnerProfile
- **spec_ref**: `openspec/specs/avg-verwerkingsregister/spec.md#requirement-learnerprofile-declares-age-derived-self-service-rights-flags`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN parsed THEN `LearnerProfile.properties` includes `ageYears`,
    `hasPartialSelfServiceRights`, `hasFullSelfServiceRights`, each `materialise: true` with a `dateDiff`
    (ageYears) or comparison (the two booleans) expression
- [x] Implement
- [x] Test

### Task 2: Add referentieniveau to AssessmentResult
- **spec_ref**: `openspec/specs/assessment/spec.md#requirement-assessmentresult-tracks-referentieniveau`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN parsed THEN `AssessmentResult.properties.referentieniveau` is a nullable
    enum of `1F`/`1S`/`2F`/`2S`/`3F`/`3S`
- [x] Implement
- [x] Test

### Task 3: Add flagKind to AttendanceFlag
- **spec_ref**: `openspec/specs/attendance/spec.md#requirement-attendanceflag-classifies-its-statutory-flagkind`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN parsed THEN `AttendanceFlag.properties.flagKind` is an enum of
    `signal-verzuim`/`langdurig-relatief-verzuim`/`thuiszitter`, default `signal-verzuim`
- [x] Implement
- [x] Test

### Task 4: Declare x-openregister-archival on six schemas
- **spec_ref**: `openspec/specs/avg-verwerkingsregister/spec.md#requirement-six-schemas-declare-a-retention-and-destruction-annotation`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN parsed THEN `LearnerProfile`/`AttendanceRecord` carry
    `x-openregister-archival.retention.default: "P5Y"`, `AttendanceFlag` carries `"P3Y"`, and
    `DossierNote`/`BehaviourIncident`/`WellbeingCheckIn` each carry `"P2Y"`, each with `category` and
    `action: "destroy"` set
- [x] Implement
- [x] Test

### Task 5: Validate the register and OpenSpec artifacts
- **spec_ref**: `openspec/changes/statutory-field-completeness/design.md`
- **files**: none (validation only)
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN parsed as JSON THEN it is valid
  - GIVEN `openspec validate statutory-field-completeness --strict` WHEN run THEN it exits 0
- [x] Implement
- [x] Test

## Verification

- All tasks checked off
- `openspec validate statutory-field-completeness --strict` passes
- `python3 -m json.tool lib/Settings/learniq_register.json` (or equivalent parse check) passes
- `git diff` reviewed against every spec requirement before commit

## Tests (company-wide ADR-009)

- PHPUnit: `vendor/bin/phpunit --filter ProcessingActivityCatalogueTest` (register-shape regression check —
  no field this test asserts on is removed/renamed by this change)
- Newman/Postman: N/A — no new route
- Browser (Playwright MCP): N/A — no UI surface changed
- `composer check:strict` (no PHP touched) and `npm run lint` (no JS/Vue touched) run once before push per
  CLAUDE.md, primarily to confirm no regression

## Documentation (company-wide ADR-010)

- N/A — declarative schema annotations closing already-documented statutory findings, no new user-facing
  feature page

## i18n (company-wide ADR-005)

- New enum values (`flagKind`, `referentieniveau`) use their standard Dutch statutory names as literal enum
  values (not translated strings — matching how `AttendanceFlag`'s existing `lifecycle` enum and
  `Assessment`'s existing enums are already literal, untranslated identifiers); no `l10n/` additions needed
