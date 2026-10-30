## ADDED Requirements

### Requirement: The guardian gets one task per child per open conference round

For every conference round in `booking-open`, the system MUST keep one invitation row per invited child. The row MUST be `open` while that child has no conversation time in the round, `booked` once the child has one, and `closed` when the round is no longer open for booking or the child is no longer invited. A booked, cancelled or declined time MUST move the child's row right after the write. The round itself MUST NOT be changed.

#### Scenario: One child booked, the other not
- **GIVEN** groep 7's round is open with Vera's time acknowledged, and groep 4's round is open with no time for Sami
- **WHEN** the invitations are written
- **THEN** Vera's row in groep 7's round is `booked` and Sami's row in groep 4's round is `open`
- @e2e exclude covered by PHPUnit `ConferenceInvitationsTest::testTheSeededInvitationsAreWhatTheServiceWrites`

#### Scenario: A cancelled time asks again
- **GIVEN** Sami's row is `booked` through a booked time
- **WHEN** the guardian cancels that time
- **THEN** Sami's row is `open` again
- @e2e exclude covered by PHPUnit `ConferenceInvitationSyncTest::testABookedTimeEndsTheTaskAndACancelBringsItBack`

#### Scenario: A closed round asks nothing
- **GIVEN** a round in `booking-closed`
- **WHEN** its invitations are synced
- **THEN** no new row is written and an open row becomes `closed`
- @e2e exclude covered by PHPUnit `ConferenceInvitationsTest::testAClosedRoundAsksNothing`
