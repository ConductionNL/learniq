# timetabling Specification

## Purpose
TBD - created by archiving change timetabling-and-substitution. Update Purpose after archive.

## Requirements

### Requirement: Timetable import delegates the wire protocol to OpenConnector via DataExchangeJob

The system MUST accept a generated timetable from an external optimiser (Zermelo, Untis, Xedule, or
equivalent) by delegating the wire protocol to OpenConnector, reusing the existing `DataExchangeJob`
mechanism (`direction: import`, `target: timetable-import`) rather than implementing a parallel job schema
or any Edukoppeling/proprietary-API wire code itself. The job's payload MUST be resolved through a
`DataMappingProfile` scoped to `target: timetable-import` declaring how the source system's fields map to
`Session`/`Room`/`Cohort`/`Course` fields, following the same validate-before-dequeue posture
`data-exchange` already requires for its other targets. Scholiq MUST NOT implement the Zermelo/Untis/Xedule
wire protocol itself; that MUST be a separate OpenConnector source configuration.

#### Scenario: A timetable-import job delegates to OpenConnector and reports its result

<!-- @e2e exclude Wire delegation to OpenConnector and DataExchangeJob lifecycle mechanics are backend-only, mirroring data-exchange's existing pure-backend posture; no scholiq DOM surface drives the OpenConnector send itself. -->

- **GIVEN** a `DataExchangeJob` with `direction: import` and `target: timetable-import`, scoped to a period
  and an academic year
- **WHEN** the job runs
- **THEN** Scholiq hands the payload to the OpenConnector `timetable-import` source configuration and
  implements no wire protocol itself
- **AND** the job's `DataMappingProfile` validates the incoming records before any `Session` is created or
  updated
- **AND** the job's lifecycle becomes `succeeded`, or `partial` with a per-record validation report, exactly
  as `data-exchange`'s other targets already behave

### Requirement: Timetable import upserts Session objects idempotently by externalRef

A `timetable-import` job MUST create or update `Session` objects, matched by the source system's own
occurrence identifier persisted on `Session.externalRef`. Re-running the same import (a re-sync, a retry
after a `partial` failure) MUST update the matching `Session` in place and MUST NOT create a duplicate.
A `Session` with no `externalRef` (created manually, not via import) MUST never be matched or overwritten by
an import.

#### Scenario: Re-importing the same timetable does not duplicate Sessions

<!-- @e2e exclude Idempotent upsert is backend PHPUnit-verified handler logic (TimetableImportHandler); no scholiq DOM surface for import mechanics. -->

- **GIVEN** a `timetable-import` `DataExchangeJob` has already created 40 `Session` objects, each carrying
  the source system's occurrence id as `externalRef`
- **WHEN** the same source timetable is imported again with one occurrence's time changed
- **THEN** the matching `Session` is updated in place (its `startsAt`/`endsAt` changed)
- **AND** the total `Session` count for that import scope remains 40, not 80

#### Scenario: A manually-created Session is never touched by an import

<!-- @e2e exclude Backend matching-key invariant; no scholiq DOM surface. -->

- **GIVEN** a `Session` created manually with no `externalRef`
- **WHEN** a `timetable-import` job runs for the same cohort/period
- **THEN** the manually-created `Session` is left unmodified and is not matched by any imported record

### Requirement: Conflict detection flags double-bookings and capacity overruns without resolving them

