# Tasks: site-pupil-portal-design

Built in waves. A key is declared only once portaliq development keeps it.

- [x] **T1**: `studentSessions` over `session` through the enrolment join, projected fields only (`StudentPortalPages::sessionsCollection()`); the change kinds read as words (`LESSON_CHANGE`)
  - PHPUnit `StudentTimetableTest::testSessionsAreReadThroughHerActiveEnrolments`, `::testTheProjectionNamesNoOtherPerson`, `::testNoorGetsTheMondayOfTheBoardThroughTheJoin` (walks the join over the real vo seed)
- [x] **T2**: `via.when: { field: lifecycle, in: [active] }` on the enrolment join, so a withdrawn enrolment grants no session
  - portaliq `PortalObjectReaderTest::testAJoinRowOutsideWhenGrantsNothing` (already on development, site-mijn-omgeving-components T1); PHPUnit `StudentTimetableTest` pins the declared `when`
- [x] **T3a**: `studentHomework` over published assignments by `learnerRefs` (never projected); the overview's `tasks` block over it
  - PHPUnit `PortalContributionProviderTest`, `GuardianSitePagesTest`
- [ ] **T3b** (waits for portaliq wave 6): the submission status lookup on the `tasks` block and `excludeWhen: { lookup: submission, in: [submitted, late, returned] }`
- [x] **T4**: `studentGrades` projects `courseName`, `methodName`, `methodBlock`, `weight`
  - PHPUnit `PortalContributionProviderTest`
- [x] **T5a**: `StudentPortalPages`: the overview (`home: true`, `group`, tasks, two `cta` tiles on actions, grades, inbox), the menu pages Inleveren, Cijfers, Toetsen and Afwezig melden, the other pages with `menu: false`
  - PHPUnit `GuardianSitePagesTest`; run through portaliq's own normalisers: nothing dropped
- [x] **T5b**: the timetable on the overview (`calendar`, `display: timetable`, `range: day`, with "Je eerste les") and a "Hele week" `cta` with `page`; the "Rooster" page in the menu as the week with day tiles (`range: week`). `firstLabel` joins the translated keys.
  - PHPUnit `GuardianSitePagesTest::testThePupilOverviewAndShortMenu`, `StudentTimetableTest::testTheTimetableBlocksReadProjectedFields`, `PortalLabelTranslatorTest`
  - Needs portaliq `calendar-timetable-display` (PR open); until it lands portaliq drops `display` and draws the plain calendar list
  - Not built: `cta` tiles with `page` for Cijfers and Toetsen (the board has no such tiles any more)
- [x] **T6**: the student manifest through `PortalLabelTranslator`; Dutch "je" entries
  - PHPUnit `PortalLabelTranslatorTest`; `npm run check:l10n`
- [x] **T7**: vo example set: a pupil portal account for one pupil, with assignments due this week
  - `python3 scripts/example-sets/vo.py --check`
  - done by `example-sets-are-the-four-schools`: Noor Bakker (vo-leerling-121) in H4b, seven assignments due in week 41, her account named by `learniq:example-set:load vo` (havo 4, as the design draws her, not havo 3)
- [x] **T9**: the overview follows the board: greeting, homework and tests as a highlight, the newest grades, the absence strip over the new `studentAttendanceSummary`
  - PHPUnit `GuardianSitePagesTest`, `PortalContributionProviderTest`
- [ ] **T8**: e2e: the pupil reads today's lessons and hands in from the overview
  - `tests/e2e/vo-pupil-flows.spec.ts`

## Follow-ups (not in this change)

- A resit window on a grade or test, with the examination regulation.
- A readable teacher on a session, stamped by the timetable source (the board shows "mevrouw Kramer").
- A lesson linked to its homework or test ("SO woordjes", "Leesverslag inleveren" on the board).
- Lessons from planninq when it is installed: the portal reads learniq `session` rows only.
- A server-side date window on `studentSessions`: portaliq reads at most 200 rows per collection; a full school year of one group's lessons is more than that.
- Returned feedback as an inbox notice.
