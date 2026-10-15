## ADDED Requirements

### Requirement: The trainer reads whose week it is and what was done

A week of BPV hours waiting for the workplace trainer MUST show the student's name, the week's working days in words, the hours and, when the student gave one, her description of the work.

#### Scenario: Petra's weeks to approve
- **GIVEN** Milan sent 16 hours for the week of 28 September with a description
- **WHEN** Petra opens her overview
- **THEN** the week reads "Milan de Boer", "28 september tot en met 2 oktober", 16 hours and Milan's description
- @e2e exclude covered by PHPUnit `ReadableCopyStampTest::testAWeekOfHoursNamesItsStudentAndDays`

### Requirement: The trainer sends a week back with a question

The workplace trainer MUST be able to send a waiting week of her own student back with a question. The week MUST then approve no hours and carry the question as its note, which the student reads on her hours page. A week without a question MUST NOT be sent back.

#### Scenario: Tuesday at the dentist
- **GIVEN** Milan's week waits for Petra
- **WHEN** Petra sends it back with "Dinsdag ging je om 14.00 uur naar de tandarts. Wil je de uren aanpassen?"
- **THEN** the week is sent back with 0 hours approved and Milan reads the question on his hours page
- @e2e exclude covered by PHPUnit `PortalHourWeekApprovalTest::testAWeekIsSentBackWithAQuestion`
