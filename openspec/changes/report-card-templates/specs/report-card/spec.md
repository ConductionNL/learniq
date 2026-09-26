# report-card Specification

## ADDED Requirements

### Requirement: ReportCardTemplate declares typed sections with a scale from a shared library

The system MUST persist `ReportCardTemplate` as an OpenRegister object: `name`, `tenant_id`, a
`sections[]` array (each entry: `kind` — one of `grades`, `lvs-results`, `attendance`,
`social-emotional`, `narrative`, `pupil-voice`, `portfolio` — `label`, `order`, and `scale`, where
`scale` MUST be one of the shared scale-library values `steps` (trapjes), `dots` (bolletjes),
`smileys`, `grades-1-10` (cijfers), `letters`, `cito-level`, `text`), and a `lifecycle` of
`active`/`archived` mirroring `CourseTemplateDetail`'s existing template pattern. `sections[]` MUST
declare `minItems: 1` at the schema level (JSON Schema, not a runtime guard) — a `ReportCardTemplate`
with zero sections is invalid and cannot be saved, so it can never reach a group's
`reportCardTemplateId` assignment in the first place.

#### Scenario: A template with a scale from every library value validates

<!-- @e2e exclude Pure OpenRegister schema/enum validation; no scholiq DOM surface for schema registration itself, covered by a `*RegisterTest` per the established convention (e.g. `ReportCardComposerRegisterTest`). -->

- **GIVEN** the `report-card` schemas are registered in OpenRegister
- **WHEN** a `ReportCardTemplate` is created with one section per `kind`, each given a distinct
  `scale` value from `steps`/`dots`/`smileys`/`grades-1-10`/`letters`/`cito-level`/`text`
- **THEN** it persists as a valid OpenRegister object with all seven sections and their scales
  intact

#### Scenario: A template with no sections fails schema validation on save

<!-- @e2e exclude Pure JSON Schema `minItems` validation at the OpenRegister object-write layer; no scholiq DOM surface beyond the generic manifest form's own validation-error rendering, covered by PHPUnit ReportCardTemplateRegisterTest. -->

- **GIVEN** a `ReportCardTemplate` payload with `sections: []`
- **WHEN** it is saved
- **THEN** OpenRegister rejects it for violating `sections[]`'s declared `minItems: 1`, so it can
  never be reachable from a `Cohort.reportCardTemplateId` assignment

### Requirement: A template maps an imported test kind to a report section, per group

`ReportCardTemplate` MUST carry `testKindSectionMap[]` (each entry: `testKind` — a free-text
identifier for an imported methodetoets/LVS test kind, e.g. `cito-rekenen-groep-6` — and
`sectionKind`, one of the same section `kind` enum), so that once a test result exists under a
given `testKind` for a learner, `ReportCardComposer` knows which section of the template it belongs
to. This mapping is per-template (and therefore effectively per-group, since a template is assigned
per group), matching Easyrapport's own documented behaviour ("De leerkracht bepaalt welke toets bij
welk rapportonderdeel hoort", `compare/proposed-rows.md` R-new-3) and ParnasSys's structure settings
(`parnassys/round1/documented-column.md` row 7.4). No LVS test-result data source exists yet in this
register (`lvs-import-contract`, tier B, not built) — this mapping is declared now so that change
does not require a further schema revision to slot in.

#### Scenario: A test-kind mapping is declared without a corresponding data source present

<!-- @e2e exclude Pure schema declaration; nothing to render or exercise until lvs-import-contract lands. -->

- **GIVEN** a `ReportCardTemplate` with `testKindSectionMap: [{testKind: "cito-rekenen-groep-6",
  sectionKind: "lvs-results"}]`
- **WHEN** the template is saved
- **THEN** it persists the mapping even though no `AssessmentResult`-shaped LVS record exists yet to
  resolve it

### Requirement: A ReportCardTemplate is assigned per group, per period

