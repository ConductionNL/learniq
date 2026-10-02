# Tasks: site-pupil-portal-design

Specs only so far. Build starts after Ruben approves the specs, `site-guardian-portal-design` T1 lands `GradeEntry.courseName`, and portaliq names the keys it accepts.

- [ ] **T1**: `studentSessions` over `session` through the enrolment join, projected fields only
  - PHPUnit `PortalContributionProviderTest`
- [ ] **T2**: `via.when: { field: lifecycle, in: [active] }` on the enrolment join, so a withdrawn enrolment grants no session
  - portaliq reader test; PHPUnit `PortalContributionProviderTest`
- [ ] **T3**: `studentHomework` over published assignments by `learnerRefs`, with the submission status lookup
  - PHPUnit `PortalContributionProviderTest`
- [ ] **T4**: `studentGrades` projects `courseName`, `methodName`, `methodBlock`, `weight`
  - PHPUnit `PortalContributionProviderTest`
- [ ] **T5**: `StudentPortalPages`: the overview, the seven menu pages, the default pages hidden from the menu
  - PHPUnit for the new class
- [ ] **T6**: the student manifest through `PortalLabelTranslator`; Dutch "je" entries
  - PHPUnit `PortalLabelTranslatorTest`; `npm run check:l10n`
- [ ] **T7**: vo example set: a pupil portal account for one havo 3 pupil, with assignments due this week
  - `python3 scripts/example-sets/vo.py --check`
- [ ] **T8**: e2e: the pupil reads today's lessons and hands in from the overview
  - `tests/e2e/vo-pupil-flows.spec.ts`

## Follow-ups (not in this change)

- A resit window on a grade or test, with the examination regulation.
- A readable teacher on a session, stamped by the timetable source.
- Returned feedback as an inbox notice.
