# School Structure — Programmes, Curriculum Plans, Cohorts, Sessions

## ADDED Requirements

### Requirement: Cohort carries free-text notes
`Cohort` MUST declare `notes` (nullable string) additively.

#### Scenario: A coordinator adds a note to a group
- **GIVEN** a `Cohort`
- **WHEN** `notes` is set to a free-text observation
- **THEN** the value persists on the `Cohort` object

#### Scenario: A pre-existing Cohort without notes is unaffected
- **GIVEN** a pre-existing `Cohort` row with no `notes` set
- **WHEN** it is read
- **THEN** `notes` resolves to `null`

### Requirement: CohortDetail surfaces notes and a today-scoped session view
`CohortDetail` MUST render a widget showing `Cohort.notes`, and a widget listing this cohort's `Session`s filtered to the current day using the manifest's `@today` filter-token grammar.

#### Scenario: A coordinator reads and edits the group's notes from the group page
- **GIVEN** `CohortDetail` for a cohort with `notes` set
- **WHEN** the page renders
- **THEN** the notes widget shows the current value

#### Scenario: A coordinator sees only today's sessions for this cohort
- **GIVEN** `CohortDetail` for a cohort with sessions on multiple days
- **WHEN** the page renders
- **THEN** the today's-sessions widget lists only sessions whose `startsAt` falls within the current day

### Requirement: CohortDetail's header already names the cohort
`CohortDetail`'s header MUST show the cohort's own `name`, not the static page-type label. This is satisfied by the current `@conduction/nextcloud-vue` `CnDetailPage` component's `objectDisplayName`/`displayTitle` resolution (which prefers `obj.name` over the `title` prop) consuming `Cohort.name` (already a required property); no manifest change is needed.

#### Scenario: A coordinator opens a cohort and sees its name in the header
- **GIVEN** `CohortDetail` for a cohort named "Groep 5/6"
- **WHEN** the page renders and the object has loaded
- **THEN** the header reads "Groep 5/6", not the literal string "Cohort"
