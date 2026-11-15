## ADDED Requirements

### Requirement: The teacher availability list reads words
The teacher availability list MUST show the round by name, the teacher by display name, the free time blocks as days and times in the reader's language and time zone, and the status as a label. It MUST NOT show the round's uuid, the teacher's user id or the stored JSON of the blocks. The server MUST write the teacher's display name on the availability on every create and update, replacing a value a client sends, and an upgrade MUST write it on stored availabilities.

#### Scenario: A coordinator reads the teacher availability list
@e2e exclude Manifest content and a list formatter. Pinned by tests/unit-js/teacherAvailabilityReadsWords.test.mjs; the live check on the primary-school instance is in the PR.
- **GIVEN** Meester Daan submitted his availability for the autumn round, Thursday 15 October from 18:00 to 20:00
- **WHEN** a coordinator opens Teacher availability in Dutch
- **THEN** the row reads the round's name, "Meester Daan" and "do 15 okt, 18:00–20:00"
- **AND** no uuid, user id or JSON

#### Scenario: A sent teacher name is replaced
@e2e exclude Listener. Pinned by tests/Unit/Listener/ReadableCopyStampTest.php.
- **GIVEN** a client saves an availability for po-leerkracht-09 with teacherName "Iemand anders"
- **WHEN** the availability is stored
- **THEN** teacherName is the teacher's display name
