# Design: attendance-self-check-in

## Context

Attendance is written by staff only (`AttendanceRecord.authorization`, `lib/Settings/learniq_register.json:15474` onwards: read, create and update by `instructors` and `compliance-officers`). The teacher's surface is `AttendanceRegisterView.vue`, which loads the session, the cohort's `learnerIds` and the existing records (`src/views/AttendanceRegisterView.vue:150-195`) and saves one record per learner. Self check-in must not widen who may write `AttendanceRecord` through the object API: a learner who may create records could mark a friend. The write therefore goes through one endpoint that checks everything and then writes as the system, the pattern `lib/Lifecycle/LearningPlanSignatureGuard.php:340` and the repair steps use with `_rbac: false`.

After D10 (planninq owns the timetable, learniq reads sessions from it) the `sessionId` a window names is the session learniq reads; the window and the record stay learniq objects keyed on that id, and nothing is copied from the timetable.

## Data model

### `CheckInWindow` (new, slug `check-in-window`, 0.1.0)

| property | type | notes |
|---|---|---|
| `sessionId` | uuid, `$ref: Session`, required | |
| `openedBy` | string, required | Nextcloud user id of the teacher |
| `opensAt`, `closesAt` | date-time, required | default: now and now plus 15 minutes; `closesAt` at most `Session.endsAt` |
| `lateAfterMinutes` | integer, default 5 | minutes after `Session.startsAt` from which a check-in counts as `late` |
| `mode` | enum `rotating-qr`, `link` | `link` for an online lesson |
| `checkInCount` | integer, read-only | aggregation: records with this window's `sessionId` and `markedVia: self-check-in` |
| `lifecycle` | enum `open`, `closed` | `close` transition by `instructors` and `compliance-officers`; a background sweep is not needed because the endpoint checks `closesAt` itself |
| `tenant_id` | string, required | |

Authorization: read, create and update by `instructors` and `compliance-officers`. Learners never read a window.

### `AttendanceRecord` (0.2.0 to 0.3.0)

- `markedVia`: enum `teacher`, `self-check-in`, default `teacher`. Existing rows read as `teacher`.
- `markedBy` keeps its meaning for teacher marks; for a self check-in it holds the learner's own user id, and the description says so.

## The code

`CheckInCodeService::codeFor(windowId, step)` returns the first eight characters of the base32 HMAC-SHA256 of `windowId|step` with the instance secret (`IConfig::getSystemValueString('secret')`, hashed with `hash_hmac`; no new secret is stored). `step` is `floor(unix time / 30)` in `rotating-qr` mode and `0` in `link` mode. `verify()` accepts the current and the previous step. The teacher's screen fetches the current code from `GET /api/check-in/{windowId}/code` (staff only) every thirty seconds and renders the QR of `/apps/learniq/check-in/{windowId}?code=<code>`.

## Endpoint

`POST /api/check-in/{windowId}` with `{code}`, `#[NoAdminRequired]`, checks in its body (gate 7):

1. the window exists, is `open` and now is between `opensAt` and `closesAt`;
2. the code verifies;
3. the caller's user id is in the `learnerIds` of the session's cohort;
4. no `AttendanceRecord` exists for (`sessionId`, caller).

Then it writes `AttendanceRecord` with `status` `present`, or `late` when now is later than `Session.startsAt + lateAfterMinutes`, `markedVia: self-check-in`, `markedBy` the caller, `markedAt` now, with `_rbac: false` because every check above has passed. Each refusal answers with a plain reason ("This check-in has closed", "You are not in this lesson's group", "Your attendance is already recorded").

## Screens

- Register (`AttendanceRegisterView.vue`): a header button "Open self check-in" creates the window and opens a full-screen panel with the QR (or the link and a copy button in `link` mode), the count of check-ins and "Close check-in". Rows written by self check-in show a label "checked in"; the status select stays editable.
- Learner page (`/check-in/:windowId`, a small custom page `CheckInPage.vue` registered in `src/registry.js`): shows the lesson title and time (read through the endpoint's `GET` answer, not through the window object) and one button. A code from the URL is sent as is.

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| `CheckInWindow` lifecycle | declarative | a two-state machine |
| `checkInCount` | declarative aggregation | a count over child rows |
| code and check-in write | imperative, `CheckInService` behind one controller | ADR-031 exception: a signed, time-based secret and a write the caller's own object rights do not allow; the checks cannot be expressed as a register rule |

## Seed data

On the VO example set: one `CheckInWindow` for the lesson "Wiskunde B, 4 havo" on a Tuesday 08:30, `mode: rotating-qr`, `lateAfterMinutes: 5`, `closed`; three `AttendanceRecord` rows with `markedVia: self-check-in` (two `present`, one `late`) and the rest `teacher`.

## Open points

- Whether the instance secret may be used for this HMAC or a per-app secret stored in app config is preferred; the implementer asks the security reviewer and names the choice in the PR.
