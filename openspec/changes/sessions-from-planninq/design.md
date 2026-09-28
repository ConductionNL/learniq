# Design: sessions-from-planninq

## Architecture Overview

```
TimetableController::mine() / ::cohort()      TimetableImportHandler (timetable-import job → running)
            │                                          │
            ▼                                          ▼
   TimetableSourceResolver::current() ◀────────────────┤
     ├─ LocalSessionTimetableSource   (learniq Session, ObjectService, RBAC)
     └─ PlanninqTimetableSource       ──dispatchTyped──▶ OCA\Planninq\Event\TimetableSessionsQueryEvent
                                                        │
                        planninq source? ──yes──▶ PlanninqTimetableImport
                                                        ├─dispatchTyped─▶ OCA\Integriq\Event\RosterImportRequestedEvent
                                                        └─ result on the job, no Session write
                                                        then TimetableConflictDetector::scanWindow(lessons from PlanninqTimetableSource)
                        no ─────────────────────▶ existing path (connector call, Session upsert, scan)
```

## Decisions

### D1: One interface, two sources, one resolver
`TimetableSource` has `name()`, `sessionsForCohorts(cohortIds, from, to)` and `sessionsForTeacher(userId, from, to)`. Both sources return rows in learniq's session shape plus `source`, so `TimetableProjector`, the conflict detector and the pages need no second code path. The resolver picks planninq when it is installed and its query event class exists; `timetable_source = learniq` in app config forces the local source, as a switch that needs no deploy.

Rejected: a flag on each reader. Three readers would each re-decide which source applies, and one of them would get it wrong.

### D2: Planninq through its typed event, never its classes
`PlanninqTimetableSource` looks `OCA\Planninq\Event\TimetableSessionsQueryEvent` up by name and dispatches it (ADR-041). An unanswered event or an answered error raises: a timetable that silently reads empty is the failure this fleet keeps shipping.

### D3: The import asks integriq; it does not fetch
With planninq as the source, `PlanninqTimetableImport` dispatches integriq's `RosterImportRequestedEvent`. Integriq fetches, maps and delivers; learniq records the counts, the rejections (as `validationReport`, the shape the job already uses) and the client flavour, and writes no `Session`. The rostering source comes from `scope.rosterSource`, else from the mapping profile's vendor (`targetSchema` `Zermelo:Appointment` → `roster-zermelo`, and so on), so existing jobs keep working unchanged.

### D4: Conflict detection on the lessons the source returns
`TimetableConflictDetector::scanWindow(sessions, tenantId)` scans exactly the lessons it is given; `scan()` keeps loading its window from learniq `Session`. After a planninq delivery the handler reads the lessons for the cohorts in `scope.groupMap` and the teachers in `scope.teacherMap`, in `scope.from`..`scope.to` or the coming 14 days, and scans those. `SessionOverlapEvaluator` reads a lesson's own `teacherUserId` before the cohort's teachers, and compares `roomReference` when neither lesson has a `roomId`.

### D5: Pages read endpoints, not schemas
`CohortTimetableView` used to query the `session` schema itself. It now calls `GET /api/timetable/cohort/{cohortId}`, which reads the cohort with RBAC (403 when the caller cannot) and then asks the current source. Its default window runs from the start of this week for eight weeks, so a year of lessons is not loaded at once. A planninq lesson is listed but not opened as a learniq session, and `MyTimetable` hides "Manage" for it: cancellation and substitution stay learniq `Session` transitions.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Which source answers | Imperative, thin resolver | Depends on which apps are installed at runtime; no schema can declare it. |
| Delivery into planninq | Imperative (`PlanninqTimetableImport`) | External-integration exception: a cross-app command (ADR-041). |
| Conflict detection | Imperative (existing detector) | Unchanged exception class (ADR-031 cross-object write bridge). |

## API Design

### `GET /api/timetable/cohort/{cohortId}`
Query: `from`, `to` (ISO 8601, optional; default this week's Monday for eight weeks).
Response 200:
```json
{"sessions": [{"id": "<id>", "title": "Wiskunde", "startsAt": "...", "endsAt": "...", "location": "A1.12", "lifecycle": "scheduled", "source": "planninq"}], "from": "...", "to": "...", "source": "planninq"}
```
403 when the caller cannot read the cohort; 503 when the source does not answer.

### `GET /api/timetable/mine` (existing)
Unchanged shape, plus `source` on each session and on the response.

## Database Changes
None. No schema changes.

## Nextcloud Integration
- Controllers: `TimetableController::cohort()` (new, `#[NoAdminRequired]`, guard: RBAC read of the cohort, 403), `::mine()` (reads through the resolver).
- Services: `TimetableSourceResolver` (`IAppConfig`), `LocalSessionTimetableSource` (`ObjectService`), `PlanninqTimetableSource` (`IAppManager`, `IEventDispatcher`), `PlanninqTimetableImport` (`IEventDispatcher`).
- Events consumed by name: `OCA\Planninq\Event\TimetableSessionsQueryEvent`, `OCA\Integriq\Event\RosterImportRequestedEvent`.

## Security Considerations
- The cohort endpoint reads the cohort through OpenRegister with RBAC before any lesson; a caller who cannot read the cohort gets 403 and no lessons.
- My timetable still resolves the caller's cohorts through RBAC reads; with planninq it adds only the lessons planninq holds for the caller's own account.
- Planninq applies its own RBAC to the query (signed-in read).
- The import sends integriq only a source id, the job's code maps and the job id.

## NL Design System
Existing components (`CnTimelineView`, the `MyTimetable` list). One new note line uses existing NcNoteCard styling; no new colours.

## File Structure
```
lib/Timetabling/Source/TimetableSource.php              (new)
lib/Timetabling/Source/LocalSessionTimetableSource.php  (new)
lib/Timetabling/Source/PlanninqTimetableSource.php      (new)
lib/Timetabling/Source/TimetableSourceResolver.php      (new)
lib/Timetabling/PlanninqTimetableImport.php             (new)
lib/Timetabling/TimetableImportHandler.php              (planninq branch)
lib/Timetabling/TimetableConnectorClient.php            (the legacy connector call, moved out of the handler unchanged)
lib/Timetabling/TimetableConflictDetector.php           (scanWindow)
lib/Timetabling/SessionOverlapEvaluator.php             (teacherUserId, roomReference)
lib/Service/TimetableProjector.php                      (source on each session)
lib/Controller/TimetableController.php                  (cohort(), mine() through the resolver)
appinfo/routes.php                                      (timetable#cohort)
src/api/timetable.js, src/views/CohortTimetableView.vue, src/views/MyTimetable.vue
tests/Stubs/Planninq/Event/TimetableSessionsQueryEvent.php   (verbatim copy of planninq #685)
tests/Stubs/Integriq/Event/RosterImportRequestedEvent.php    (verbatim copy of integriq #2222)
tests/Unit/Timetabling/Source/*Test.php, tests/Unit/Timetabling/PlanninqTimetableImportTest.php
```

## Seed Data
No schema is introduced or changed, so no seed rows change.

## Migration Plan
None. Rollback: revert, or set `timetable_source` to `learniq`.
