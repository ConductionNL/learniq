---
kind: config
depends_on: []
---

# Proposal: assessment-completeness

## Summary

Closes five of the six named gaps in the "Assignments and assessment" competitor findings within this
lane's scope (findings 6.11, 6.3, 6.4, 6.6, 5.7 — 5.14 adaptive-practice integration is out of scope for this
lane's brief): a data-model landing spot for a plagiarism-check result on `Submission`; a QTI export action
button on `ItemBankDetail` calling the already-built `QtiExportController`; an `ExamAccommodation` widget on
`AssessmentDetail`; a `method-test` source kind + fields on `GradeEntry`; and a documented finding that LTI
1.3 grade pass-back (finding 5.7) is **already fully built** as of this branch's base — no new work needed,
evidenced below.

## Motivation

`change-plan.md`'s `assessment-completeness` row and `findings.md` rows 6.11/6.3/6.4/6.6/5.7 all describe a
consuming interface or manifest page that already exists but stops short of being usable end to end:

- **6.11 (plagiarism)**: `lib/Plagiarism/ProvidesPlagiarismCheck.php` is a fully-specified interface and
  `Assignment.plagiarismProvider` names a provider slug, but nothing on `Submission` records the result a
  provider would return (`getSimilarityScore()` → `float|null`) — "no provider, no caller" per the finding,
  and this change closes the **data-model** half (a provider implementation is explicitly out of scope; the
  interface's own docblock says "No built-in provider is bundled").
- **6.3 (QTI export)**: `lib/Controller/QtiExportController.php`, `QtiExportService`, the `/api/assessment/
  qti-export` route, and the `qti.export` action-authorization entry (`lib/actions.seed.json`) are all built
  and unit-tested (`QtiExportServiceTest`) — only a UI action to trigger the download is missing.
  `ImportQtiModal`'s dead `CnWizardDialog` registration (the other half of 6.3) is explicitly out of scope —
  the brief names it as another lane's registry fix.
- **6.4 (accommodation widget)**: `ExamAccommodation` is a fully-built, lifecycle-managed schema with its own
  `ExamAccommodationDetail` page, but nothing on `AssessmentDetail` surfaces a learner's approved
  accommodations for the assessment being viewed.
- **6.6 (method test)**: `GradeEntry.sourceKind` is an enum of origins (`assignment-submission`,
  `assessment-result`, `participation`, `manual`, `exemption`, `lti-ags`, `portfolio`) with no `method-test`
  value and no way to record which methode/block a mark came from (findings: "no method-test model").
- **5.7 (LTI grade pass-back)**: **verified already built** on this branch's base —
  `LtiToolPlacement.curriculumPlanId`/`gradeEntryComponentId`/`gradeScaleId` declare the AGS-to-gradebook
  mapping, `lib/Service/LtiAgsPullClient.php` + `lib/BackgroundJob/LtiAgsScorePollJob.php` (with
  `LtiAgsScorePollJobTest.php`) implement the actual AGS score pull, and
  `openspec/specs/course-management/spec.md:96-112` already documents the requirement and scenario. This
  postdates `findings.md`'s "partial" rating — a parallel-fix check (`git log`) shows no open PR duplicating
  this; it is simply stale corpus data, not a gap to close.

## Affected Projects

- [x] Project: `learniq` — `Submission`, `GradeEntry` schema additions; `ItemBankDetail`, `AssessmentDetail`
  manifest widget additions.

## Scope

### In Scope

- `Submission`: `plagiarismStatus` (enum: `not-requested`/`pending`/`completed`, default `not-requested`),
  `plagiarismScore` (nullable number 0.0–1.0), `plagiarismCheckedAt` (nullable date-time) — the landing spot
  `ProvidesPlagiarismCheck::getSimilarityScore()` needs once a provider is wired (a future, separate change).
- `ItemBankDetail`: a `navigate` header action targeting the existing `/api/assessment/
  qti-export?itemBankId=@objectId` route (`api-call`'s `method` enum is `POST`/`PUT` only — this route is
  `GET` — so `navigate` is the schema-valid fit; see design.md Decision 2).
- `AssessmentDetail`: a new `object-list` widget listing `ExamAccommodation` rows filtered by
  `assessmentId: "@objectId"`.
- `GradeEntry`: add `method-test` to the `sourceKind` enum, plus nullable `methodName` (e.g. "Wereld in
  Getallen", "Snappet") and `methodBlock` (e.g. "blok 3") properties (finding 6.6's "per subject per block").
- Documentation: this proposal records finding 5.7 as closed on the current base, with file/line evidence, so
  it is not re-attempted by a future sweep.

### Out of Scope

- A concrete plagiarism provider implementation (Turnitin/Ouriginal adapter) — `ProvidesPlagiarismCheck`'s
  own docblock says none ships with learniq; this is deployment-specific integration work.
- `ImportQtiModal`'s dead `CnWizardDialog` registration — named in the brief as another lane's registry fix.
- Proctoring (`lib/Proctoring/ProvidesProctoring.php` has no implementation) — not named in this lane's brief
  for this change (only "an accommodation widget", not proctoring itself).
- Adaptive-practice tool integration (Snappet/Gynzy/Prowise, finding 5.14) — in `change-plan.md`'s row but
  not in this lane's brief for this change.
- Any change to `LtiToolPlacement`/`LtiAgsPullClient`/`LtiAgsScorePollJob` — verified already complete.

## Approach

All changes are declarative schema/manifest edits, plus one manifest-level `navigate` action referencing an
already-built, already-authorized route. No PHP is added or modified.

## Capabilities

### Modified Capabilities

- `assessment` — `Submission` gains plagiarism-result fields; `GradeEntry` gains a `method-test` source kind;
  `AssessmentDetail` surfaces `ExamAccommodation`; `ItemBankDetail` gains a QTI export action.

## New Dependencies

None.

## Impact

- `lib/Settings/learniq_register.json` — `Submission` (+3 properties), `GradeEntry` (+1 enum value, +2
  properties).
- `src/manifest.d/learning.json` — `ItemBankDetail` (+1 header action), `AssessmentDetail` (+1 widget +1
  layout entry).

## Cross-Project Dependencies

None.

## Risks

### Risk 1: `plagiarismScore`/`plagiarismStatus` have no caller yet — a schema-only change with no wiring could look like a dead field

**Severity:** Low — **Mitigation:** documented explicitly in this proposal and in the field descriptions
themselves as "the landing spot for a future provider", matching the interface's own "hook only, no built-in
provider" framing; this mirrors how `Assignment.plagiarismProvider` itself already ships unwired.

### Risk 2: A `navigate` action opening the export URL in the SAME browser tab could, on some browsers, navigate away from the SPA instead of triggering a download

**Severity:** Low — **Mitigation:** `QtiExportController::export()` returns a `DataDownloadResponse`, which
sets `Content-Disposition: attachment`; browsers handle that header by downloading rather than navigating
away, but this was not exercised against a live instance in this change (see Open Questions). If it turns
out to navigate away on a particular browser, the fix is scoped to this one action entry.

## Rollback Strategy

Every change is additive (new nullable/defaulted properties, a new enum value, new manifest widgets/actions).
Revert the commit(s); no data migration exists to unwind.

## Open Questions

- A live browser pass confirming the QTI export button actually downloads a file, and that the
  `ExamAccommodation` widget renders correctly on `AssessmentDetail`, was not performed this pass (no local
  Nextcloud instance exercised — shared box under load across parallel lanes). The manifest-shape
  verification (merged-manifest Ajv validation) is the code-level substitute used instead.
