---
slug: attendance
title: Attendance & Threshold Reporting
status: done
feature_tier: must
depends_on_adrs: [ADR-008, ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [leerplicht-16uur, college-aanwezigheid, training-attendance, compliance-presence]
replaces: [absence-leerplicht]
---

# Attendance & Threshold Reporting

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, declarative calculation triggers, and notification config — no `#### Scenario:` headings exist in this spec.

## Purpose

Institutions record who was present, and some are obliged to act when absence crosses a threshold. The Dutch **Leerplichtwet** art. 21a obliges every school to report `ongeoorloofd verzuim` of 16 lesuren in 4 weeks to the `leerplichtambtenaar`; HE programmes track `college-aanwezigheid` for attendance-based credit; corporate compliance training needs presence proof for an audit; some certifications require N hours of attended instruction. The structure is the same: an `AttendanceRecord` per (Session, learner) with a status, and an `AttendanceThreshold` rule that watches a learner's records over a window and fires a trigger when crossed. This generalises the Dutch `absence-leerplicht` stub: the 16-uur leerplicht rule is one `AttendanceThreshold` profile; the *report to the leerplichtambtenaar* is a `data-exchange` adapter (Digikoppeling), not part of this spec.

## What

- **AttendanceRecord** — per Session per learner: `status` (`present` | `absent-unexcused` | `absent-excused` | `late` | `left-early`), `minutesAttended`, `markedBy`, `markedAt`, optional `reason`/`excuseRef`. Bulk-markable from a Session roster.
- **ExcuseRequest** — a learner (or parent, or 18+ learner self) submits an absence excuse for a date range, with a reason and optional attachment; a coordinator approves/rejects; an approved one flips matching `AttendanceRecord`s to `absent-excused`. The submission may go through an external authenticated flow (Dutch: DigiD sick-reporting) — the auth strength is configurable.
- **AttendanceThreshold** — a rule: `scope` (per learner / per cohort), `window` (rolling N weeks / a fixed term), `metric` (unexcused lesuren / unexcused sessions / attendance-%), `limit`, and an `onCross` action (notify mentor + coordinator; create a flag; trigger a `data-exchange` job to a `target`). Reuses the same threshold/`calculatedChange` machinery as `Regulation` coverage thresholds in the compliance wedge.
- **AttendanceFlag** — created when a threshold crosses: the learner, the rule, the window, the breaching records, and a workflow (`open → in-handling → reported → resolved`) — so a mentor's intervention and the leerplicht report are tracked. Every version is kept by the audit trail per ADR-008.
- A mentor dashboard widget: which learners in my cohort are trending toward a threshold.

## User Stories

- As a teacher, I want to mark a Session's attendance for the whole cohort in one screen, including late and left-early.
- As a parent (or an 18+ learner for themselves), I want to submit a sick report for a date range via the authenticated flow, and have it flip those days to excused once a coordinator approves.
- As an attendance coordinator, I want a threshold rule that watches for 16 unexcused lesuren in any rolling 4-week window and flags the learner when it's crossed.
- As a mentor, I want a dashboard of learners in my cohort approaching a threshold so I can intervene before it triggers.
- As a coordinator, I want a crossed threshold to produce a flag with a workflow, and (where the rule says so) to kick off the report to the leerplichtambtenaar — with the report attempt recorded for audit.

## Acceptance Criteria

- GIVEN a Session, WHEN a teacher bulk-marks the roster, THEN one `AttendanceRecord` per cohort member is created/updated with the chosen status and `minutesAttended`.
- GIVEN an approved `ExcuseRequest` covering a date range, WHEN it's approved, THEN matching `AttendanceRecord`s flip to `absent-excused`; the affected threshold metrics recompute.
- GIVEN an `AttendanceThreshold` of 16 unexcused lesuren in a rolling 4 weeks, WHEN a learner's count reaches 16 in any such window, THEN an `AttendanceFlag` is created (`open`) and the `onCross` notification fires to the mentor + coordinator (idempotency-keyed — re-crossing the same window doesn't re-flag).
- GIVEN a threshold rule with `onCross` including a `data-exchange` target, WHEN the flag is created, THEN a `DataExchangeJob` (see `data-exchange`) is queued to that target; the flag moves `open → reported` only after the job succeeds, and the attempt is in the audit trail.
- GIVEN a mentor opens the cohort attendance widget, THEN learners are shown with their current count against each applicable threshold, sorted by proximity to the limit.

