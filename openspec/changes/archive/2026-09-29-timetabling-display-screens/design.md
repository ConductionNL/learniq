# Design: timetabling-display-screens

## Context

The day's lessons and changes are already computed for signed-in users (`TimetableProjector::windowedSessions()` and `todaysChanges()`, `lib/Service/TimetableProjector.php:127`, :164). A hall screen has no user, so it needs its own door with its own, smaller output. Learniq's public routes (`CredentialVerifyController`, `LearningRecordShareVerifyController`) show the `#[PublicPage]` shape.

## Sessions after D10

The screen service reads lessons through the same session reader the timetable uses; after `sessions-from-planninq` that reads planninq's `timetableSession` (subject, times, `groupReference`, `teacherReference`, `roomReference`, `status`). The screen shows the school's own codes, which planninq carries.

## Data model

### `DisplayScreen` (new, slug `display-screen`, 0.1.0)

| property | type | notes |
|---|---|---|
| `name` | string, required | "Aula gebouw A" |
| `vestigingId` | uuid, `$ref: Vestiging`, nullable | |
| `roomIds` | array of uuid, `$ref: Room` | empty means every room of the location |
| `cohortIds` | array of uuid, `$ref: Cohort` | empty means every group of the location |
| `shows` | `today`, `today-and-tomorrow`, `changes-only` | default `today` |
| `showTeacherCodes` | boolean, default true | |
| `tokenHash` | string, write-only | SHA-256 of the token; never returned by any route |
| `tokenCreatedAt`, `lastSeenAt` | date-time | `lastSeenAt` stamped by the public route at most once a minute |
| `lifecycle` | `active`, `revoked` | |
| `tenant_id` | string, required | |

Authorization: read, create and update `team-leads`, `compliance-officers`. `tokenHash` is listed in the schema's property-level read rule as hidden from every group (OpenRegister's property RBAC), so even staff reading the object never see it.

## Token

`POST /api/display-screens/{id}/token` (staff groups above, check in the body) creates 32 random bytes with `ISecureRandom`, stores the hash, and returns the screen address once. Renewing replaces the hash; revoking sets `lifecycle: revoked`.

## Public route

`GET /api/public/display/{token}` and the page `GET /display/{token}`, both `#[PublicPage]`, `#[NoCSRFRequired]`, with brute-force protection (`#[BruteForceProtection(action: 'learniq-display')]`) on a miss:

1. Find the active screen whose `tokenHash` equals the hash of the token, as the system; none: 404.
2. Load the lessons of today (and tomorrow) for the screen's rooms or groups through the session reader.
3. Project each to `{startsAt, endsAt, group, subject, room, teacherCode, change}` where `change` is `cancelled`, `other-teacher` or `other-room` or null. No learner, no user id, no `changeReason`, no `affectedLearnerIds`. Teacher code only when `showTeacherCodes`.
4. Cache the answer per screen for 60 seconds.

The page is a minimal full-screen view (`src/views/DisplayScreenView.vue` served by a public template), large type, sorted by time and then group, changes highlighted, refreshing every 60 seconds and keeping the last good data with "Last updated at" when a refresh fails.

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| screen object, lifecycle | declarative | |
| token issue and public read | imperative, controller and `DisplayScreenService` | a public, token-addressed door with a separate projection; ADR-031 external-facing exception |

## Seed data

VO example set: screen "Aula gebouw A" for the main location, `shows: today`, teacher codes on, with a note in the seed that the token is created on first use (no token in seed data).

## Open points

- Whether screens should show the next hour only on very large schools; the page scrolls by time today.

## As built (2026-09-28)

- The address is `<screen uuid>.<secret>`, so the public door reads one row by id as the system (`_render: false`, the raw row) and compares `sha256(secret)` with `tokenHash` in constant time. `tokenHash` is `writeOnly`, the platform's own marker for a field no read returns, instead of a property read rule.
- The screen has a plain `status` (`active`, `revoked`) rather than a lifecycle: revoking is a write by `DisplayScreenService`, and a revoked screen answers 404 on the next request.
- `lastSeenAt` is left out: stamping it would turn every public read into a write.
- The lessons come from `TimetableSourceResolver`, so with planninq installed the screen shows planninq's lessons, with `subject` and `teacherReference` (the school's teacher code) now carried on its rows. Learniq's own sessions have no teacher code, so the column stays empty for them; a substitute's user id never reaches the screen.
- The page is its own small bundle (`learniq-display.js`, `templates/display.php`), with the token handed over through initial state.
- Staff open the address page from the screen's detail page ("Address"), because an `api-call` action cannot show the answer it gets back.