The system MUST detect, and MUST NOT automatically resolve, scheduling conflicts among `Session` objects
within an affected time window: a teacher (or, once substituted, the assigned `substituteTeacherId`)
double-booked across overlapping Sessions; the same `Room` double-booked; the same `Cohort` scheduled twice
at once; a learner (resolved via their cohorts' `learnerIds`) double-booked across Sessions in different
cohorts; a `Room`'s `capacity` exceeded by the candidate count of a Session linked to an `Assessment`
(`room-capacity-exceeded`); and any of the above overlap kinds where at least one Session is linked to an
`Assessment` (`exam-clash`). Detection MUST run as an OR-event-driven scan (on `Session` create/update, and
once in batch after a `timetable-import` `DataExchangeJob` reaches `succeeded`), scoped to the affected date
window — never a full-register scan. Each finding MUST be persisted as a `TimetableConflict` object; the
detector MUST NOT edit, cancel, or reassign any `Session` itself.

#### Scenario: Two Sessions imported for the same room at overlapping times are flagged, not auto-moved

<!-- @e2e exclude Cross-object conflict-scan logic is backend-only (TimetableConflictDetector), PHPUnit-verified; no DOM surface for the scan itself — the resulting queue view is covered by a separate scenario below. -->

- **GIVEN** two `Session` objects with the same `roomId` and overlapping `[startsAt, endsAt)` intervals
- **WHEN** the conflict detector scans the affected window
- **THEN** a `TimetableConflict` with `kind: room-double-booking` is created referencing both `sessionIds`
- **AND** neither `Session`'s `roomId`, `startsAt`, or `endsAt` is modified by the detector

#### Scenario: Re-scanning an unchanged window does not create duplicate conflicts

<!-- @e2e exclude Idempotent conflict-row matching is backend PHPUnit-verified logic; no DOM surface. -->

- **GIVEN** an `open` `TimetableConflict` already exists for a given `sessionIds` pair and `kind`
- **WHEN** the same window is re-scanned without the underlying Sessions changing
- **THEN** no second `TimetableConflict` row is created for that pair and kind

#### Scenario: An exam Session exceeding room capacity is flagged as room-capacity-exceeded

<!-- @e2e exclude Backend capacity comparison (Cohort.learnerIds length vs Room.capacity); PHPUnit-verified; no DOM surface for the comparison itself. -->

- **GIVEN** a `Session` linked to an `Assessment`, assigned a `Room` with `capacity: 30`, whose cohort has 34
  `learnerIds`
- **WHEN** the conflict detector scans the Session
- **THEN** a `TimetableConflict` with `kind: room-capacity-exceeded` is created referencing the Session and
  the Room

### Requirement: Detected conflicts are queued for coordinator review

Every created `TimetableConflict` MUST have a `lifecycle` of `open → acknowledged → resolved` and MUST
declare an `x-openregister-notifications` `created`-trigger rule delivering to the scheduling-coordinator
group (`kind: groups`), per the verified dialect (`scholiq-notifications`). `TimetableConflict` visibility
MUST be restricted to admin/coordinator/scheduling roles; it MUST NOT be surfaced to learners or parents —
they are notified separately, once a human has acted, via the substitution/cancellation notification below.

#### Scenario: A coordinator sees a newly-detected conflict in their review queue

- **GIVEN** a `TimetableConflict` is created with `lifecycle: open`
- **WHEN** a scheduling coordinator opens `TimetableConflictQueue`
- **THEN** the conflict appears with its `kind`, referenced Sessions, and severity
- **AND** the coordinator receives the declared `created` notification

#### Scenario: A learner cannot see the raw conflict queue

- **GIVEN** a `TimetableConflict` referencing a Session in a learner's own cohort
- **WHEN** that learner requests the conflict data
- **THEN** access is denied — `TimetableConflict` is scoped to admin/coordinator/scheduling roles only

### Requirement: Substitution and cancellation require a reason and are gated by SessionChangeGuard

The system MUST require, when marking a `Session` cancelled or assigning a substitute teacher via the
`substitute-teacher` transition, that the caller be a teacher of the Session's cohort or an admin/
coordinator, enforced by `SessionChangeGuard` resolving the caller's Nextcloud user id server-side (never a
client-supplied claim). Both transitions MUST require `changeReasonKind` to be set; `substitute-teacher`
MUST additionally require `substituteTeacherId` to be set. A transition attempted without the required
fields, or by a caller who is neither a cohort teacher nor an admin/coordinator, MUST be refused.

#### Scenario: A cohort teacher cancels a Session with a reason

- **GIVEN** a teacher listed in `Cohort.teacherIds` for a Session's cohort
- **WHEN** they cancel the Session with `changeReasonKind: teacher-absence`
- **THEN** the transition succeeds and the Session's `lifecycle` becomes `cancelled`

#### Scenario: Cancelling without a reason is refused

- **GIVEN** a cohort teacher attempts to cancel a Session without setting `changeReasonKind`
- **WHEN** the `cancel` transition is invoked
- **THEN** `SessionChangeGuard` refuses the transition and the Session remains in its prior lifecycle state

#### Scenario: A teacher outside the cohort cannot substitute or cancel

- **GIVEN** an authenticated teacher who is not listed in `Cohort.teacherIds` for a Session's cohort and is
  not an admin/coordinator
- **WHEN** they attempt the `cancel` or `substitute-teacher` transition
- **THEN** `SessionChangeGuard` refuses the transition

### Requirement: Cancellation or substitution notifies affected learners and parents

