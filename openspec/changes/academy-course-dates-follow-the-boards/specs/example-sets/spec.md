## ADDED Requirements

### Requirement: The academy catalogue carries the boards' course dates and places

The training example set MUST carry the course dates board Zoeken lists, each with a capacity of 12, and the places other companies hold on them as board LqBord counts them, so that the places left per date are capacity minus live bookings and loose live enrolments. It MUST carry the courses "Hybride warmtepomp installeren en inregelen" (tag `basis`) and "Storingen zoeken aan warmtepompen" (tag `gevorderd`) as published corporate courses, and the board's summary lines as the course descriptions. The new rows MUST be appended after the story, so no uuid the story wrote moves, and no room or trainer may be in two places at once.

#### Scenario: The first F-gassen date has one place left and three later dates
- **GIVEN** the training set as the generator writes it
- **WHEN** the places of "F-gassen: herhaling en examen" are counted per date from 8 October
- **THEN** 8 October has 1 place left, 22 October 7, and 5 and 19 November 12 each
- @e2e exclude example data, covered by PHPUnit `TrainingExampleSetTest::testTheCatalogueCarriesTheBoardsDatesAndPlaces`; the rendered catalogue is compared by `tests/e2e/portal-design/warmtepompacademie.spec.ts`

#### Scenario: Hybride and F-gassen share 22 October without sharing a room
- **GIVEN** the training set
- **WHEN** the sessions of 22 October are listed
- **THEN** no room holds two sessions at the same time
- @e2e exclude example data, covered by PHPUnit `TrainingExampleSetTest::testNoTrainerOrRoomIsInTwoPlacesAtOnce`