## Requirements

### Requirement: Persist Attendance domain objects in OpenRegister

The system MUST persist `AttendanceRecord`, `ExcuseRequest`, `AttendanceThreshold`, `AttendanceFlag` as
OpenRegister objects with `x-openregister-lifecycle` (ExcuseRequest: submitted → approved | rejected;
AttendanceFlag: open → in-handling → reported → resolved), `x-openregister-relations` (AttendanceRecord↔
Session/learner, Flag↔learner/threshold), `x-openregister-calculations` (per-learner rolling counts vs
each threshold), and `x-openregister-notifications` (`onCross` mentor/coordinator alert,
idempotency-keyed). `AttendanceFlag` MUST NOT be `appendOnly` (Open Register refuses every update on an append-only schema,
transitions included; the audit trail keeps each version per ADR-008). `AttendanceFlag` MUST
additionally persist an `interventions` list — each entry timestamped, attributed to the acting
mentor/coordinator (Nextcloud user ID), and carrying a free-text note — recording the school's handling
history (contact attempts, agreements reached, escalations) while the flag is `open`/`in-handling`.
Appending an intervention MUST NOT bypass the audit trail: each addition is a new, audited version
of the flag (ADR-008), never an in-place edit of a prior entry.

#### Scenario: Attendance objects persist in OpenRegister

- **GIVEN** the attendance schemas are registered in OpenRegister
- **WHEN** an `AttendanceRecord`, `ExcuseRequest`, `AttendanceThreshold`, or `AttendanceFlag` is created
- **THEN** it is stored as an OpenRegister object with its lifecycle, relations, calculations, and
  notifications metadata, and `AttendanceFlag` is not `appendOnly`, its versions kept by the audit trail (ADR-008)

#### Scenario: A mentor's intervention is recorded on the flag

- **GIVEN** an `AttendanceFlag` in `in-handling`
- **WHEN** a mentor records a contact attempt with the learner as an intervention note
- **THEN** the note is appended to the flag's `interventions` list with its author and timestamp, as a new
  audited version of the flag

### Requirement: Threshold crossing is a declared calculation trigger

The threshold-crossing detection MUST be a declared calculation +
`calculatedChange` trigger — NOT a PHP TimedJob. It MUST reuse the same
threshold machinery as compliance-`Regulation` coverage thresholds (no
parallel mechanism — ADR-022).

`AttendanceThreshold.x-openregister-calculations.unexcusedLesuren` is a
materialised, `x-openregister-aggregations`-backed per-cohort aggregate
(count of `attendance-record` rows where `cohortId == @self.cohortId` and
`status == 'absent-unexcused'`, scaled by `lessonHourMinutes`), and
`isThresholdCrossed` is `unexcusedLesuren >= limit` — the same
aggregate-then-compare shape `Regulation.coveragePercent`/`ragStatus` already
use. The existing `thresholdCrossed` notification's `calculatedChange`
trigger on `unexcusedLesuren` now fires for real.

A true per-INDIVIDUAL-learner (as opposed to per-cohort) continuously-live
crossing figure is NOT achievable by this mechanism alone —
`AttendanceThreshold` has no `learnerId` field to parameterise a per-learner
aggregate against, and this is a named, open platform gap (see
`openspec/changes/archive/2026-09-29-attendance-threshold-calculation/proposal.md`), not a
silent limitation.

#### Scenario: Threshold crossing fires via declared calculation trigger

- **GIVEN** an `AttendanceThreshold` rule expressed as a declared calculation
- **WHEN** a cohort's aggregated unexcused-lesuren count crosses the limit
- **THEN** detection fires through a `calculatedChange` trigger (not a PHP TimedJob), reusing the same
  threshold machinery as compliance-`Regulation` coverage thresholds (ADR-022)

#### Scenario: A guarded manual check records a real per-learner crossing and creates an AttendanceFlag

- **GIVEN** an `AttendanceThreshold` and a specific learner's externally-computed unexcused-lesuren value
  that meets or exceeds the threshold's `limit`
- **WHEN** the `check-threshold` transition is invoked with that learner id and metric value as inputs
- **THEN** `AttendanceThresholdCrossingGuard` allows the transition, `ObjectTransitionedEvent` fires with
  `action: check-threshold`, and `AttendanceFlagCreationHandler` creates an `AttendanceFlag` for that
  learner

