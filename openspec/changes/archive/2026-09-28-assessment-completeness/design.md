# Design: assessment-completeness

## Context

Five findings in the "Assignments and assessment" competitor comparison (6.11, 6.3, 6.4, 6.6, 5.7) each name
a consuming interface, controller, or schema that already exists in the codebase but stops short of being
reachable/usable. This change closes the small remaining gap in four of them and documents that the fifth
(5.7, LTI grade pass-back) is already closed on this branch's base.

## Goals / Non-Goals

**Goals:** give `ProvidesPlagiarismCheck` a result to write to; make the already-built QTI export reachable
from the UI; surface `ExamAccommodation` on the assessment it applies to; let a `GradeEntry` record a method
test's subject/block.

**Non-Goals:** building a plagiarism provider, fixing `ImportQtiModal`, building proctoring, adaptive-practice
integration, or touching the already-complete LTI AGS pull pipeline.

## Decisions

### Decision 1: `Submission` gets the plagiarism fields, not `Assignment`

**Alternatives considered:** adding `plagiarismScore` to `Assignment`. Rejected: a plagiarism check runs
per-submission (one Assignment has many Submissions, each independently checked); `Assignment` already only
carries the provider *configuration* (`plagiarismProvider`), and the *result* belongs on the specific
`Submission` it was run against — mirroring how `Submission.proposedGrade` (a per-submission outcome) sits
beside `Assignment.maxPoints` (a per-assignment configuration).

### Decision 2: The QTI export button is a manifest `navigate` action, not `api-call` or a new frontend component

**Alternatives considered:** (a) a bespoke Vue button component calling `axios.get(...)` and triggering a
`Blob` download manually — rejected as unnecessary new frontend code for a `kind: config` change. (b) the
`api-call` action type with `download: true` — this was the first choice (its own description reads "request
the response as a binary blob... and trigger a browser file download"), but `app-manifest-v2.schema.json`
restricts `api-call.method` to `["POST", "PUT"]` only, and `QtiExportController::export()` is a `GET` route
(`appinfo/routes.php:61`) — confirmed by running the merged manifest through Ajv, which rejected `method:
"GET"` outright. **Chosen:** `type: "navigate"` with `target` set to the export URL — a plain browser
navigation to a `GET` endpoint that returns a `DataDownloadResponse` (Content-Disposition: attachment),
which the browser handles as a file download without a manifest action type built specifically for GET
downloads existing in this dialect.

### Decision 3: `ExamAccommodation` is surfaced via a plain `object-list` widget, not a new integration

Every other "show related records of schema X on detail page Y" case in this codebase (e.g.
`lprof-enrol`/`lprof-plans` on `LearnerProfileDetail`) uses `type: "object-list"` with a `filter`. There is no
reason for `AssessmentDetail`'s `ExamAccommodation` list to be different — no new widget type, no new
component, just the existing pattern with `filter: { assessmentId: "@objectId" }`.

### Decision 4: `method-test` is a `GradeEntry.sourceKind` value with two new inline properties, not a new `MethodTest` schema

**Alternatives considered:** a standalone `MethodTest` schema (mirroring `FirstAidIncident`'s pattern in the
sibling `learner-record-extras` change). Rejected here: unlike a first-aid incident (a genuinely distinct
entity with its own lifecycle and follow-up log), a method test result IS a grade — it already fits
`GradeEntry`'s existing shape (`learnerId`, `curriculumPlanId`, `componentId`, a value) exactly the way
`assessment-result`/`participation`/`portfolio` source kinds already do, each via `sourceKind` plus an
origin-specific field or two. Adding a schema would duplicate `GradeEntry`'s value/learner/component
plumbing for no benefit; two nullable properties (`methodName`, `methodBlock`) populated only when
`sourceKind === 'method-test'` follow the established `sourceKind`-discriminated-origin pattern exactly
(same shape as `submissionId`/`assessmentResultId`/`sessionId`, each null unless their own `sourceKind`
applies).

### Decision 5: Finding 5.7 (LTI grade pass-back) is documented as closed, not re-implemented

Verified on this branch's base (`git log --oneline -- lib/Settings/learniq_register.json` shows
`gradeEntryComponentId` on `LtiToolPlacement` predates this round's corpus research by several commits):
`LtiAgsPullClient.php` + `LtiAgsScorePollJob.php` (tested by `LtiAgsScorePollJobTest.php`) implement the full
AGS score pull, and `openspec/specs/course-management/spec.md` already documents the requirement. Building a
second implementation would violate "check for a parallel fix before building one" — this is corpus staleness
(findings.md's "partial" rating predates the current base), not a real gap.

## Declarative-vs-imperative decision (ADR-031)

No lifecycle, aggregation, calculation, notification, relation, or dashboard-widget behaviour is introduced
by this change — every addition is a plain schema property, enum value, or manifest widget/action. No
declarative-vs-imperative call is needed.

## Seed Data

No seed changes needed: `plagiarismStatus` defaults to `not-requested` (no existing `Submission` row needs
updating to stay valid), `plagiarismScore`/`plagiarismCheckedAt` are nullable, `GradeEntry.methodName`/
`methodBlock` are nullable and only populated for new `sourceKind: 'method-test'` rows going forward.

## Risks / Trade-offs

- [Risk] The QTI export action's exact URL-token-interpolation behaviour for a query-string parameter was not
  exercised live (see proposal Risk 2). → Mitigation: merged-manifest Ajv validation confirms the action's
  shape is schema-valid; if the query-string interpolation turns out not to work as declared, the fix is a
  one-line change to the action's `url`/`params` field, isolated from every other change in this PR.

## Migration Plan

Not applicable — declarative schema/manifest edits only; `migration.md` is skipped per its own `skipWhen`
condition (no DB/backend impact beyond OpenRegister schema properties).

## Open Questions

- Live browser verification of the QTI export button and the AssessmentDetail accommodation widget —
  deferred, see proposal.
