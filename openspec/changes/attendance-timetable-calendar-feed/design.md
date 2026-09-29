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