#### Scenario: A guarded manual check below the limit is refused

- **GIVEN** an `AttendanceThreshold` and a learner's metric value below the threshold's `limit`
- **WHEN** the `check-threshold` transition is invoked with that value
- **THEN** `AttendanceThresholdCrossingGuard` refuses the transition and no `AttendanceFlag` is created

### Requirement: Sick-reporting auth strength is declarative config
The external authenticated sick-reporting flow's auth strength MUST be declarative config; the DigiD handshake itself is a `data-exchange`/openconnector concern.

#### Scenario: Sick-reporting auth strength is declarative config
- **GIVEN** an external authenticated sick-reporting flow
- **WHEN** its required auth strength is set
- **THEN** the auth strength is declarative config while the DigiD handshake remains a `data-exchange`/openconnector concern

### Requirement: External authority reporting via DataExchangeJob

The report to an external authority (leerplichtambtenaar via Digikoppeling, etc.) MUST be a
`DataExchangeJob` (see `data-exchange`), not implemented inline here. When the target is `leerplicht` (the
verzuimloket melding), the composed report MUST draw on the originating `AttendanceFlag`'s
`breachingRecordIds` (the underlying `AttendanceRecord`s) and its `interventions` history, not only the
flag's summary metrics — mirroring `data-exchange`'s OSO dossier-composer pattern of assembling a dossier
from multiple linked objects rather than a flat field-mapped export. `AttendanceFlag` MUST additionally
carry a declared statutory reporting-deadline calculation: 5 school days after the threshold crossing,
where a school day is a calendar day on which the tenant has at least one scheduled `Session` (reusing
`Session` occurrence as the school-day source of truth — vacation weeks and closure days already carry no
`Session`s and are excluded without a separate vacation calendar). Reaching or passing the deadline while
the flag has not reached `reported` MUST raise a distinct, idempotency-keyed overdue signal to the mentor
and coordinator, additive to the existing `onCross` alert. This deadline calculation is informational and
MUST NOT alter the existing `in-handling → reported` transition guard.

#### Scenario: External authority report runs as a DataExchangeJob

- **GIVEN** a crossed threshold whose `onCross` includes an external-authority target
- **WHEN** the report to the authority (e.g. leerplichtambtenaar via Digikoppeling) is dispatched
- **THEN** it runs as a `DataExchangeJob` (see `data-exchange`) and is not implemented inline in this spec

#### Scenario: Verzuimloket report composes from records and intervention history

- **GIVEN** an `AttendanceFlag` with populated `breachingRecordIds` and two recorded `interventions`
- **WHEN** its `leerplicht`-target `DataExchangeJob` composes the verzuimloket dossier
- **THEN** the composed dossier includes the breaching `AttendanceRecord`s and the intervention history,
  not just the flag's summary metrics

#### Scenario: Deadline countdown skips a vacation week

- **GIVEN** an `AttendanceFlag` raised on the last school day before a one-week vacation with no scheduled
  `Session`s
- **WHEN** the 5-school-day reporting deadline is calculated
- **THEN** the vacation week contributes zero school days to the countdown, and the deadline lands on the
  5th day on which the tenant has a scheduled `Session` after the crossing

#### Scenario: Unreported flag past deadline raises an overdue signal

- **GIVEN** an `AttendanceFlag` that has not reached `reported` by its calculated `reportDeadlineAt`
- **WHEN** the deadline calculation flips to overdue
- **THEN** a distinct, idempotency-keyed overdue notification reaches the mentor and coordinator, in
  addition to the original `onCross` alert, and the flag's existing report-transition guard is unaffected

### Requirement: Frontend is declarative with named custom views
The frontend MUST be declarative: `src/manifest.json` pages for AttendanceRecord/ExcuseRequest/AttendanceThreshold/AttendanceFlag index+detail; a custom `MarkAttendanceView` (the Session roster grid — genuine UI), `SubmitExcuseModal`, and a `cohort-attendance` dashboard widget. No PHP CRUD controllers.

#### Scenario: Frontend is declarative with named custom views
- **GIVEN** the attendance app frontend
- **WHEN** the UI is composed
- **THEN** AttendanceRecord/ExcuseRequest/AttendanceThreshold/AttendanceFlag index+detail are declarative `src/manifest.json` pages, the only custom views are `MarkAttendanceView`, `SubmitExcuseModal`, and the `cohort-attendance` dashboard widget, and there are no PHP CRUD controllers

