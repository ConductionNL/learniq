## ADDED Requirements

### Requirement: A school's menu and search show what its board shows

The vo portal MUST leave the BPV hours page (`learniq:studentHourWeeks`) out of its pupils' menu. The po portal's search MUST search its news.

#### Scenario: Noor's menu
- **GIVEN** Noor, a pupil at Vaartveld College
- **WHEN** she opens Mijn Vaartveld
- **THEN** her menu has no "BPV en uren"
- @e2e exclude declaration covered by PHPUnit `ExamplePortalDeclarationsTest::testEverySchoolPortalLeavesOutTheCaseItems`
