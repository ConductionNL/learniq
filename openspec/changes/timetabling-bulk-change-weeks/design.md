# Design: timetabling-bulk-change-weeks

## Context

A change to one lesson runs through the `Session` lifecycle (`cancel`, `substitute-teacher`, `substitute-teacher-in-progress`, guarded by `SessionChangeGuard`, `lib/Settings/learniq_register.json:6331-6343`), `SessionChangeNoticeHandler` materialises the affected people, the `rosterChanged` notification tells them, and `SessionConflictListener` checks clashes. A bulk change must keep every one of those per lesson and change only the messaging.

## Sessions after D10

"The same weekly lesson" is found by the timetable's session reader: today learniq `Session` rows with the same `cohortId`, `courseId`, weekday and local start time; after `sessions-from-planninq`, planninq rows with the same `groupReference` (or `cohortId`), `subject`, weekday and start time. The change itself stays learniq's operational record: after D10 it is kept on learniq's own change object keyed on the session reference, as the follow-up `sessions-from-planninq` defines for single changes; this change adds only the batch around it.

## Data model

### `SessionChangeBatch` (new, slug `session-change-batch`, 0.1.0)

| property | type | notes |
|---|---|---|
| `kind` | `cancel`, `substitute`, `room` | |
| `sessionIds` | array of uuid, `$ref: Session`, required | the ticked lessons |
| `substituteTeacherId` | string, nullable | for `substitute` |
| `roomId` | uuid, `$ref: Room`, nullable | for `room` |
| `changeReasonKind` | the `Session.changeReasonKind` enum, required | |
| `changeReason` | string, nullable | |
| `results` | array of `{sessionId, outcome: applied or refused, reason}` | written by the service |
| `affectedLearnerIds`, `affectedParentIds` | arrays | union over the applied lessons |
| `madeBy` | string | |
| `tenant_id` | string, required | |

Authorization: read and create `instructors`, `team-leads`, `compliance-officers`. Who may change a given lesson (its cohort's teachers, `admin`, `coordinators`) cannot be written as a row rule on the batch, so the service leaves that to `SessionChangeGuard`, which it runs for every lesson.

Notification on `SessionChangeBatch` (`x-openregister-notifications`, trigger on create of a batch with at least one applied lesson): "Je rooster is gewijzigd" with the list of dates, to `affectedLearnerIds` and `affectedParentIds`.

### `Session` (0.1.0 to 0.2.0)

- `changeBatchId` (uuid, nullable, `$ref: SessionChangeBatch`).
- `rosterChanged` gets a condition `changeBatchId` is empty, so a lesson changed in a batch sends no message of its own.

## Service

`SessionChangeBatchService::apply(array $batch, string $callerId): array`, route `POST /api/session-change-batches` (`#[NoAdminRequired]`, check in the body, gate 7):

1. For each session id: set `changeBatchId`, the reason fields and `substituteTeacherId` or `roomId`, then fire `cancel` or the substitute transition that fits its lifecycle, as the caller (so `SessionChangeGuard` runs with the caller's rights); for `room`, update `roomId` and set `changeReasonKind`.
2. Record `applied` or `refused` with the guard's message; a refusal does not stop the loop.
3. After the loop, read each applied lesson's materialised affected people, store the union on the batch, and save the batch, which fires the one notification.

`GET /api/sessions/{id}/series?until=` returns the recurring lessons of the same weekly slot for the dialog.

## Screens

`SubstitutionModal.vue` gains "Apply to more weeks": the series list with dates and tick boxes (the current lesson ticked), an until date, and after applying a result list (applied, refused with reason).

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| per-lesson guard, notice materialisation, conflict check | existing, unchanged | |
| one message per batch | declarative notification on the batch, condition on the lesson's notification | |
| applying one change to many lessons | imperative, `SessionChangeBatchService` | a loop of guarded transitions with a per-lesson outcome; ADR-031 exception for bulk work |

## Seed data

VO example set: one batch cancelling three Tuesday lessons of "Wiskunde B, 4 havo" for "teacher-absence", with one refused lesson (already completed) in `results`.
