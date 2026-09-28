# data-exchange Specification

## ADDED Requirements

### Requirement: Pupil, cohort, report-card, and attendance indexes declare columns and explicit built-in mass-action toggles

`LearnerProfiles`, `Cohorts`, `ReportCards`, and `AttendanceRecords` MUST each declare `columns`
(a curated subset of already-existing fields, replacing the generic default column set) and
`actionToggles` with `showMassImport: true`, `showMassExport: true`, `showMassCopy: true`, and
`showMassDelete: true` — making `CnIndexPage`'s existing built-in mass-action capability an
explicit, documented feature of these four indexes rather than an implicit platform default.

#### Scenario: The four indexes expose their curated columns and explicit mass-action toggles

<!-- @e2e exclude Declarative manifest column/actionToggles addition, no new component behaviour to exercise; verified by reasoning over the built effective manifest (build_effective_manifest.js), mirroring how report-card-templates and care-and-support-index (sibling changes this round) verified their own manifest-only additions. -->

- **GIVEN** the manifest is built
- **WHEN** `LearnerProfiles`, `Cohorts`, `ReportCards`, and `AttendanceRecords` are each inspected
- **THEN** each declares a non-empty `columns` array and `actionToggles.showMassImport`/
  `showMassExport`/`showMassCopy`/`showMassDelete` all `true`

### Requirement: An Import & export nav entry makes the existing data-exchange tooling discoverable

The system MUST declare an `ImportExportToolsMenu` nav entry under the existing `GroupDataExchange`
menu group, labelled "Import & export", routing to the existing `DataExchangeJobs` page — no new
page is declared, per ADR-097 Decision 5's "a second index over an already-indexed schema is a
role lens, not a page" preference (already applied by `care-and-support-index`, a sibling change
this round, for the same reason).

#### Scenario: The Import & export entry routes to the existing DataExchangeJobs page

<!-- @e2e exclude Declarative manifest nav addition, no new route or component; verified by reasoning over the built effective manifest. -->

- **GIVEN** the manifest is built
- **WHEN** `ImportExportToolsMenu` is inspected
- **THEN** it routes to `DataExchangeJobs`, and no second `type: "index"` page exists over the
  `data-exchange-job` schema
