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