`Cohort` MUST gain `reportCardTemplateId` (nullable, `$ref: ReportCardTemplate`) — the template used
whenever a `ReportPeriod` covering that cohort is composed. This mirrors ParnasSys's per-leerjaar
template setting (`parnassys/round1/documented-column.md` row 7.4: "per leerjaar instellen wat de
uiterlijke kenmerken van het rapport zijn"). A cohort with no `reportCardTemplateId` set composes
using today's fixed shape (see the MODIFIED composition requirement below) — this is additive, not a
forced migration.

#### Scenario: A cohort's assigned template determines its report cards' sections

<!-- @e2e tests/e2e/spec-coverage/report-card.spec.ts -->

- **GIVEN** a `Cohort` with `reportCardTemplateId` set to a template declaring `grades` and
  `narrative` sections only
- **WHEN** its `ReportPeriod` is composed
- **THEN** every resulting `ReportCard` carries a `templateId` matching the cohort's assigned
  template and its populated sections are limited to `grades` and `narrative`

## MODIFIED Requirements

### Requirement: Composition is a declared-transition-triggered PHP composer, not a DataExchangeJob and not a TimedJob

`ReportPeriod`'s `compose` transition (`open → composed`, requiring `ReportPeriodComposeGuard`) MUST
trigger `ReportCardComposer`, an OR-event-driven `Listener` (ADR-031 "cross-object write bridge"
exception, mirroring `ConferenceScheduleGenerator`'s shape), NOT a PHP `TimedJob` and NOT a
`data-exchange` `DataExchangeJob`. For every learner in `cohortIds[] → Cohort.learnerIds`, it MUST
create one `ReportCard` (`draft`), stamped with `templateId` copied from the learner's `Cohort.
reportCardTemplateId` when set. **When a template is assigned**, the composer populates exactly the
sections that template's `sections[]` declares: a `grades` section from `subjectGrades[]` (one entry
per `curriculumPlanIds[]` entry, populated from `FinalGrade.breakdown.periods[periodCode]` plus
`sourceGradeEntryIds[]`, unchanged from before), an `attendance` section from `attendanceSummary`
(unchanged, gated on `attendanceIncluded`, still composed from `AttendanceRecord`s within
`[startDate, endDate]`), a `narrative` section from `mentorComment` (unchanged field, now also
addressable as a named section), and `social-emotional`/`pupil-voice`/`portfolio`/`lvs-results`
sections stored as free-form mentor-edited text placeholders when the template declares them (no
automated data source for these four exists yet). A section kind the template does not declare is
not populated. **When no template is assigned** (`Cohort.reportCardTemplateId` is unset), the
composer MUST fall back to today's fixed shape unchanged: `subjectGrades[]` + `attendanceSummary` +
`mentorComment`, with `templateId` left null — existing, un-templated periods compose identically to
before this change. A `recompose` self-loop transition on `draft` `ReportCard`s MUST allow re-running
the composer for a single learner without recreating the object.
<!-- Previous behavior: The composer always populated the fixed subjectGrades[]/attendanceSummary/
mentorComment shape with no concept of a template or section kind. -->

#### Scenario: Composing a period creates one ReportCard per cohort learner

<!-- @e2e tests/e2e/spec-coverage/report-card.spec.ts -->

- **GIVEN** a locked `ReportPeriod` covering 2 cohorts totalling 30 learners and 4
  `curriculumPlanIds`
- **WHEN** `compose` runs
- **THEN** exactly 30 `draft` `ReportCard`s are created, each with up to 4 `subjectGrades[]` rows
- **AND** no `DataExchangeJob` and no PHP `TimedJob` is involved in the composition

#### Scenario: A subject with no matching period component contributes no row, not an error

<!-- @e2e exclude Composer null-handling is backend logic verified by PHPUnit ReportCardComposerTest; no scholiq DOM surface. -->

- **GIVEN** a `curriculumPlanId` in `ReportPeriod.curriculumPlanIds` whose `CurriculumPlan.
  components[]` declares no component with `period` matching `ReportPeriod.periodCode`
- **WHEN** `ReportCardComposer` runs for a learner enrolled in that subject
- **THEN** no `subjectGrades[]` row is created for that subject and composition completes without
  error for the learner's other subjects

#### Scenario: An untemplated cohort composes exactly as before this change

<!-- @e2e exclude Regression/fallback path is backend logic verified by PHPUnit ReportCardComposerTest; no new scholiq DOM surface. -->

- **GIVEN** a `Cohort` with `reportCardTemplateId` unset, in an otherwise identical setup to the
  first scenario above
- **WHEN** `compose` runs
- **THEN** each resulting `ReportCard` has `templateId: null` and carries only `subjectGrades[]`,
  `attendanceSummary`, and `mentorComment` — identical in shape to composition before this change

#### Scenario: A templated cohort composes only the sections its template declares

<!-- @e2e tests/e2e/spec-coverage/report-card.spec.ts -->

- **GIVEN** a `Cohort` with `reportCardTemplateId` set to a template declaring only `grades` and
  `narrative` sections
- **WHEN** `compose` runs for that cohort
- **THEN** each resulting `ReportCard` carries `templateId` set, `subjectGrades[]` and
  `mentorComment` populated, and no `pupil-voice`/`social-emotional`/`portfolio`/`lvs-results`
  section content is written

### Requirement: docudesk PDF rendering is fail-soft, non-blocking, and its contract is explicitly proposed

`ReportCard` MUST carry nullable `docudeskRenderStatus` (`requested | rendered | failed`),
`docudeskRequestedAt`, `docudeskDocumentRef`, and `docudeskRenderError`, mirroring `Credential`'s
wallet-offer-state field shape. A `renderToPdf` (`finalised → finalised`) and `rerenderToPdf`
(`published-to-parents → published-to-parents`) self-loop transition pair MUST each `require`
`OCA\Learniq\Service\ReportCardPdfDelegationService`, which MUST always return `true` (fail-soft — a
render failure is logged and recorded in `docudeskRenderError` but MUST NOT block any `ReportCard`
lifecycle transition, mirroring `bpv-praktijkovereenkomst`'s POK precedent that the OpenRegister
object is the legally complete record regardless of a rendered document's existence). The service
posts to a **proposed, not-yet-verified** docudesk REST contract (no docudesk endpoint exists in
this repo to reference), using the same `IClientService` + `IURLGenerator` + `IAppConfig`
bearer-token seam `DataExchangeRunHandler::callOpenConnector()`/`WalletOfferDelegationService`
already establish. **The `templateSlug` sent in that payload MUST be the `ReportCardTemplate.slug`
of the `ReportCardTemplate` referenced by the `ReportCard`'s `templateId`, resolved via
`ObjectService` at render time; when `templateId` is null, `templateSlug` MUST remain the literal
`'report-card'` (today's fixed value, unchanged for un-templated report cards).** The docudesk-side
endpoint implementation is an explicit, tracked follow-up leaf, not built by this change.
<!-- Previous behavior: templateSlug was always the literal 'report-card' constant
(ReportCardPdfDelegationService::TEMPLATE_SLUG), regardless of any template. -->

#### Scenario: A PDF render failure does not block publication

<!-- @e2e exclude Fail-soft backend hook; no scholiq DOM surface — the renderToPdf action behaves identically to a no-op from the user's perspective regardless of docudesk reachability. Covered by ReportCardPdfDelegationServiceTest per tasks.md. -->

- **GIVEN** a `finalised` `ReportCard` and docudesk unreachable or absent
- **WHEN** `renderToPdf` is triggered
- **THEN** the transition succeeds regardless, `docudeskRenderStatus` becomes `failed`,
  `docudeskRenderError` records the failure, and `lifecycle` remains `finalised`

#### Scenario: A successful render records the docudesk document reference

<!-- @e2e exclude Backend hook success path; no scholiq DOM surface beyond the existing generic lifecycleActions button. Covered by ReportCardPdfDelegationServiceTest per tasks.md. -->

- **GIVEN** a `finalised` `ReportCard` and a reachable docudesk endpoint accepting the proposed
  contract
- **WHEN** `renderToPdf` is triggered and docudesk returns 2xx with a document reference
- **THEN** `docudeskRenderStatus` becomes `rendered`, `docudeskDocumentRef` is set, and
  `docudeskRenderError` is cleared

#### Scenario: A report card with an assigned template sends that template's slug to docudesk

<!-- @e2e exclude Backend delegation payload assertion; no scholiq DOM surface for the outbound payload itself. Covered by ReportCardPdfDelegationServiceTest per tasks.md. -->

- **GIVEN** a `finalised` `ReportCard` whose `templateId` references a `ReportCardTemplate` with
  `slug: "huisstijl-groep-6"`
- **WHEN** `renderToPdf` is triggered
- **THEN** the outbound payload's `templateSlug` is `"huisstijl-groep-6"`, not the literal
  `"report-card"`

#### Scenario: A report card with no assigned template keeps sending the default slug

<!-- @e2e exclude Backend delegation payload assertion; no scholiq DOM surface for the outbound payload itself. Covered by ReportCardPdfDelegationServiceTest per tasks.md. -->

- **GIVEN** a `finalised` `ReportCard` with `templateId: null`
- **WHEN** `renderToPdf` is triggered
- **THEN** the outbound payload's `templateSlug` is the literal `"report-card"`, unchanged from
  today
