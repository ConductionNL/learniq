# Contract: assessment-portal-endpoints

Learniq's side of the timed-task contract in ConductionNL/portaliq#749
(`openspec/changes/portal-take-assessment/design.md`, "The contribution contract").

## Consumers
- `portaliq`: `ContributionController::action()` forwards the five `timedTask` steps
  server-to-server, and `TimedTaskView` renders the answers.

## Common to every endpoint

**Auth**: header `X-Portal-Subject`, portaliq's HS256 assertion (`use: assertion`, `iss: portaliq`,
`exp` in the future, `iat` not in the future, non-empty `sub`), signed with portaliq's dedicated
`jwt_signing_secret`. No Nextcloud session is used or accepted in its place. The claim `audience`
MUST be `student`.

**Body**: JSON. `learnerRef` (the pupil's LearnerProfile uuid) is stamped by portaliq from the
subject's own account (`subjectField`), over any client value. It is the only learner identity the
endpoints read.

**Errors** carry `error` and, when a pupil should read it, `message` in the pupil's Nextcloud
language (Dutch or English), which the portal shows as is.

| Code | `error` | Condition |
|------|---------|-----------|
| 401 | `unauthorized` | Assertion missing, malformed, badly signed, expired, not an assertion, wrong issuer |
| 403 | `forbidden` | Audience is not `student`, or no `learnerRef` in the body |
| 403 | `not_available` | The profile is unknown, merged, deleted or has no Nextcloud account; or the test is not open for this pupil (see `start`) |
| 502 | `downstream_error` | OpenRegister or a lifecycle step failed; no internals leak |

## Endpoints

### `POST /apps/learniq/api/portal/assessments` (step `available`)
**Request:** `{ "learnerRef": "<uuid>" }`

**Response (200):**
```json
{ "tasks": [ {
  "taskId": "<assessment-uuid>", "title": "Toets hoofdstuk 3", "description": "…",
  "timeLimitMinutes": 30, "extraTimeMinutes": 7.5, "availableUntil": "2026-10-01T12:00:00+02:00",
  "needsAccessCode": true, "state": "available", "attemptId": null
} ] }
```
A task is listed when the pupil holds an active or pending Enrolment for its course or cohort, the
test is `published`, has no `proctoring` configuration, is open for this pupil (window, drip,
release conditions) and has attempts left, or when the pupil has an attempt in progress (`state:
in-progress`, `attemptId` set). `extraTimeMinutes` is null for an untimed test or without extra time.

### `POST /apps/learniq/api/portal/assessments/start` (step `start`)
**Request:** `{ "learnerRef": "<uuid>", "taskId": "<assessment-uuid>", "accessCode": "…" }`

**Response (200):** the new attempt, or the one in progress:
```json
{ "attemptId": "<attempt-uuid>", "title": "Toets hoofdstuk 3",
  "serverNow": "2026-10-01T09:00:00+02:00", "deadlineAt": "2026-10-01T09:37:30+02:00",
  "items": [ { "itemId": "<item-uuid>", "type": "choice", "prompt": "…", "points": 1,
               "choices": [ { "id": "A", "label": "…" } ] } ],
  "responses": { "<item-uuid>": "A" } }
```
`deadlineAt = startedAt + timeLimitMinutes × (1 + extra-time percentage / 100)`, null when
untimed. Items come in the attempt's drawn order with its option order, never with a correct
answer. `sources` and `targets` are set for `match`.

| Code | `error` | Condition |
|------|---------|-----------|
| 403 | `not_available` | Unknown or unpublished test, not enrolled, other school, proctored, window or release conditions shut, attempts used |
| 403 | `access_code_required` | The test has a code and none was sent |
| 403 | `access_code_wrong` | The code does not match |

### `POST /apps/learniq/api/portal/assessments/answer` (step `answer`)
**Request:** `{ "learnerRef": "<uuid>", "attemptId": "<uuid>", "itemId": "<uuid>", "response": … }`

| type | `response` |
|---|---|
| choice, inlineChoice | option id (string), one of the item's options |
| textEntry, extendedText, any other type | text (string, at most 20,000 characters) |
| order | option ids in the pupil's order (string[]) |
| match | object from source id to target id |

**Response (200):** `{ "saved": true }`

| Code | `error` | Condition |
|------|---------|-----------|
| 404 | `not_found` | No such attempt, or not this pupil's |
| 409 | `attempt_closed` | Handed in, graded, or past the deadline plus 30 seconds (the attempt is then handed in) |
| 422 | `unknown_item` | The item is not part of this attempt |
| 422 | `invalid_response` | The answer does not fit the item's type or options |

### `POST /apps/learniq/api/portal/assessments/submit` (step `submit`)
**Request:** `{ "learnerRef": "<uuid>", "attemptId": "<uuid>" }`

**Response (200):** `{ "state": "submitted" }`. Closed items are scored on hand-in.

| Code | `error` | Condition |
|------|---------|-----------|
| 404 | `not_found` | No such attempt, or not this pupil's |
| 409 | `attempt_closed` | Already handed in or graded |

### `POST /apps/learniq/api/portal/assessments/result` (step `result`)
**Request:** `{ "learnerRef": "<uuid>", "attemptId": "<uuid>" }`

**Response (200):** `{ "released": false }` until released, then
```json
{ "released": true, "score": 14, "maxScore": 18, "passed": true, "feedback": null,
  "items": [ { "itemId": "<item-uuid>", "prompt": "…", "response": "A", "score": 1, "maxScore": 1 } ] }
```
Released means: the attempt is `graded`, and, when it fed a GradeEntry, that entry is `published`
(or `revised`) with no future `visibleFrom`. `passed` is null unless the test uses a pass mark.

| Code | `error` | Condition |
|------|---------|-----------|
| 404 | `not_found` | No such attempt, or not this pupil's |

## Error Codes
| Code | Meaning | Condition |
|------|---------|-----------|
| 401 | unauthorized | No valid portal assertion |
| 403 | forbidden / not_available / access_code_* | Wrong audience or no learner; the test is not open for this pupil; the code |
| 404 | not_found | The attempt is not this pupil's |
| 409 | attempt_closed | Nothing changes after hand-in or after the deadline |
| 422 | unknown_item / invalid_response | The answer does not belong to the attempt or does not fit the item |
| 502 | downstream_error | A storage or lifecycle step failed |

## Versioning
Version 1 of learniq's timed-task endpoints, matching portaliq#749's payloads. Fields may be added;
none are removed or renamed without a coordinated portaliq change.

## Breaking Change Policy
A breaking change needs a portaliq change first and is announced in both repos' PRs.

## SLA
Same as any learniq request. The forward times out in portaliq (`FORWARD_TIMEOUT`); `answer` is
one read of the attempt and the item plus one write.
