# Design: timetabling-elective-lesson-signup

## Context

Learniq's sign-up objects today are course level (`Enrolment`, `SubjectChoice`). An optional lesson is below that: one occurrence, or a series, with a capacity, open to learners of several groups. Rules that must hold whoever writes (a learner, a coordinator, another system) belong in a creating listener, the pattern `EnrolmentPrerequisiteListener` set because OpenRegister runs no lifecycle guard on create (`lib/Listener/EnrolmentPrerequisiteListener.php:13-24`).

## Sessions after D10

An offer names its lessons by session reference: `sessionIds` (learniq `Session` uuids) today, and `timetableSessionRefs` (`{sourceSystem, externalRef}` of planninq `timetableSession` rows, contract version 1 of planninq's school-timetable-target) once learniq reads sessions from planninq (follow-up `sessions-from-planninq`). A sign-up keys on the same reference. Start and end times are read from the session on display, never copied.

## Data model

### `ElectiveOffer` (new, slug `elective-offer`, 0.1.0)

| property | type | notes |
|---|---|---|
| `name`, `description` | string | "Keuzewerktijd wiskunde" |
| `sessionIds` | array of uuid, `$ref: Session` | the lessons offered |
| `timetableSessionRefs` | array of `{sourceSystem, externalRef}` | the same after D10 |
| `capacityPerLesson` | integer, minimum 1, required | |
| `eligibleCohortIds` | array of uuid, `$ref: Cohort` | empty means every learner of the tenant |
| `windowMode` | `fixed`, `relative` | |
| `opensAt`, `closesAt` | date-time, nullable | for `fixed` |
| `opensDaysBefore`, `closesHoursBefore` | integer, nullable | for `relative`, counted from each lesson's start |
| `lifecycle` | `draft`, `open`, `closed` | |
| `tenant_id` | string, required | |

Authorization: read `authenticated`; create and update `instructors`, `team-leads`, `compliance-officers`.

### `ElectiveSignUp` (new, slug `elective-sign-up`, 0.1.0)

| property | type | notes |
|---|---|---|
| `offerId` | uuid, `$ref: ElectiveOffer`, required | |
| `sessionId` or `timetableSessionRef` | one required | the lesson |
| `learnerId` | string, required | |
| `status` | `signed-up`, `placed`, `withdrawn` | |
| `madeBy` | string | user id of whoever wrote it |
| `madeVia` | `learner`, `coordinator`, `integration` | stamped by the listener from the caller's groups |
| `tenant_id` | string, required | |

Calculation on `ElectiveOffer` (`x-openregister-aggregations`): `signUpCountBySession`, the count of non-withdrawn sign-ups grouped by session.

Authorization of `ElectiveSignUp`: read `instructors`, `team-leads`, `compliance-officers`, `elective-integrations`, and the learner's own rows (`match: {learnerId: $userId}`); create and update `instructors`, `team-leads`, `compliance-officers`, `elective-integrations`. Learners write through the endpoint below. A new scope `elective-integrations` is added to `components.securitySchemes.oauth2.flows.authorizationCode.scopes` so OpenRegister provisions the group.

## The rules listener

`ElectiveSignUpRules` on `ObjectCreatingEvent` and `ObjectUpdatingEvent` for `elective-sign-up`, refusing with a reason:

1. the learner is in one of `eligibleCohortIds` (`Cohort.learnerIds`), when the list is not empty;
2. the offer is `open` and the lesson is one of its lessons;
3. inside the window, unless the caller is in `instructors`, `team-leads` or `compliance-officers` and the status is `placed` (placing after the deadline);
4. the lesson's non-withdrawn sign-ups stay within `capacityPerLesson`, for every caller;
5. no other non-withdrawn sign-up exists for the same learner and lesson.

A withdrawal (status to `withdrawn`) is allowed for the learner inside the window and for staff and integrations at any time before the lesson starts.

## Learner endpoint

`POST /api/electives/{offerId}/sign-up` with the lesson reference, and `POST /api/elective-sign-ups/{id}/withdraw`, `#[NoAdminRequired]`: the caller is always the learner (`learnerId` is set from the session user, never from the body). The write goes through `ObjectService` with `_rbac: false`, so the listener's rules are the only rules, and they run.

## Screens

- `MyElectives` (`/my-electives`, under My learning): open offers the learner is eligible for, each lesson with its time, room, free places and Sign up or Withdraw.
- `ElectiveOffers` index and `ElectiveOfferDetail` (under Learning): per lesson a list of signed-up learners, a list of eligible learners without a sign-up, and "Place" (writes `status: placed`).

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| offer lifecycle, counts | declarative | lifecycle and aggregation |
| eligibility, window, capacity, one per lesson | imperative, creating and updating listener | a rule across rows that must run for every writer, including the object API; the precedent is `EnrolmentPrerequisiteListener` |
| learner sign-up | imperative endpoint | the learner may not write the schema directly |

## Seed data

VO example set: offer "Keuzewerktijd wiskunde" for all havo 4 and 5 cohorts, `relative` window (opens 7 days before, closes 12 hours before), capacity 24, four Thursday lessons; eleven sign-ups on the first lesson, one `placed` by a coordinator after the deadline, one `withdrawn`.

## As built (2026-09-28)

- `ElectiveService` reads offers, lessons, windows and places as the system; `ElectiveBoard` builds the learner's page and the coordinator's roster; `ElectiveSignUpRules` enforces the rules on the creating and updating events, registered through `ElectiveListenerRegistrar`.
- `signUpCountBySession` is computed by `ElectiveBoard` instead of an `x-openregister-aggregations` block: the count is per lesson key, which covers both learniq sessions and planninq references.
- A planninq lesson on an offer carries its own `startsAt`, `endsAt` and `title` in `timetableSessionRefs`, so a relative window can be counted from it without a timetable read per sign-up.
- An admin, and a write with no user, bypass the rules: break-glass, and the way an example set or demo data is imported.
- The coordinator's roster and "Place" are `GET /api/electives/{offerId}/roster` and `POST /api/electives/{offerId}/place`, behind the ADR-023 action `elective.manage`; the roster page opens from the offer's detail page ("Sign-ups").
- The VO example set's offer is `closed` (the school year is over), with eleven sign-ups, one placement by the teamleider and one withdrawal on the first of four Thursdays in February.
