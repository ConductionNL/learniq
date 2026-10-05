# Proposal: the teacher availability page reads its times as words

## Why

`teacher-availability-reads-words` made the list read "do 22 okt, 18:00–20:00" through learniq's `timeBlocks` formatter. One click further, the availability's own page still showed the stored blocks: a small table of ISO timestamps (`2026-10-22T16:00:00+00:00`). The page's data widget comes from nextcloud-vue and had no way to take a formatter per field.

## What changes

- nextcloud-vue (ConductionNL/nextcloud-vue#1305): `CnObjectDataWidget` honours a field override `formatter`, resolved from the same registry CnAppRoot hands the list columns (`cnFormatters`), called with the same signature. Additive.
- learniq: the availability page's data widget names `timeBlocks` for `blocks`, so the page reads the times the way the list does.

## Why this way

The alternatives were a stamped readable copy (a `timesText` field filled by `ReadableCopyStamp`) or a custom detail widget. A stamped copy adds a property, a back-fill and a second source of truth in one fixed language and time zone, while the formatter already renders in the reader's language and time zone. The library change is small, has no consumer impact, and every app with a list formatter can now use it on a detail page.

## Not changed

- No schema, no data, no register version.
- Until nextcloud-vue ships #1305 in a release and learniq moves to it, the override is ignored and the page renders as before.
