## Implementation Tasks

### Task 1: Add address/emergency/medical/gezag fields to LearnerProfile
- **spec_ref**: `openspec/specs/avg-verwerkingsregister/spec.md#requirement-scholiq-must-ship-its-processing-catalogue-as-draft-seed-content`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN it is parsed THEN `LearnerProfile.properties` includes `address`,
    `emergencyContacts`, `medicalConditions`, `allergies`, `medicalConsentDate`, `hasParentalAuthority`,
    all nullable/defaulted so existing rows stay valid
  - GIVEN `LearnerProfile.x-openregister-processing.dataCategories` WHEN read THEN it includes `address`,
    `emergencyContacts`, `medicalConditions`, `allergies`
- [x] Implement
- [x] Test

### Task 2: Add Cohort.kind for standing care/plusklas groups
- **spec_ref**: `openspec/specs/school-structure/spec.md#requirement-cohort-declares-a-kind-distinguishing-standing-careplusklas-subgroups-from-teaching-cohorts`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN parsed THEN `Cohort.properties.kind` is an enum of `teaching`/`care`/
    `plusklas` defaulting to `teaching`
- [x] Implement
- [x] Test

### Task 3: Add the FirstAidIncident schema
- **spec_ref**: `openspec/specs/pupil-dossier/spec.md#requirement-persist-firstaidincident-as-a-fourth-pupil-dossier-domain-object`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN parsed THEN a `FirstAidIncident` schema exists with `learnerId`,
    `reportedBy`, `occurredAt`, `whatHappened`, `treatmentGiven`, `notifiedGuardian`, `notifiedGuardianAt`,
    `followUpActions`, `resolution`, `tenant_id`, `lifecycle`, an `x-openregister-lifecycle`
    (`open`→`in-handling`→`resolved`), an `x-property-rbac` read gate, and an
    `x-openregister-notifications.incidentRecorded` rule
  - GIVEN `x-openregister-processing.code` WHEN read THEN it is `scholiq-first-aid-incidents`
- [x] Implement
- [x] Test

### Task 4: Update ProcessingActivityCatalogueTest for the eleventh activity
- **spec_ref**: `openspec/specs/avg-verwerkingsregister/spec.md#requirement-scholiq-must-ship-its-processing-catalogue-as-draft-seed-content`
- **files**: `tests/Unit/Settings/ProcessingActivityCatalogueTest.php`
- **acceptance_criteria**:
  - GIVEN `ACTIVITY_SCHEMAS` WHEN read THEN it maps `FirstAidIncident` to `scholiq-first-aid-incidents`
  - GIVEN the activity-count assertion WHEN run THEN it expects 11 distinct codes, and the whole suite
    passes green
- [x] Implement
- [x] Test

### Task 5: Add FirstAidIncidents index + FirstAidIncidentDetail manifest pages
- **spec_ref**: `openspec/specs/pupil-dossier/spec.md#requirement-persist-firstaidincident-as-a-fourth-pupil-dossier-domain-object`
- **files**: `src/manifest.d/pupil-record.json`
- **acceptance_criteria**:
  - GIVEN the merged manifest WHEN built THEN routes `/pupil-dossier/first-aid` (index) and
    `/pupil-dossier/first-aid/:id` (detail) exist, following the `DossierNotes`/`BehaviourIncidents` pattern
    (data + related widgets, audit sidebar tab, `lifecycleActions`)
  - GIVEN the `GroupPupilDossier` menu WHEN rendered THEN it includes a "First aid incidents" entry gated
    to `instructor`/`coordinator`/`admin`, matching its siblings
- [x] Implement
- [x] Test

### Task 6: Reorganise LearnerProfileDetail into a tabbed pupil card
- **spec_ref**: `openspec/changes/learner-record-extras/design.md#decision-4-use-the-existing-tabs-widget-type-for-the-pupil-card-layout`
- **files**: `src/manifest.d/people.json`
- **acceptance_criteria**:
  - GIVEN `LearnerProfileDetail` WHEN rendered THEN a `tabs` widget occupies the top-left slot with four
    tabs: Identity, Address & contact, Guardians (the existing `related` widget, promoted), and Medical &
    first aid (new fields + a `FirstAidIncident` object-list scoped to `learnerId: "@objectId"`)
  - GIVEN a widget consumed by a tab WHEN the layout array is read THEN it has no separate grid `layout`
    entry of its own (no double-render)
  - GIVEN the KPI column and the Enrolments/Learning plans/Final grades/Attendance/Credentials/dossier
    object-lists WHEN the page renders THEN they are unchanged in position and behaviour
- [x] Implement
- [x] Test

### Task 7: Validate the merged manifest and OpenSpec artifacts
- **spec_ref**: `openspec/changes/learner-record-extras/design.md`
- **files**: none (validation only)
- **acceptance_criteria**:
  - GIVEN `npm run check:manifest` WHEN run THEN it exits 0 against the edited fragments
  - GIVEN `openspec validate learner-record-extras --strict` WHEN run THEN it exits 0
- [x] Implement
- [x] Test

## Verification

- All tasks checked off
- `openspec validate learner-record-extras --strict` passes
- Manual read-through of the merged `LearnerProfileDetail` manifest against the acceptance criteria above
- `git diff` reviewed against every spec requirement before commit

## Tests (company-wide ADR-009)

- PHPUnit: `vendor/bin/phpunit --filter ProcessingActivityCatalogueTest` (updated for the 11th activity)
- Newman/Postman: N/A — no new PHP controller or route; `FirstAidIncident` is served by OpenRegister's
  existing generic object API, already covered by its own suite
- Browser (Playwright MCP): a manual pass against the built app verifying the four tabs render and Guardians
  resolves; no new Playwright spec file added in this pass — `tests/e2e/spec-coverage/pupil-dossier.spec.ts`
  already exercises the sibling DossierNote/BehaviourIncident/WellbeingCheckIn manifest pattern this change
  extends with the same shape
- `composer check:strict` and `npm run lint` run once before push per CLAUDE.md's verification order

## Documentation (company-wide ADR-010)

- N/A this pass — no `docs/` feature page exists yet for the pupil-dossier capability cluster; adding one is
  better scoped alongside the other three pupil-dossier schemas than as a one-off for this change alone

## i18n (company-wide ADR-005)

- New user-facing labels (tab titles, "First aid incidents" menu entry, field titles) are plain English
  titles in the schema/manifest per this repo's existing convention (Dutch appears only in `nl` subject
  lines and seed content, per CLAUDE.md's writing-skill rule); no `l10n/` string additions are needed beyond
  what `npm run test:l10n` already covers for manifest-declared titles
