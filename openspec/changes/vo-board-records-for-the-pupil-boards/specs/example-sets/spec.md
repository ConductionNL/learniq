## ADDED Requirements

### Requirement: The secondary school set holds the records the 3HV-B boards draw

The vo example set MUST hold, for class 3HV-B, the pupils Daan Visser, Julia Peters and Lucas van Dijk with an active enrolment in the class; Daan's care note and support request; Julia's concept grade for Nederlands; Lucas's unexcused absence at Nederlands; a Biologie test open to take on the reference day, a published one not yet open and a closed Nederlands test; the assignment Betoog schrijven with its submissions and marks; and, for the school, an admission after the intake in a 2027-2028 round, an own 8-hour attendance threshold, a bilingual course and the Meldcode regulation. The records MUST be added after every other object without a random draw, so no existing uuid moves.

#### Scenario: A fresh load carries the board records
- **GIVEN** a fresh instance
- **WHEN** the operator loads the vo set
- **THEN** Daan Visser's dossier holds the care note of 2 October, Julia Peters has a concept 8,2 for Nederlands, Lucas van Dijk is unexcused absent at Monday's Nederlands, 3HV-B has an open Biologie test, and Betoog schrijven has 14 handed-in submissions
- @e2e exclude covered by PHPUnit `SecondarySchoolBoardRecordsTest` and the generator check (`scripts/example-sets/vo.py --check`)
