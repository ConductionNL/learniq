# Design: read your timetable in your normal calendar app

## Context

At development `acdf1dd5`:

- `lib/Controller/TimetableController.php:121` `mine()` resolves caller cohorts, `sessionsForCohorts` and `sessionsForTeacher`, merges them and projects with `TimetableProjector::personalSessions`.
- `lib/Service/TimetableProjector.php:84` `personalSessions`, `:113` `resolveWindow`, `:241` `projectSession`.
- `lib/Service/TimetableVisibilityService.php` applies the school visibility policy to what a caller sees of others.
- `Session` carries `startsAt`, `endsAt`, `location`, `roomId`, `substituteTeacherId`, `changeReasonKind`, `lifecycle`.
- `grep -rli "text/calendar\|VCALENDAR" lib src appinfo` finds nothing: there is no iCalendar code to reuse.

## Goals / Non-Goals

**Goals**
- Lessons appear in the calendar app a person already uses, and stay current.

**Non-Goals**
- Two-way calendar sync.
- CalDAV server functionality.
- Writing sessions into a user's Nextcloud calendar objects.

## Decisions

### D1: A subscription feed, not calendar writes

Writing events into a user's calendar creates copies that go stale on a cancellation. A subscription is refreshed by the calendar app and needs no write access to the calendar.

### D2: Reuse `mine()` logic

The feed calls the same resolver as the page through an extracted service method, so the two cannot show different lessons.

### D3: The feed reads as its owner, for the read only

The feed is fetched without a session. Every read behind My timetable is scoped by OpenRegister to the active user (RBAC on cohorts and enrolments, multitenancy on every read, lesson notes for that user). Rewriting those reads to take a user id explicitly would mean a second, hand-scoped copy of the page's reads, which is how the two would drift apart.

So the feed controller resolves the token to its owner and makes that owner the request's active user with `IUserSession::setVolatileActiveUser()` (OCP, Nextcloud 29 and later; learniq needs 32), calls `PersonalTimetableService::forUser()`, and clears the volatile user in a `finally` block. The request does nothing else as that user: no write, no other route. A disabled or deleted owner gets a 404, as an unknown token does.

### D4: Times in UTC, no VTIMEZONE

Sessions are stored as ISO 8601 instants. The writer emits `DTSTART`/`DTEND` in the UTC form (`...Z`), which every calendar app shows in the reader's own time zone. That avoids shipping and maintaining VTIMEZONE rules. The feed covers 14 days back to 84 days (twelve weeks) ahead and asks to be refreshed hourly (`REFRESH-INTERVAL`, `X-PUBLISHED-TTL`).

### D5: What an event says

- Summary: the lesson title (`Lesson` when it has none); a cancelled lesson reads `Cancelled: <title>` and has `STATUS:CANCELLED`, because several calendar apps do not show the status.
- Location: the room's name, else the session's location text.
- Description: `This lesson is cancelled.`, the substitute, `You cover this lesson.` for a covering teacher, and the reason kind as a label.
- The substitute is named only when `TimetableVisibilityService::mayOpen(uid, 'teacher', substitute)` allows it. Otherwise the event says a substitute covers the lesson, without a name.
- The free-text `changeReason` is left out: it routinely names a person ("Mr X is ill").
- Lesson notes are left out: the feed is a calendar, not the lesson page.

### D6: The address is shown once

Only the SHA-256 hash of the 32-byte token is stored, as the indexed user preference `timetable_feed_token_hash` (`IUserConfig::FLAG_INDEXED`, so the lookup is a database search). The address is therefore shown once, right after it is made. Opening the dialog later says an address exists and offers Reset link (a new address; the old one answers 404 at once) and Remove address.

### D7: Rate limits

The feed: 60 requests per hour per IP for anonymous callers, 120 for a signed-in browser. Making an address: 20 per hour per user.
