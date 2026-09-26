# Tasks: report-card-templates

## Implementation Tasks

### Task 1: Add the ReportCardTemplate schema, and the two referencing properties
- **spec_ref**: `openspec/changes/report-card-templates/specs/report-card/spec.md#requirement-reportcardtemplate-declares-typed-sections-with-a-scale-from-a-shared-library`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the register is loaded WHEN a `ReportCardTemplate` is created with 7 sections (one per
    kind) and a `testKindSectionMap[]` entry THEN it validates and persists
  - GIVEN a `ReportCardTemplate` payload with `sections: []` WHEN it is saved THEN it is rejected
    for violating `minItems: 1`
  - GIVEN the register is loaded WHEN `Cohort.reportCardTemplateId` and `ReportCard.templateId`
    are inspected THEN both are nullable `$ref: ReportCardTemplate` properties
- [ ] Implement
- [ ] Test

### Task 2: Seed ReportCardTemplate mock data, and wire two existing mock rows to it
- **spec_ref**: `openspec/changes/report-card-templates/design.md#seed-data`
- **files**: `lib/Settings/learniq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN `DemoDataService` loads seed data THEN 3 `ReportCardTemplate`
    objects exist, one `Cohort` mock row has `reportCardTemplateId` set, and one `ReportCard` mock
    row has a matching `templateId`
- [ ] Implement
- [ ] Test

### Task 3: ReportCardComposer stamps templateId and limits sections to the assigned template
- **spec_ref**: `openspec/changes/report-card-templates/specs/report-card/spec.md#requirement-composition-is-a-declared-transition-triggered-php-composer-not-a-dataexchangejob-and-not-a-timedjob`
- **files**: `lib/Listener/ReportCardComposer.php`, `tests/Unit/Listener/ReportCardComposerTest.php`
- **acceptance_criteria**:
  - GIVEN a `Cohort` with `reportCardTemplateId` set to a template declaring only `grades` and
    `narrative` WHEN `compose` runs THEN each `ReportCard` gets `templateId` set and only those two
    sections populated
  - GIVEN a `Cohort` with `reportCardTemplateId` unset WHEN `compose` runs THEN each `ReportCard`
    has `templateId: null` and composes the pre-change fixed shape unchanged
  - GIVEN the two pre-existing composer regression scenarios (one ReportCard per learner; a
    subject with no matching period component) THEN both still pass unmodified
- [ ] Implement
- [ ] Test

### Task 4: ReportCardPdfDelegationService resolves and sends the assigned template's slug
- **spec_ref**: `openspec/changes/report-card-templates/specs/report-card/spec.md#requirement-docudesk-pdf-rendering-is-fail-soft-non-blocking-and-its-contract-is-explicitly-proposed`
- **files**: `lib/Service/ReportCardPdfDelegationService.php`, `tests/Unit/Service/ReportCardPdfDelegationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a `ReportCard` with `templateId` set to a template with `slug: "huisstijl-groep-6"` WHEN
    `renderToPdf` runs THEN the outbound payload's `templateSlug` is `"huisstijl-groep-6"`
  - GIVEN a `ReportCard` with `templateId: null` WHEN `renderToPdf` runs THEN `templateSlug` is the
    literal `"report-card"`, unchanged
  - GIVEN the two pre-existing fail-soft regression scenarios THEN both still pass unmodified
- [ ] Implement
- [ ] Test

### Task 5: Declare ReportCardTemplate index+detail manifest pages
- **spec_ref**: `openspec/changes/report-card-templates/specs/report-card/spec.md#requirement-a-reportcardtemplate-is-assigned-per-group-per-period`
- **files**: `src/manifest.json` (or the matching `src/manifest.d/*.json` fragment per this repo's ADR-037 modular-pipeline convention)
- **acceptance_criteria**:
  - GIVEN the manifest is built WHEN the app navigates to the new pages THEN
    `ReportCardTemplateIndex`/`ReportCardTemplateDetail` list and edit `sections[]`,
    `testKindSectionMap[]`, and `lifecycle`, and `CohortDetail` exposes `reportCardTemplateId`
- [ ] Implement
- [ ] Test

### Task 6: Add ReportCardTemplateRegisterTest
- **spec_ref**: `openspec/changes/report-card-templates/specs/report-card/spec.md#requirement-a-template-maps-an-imported-test-kind-to-a-report-section-per-group`
- **files**: `tests/Unit/Settings/ReportCardTemplateRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN parsed THEN `ReportCardTemplate`'s schema block, `x-openregister`
    block, and `sections[]`/`testKindSectionMap[]` shapes are well-formed and `minItems: 1` is
    present on `sections`
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- No new API endpoints — OpenRegister's generic object API serves `ReportCardTemplate`, no Newman
  collection changes needed
- UI changes are declarative manifest pages only, no bespoke Vue component to browser-test
- All tests pass: `vendor/bin/phpunit --filter ReportCard`
- Feature documentation: N/A this round — `docs/Features/` update is tracked with the sibling
  `filinq-configurable-report-templates` change once PDF rendering is visibly configurable
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for the new manifest page titles/
  labels and section-kind/scale enum display labels (ADR-007); load the `writing` skill first —
  no em-dashes, no Title Case
- `openspec validate --change report-card-templates` passes
