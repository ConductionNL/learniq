# Design: site-pupil-portal-design

## Design of record

`LearniqPupil.dc.html` in the portal-design canvas (artifact `3Jy3r5e5f9v9ktCLxisNG6`): the leerlingportaal of Esdoornveen College on a phone, for pupil Noa.

## Mockup to declaration

| Mockup element | Learniq declares | Data today |
|---|---|---|
| "Hoi Noa. Je moet vandaag nog één ding inleveren." | page intro with a count of open hand-ins due today | `studentHomework` (NEW) |
| "Inleveren": title, due date and time, "Vandaag", "Over 7 dagen" | `{ type: tasks, collection: studentHomework, dueField: dueAt, titleFields: [title], lookups: [submission], excludeWhen: { lookup: submission, in: [submitted, late, returned] } }` | `assignment.title`, `dueAt` |
| "Je rooster vandaag" with a cancelled lesson | `{ type: calendar, range: day }` with two sources over `studentSessions` (see below) | `session.startsAt`, `endsAt`, `title`, `location`, `lifecycle` (NEW collection) |
| "Werk inleveren", "Afwezig melden" | two `cta` blocks on actions | `createSubmission`, `createExcuseRequest` |
| "Cijfers", "Toetsen" | two `cta` blocks with `page: studentGrades` and `page: studentTests` (REQ-SMO-024) | the default pages of those collections |
| "Nieuwste cijfers" with "Telt 2 keer mee" | `collection` block, `limit: 3`, `sort: gradedAt desc` | `grade-entry.value`, `weight` (projection added), `courseName` (guardian change) |
| "Berichten" | `{ type: inbox, collection: studentInbox, limit: 2 }` | `studentInbox` (grade notices) |
| "Vraag of probleem?" | `richText` block from the school's settings | none in learniq; portaliq page text |

## The timetable join (NEW)

A session belongs to a cohort, not to a pupil. The pupil's cohorts come from her own enrolments. The collection reads:

```
studentSessions
  schema: session            scopeField: cohortId      scopeClaim: learnerRef
  via: { schema: enrolment, scopeField: learnerRef, targetField: cohortId, match: scopeField,
         when: { field: lifecycle, in: [active] } }
  filter: lifecycle in [scheduled, in-progress, cancelled]
  fields: cohortId, courseId, title, startsAt, endsAt, location, lifecycle, onlineMeetingUrl
```

This is the same reverse join the parent audience uses (portal-parent design), with the pupil's own `learnerRef` as the start instead of a guardian. An enrolment in `withdrawn` or `failed` must not count. Portaliq's `via.when` (REQ-SMO-023) does that: the join declares `when: { field: lifecycle, in: [active] }`. Learniq's enrolment field is `lifecycle`; portaliq's example scenario says `status`, which is only an example. A malformed `when` fails the join closed, to zero rows.

The calendar block shows the timetable with two sources over `studentSessions`, using the source grammar `ParentRecordPage::childSources()` already uses:

- `only: { field: lifecycle, in: [scheduled, in-progress] }`, kind "Les".
- `only: { field: lifecycle, in: [cancelled] }`, kind "Valt uit".

The overview declares `range: day`, so "Je rooster vandaag" shows today only. The "Hele week" link is a `cta` with `page: studentSessions`, whose page shows the calendar with `range: week`.

`affectedLearnerIds`, `affectedParentIds`, `substituteTeacherId` and `changeReason` are not projected. They name other people or carry staff notes.

## Work to hand in (NEW)

`assignment.learnerRefs` is stamped by `AssignmentLearnerRefsStamp` from the group's enrolments. Portaliq matches list membership since portaliq#750. The pupil reads:

```
studentHomework
  schema: assignment   scopeField: learnerRefs   scopeClaim: learnerRef
  filter: lifecycle = published
  fields: title, dueAt, cohortId, allowLateSubmission, lifecycle
  lookup: status from studentSubmissions by assignmentId (draft = Open, submitted, late, returned)
```

`learnerRefs` is never projected, as on the parent side.

The `tasks` block declares the lookup `{ as: submission, collection: studentSubmissions, matchField: assignmentId, valueField: lifecycle }` and `excludeWhen: { lookup: submission, in: [submitted, late, returned] }` (REQ-SMO-025). Handed-in work leaves "Inleveren" on the overview; a `draft` submission or none keeps the row. The "Inleveren" page lists every published assignment with its status. `instructions` is projected on the detail only, so the pupil can read what to hand in.

## Labels

The student manifest passes through `PortalLabelTranslator`, like the parent one. Dutch copy uses "je", as in the mockup. The guardian copy uses "u". The translator picks the entry by key, so both forms can live in `l10n/nl.json`.

## Phone first

The mockup is a phone screen. Nothing in this change is desktop only. A timetable row is one line of time, course and place; there is no table to scroll sideways. That is a property of portaliq's components, named in `site-mijn-omgeving-components`.
