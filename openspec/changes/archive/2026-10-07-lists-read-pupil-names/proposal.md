# Proposal: every staff list reads a pupil by name

## Why

Seen on the primary-school instance on 2026-10-05, after `pupils-read-by-name`: the lists that change left alone still name the pupil by Nextcloud user id. A course's and a group's enrolments, a curriculum plan's grades, a programme's final grades, the report cards, an assessment's results and the staff lists of signals (attendance, study progress, engagement) all read `po-leerling-147` where the teacher expects "Vera Hulstkamp".

`pupils-read-by-name` fixed its lists by showing the row's `learnerRef` through the `fkResolve` cell. That does not carry over as it is:

- Seven of these schemas have no `learnerRef` at all (attendance flag, study progress flag, engagement risk flag, exam accommodation, learning plan, school advice, attestation).
- Two that have one are not stamped on every write. Enrolment and final grade get a `learnerRef` only from the example data and from credential renewal; an enrolment made through the form has none, and `fkResolve` on an empty value shows an empty cell, which is worse than the code.

Adding `learnerRef` plus a stamp and a back-fill to nine schemas would also change what the portal shows (the portal scopes on `learnerRef`, and an unstamped row stays out of it on purpose), so it is not a display fix.

## What changes

- learniq registers one cell widget, `learnerName`. It shows the pupil's learner profile by name: through the row's `learnerRef` when the row carries one, and otherwise by looking the profile up by the row's Nextcloud user id (`ncUserId`). Several pupils (a group hand-in) read as names separated by commas. Each profile is fetched once per page load, whichever list asks. A pupil without a profile keeps the user id, so the cell never goes blank.
- Every staff list that showed `learnerId` uses it, under the heading "Learner": the course and group enrolments, a programme's and a curriculum plan's final grades, a curriculum plan's and a grade scale's grades, an assessment's results and accommodations, an assignment's hand-ins, a report period's report cards, a learning plan template's plans, a regulation's attestations and outside training, and the attendance, study progress and engagement signals.
- The index pages of report cards, learning plans and school advice change their learner column the same way. The index pages of enrolments, grades, final grades and assessment results listed every schema property, uuids included; they now declare a teacher's columns.
- On the lists this change touches, a course and a group read by name (`fkResolve`) instead of by uuid.

## Not changed

- No schema, no stored data, no portal behaviour. Register version unchanged.
- The lists `pupils-read-by-name` already fixed keep `fkResolve` on `learnerRef`; those schemas are stamped on every write.
- Index pages outside this list that derive their columns from the schema (attendance signals, accommodations, BSA flags and similar admin indexes) still show every property. Listed as a follow-up.