### Requirement: AttendanceFlag classifies its statutory flagKind

`AttendanceFlag` MUST carry a `flagKind` enum property (`signal-verzuim`, `langdurig-relatief-verzuim`,
`thuiszitter`; default `signal-verzuim`) classifying which statutory concern the flag represents (finding
4.9). SWV notification reuses the existing `dataExchangeJobId` field — no new field is needed for that half
of the finding.

#### Scenario: A langdurig-relatief-verzuim flag is classified distinctly from a routine signal

- **GIVEN** an `AttendanceThreshold` crossing that represents a langdurig relatief verzuim case
- **WHEN** the resulting `AttendanceFlag` is created with `flagKind: "langdurig-relatief-verzuim"`
- **THEN** it is distinguishable from a routine `signal-verzuim` flag by that field alone

#### Scenario: An existing AttendanceFlag without a declared flagKind defaults to signal-verzuim

- **GIVEN** an `AttendanceFlag` row created before this change, with no `flagKind` value stored
- **WHEN** the row is read
- **THEN** `flagKind` resolves to its default, `"signal-verzuim"`

### Requirement: A crossed threshold notifies each recipient group once

The `thresholdCrossed` notification of `AttendanceThreshold` MUST name the mentor and the coordinator in one recipient entry, and no group MAY appear in more than one of its entries, so nobody receives the same notification twice.

#### Scenario: The mentor is notified once
@e2e exclude Register-content invariant; pinned by tests/Unit/Settings/AttendanceThresholdRegisterTest.php (testThresholdCrossedNotificationNotifiesMentorAndCoordinator).
- **GIVEN** an `AttendanceThreshold` whose unexcused hours rise to its limit
- **WHEN** the `thresholdCrossed` notification fires
- **THEN** its recipients are one entry naming `mentor` and `coordinator`
- **AND** no group is named in a second entry

### Requirement: An attendance flag outside the leerplicht carries a neutral kind

`AttendanceFlag.flagKind` MUST offer `attendance-requirement` next to the school concerns (`signal-verzuim`, `langdurig-relatief-verzuim`, `thuiszitter`), for a learner who falls below an attendance requirement of a course, programme or training that is not a statutory school concern. The flag created for a threshold crossing MUST take its kind from the threshold: `leerplicht-16uur` gives `signal-verzuim`; `college-aanwezigheid`, `training-attendance` and `compliance-presence` give `attendance-requirement`. A `generic` threshold MUST keep the schema default, so existing school installs see no change.

#### Scenario: A university workgroup requirement is not a leerplicht signal

- **GIVEN** an active AttendanceThreshold with `kind: college-aanwezigheid`
- **WHEN** a guarded `check-threshold` records a crossing for a student
- **THEN** the created AttendanceFlag has `flagKind: attendance-requirement`

#### Scenario: A company compliance presence requirement is neutral

- **GIVEN** an active AttendanceThreshold with `kind: compliance-presence`
- **WHEN** a crossing is recorded for an employee
- **THEN** the created AttendanceFlag has `flagKind: attendance-requirement`

#### Scenario: The leerplicht profile still raises a verzuim signal

- **GIVEN** an active AttendanceThreshold with `kind: leerplicht-16uur`
- **WHEN** a crossing is recorded for a pupil
- **THEN** the created AttendanceFlag has `flagKind: signal-verzuim`

### Requirement: The municipality's feedback on a leerplicht report is recorded on the attendance flag
A coordinator or administrator MUST record the municipality's case route (MAS route) on the reported `AttendanceFlag` through a `recordMunicipalityFeedback` transition that keeps the flag `reported`. The actor and the time MUST be stamped server-side. The report itself is sent by integriq; the flag moves to `reported` only once integriq concluded its job `succeeded`.

#### Scenario: a coordinator records the MAS route
- GIVEN an attendance flag in `reported`
- WHEN a coordinator records the municipality's feedback with MAS route "casusoverleg"
- THEN the flag stays `reported` and `municipalityFeedback.recordedBy` is that coordinator

#### Scenario: the report is not sent yet
- GIVEN an attendance flag in `in-handling` whose integriq job is `queued`
- WHEN someone tries to move it to `reported`
- THEN the transition is refused until integriq concluded the job `succeeded`

### Requirement: The server stamps who an excuse request is about and who filed it

