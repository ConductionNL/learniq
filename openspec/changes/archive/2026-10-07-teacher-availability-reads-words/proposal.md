# Proposal: the teacher availability list reads words

## Why

Seen on the primary-school instance in the review of 2026-10-04: the teacher availability list showed every property as stored. The round read as a uuid, the teacher as a Nextcloud user id (`po-leerkracht-09`) and the free time blocks as a JSON block (`[{"startsAt":"2026-10-15T16:00:00+00:00", ...}]`).

## What changes

- The list declares its columns: round, teacher, times and status.
- The round column resolves the round to its name through the `fkResolve` cell, the way other lists resolve a reference.
- A Nextcloud user is no register object a cell can resolve, so the availability carries the teacher's display name, `teacherName`, the way a conference slot does. ReadableCopies derives it and ReadableCopyStamp writes it on every create and update, replacing a value a client sends. BackfillReadableCopies writes it on stored availabilities; the app version moves so `occ upgrade` runs it.
- The times read as "do 15 okt, 18:00–20:00", several blocks separated by "; ", in the reader's language and time zone. Learniq adds a `timeBlocks` list column formatter to CnAppRoot's built-in formatters for this.
- The availability page shows teacher, round, status and times, and leaves the user id and the tenant out.
- Field titles read "Conference round", "Teacher" and "Times"; the schema title reads "Teacher availability". Two new catalogue keys with their Dutch.
- Register 0.34.40, teacher availability 0.1.2.

## Not changed

- No enum value or stored data except the new copy.
- The availability page's data widget has no column formatter, so the times on that page still show as stored. Listed as a follow-up for nextcloud-vue (a formatter on a data widget field).
- The register goes to 0.34.40, not 0.34.39: pupils-read-by-name (open beside this change) takes 0.34.39.
