## ADDED Requirements

### Requirement: LearnerProfile declares age-derived self-service-rights flags

`LearnerProfile` MUST carry a materialised `ageYears` (calculated via `dateDiff` from `birthDate` to `now` in
years), and two materialised booleans derived from it: `hasPartialSelfServiceRights` (true when
`ageYears >= 12`) and `hasFullSelfServiceRights` (true when `ageYears >= 16`) — finding 2.11. These are the
data-model half only; no portal UI or action gating is built by this requirement (a separate, `code`-kind
change's responsibility).

#### Scenario: A 13-year-old learner has partial but not full self-service rights

- **GIVEN** a `LearnerProfile` with `birthDate` 13 years before today
- **WHEN** the row is read
- **THEN** `ageYears` is `13`, `hasPartialSelfServiceRights` is `true`, `hasFullSelfServiceRights` is `false`

#### Scenario: A learner under 12 has neither self-service right

- **GIVEN** a `LearnerProfile` with `birthDate` 9 years before today
- **WHEN** the row is read
- **THEN** both `hasPartialSelfServiceRights` and `hasFullSelfServiceRights` are `false`

### Requirement: Six schemas declare a retention-and-destruction annotation

`LearnerProfile` and `AttendanceRecord` MUST declare `x-openregister-archival.retention.default: "P5Y"`;
`AttendanceFlag` MUST declare `"P3Y"`; `DossierNote`, `BehaviourIncident`, and `WellbeingCheckIn` MUST each
declare `"P2Y"` — each with a `category` naming the retention rationale and `action: "destroy"` (finding
2.9). OpenRegister's own `ArchivalRetentionTask` cron, destruction-list approval workflow, and
`archival.destroyed` audit-trail logging (all `status: done` in `openregister/openspec/specs/
archival-destruction-workflow`) implement the sweep, approval, and log — learniq declares only the
annotation.

#### Scenario: A LearnerProfile row's archiefactiedatum is calculated from its retention period

- **GIVEN** the `LearnerProfile` schema declares `x-openregister-archival.retention.default: "P5Y"`
- **WHEN** a new `LearnerProfile` object is created
- **THEN** its `retention.archiefactiedatum` is set to its creation date plus 5 years, per OpenRegister's own
  default archival-metadata behaviour

#### Scenario: A user-driven delete on an archival schema is rejected

<!-- @e2e exclude the 403 SCHEMA_ARCHIVAL_IMMUTABLE rejection is OpenRegister's own platform mechanism,
     already covered by archival-annotation-vocabulary's own test suite; this requirement only asserts that
     learniq's six schemas correctly declare the annotation that triggers it -->

- **GIVEN** a `DossierNote` whose schema declares `x-openregister-archival`
- **WHEN** a user attempts to delete it directly (not via the platform's destruction-list workflow)
- **THEN** OpenRegister rejects the delete with HTTP 403 `SCHEMA_ARCHIVAL_IMMUTABLE`