At the `cancel`, `substitute-teacher`, and `substitute-teacher-in-progress`
transitions, the system MUST materialise `affectedLearnerIds` (from the
Session's `Cohort.learnerIds`) and `affectedParentIds` (from each affected
learner's `LearnerProfile.parentIds`) onto the Session, then declare
`x-openregister-notifications` `transition`-triggered rules (`action: [cancel,
substitute-teacher, substitute-teacher-in-progress]`) with `recipients:
[{kind: field, field: affectedLearnerIds}, {kind: field, field:
affectedParentIds}]` and an inline `nl`/`en` subject. This change MUST
introduce no local quiet-hours or delivery-suppression logic; delivery timing
and per-user opt-out MUST be governed entirely by OpenRegister's existing
dispatcher and preference API, per `scholiq-notifications`'s existing
requirements.

<!-- Extends the action list from {cancel, substitute-teacher} to also include
     substitute-teacher-in-progress: SessionChangeNoticeHandler's own
     WATCHED_ACTIONS constant already reacts to all three (the in-progress
     counterpart of substitute-teacher, split into its own transition name per
     the Session schema's own transition docblock), and the declared
     notification must cover every action the handler materialises data for,
     or a substitution made mid-lesson would silently notify nobody even
     though affectedLearnerIds/affectedParentIds were correctly written. -->

#### Scenario: Cancelling a Session notifies every affected learner and parent

- **GIVEN** a Session belonging to a cohort with 28 learners, 19 of whom have a linked parent account
- **WHEN** the Session is cancelled with a reason
- **THEN** `affectedLearnerIds` contains all 28 learners and `affectedParentIds` contains the 19 linked
  parents
- **AND** each receives an `nc-notification` via the declared `transition` rule

#### Scenario: Assigning a substitute teacher mid-lesson also notifies

- **GIVEN** a Session that is already `in-progress`
- **WHEN** a substitute teacher is assigned via the `substitute-teacher-in-progress` transition
- **THEN** the same `rosterChanged` notification rule fires for the Session's affected learners and parents,
  exactly as it does for the `scheduled`-state `substitute-teacher` transition

#### Scenario: A learner who opted out of Session-change notifications receives nothing

<!-- @e2e exclude Preference-off delivery gate is OpenRegister dispatcher behaviour (per scholiq-notifications), not scholiq-local logic; no new DOM surface — reuses the existing settings panel already covered by scholiq-notifications' own scenario. -->

- **GIVEN** a learner who disabled Session-cancellation notifications via the existing per-user settings
  panel
- **WHEN** a Session in their cohort is cancelled
- **THEN** OpenRegister's dispatcher records a `preference-off` skip and that learner receives nothing,
  while other affected learners/parents are still notified

### Requirement: Exam accommodations are recorded as approved, evidence-backed entitlements

The system MUST persist `ExamAccommodation` objects (`learnerId`, optional `assessmentId` for a
per-assessment override, `accommodationKind` [`extra-time-percentage`|`separate-room`|`reader`|
`screen-reader-software`|`rest-breaks`|`other`], an optional `value`, an `evidenceRef` OpenRegister file
attachment, `approvedBy`, `lifecycle`: `requested → approved → active → expired | revoked`). Creating a
`requested` row MUST be available to the learner or their parent/guardian (via `parentIds`/`guardianRefs`,
matching the existing `ExcuseRequest`/`SupportRequest` portal-scoping pattern); the `approve` transition MUST
be restricted via `x-openregister-authorization` to `admin`/`compliance-officer`/`mentor` roles — a learner
MUST NOT be able to self-approve their own accommodation. `evidenceRef` MUST reference an OpenRegister file
attachment; this app MUST NOT store the evidence file's bytes itself.

#### Scenario: A learner requests an accommodation and a mentor approves it

- **GIVEN** a learner with a dyslexie-verklaring attachment
- **WHEN** they (or their parent) submit an `ExamAccommodation` request with `accommodationKind:
  extra-time-percentage`, `value: 25`, and the evidence attached
- **THEN** the request is created in `requested` state
- **AND** when an authorised mentor approves it, the lifecycle moves to `approved`

#### Scenario: A learner cannot self-approve their own accommodation

- **GIVEN** a learner who submitted their own `ExamAccommodation` request
- **WHEN** they attempt the `approve` transition themselves
- **THEN** the transition is refused — only `admin`/`compliance-officer`/`mentor` roles may approve

### Requirement: Frontend is declarative with named custom views

The frontend MUST be declarative: `src/manifest.json` index/detail pages for `Room`, `TimetableConflict`,
and `ExamAccommodation`; named custom Vue views `SubstitutionModal` (mark cancelled / assign substitute,
requiring a reason) and `TimetableConflictQueue` (the coordinator review surface). There MUST be no PHP CRUD
controllers; the only PHP is `SessionChangeGuard`, `SessionChangeNoticeHandler`,
`SessionConflictListener`/`TimetableConflictDetector`, and `TimetableImportHandler` — all ADR-031-scoped
exceptions (lifecycle guard, cross-object write bridge, external-system bridge).

#### Scenario: Render the timetabling surface declaratively with named views

- **GIVEN** the `timetabling` frontend
- **WHEN** it renders `Room`/`TimetableConflict`/`ExamAccommodation` index and detail surfaces
- **THEN** they are driven by `src/manifest.json`, with `SubstitutionModal` and `TimetableConflictQueue` as
  the only named custom views, and no PHP CRUD controller exists for any of these objects

### Requirement: Sessions has today- and week-scoped index views
The system MUST provide `SessionsToday` and `SessionsThisWeek` as manifest-declared `type: index` pages on the `Session` schema, each pre-scoped via `config.filter.startsAt` using the shared `@today`/`@today+Nd` filter-token grammar (`resolveFilterMap` → `resolveFilterTokens`, the same mechanism `LessonIndex`/`Submissions`/etc. already use with `@route.*` tokens).

#### Scenario: A teacher opens today's sessions
- **GIVEN** `Session` rows exist across multiple days
- **WHEN** `SessionsToday` is opened
- **THEN** only sessions whose `startsAt` falls within the current day are listed

#### Scenario: A teacher opens this week's sessions
- **GIVEN** `Session` rows exist across multiple weeks
- **WHEN** `SessionsThisWeek` is opened
- **THEN** only sessions whose `startsAt` falls within the next 7 days from today are listed

### Requirement: A per-teacher filter on TimetableConflictQueue is out of scope for a config change
`TimetableConflictQueue` is a registered custom Vue component (`src/views/TimetableConflictQueue.vue`), not a manifest-declarative page. A `config`-kind change MUST NOT modify it; adding a per-teacher filter control there requires a `code`-kind change per ADR-032 and is explicitly deferred.

#### Scenario: TimetableConflictQueue is unmodified by this change
- **GIVEN** this change's diff
- **WHEN** `src/views/TimetableConflictQueue.vue` is inspected
- **THEN** it is unchanged; the per-teacher filter is tracked as a follow-up `code` change

### Requirement: The timetable connection is available when planninq and integriq are installed
The timetable row in `lib/Settings/connections.json` MUST NOT be declared unavailable. It MUST be `reportedOnly`, and learniq MUST report its status to integriq: `configured` when planninq is enabled, `unavailable` with a reason naming planninq otherwise. The report MUST be recorded by the daily connection report job and by an admin settings save.

#### Scenario: Planninq is installed
- **GIVEN** planninq is enabled and integriq is installed
- **WHEN** the daily connection report runs
- **THEN** the timetable row is reported `configured`

#### Scenario: Planninq is missing
- **GIVEN** planninq is not enabled
- **WHEN** the daily connection report runs
- **THEN** the timetable row is reported `unavailable`, and the message says planninq is not installed

### Requirement: The timetable page offers the import to whoever may request an exchange
`GET /api/timetable/imports/access` MUST answer whether the signed-in user holds `exchange.request` and whether planninq takes a delivery. The timetable conflicts page MUST show an import button only to a user who holds the right, MUST enable it only where planninq takes the delivery, and MUST post the chosen rostering system to `POST /api/timetable/imports`.

#### Scenario: An administration manager opens the timetable conflicts
- **GIVEN** a user in `administration-managers` on an instance with planninq
- **WHEN** they open the timetable conflicts page
- **THEN** they see an enabled "Import a timetable" button

#### Scenario: A coordinator without the right
- **GIVEN** a coordinator the action matrix does not grant `exchange.request`
- **WHEN** they open the timetable conflicts page
- **THEN** no import button shows

### Requirement: An administrator keeps the group code maps and the SWV receiver on the admin page
The admin page MUST have a section that reads and writes, per rostering system (`roster-zermelo`, `roster-untis-oneroster`, `roster-xedule`, `roster-timeedit`), the map from group code to learniq cohort, and the `swv_receiver_id` the SWV hand-off sends. The endpoints MUST be admin settings. A receiver that is not a lowercase hyphenated name, or an unknown rostering system, MUST be refused with a reason.

#### Scenario: Saving a map for Zermelo
- **GIVEN** an administrator on the admin page
- **WHEN** they map Zermelo group `4H1` to a cohort and save
- **THEN** the map reads back for `roster-zermelo`, with empty rows dropped

### Requirement: An import without a posted map uses the kept map
A timetable import request without its own `groupMap` MUST send the kept map of its rostering system to integriq, and MUST scan that map's cohorts for conflicts. A request that posts a map MUST send the posted map.

#### Scenario: Importing from Zermelo without a map
- **GIVEN** the administrator keeps `4H1` to a cohort for Zermelo
- **WHEN** an import for `roster-zermelo` is requested without a map
- **THEN** integriq receives the kept map

### Requirement: A coordinator applies one change to several weeks

From a lesson's change dialog, a user who may change that lesson MUST be able to list the lessons of the same weekly slot (same group, course, weekday and start time) up to a chosen date, tick the ones to change, and apply one change (cancel, substitute teacher or other room) with one reason to all of them. The dialog MUST then show per lesson whether it was changed or refused, with the reason.

#### Scenario: A coordinator cancels three weeks of a lesson

- **GIVEN** "Wiskunde B, 4 havo" every Tuesday at 10:15, and its teacher away for three weeks
- **WHEN** the coordinator opens next Tuesday's lesson, chooses "Cancel", then "Apply to more weeks", ticks the next three Tuesdays, gives the reason teacher absence and applies
- **THEN** the three lessons show as cancelled
- **AND** the dialog lists all three as changed

### Requirement: Every lesson in a batch passes the same checks

Each lesson in a batch MUST go through the same transition and guard as a single change, as the user who made the batch, and MUST be checked for conflicts as a single change is. A lesson the guard refuses MUST be recorded as refused with the guard's reason, and the other lessons MUST still be changed.

#### Scenario: A lesson that already took place is refused

<!-- @e2e exclude Guard outcome inside a batch; covered by SessionChangeBatchServiceTest::testRefusedLessonDoesNotStopTheBatch. -->

- **GIVEN** a batch with four lessons, one of them already completed
- **WHEN** the batch is applied
- **THEN** three lessons are cancelled and the completed one is recorded as refused with the guard's reason

### Requirement: Affected people get one message per batch

A lesson changed as part of a batch MUST NOT send its own change message. The batch MUST send one message to every learner and parent affected by any of its changed lessons, listing the dates.

#### Scenario: A parent gets one message for three weeks

<!-- @e2e exclude Notification delivery through the register dialect; covered by SessionChangeBatchRegisterTest and gate 18. -->

- **GIVEN** a batch that cancelled three Tuesday lessons of a learner's group
- **WHEN** the batch is saved
- **THEN** the learner's parent gets one message "Your timetable has changed" listing the three dates

### Requirement: A coordinator plans standby hours

A user in `team-leads` or `compliance-officers` MUST be able to plan standby hours per teacher, weekly or on one date, with a time window, a location and a validity period. A teacher MUST see their own standby hours in their personal timetable.

#### Scenario: A coordinator puts a teacher on standby

- **GIVEN** a coordinator on the standby planning page for 2026-2027
- **WHEN** they add teacher e.devries to Tuesday 10:15 to 11:05 at the main location and save
- **THEN** the Tuesday cell for that hour lists e.devries
- **AND** e.devries's timetable shows a standby block every Tuesday at 10:15

### Requirement: The substitution dialog offers standby teachers first

When a coordinator or the cohort's teacher assigns a substitute, the dialog MUST list the teachers on standby during the lesson first, then the teachers who work that day and have no lesson at that time, each with the reason they are listed, and MUST allow a search over all staff. A standby teacher who has a lesson at that time MUST be listed last with that reason. The absent teacher MUST NOT be listed. Learniq MUST NOT assign a substitute on its own.

#### Scenario: A coordinator covers a sick teacher's lesson

- **GIVEN** Tuesday's 10:15 "Wiskunde B, 4 havo" whose teacher is ill, and e.devries on standby then
- **WHEN** the coordinator opens the lesson's substitution dialog
- **THEN** e.devries is at the top under "On standby"
- **AND** choosing e.devries with the reason teacher absence saves the substitution

#### Scenario: A busy standby teacher goes last

<!-- @e2e exclude Ordering rule of the candidate service; covered by SubstitutionCandidateServiceTest::testBusyStandbyTeacherGoesLast. -->

- **GIVEN** a teacher on standby on Wednesday hour 2 who also teaches a lesson then
- **WHEN** candidates are requested for another lesson at that time
- **THEN** that teacher is last, with the reason "has a lesson then"
