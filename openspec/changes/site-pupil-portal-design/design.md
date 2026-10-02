# Design: site-pupil-portal-design

## Design of record

`LearniqPupil.dc.html` in the portal-design canvas (artifact `3Jy3r5e5f9v9ktCLxisNG6`): the leerlingportaal of Esdoornveen College on a phone, for pupil Noa.

## Mockup to declaration

| Mockup element | Learniq declares | Data today |
|---|---|---|
| "Hoi Noa. Je moet vandaag nog één ding inleveren." | page intro with a count of open hand-ins due today | `studentHomework` (NEW) |
| "Inleveren": title, due date and time, "Vandaag", "Over 7 dagen" | `tasks` block over `studentHomework`, open only, sort `dueAt` | `assignment.title`, `dueAt` |
| "Je rooster vandaag" with a cancelled lesson | calendar or timetable block over `studentSessions`, today | `session.startsAt`, `endsAt`, `title`, `location`, `lifecycle = cancelled` (NEW collection) |
| "Werk inleveren", "Cijfers", "Toetsen", "Afwezig melden" | four `cta` blocks | `createSubmission` / `handIn`, the grades page, `studentTests`, `createExcuseRequest` |
| "Nieuwste cijfers" with "Telt 2 keer mee" | `collection` block, `limit: 3`, `sort: gradedAt desc` | `grade-entry.value`, `weight` (projection added), `courseName` (guardian change) |
| "Berichten" | `inbox` block, `limit: 2` | `studentInbox` (grade notices), portaliq notices |
| "Vraag of probleem?" | `richText` block from the school's settings | none in learniq; portaliq page text |

## The timetable join (NEW)

A session belongs to a cohort, not to a pupil. The pupil's cohorts come from her own enrolments. The collection reads:

```
studentSessions
  schema: session            scopeField: cohortId      scopeClaim: learnerRef
  via: { schema: enrolment, scopeField: learnerRef, targetField: cohortId, match: scopeField }
  filter: lifecycle in [scheduled, in-progress, cancelled]
  fields: cohortId, courseId, title, startsAt, endsAt, location, lifecycle, onlineMeetingUrl
```

This is the same reverse join the parent audience uses (portal-parent design), with the pupil's own `learnerRef` as the start instead of a guardian. An enrolment in `withdrawn` or `failed` should not count. `via` has no filter on the joined schema today (see the comment on `poSharedPortfolios`), so a withdrawn enrolment would still let the pupil see that group's sessions. Two ways out, to settle at build time:

1. Portaliq adds a joined-schema filter to `via` (already flagged as a follow-up in `PortalContributionProvider`).
2. Accept it: a withdrawn pupil sees the old group's lessons until the enrolment is removed. That leaks a timetable, not personal data.

The spec requires option 1 or an equivalent. It does not accept option 2 silently.

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

`learnerRefs` is never projected, as on the parent side. `instructions` is projected on the detail only, so the pupil can read what to hand in.

## Labels

The student manifest passes through `PortalLabelTranslator`, like the parent one. Dutch copy uses "je", as in the mockup. The guardian copy uses "u". The translator picks the entry by key, so both forms can live in `l10n/nl.json`.

## Phone first

The mockup is a phone screen. Nothing in this change is desktop only. A timetable row is one line of time, course and place; there is no table to scroll sideways. That is a property of portaliq's components, named in `site-mijn-omgeving-components`.
