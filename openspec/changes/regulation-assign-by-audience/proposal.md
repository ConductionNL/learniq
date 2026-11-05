---
kind: code
---

# Assign a regulation to one audience, after a preview

## Why

The group enrol dialog (board LqCfGroepInschrijven, lane LQB-C) lets a compliance officer pick a section, a team or an HR function, see how many staff that is and who is already enrolled, and then enrol only that audience. Today the assignment always enrols the regulation's whole audience and nothing previews it.

## What changes

- `GET /api/compliance/regulations/{id}/assign/preview?audienceKind=section|team|hr-function&audience=<value>` answers the course name and the audiences per kind, and once an audience is chosen the total, the number already enrolled, the number to enrol and the first 50 rows (name, HR function, enrol or enrolled). It writes nothing.
- `POST /api/compliance/regulations/{id}/assign` accepts `audienceKind`, `audience` and `dueDate` and enrols only that audience, with that due date. Without `audienceKind` it enrols the whole audience as before.
- Both answer only for a published regulation and only for a caller with the `regulation.assign` action, checked before the regulation is read.
- Audiences come from the learner profile: section is the top level of `department`, team the whole `department` path, HR function one of `roles`.

## Not in this change

- The dialog's `message` is not sent to the enrolled staff.
