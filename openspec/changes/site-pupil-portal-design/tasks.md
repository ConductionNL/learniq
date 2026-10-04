# Tasks: site-pupil-portal-design

Built in waves. A key is declared only once portaliq development keeps it.

- [ ] **T1** (waits for portaliq `via.when`, see T2): `studentSessions` over `session` through the enrolment join, projected fields only
  - PHPUnit `PortalContributionProviderTest`
- [ ] **T2**: `via.when: { field: lifecycle, in: [active] }` on the enrolment join, so a withdrawn enrolment grants no session
  - portaliq reader test; PHPUnit `PortalContributionProviderTest`
- [x] **T3a**: `studentHomework` over published assignments by `learnerRefs` (never projected); the overview's `tasks` block over it
  - PHPUnit `PortalContributionProviderTest`, `GuardianSitePagesTest`
- [ ] **T3b** (waits for portaliq wave 6): the submission status lookup on the `tasks` block and `excludeWhen: { lookup: submission, in: [submitted, late, returned] }`
- [x] **T4**: `studentGrades` projects `courseName`, `methodName`, `methodBlock`, `weight`
  - PHPUnit `PortalContributionProviderTest`
- [x] **T5a**: `StudentPortalPages`: the overview (`home: true`, `group`, tasks, two `cta` tiles on actions, grades, inbox), the menu pages Inleveren, Cijfers, Toetsen and Afwezig melden, the other pages with `menu: false`
  - PHPUnit `GuardianSitePagesTest`; run through portaliq's own normalisers: nothing dropped
- [ ] **T5b** (waits for portaliq wave 6 and T1): the timetable on the overview (`calendar` `range: day`) and the "Rooster" page, `cta` tiles with `page` for Cijfers and Toetsen
- [x] **T6**: the student manifest through `PortalLabelTranslator`; Dutch "je" entries
  - PHPUnit `PortalLabelTranslatorTest`; `npm run check:l10n`
- [ ] **T7**: vo example set: a pupil portal account for one havo 3 pupil, with assignments due this week
  - `python3 scripts/example-sets/vo.py --check`
- [ ] **T8**: e2e: the pupil reads today's lessons and hands in from the overview
  - `tests/e2e/vo-pupil-flows.spec.ts`

## Follow-ups (not in this change)

- A resit window on a grade or test, with the examination regulation.
- A readable teacher on a session, stamped by the timetable source.
- Returned feedback as an inbox notice.
