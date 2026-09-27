# attendance

## MODIFIED Requirements

### Requirement: Threshold crossing is a declared calculation trigger

The threshold-crossing detection MUST be a declared calculation +
`calculatedChange` trigger — NOT a PHP TimedJob. It MUST reuse the same
threshold machinery as compliance-`Regulation` coverage thresholds (no
parallel mechanism — ADR-022).

`AttendanceThreshold.x-openregister-calculations.unexcusedLesuren` is a
materialised, `x-openregister-aggregations`-backed per-cohort aggregate
(count of `attendance-record` rows where `cohortId == @self.cohortId` and
`status == 'absent-unexcused'`, scaled by `lessonHourMinutes`), and
`isThresholdCrossed` is `unexcusedLesuren >= limit` — the same
aggregate-then-compare shape `Regulation.coveragePercent`/`ragStatus` already
use. The existing `thresholdCrossed` notification's `calculatedChange`
trigger on `unexcusedLesuren` now fires for real.

A true per-INDIVIDUAL-learner (as opposed to per-cohort) continuously-live
crossing figure is NOT achievable by this mechanism alone —
`AttendanceThreshold` has no `learnerId` field to parameterise a per-learner
aggregate against, and this is a named, open platform gap (see
`openspec/changes/attendance-threshold-calculation/proposal.md`), not a
silent limitation.

#### Scenario: Threshold crossing fires via declared calculation trigger

- **GIVEN** an `AttendanceThreshold` rule expressed as a declared calculation
- **WHEN** a cohort's aggregated unexcused-lesuren count crosses the limit
- **THEN** detection fires through a `calculatedChange` trigger (not a PHP TimedJob), reusing the same
  threshold machinery as compliance-`Regulation` coverage thresholds (ADR-022)

#### Scenario: A guarded manual check records a real per-learner crossing and creates an AttendanceFlag

- **GIVEN** an `AttendanceThreshold` and a specific learner's externally-computed unexcused-lesuren value
  that meets or exceeds the threshold's `limit`
- **WHEN** the `check-threshold` transition is invoked with that learner id and metric value as inputs
- **THEN** `AttendanceThresholdCrossingGuard` allows the transition, `ObjectTransitionedEvent` fires with
  `action: check-threshold`, and `AttendanceFlagCreationHandler` creates an `AttendanceFlag` for that
  learner

#### Scenario: A guarded manual check below the limit is refused

- **GIVEN** an `AttendanceThreshold` and a learner's metric value below the threshold's `limit`
- **WHEN** the `check-threshold` transition is invoked with that value
- **THEN** `AttendanceThresholdCrossingGuard` refuses the transition and no `AttendanceFlag` is created
