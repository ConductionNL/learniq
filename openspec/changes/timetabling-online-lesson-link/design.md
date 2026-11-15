# Design: join an online lesson from the timetable

## Context

At development `acdf1dd5`:

- `src/manifest.d/learning.json:2668-2672` mounts a Talk integration widget titled Join call on `SessionDetail` (`/sessions/:id`).
- `src/views/MyTimetable.vue:152-155` renders `location` as plain text.
- `lib/Settings/learniq_register.json` `Session` has `location`, `roomId`, `externalRef` and no link.

## Goals / Non-Goals

**Goals**
- One click from the timetable to the online lesson.

**Non-Goals**
- Creating meetings on other platforms.
- Attendance capture from meetings.

## Decisions

### D1: A plain URL field

Any platform works with a URL, and the Talk widget keeps working alongside; a per-platform integration would bind the field to one vendor.

### D2: https only, checked three times (built 2 Oct)

`Session.onlineMeetingUrl` is `format: uri` with the pattern `^https://[^\s/?#]+[^\s]*$`, so OpenRegister refuses a `javascript:`, `http:`, `data:` or relative value on save with the schema's reason. The projection (`TimetableProjector::httpsOrNull`) hands a page only an https value, so older data or another source cannot put an unsafe link on a page. The page's `joinUrl()` checks once more before rendering the Join action, which opens in a new tab with `rel="noopener noreferrer"`.

### D3: Where the link shows (built 2 Oct)

- My timetable: a Join action on the lesson row, not on a cancelled lesson.
- The lesson page (`SessionDetail`): its data widget renders every Session property, so the link appears there and in the generic edit form with no manifest change. The existing Talk widget stays alongside it.
- The calendar feed: the event's `URL` property.
- Who sees it: whoever may read the session, because the link is a property of the session and travels with the session's own read rules. Planninq lessons carry no link.