`ExcuseRequest.required` MUST list only what every caller sends: `dateFrom`, `dateTo`, `reason` and `reasonKind`. OpenRegister validates `required` before any listener runs, so the owner fields MUST be filled and enforced by a pre-write listener instead. For a portal report (no Nextcloud session, no `learnerId`, a `learnerRef`) the server MUST take `learnerId`, `learnerRef` and `tenant_id` from the pupil's LearnerProfile, and MUST refuse the report when that profile is unknown or cannot be read. A pupil's own report MUST name the pupil as submitter and record `submittedAuthLevel` `basic`. A guardian's report (`submittedByRef` set) MUST be refused unless the pupil's profile lists that guardian in `guardianRefs`; it MUST record the guardian's user id in `submittedBy` when the guardian has one and `submittedAuthLevel` `substantial`. Every other write MUST have its `learnerRef` derived from `learnerId`, ignoring a client value, and MUST get `submittedAuthLevel` `basic` when it sends none. Every write that still lacks `learnerId`, a submitter (`submittedBy` or `submittedByRef`) or `tenant_id` MUST be refused.

#### Scenario: A pupil reports an absence through the portal
@e2e exclude Pre-write listener with no screen of its own in learniq; pinned by tests/Unit/Listener/ExcuseRequestOwnerStampTest.php (testAPupilReportIsStampedFromTheProfile).
- **GIVEN** pupil `pupil-1` with LearnerProfile `lp-1`
- **WHEN** portaliq creates an ExcuseRequest with the dates, reason and kind, and `learnerRef: "lp-1"`
- **THEN** it is stored with `learnerId: "pupil-1"`, `submittedBy: "pupil-1"`, the pupil's school and `submittedAuthLevel: "basic"`

#### Scenario: A guardian reports an absence for their child
@e2e exclude Pre-write listener; pinned by tests/Unit/Listener/ExcuseRequestOwnerStampTest.php (testAGuardianReportNamesTheChildAndTheGuardian).
- **GIVEN** the pupil's profile lists guardian `gp-1`, whose user is `ouder-1`
- **WHEN** portaliq creates an ExcuseRequest with `learnerRef: "lp-1"` and `submittedByRef: "gp-1"`
- **THEN** it is stored with `learnerId: "pupil-1"`, `submittedBy: "ouder-1"` and `submittedAuthLevel: "substantial"`

#### Scenario: A guardian cannot report for somebody else's child
@e2e exclude Pre-write listener; pinned by tests/Unit/Listener/ExcuseRequestOwnerStampTest.php (testAGuardianOfAnotherChildIsRefused).
- **GIVEN** the pupil's profile does not list guardian `gp-9`
- **WHEN** portaliq creates an ExcuseRequest with `learnerRef: "lp-1"` and `submittedByRef: "gp-9"`
- **THEN** the write is refused with reason `excuse-guardian-unknown`

#### Scenario: Staff are still held to the owner fields
@e2e exclude Pre-write listener; pinned by tests/Unit/Listener/ExcuseRequestOwnerStampTest.php (testAStaffCreateWithoutItsOwnerFieldsIsRefused).
- **GIVEN** a signed-in mentor
- **WHEN** they create an ExcuseRequest without `learnerId`, without a submitter or without `tenant_id`
- **THEN** the write is refused with reason `excuse-owner-missing`

## Standards

Schema.org `Event` / `Schedule` for sessions; NL Leerplichtwet art. 21a (the 16-uur rule as an `AttendanceThreshold` profile); Digikoppeling / StUF for the leerplicht report (a `data-exchange` adapter); eIDAS / DigiD assurance for authenticated sick-reporting.

## Data Model

All in OpenRegister. New: `AttendanceRecord`, `ExcuseRequest`, `AttendanceThreshold`, `AttendanceFlag`. Consumes: `Session`, `Cohort` (`school-structure`), the `Regulation`/threshold/`calculatedChange` machinery (compliance wedge), `DataExchangeJob` (`data-exchange`). No PHP service classes — fully declarative. See `docs/ARCHITECTURE.md`.

## Out of Scope

- The wire protocol of the leerplicht report (Digikoppeling/StUF) — a `data-exchange` / openconnector adapter.
- The DigiD authentication handshake — openconnector / NC auth.
- Geofenced / NFC / biometric attendance capture (a follow-up if a buyer needs it).
- Truancy-pattern prediction (would be an `AiFeature` registration).
