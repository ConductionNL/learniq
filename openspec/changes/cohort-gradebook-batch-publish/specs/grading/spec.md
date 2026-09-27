# grading Specification

## ADDED Requirements

### Requirement: A teacher previews and batch-publishes a cohort's concept grades

`CohortGradebookView` MUST offer, under the grid, a publish panel scoped to one plan component or to
all components. For the marks in scope that are `concept` or `published` it MUST show the count,
average, lowest and highest mark and a histogram with a text count per band, and, when the plan's
`GradeScale` has a `passThreshold`, how many pass. It MUST offer "Publish N marks", where N is the
number of `concept` entries in scope with a numeric value, and MUST ask for confirmation first. On
confirmation it MUST fire the existing `publish` transition for each of those entries, continue past
a refused entry, and then report how many were published and which were not, with the reason.

#### Scenario: The preview shows the spread before anything is published

<!-- @e2e exclude Distribution logic covered by node test tests/unit-js/gradebookPublish.test.mjs; lanes do not run against the shared instance. -->

- **GIVEN** a component with concept marks 4.5, 6.0, 7.5 and 8.0 on a scale with `passThreshold` 5.5
- **WHEN** the teacher picks that component
- **THEN** the panel shows 4 marks, average 6.5, lowest 4.5, highest 8.0 and 3 passing, and nothing
  is published yet

#### Scenario: Batch publish publishes every concept mark in scope

<!-- @e2e exclude Node test tests/unit-js/gradebookPublish.test.mjs covers which entries are publishable; the transition is the existing GradeEntry publish. -->

- **GIVEN** a component with 3 concept marks, 1 published mark and 1 concept entry without a value
- **WHEN** the teacher confirms "Publish 3 marks"
- **THEN** the `publish` transition fires for exactly those 3 entries

#### Scenario: A refused entry does not stop the batch

<!-- @e2e exclude Node test tests/unit-js/gradebookPublish.test.mjs (publishReport). -->

- **GIVEN** a batch of 3 where the server refuses one entry because its report period is locked
- **WHEN** the batch runs
- **THEN** the other 2 are published and the panel names the refused learner with the reason
