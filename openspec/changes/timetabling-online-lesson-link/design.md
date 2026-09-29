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
