# assignments Specification

## ADDED Requirements

### Requirement: An assignment can ask for more than one marker

`Assignment` MUST declare `markersPerSubmission` (integer, minimum 1, maximum 5, default 1) and `finalGradeRule` (`manual`, `average` or `highest`, default `manual`). With `markersPerSubmission` at 1 the marking flow MUST behave exactly as before this change. Every `Assignment` stored before this change MUST read as `markersPerSubmission: 1`.

#### Scenario: A thesis assignment asks for two markers

<!-- @e2e exclude Register shape with no screen of its own; the assignment form renders every property. Covered by DoubleMarkingRegisterTest. -->

- **GIVEN** a coordinator editing the assignment "Afstudeerverslag bedrijfskunde"
- **WHEN** they set two markers per submission and the final grade rule to agreed by hand, and save
- **THEN** the assignment stores `markersPerSubmission: 2` and `finalGradeRule: manual`
- **AND** a value of 6 markers is refused by the schema

### Requirement: The teacher in charge allocates markers

A user in `instructors`, `compliance-officers` or `team-leads` MUST be able to allocate markers to the handed-in submissions of an assignment with `markersPerSubmission` above 1, from the assignment's submissions list, for all submissions at once or for one. Allocation MUST create one `draft` `SubmissionMark` per marker and submission, MUST NOT create a second row for a (marker, submission) pair that already has one, MUST refuse more markers than `markersPerSubmission`, and MUST refuse a marker who is one of the submission's learners.

#### Scenario: A coordinator allocates two markers to every hand-in

- **GIVEN** the assignment "Afstudeerverslag bedrijfskunde" with two markers per submission and twelve handed-in submissions
- **WHEN** the coordinator opens the submissions list, chooses "Allocate markers", picks j.devries and a.bakker and confirms
- **THEN** each of the twelve submissions shows both markers
- **AND** running the allocation again adds no second row for either marker

#### Scenario: A learner cannot mark their own group work

<!-- @e2e exclude Service rule; covered by SubmissionMarkAllocationServiceTest::testRefusesAMarkerWhoIsALearnerOfTheSubmission. -->

- **GIVEN** a group submission whose learners include s.jansen, who is also a student assistant in `instructors`
- **WHEN** the coordinator allocates s.jansen as a marker on that submission
- **THEN** the allocation is refused with a reason naming the submission

### Requirement: Each marker scores in their own SubmissionMark

When a submission has allocated markers, the marking screen MUST save a marker's rubric scores, grade and notes into that marker's own `SubmissionMark` and MUST fire `submit` on it. It MUST NOT change or return the `Submission`. A marker's notes in `SubmissionMark.feedbackText` MUST NOT be shown to the learner.

#### Scenario: The first marker hands in a mark

- **GIVEN** j.devries is allocated to a submission of "Afstudeerverslag bedrijfskunde"
- **WHEN** j.devries opens the submission's marking screen, scores the rubric to 7.5 and saves
- **THEN** j.devries's mark shows as submitted
- **AND** the submission is still waiting for marks and has not been returned to the learner

### Requirement: A marker sees other marks only after submitting their own

A marker MUST NOT be able to read another marker's `SubmissionMark` for the same submission while their own mark is `draft`. After their own mark is `submitted`, and for users in `compliance-officers` or `team-leads` at any time, the other marks MUST be readable. The rule MUST hold for the API as well as the screen.

#### Scenario: The second marker cannot peek

<!-- @e2e exclude Access rule on an endpoint; covered by SubmissionMarkControllerTest::testDraftMarkerSeesOnlyOwnMark. -->

- **GIVEN** j.devries has submitted a mark and a.bakker's mark on the same submission is still a draft
- **WHEN** a.bakker requests the marks of that submission from `GET /api/submissions/{id}/marks`
- **THEN** only a.bakker's own draft comes back

### Requirement: One person sets the final grade once every mark is in

When every allocated mark on a submission is `submitted`, the marking screen MUST show every mark side by side and a final grade field to an allocated marker and to users in `compliance-officers` or `team-leads`. The field MUST start at the average of the submitted grades when `finalGradeRule` is `average`, at the highest when it is `highest`, and empty when it is `manual`. Saving MUST run the existing save and return path, so the `Submission` gets the final grade, one `concept` `GradeEntry` is created and the submission is returned, and MUST record `finalGradeSetBy` and `finalGradeRuleApplied`. No code path MUST write the final grade without that save.

#### Scenario: Two markers agree a grade

- **GIVEN** a submission with marks of 7.5 from j.devries and 6.8 from a.bakker, both submitted, and the rule agreed by hand
- **WHEN** the coordinator opens the marking screen, reads both marks side by side, enters 7.2 and saves
- **THEN** the submission is returned to the learner with grade 7.2
- **AND** the gradebook holds one concept grade of 7.2 for that learner
- **AND** the submission records the coordinator as the person who set the final grade

#### Scenario: The average rule proposes a grade and waits for a person

- **GIVEN** the same two marks and the rule average
- **WHEN** the coordinator opens the marking screen
- **THEN** the final grade field shows 7.15
- **AND** nothing is saved or returned until the coordinator confirms
