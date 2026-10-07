# Proposal: parent-portal-teacher-names

## Why

Ruben reviewed the parent portal recordings (2026-10-02). A table showed a teacher's Nextcloud user id ("po-leerkracht-09"), and the parent section was headed "Learniq", the app's name, on a Dutch school site. Portaliq now swaps a user id for the user's display name on the server when a column declares `render: user` (portaliq#1077).

## What changes

- Every parent column that holds a staff user id declares `render: user`:
  - "Met" on the free times and the conversation times reads `teacherId`. Before, it read the `teacherName` copy, which older preference-round slots do not carry.
  - "Met" on the bookings reads `requestedTeacherIds`.
  - "Besloten door" on the absence reports reads `decidedBy`. It is now projected, and leaves the server only as a name.
- No other column changes. The report card, homework and attendance collections hold no teacher user id in their projections.
- The parent contribution is labelled "School" instead of "Learniq". The student, praktijkopleider and external-assessor audiences keep "Learniq".

## Not changed

- Scope, filters and every other column.
