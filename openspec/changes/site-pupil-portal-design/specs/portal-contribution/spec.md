## ADDED Requirements

### Requirement: A pupil lands on an overview of today

The student audience MUST declare pages. The first MUST be `studentOverview`, "Overzicht", with `home: true`, showing in this order: work to hand in as a `tasks` block with `dueField: dueAt`, this week's timetable, two quick actions (hand in, report absent), the three newest grades and the two newest inbox messages. Every block MUST read a collection scoped to the pupil's own `learnerRef`. Design of record: `LearniqPupil.dc.html`.

#### Scenario: Noa opens the portal between classes
- GIVEN pupil Noa with one assignment due today at 23.59 and one due in 7 days
- WHEN she signs in on her phone
- THEN "Dit moet u nog doen" on `/mijn` shows both, today's first, each with its date and time
- AND below it this week's lessons and her three newest grades
- @e2e exclude planned: written with the build in tests/e2e/vo-pupil-flows.spec.ts (specs-only change)

### Requirement: The pupil menu is short

The student pages MUST declare `group: Mijn omgeving` and appear in the menu as: Overzicht, Rooster, Inleveren, Cijfers, Toetsen, Afwezig melden. Berichten is portaliq's own inbox entry. The default page of each student collection MUST keep its route and MUST declare `menu: false`.

#### Scenario: No collection names in the menu
- GIVEN Noa signed in
- WHEN she opens the menu
- THEN she sees the six entries and Berichten, and no "My submissions" or "My enrolments"
- @e2e exclude planned: written with the build in tests/e2e/vo-pupil-flows.spec.ts (specs-only change)

### Requirement: NEW: A pupil sees her own timetable

The student audience MUST declare `studentSessions`, the `session` rows of the cohorts the pupil is actively enrolled in, joined through her own enrolments. It MUST project only `cohortId`, `courseId`, `title`, `startsAt`, `endsAt`, `location`, `lifecycle` and `onlineMeetingUrl`. A cancelled session MUST show as cancelled ("valt uit"), not disappear. A session of a cohort the pupil is not enrolled in MUST NOT show. The join MUST declare `via.when: { field: lifecycle, in: [active] }`, so a withdrawn or failed enrolment grants no session. The overview shows the timetable as a `calendar` block with `range: week`; a one-day range is not offered by portaliq's contract. This is new work: no student collection reads sessions today.

#### Scenario: Today's lessons with a cancellation
- GIVEN Noa's group has wiskunde at 8.30, engels at 10.15 and a cancelled geschiedenis at 12.30 today
- WHEN she opens "Overzicht"
- THEN the week's timetable lists the three under today, the last marked "valt uit"
- @e2e exclude planned: written with the build in tests/e2e/vo-pupil-flows.spec.ts (specs-only change)

#### Scenario: Another group's lessons stay hidden
- GIVEN a session for a cohort Noa is not enrolled in
- WHEN she opens "Rooster"
- THEN that session is not there
- @e2e exclude server scope; pinned by a PortalContributionProviderTest on the enrolment join written with the build

#### Scenario: A withdrawn enrolment stops the timetable
- GIVEN Noa's enrolment in a cohort is `withdrawn`
- WHEN she opens "Rooster"
- THEN no session of that cohort shows
- @e2e exclude enforced by portaliq's `via.when` (REQ-SMO-023); covered by a portaliq reader test and a PortalContributionProviderTest on the declared `when`

### Requirement: NEW: A pupil sees the work she has to hand in

The student audience MUST declare `studentHomework`, the published assignments whose `learnerRefs` holds the pupil, with `title`, `dueAt` and a status from her own submission: "Open", "Ingeleverd", "Te laat ingeleverd" or "Nagekeken". `learnerRefs` MUST NOT be projected. Open work MUST link to the hand-in. This is new work: the pupil reads only her own submissions today.

#### Scenario: An open assignment links to the hand-in
- GIVEN a published assignment "Verslag biologie: de cel" for Noa's group, not handed in
- WHEN she taps it on "Overzicht"
- THEN the hand-in form opens for that assignment
- @e2e exclude planned: written with the build in tests/e2e/vo-pupil-flows.spec.ts (specs-only change)

#### Scenario: Handed-in work shows its status
- GIVEN Noa handed in "Verslag biologie"
- WHEN she opens "Inleveren"
- THEN the row reads "Ingeleverd"
- @e2e exclude planned: written with the build in tests/e2e/vo-pupil-flows.spec.ts (specs-only change)

### Requirement: A pupil's grade shows its subject and weight

`studentGrades` MUST project `courseName`, `methodName`, `methodBlock` and `weight`. A grade line MUST read the subject and test, the value, and "telt N keer mee" when the weight is not one.

#### Scenario: A double-weight grade
- GIVEN a published grade 7,2 for wiskunde, hoofdstuk 2, weight 2
- WHEN Noa opens "Overzicht"
- THEN she reads "Wiskunde, hoofdstuk 2", 7,2 and "Telt 2 keer mee"
- @e2e exclude planned: written with the build in tests/e2e/vo-pupil-flows.spec.ts (specs-only change)

### Requirement: Every pupil label on the site reads in Dutch

The student manifest MUST pass through `PortalLabelTranslator`. Every student label MUST have a Dutch entry in the "je" form. A Dutch site MUST show no English string from learniq to a pupil.

#### Scenario: The pupil site reads Dutch
- GIVEN the Esdoornveen site in Dutch
- WHEN Noa opens any of her pages
- THEN every heading, button and menu entry from learniq is Dutch
- @e2e exclude planned: written with the build in tests/e2e/vo-pupil-flows.spec.ts (specs-only change)
