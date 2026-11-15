# timetable-source Specification

## Purpose
Learniq reads timetable sessions from planninq, the fleet's timetable owner (decision D10), through a source adapter, and keeps its own `Session` schema as the fallback for schools without planninq. A `timetable-import` job delivers into planninq through integriq when planninq is the source. Cross-app calls are typed events looked up by name (ADR-041); reads go through OpenRegister RBAC (ADR-022).

## Requirements

### Requirement: A resolver picks planninq when it is installed (REQ-001)
`TimetableSourceResolver::current()` MUST return the planninq source when planninq is installed and its `TimetableSessionsQueryEvent` class exists, and the local `Session` source otherwise. The app config `timetable_source` set to `learniq` MUST force the local source.

#### Scenario: Planninq installed
- GIVEN planninq is installed and its query event class exists
- WHEN the resolver is asked for the current source
- THEN it returns the planninq source

#### Scenario: Planninq absent or switched off
- GIVEN planninq is not installed, or `timetable_source` is `learniq`
- WHEN the resolver is asked for the current source
- THEN it returns the local `Session` source

### Requirement: The planninq source reads through planninq's query event (REQ-002)
`PlanninqTimetableSource` MUST dispatch `OCA\Planninq\Event\TimetableSessionsQueryEvent` with `sourceApp: learniq` once per cohort or teacher asked for, and MUST map each answered lesson onto learniq's session shape: `title`, `startsAt`, `endsAt`, `location` (room label, else room code), `cohortId`, `lifecycle` (`cancelled` for a cancelled lesson, else `scheduled`), `teacherUserId`, `roomReference` and `source: planninq`. An unanswered event or an answered error MUST raise, never read as an empty timetable.

#### Scenario: A cohort's lessons come from planninq
- GIVEN planninq answers two lessons for cohort `c-1`, one cancelled
- WHEN the planninq source is asked for cohort `c-1`
- THEN it returns two sessions with `source: planninq`, the second with `lifecycle: cancelled`

#### Scenario: Planninq does not answer
- GIVEN nothing handles the query event
- WHEN the planninq source is asked for a cohort
- THEN it raises instead of returning an empty list

### Requirement: A timetable-import job delivers into planninq when planninq is the source (REQ-003)
When the resolver returns the planninq source, `TimetableImportHandler` MUST NOT write any learniq `Session`. It MUST dispatch `OCA\Integriq\Event\RosterImportRequestedEvent` with the job's rostering source, its `scope.groupMap` and `scope.teacherMap` when set, and the job id; record `recordsProcessed`, `recordsAccepted`, `recordsRejected`, the rejections as `validationReport`, the target and the client flavour on the job; and transition it to `succeed`, `partial` or `fail`. A missing integriq, a failed delivery or an unresolvable source MUST fail the job with a readable message. The job's rostering source MUST come from `scope.rosterSource`, else from the mapping profile's vendor (`Zermelo`, `Untis`, `Xedule`, `TimeEdit`).

#### Scenario: A Zermelo job lands in planninq and writes no Session
- GIVEN planninq is the source and integriq answers `delivered` with 2 created and 1 rejected
- WHEN a `timetable-import` job with mapping profile vendor Zermelo starts running
- THEN integriq is asked for `roster-zermelo`
- AND no `session` object is saved
- AND the job records 3 processed, 2 accepted, 1 rejected and transitions `partial`

#### Scenario: Integriq is not installed
- GIVEN planninq is the source and integriq's event class does not exist
- WHEN a `timetable-import` job starts running
- THEN the job fails with a message naming integriq and no `session` object is saved

### Requirement: Conflict detection runs on the adapter's lessons (REQ-004)
After a delivery into planninq, the handler MUST load the delivered window's lessons for the delivered cohorts from the planninq source and pass them to `TimetableConflictDetector::scanWindow()`. `SessionOverlapEvaluator` MUST treat a lesson's own `teacherUserId` as its teacher and compare `roomReference` when no `roomId` is set.

#### Scenario: Two planninq lessons for one teacher overlap
- GIVEN two planninq lessons at the same time with the same `teacherUserId`
- WHEN the window is scanned
- THEN a `teacher-double-booking` conflict is queued for the two lesson ids

### Requirement: Both timetable pages read through the adapter (REQ-005)
`GET /api/timetable/cohort/{cohortId}` MUST return the cohort's sessions from the current source after an RBAC read of the cohort (403 when the caller cannot read it). `GET /api/timetable/mine` MUST load the caller's cohort sessions from the current source and, with planninq, also the lessons planninq holds for the caller's own account. `CohortTimetableView` MUST read the cohort endpoint, and neither page MUST open a planninq lesson as a learniq session.

#### Scenario: A teacher opens a cohort timetable with planninq installed
- GIVEN planninq is the source and the teacher can read cohort `c-1`
- WHEN the cohort timetable page loads
- THEN it shows the lessons planninq holds for `c-1`

#### Scenario: A caller who cannot read the cohort
- GIVEN the caller cannot read cohort `c-9`
- WHEN they call `GET /api/timetable/cohort/c-9`
- THEN the response is 403 and no lesson is read
