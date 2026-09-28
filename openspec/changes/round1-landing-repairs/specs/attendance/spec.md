# Attendance: threshold notification recipients delta

## ADDED Requirements

### Requirement: A crossed threshold notifies each recipient group once

The `thresholdCrossed` notification of `AttendanceThreshold` MUST name the mentor and the coordinator in one recipient entry, and no group MAY appear in more than one of its entries, so nobody receives the same notification twice.

#### Scenario: The mentor is notified once
@e2e exclude Register-content invariant; pinned by tests/Unit/Settings/AttendanceThresholdRegisterTest.php (testThresholdCrossedNotificationNotifiesMentorAndCoordinator).
- **GIVEN** an `AttendanceThreshold` whose unexcused hours rise to its limit
- **WHEN** the `thresholdCrossed` notification fires
- **THEN** its recipients are one entry naming `mentor` and `coordinator`
- **AND** no group is named in a second entry
