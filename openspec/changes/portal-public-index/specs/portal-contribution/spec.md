## ADDED Requirements

### Requirement: A school offers its portal an index of its public courses, programmes and school days

`PortalContributionProvider::getPublicIndex(string $portal)` MUST answer the portal's public index: every published course that is offered (a training course, `level: corporate`, or one with `selfEnrolment`) with a run still to come (its first run's first day as `date`, last day as `endDate`, the number of days, the first days of up to three other runs, and the facets "Plaats" and "Start in"); every published programme (with the facets "Niveau" and "Leerweg" when its description names them); and every school-wide `school-event` still to come. A draft or archived course or programme, a course with no run to come, a cancelled lesson, a day for some groups only and a day that is over MUST stay out. On an example portal only that example set's objects count; on any other portal all public objects count.

#### Scenario: The academy's F-gassen course
- GIVEN the training example set on Monday 5 October 2026
- WHEN portaliq asks the index of `warmtepompacademie`
- THEN "F-gassen: herhaling en examen" has date 2026-10-08, one day, place "Praktijkhal Zuiddrecht" and start "Oktober 2026"
- @e2e exclude portaliq draws the catalogue; PortalPublicIndexTest reads the real seeds

#### Scenario: Each example portal its own set
- GIVEN every example set loaded on one instance
- WHEN portaliq asks the index of `wilgenboom`
- THEN it holds no course and no programme of another set
- @e2e exclude covered by PortalPublicIndexTest

#### Scenario: A group's own day stays private
- GIVEN the po set's trip "Naar de kinderboerderij" for some groups
- WHEN portaliq asks the index of `wilgenboom`
- THEN that day is not in it
- @e2e exclude covered by PortalPublicIndexTest

### Requirement: The public index carries a school's published test schedule

A school's public index MUST include, for each exam period the school marked public, its `exam-sitting` rows with day, start and end time, subject name, room name, and the department and year of the sitting's cohorts, filterable by department and year and by exam period. A sitting of a period not marked public, or a sitting in `draft`, MUST NOT be in the index. A sitting MUST NOT carry the names of pupils or invigilators.

#### Scenario: Toetsweek 1 for 4 havo
- **GIVEN** toetsweek 1 (9 to 13 November) is public and 4 havo has wiskunde A on Tuesday 10 November 08.30 to 10.10
- **WHEN** the index is read with department havo, year 4, period toetsweek 1
- **THEN** the item reads "Di 10 nov, 08.30 - 10.10, Wiskunde A" with its room, and no pupil names
- @e2e tests/e2e/portal-design/vaartveld.spec.ts

#### Scenario: A rescheduled test follows
- **GIVEN** the roostermaker moves that sitting to Wednesday
- **WHEN** the index is read again
- **THEN** the item reads Wednesday
- @e2e exclude index rebuild asserted in `PortalPublicIndexTest`

### Requirement: Every school day in the index has a category

Each school-day item of the public index MUST carry a category derived from `school-event.kind`: holiday, study day or day off, school activity, or parent evening, so a calendar block can show only the categories its editor chose.

#### Scenario: Only holidays and days off
- **GIVEN** De Wilgenboom's school days include the autumn holiday, a study day on 9 October and the school photographer on 7 October
- **WHEN** a calendar block asks for holidays and study days
- **THEN** it receives the holiday and the study day, not the photographer
- @e2e exclude category mapping asserted in `PortalPublicIndexTest`


### Requirement: A public course or programme has a page of its own

Every course and programme item of the public index MUST carry a `slug` made from its title (lower case, letters and digits, words joined by `-`, at most 100 characters), the address part of its page on the portal. `PortalContributionProvider::getPublicDetail(string $portal, string $id)` MUST answer the page of the item with that id in this portal's public index, in the shape of portaliq's `public-detail-page-for-a-provider-item`: its `title`; as `facts` its kind, its dates ("8 okt", or "3 nov – 10 nov" for a run of several days) and each of its facets; its description as a section "Omschrijving"; and, when the portal declares a course list, a `secondaryLink` to it. The page MUST be made from the index item only, so it says nothing the index does not already show a visitor. An id this portal's index does not list (a draft, another example set's item, a run that is over, an unknown id) and an item of another type (a school day, a test) MUST answer null.

#### Scenario: The F-gassen course page
- GIVEN the training example set on Monday 5 October 2026
- WHEN portaliq asks the detail of the F-gassen course of `warmtepompacademie` with the id the index gave
- THEN the page reads the title, kind "Cursus", date "8 okt", place "Praktijkhal Zuiddrecht", start "Oktober 2026", and a link back to `/cursusaanbod`
- @e2e exclude portaliq draws the page; PortalPublicIndexTest::testACourseHasAPageOfItsOwn reads the real seeds

#### Scenario: A programme page
- GIVEN the mbo example set
- WHEN portaliq asks the detail of "Mechatronica" of `esdoornveen`
- THEN the facts read level "Niveau 4" and learning path "BOL, BBL", and no date
- @e2e exclude covered by PortalPublicIndexTest::testAProgrammeHasAPageOfItsOwn

#### Scenario: Another portal's course has no page here
- GIVEN every example set loaded on one instance
- WHEN portaliq asks `esdoornveen` for the detail of the academy's F-gassen course
- THEN the answer is null
- @e2e exclude covered by PortalPublicIndexTest::testOnlyWhatTheIndexListsHasAPage
