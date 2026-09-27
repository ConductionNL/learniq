# competency Specification

## ADDED Requirements

### Requirement: A coverage matrix shows goals by year with planned and assessed marked

The frontend MUST offer one custom page, `CurriculumCoverageMatrix` at `/curriculum/coverage`, rendering the registered
component `CurriculumCoverageMatrixView`, reachable from a "Curriculum coverage" entry in the Learning menu next to
"Curriculum" and visible to the staff roles that may read `CurriculumCoverage` (instructor, coordinator, team lead,
administration manager, admin). It MUST NOT also be a card on the Reports page. The page MUST render a read-only
`CnDataMatrix` built from the selected framework's `CurriculumCoverage` rows and `Competency` rows: the active goals as
rows in tree order with each domain as a heading row, the framework's year labels as columns in natural order (a
single "All years" column when there are none), and in each leaf goal's cell, only for the years the goal applies to,
one of "Planned and assessed", "Planned", "Assessed" or "Not covered" plus the deepest depth's level label. Status MUST
be carried by words, not colour alone. A framework and a subject filter (all subjects, each subject, no subject) MUST
narrow the matrix, and `?framework=<id>` MUST preselect a framework. The page MUST say that it shows the plan, not
what learners have mastered.

#### Scenario: A coordinator sees which goals of groep 5 are taught and tested

<!-- @e2e exclude Lanes may not drive the shared instance; the layout rules live in pure builders covered by tests/unit-js/curriculumCoverage.test.mjs ("the matrix shows goals under their domain, years as columns, cells only in a goal's years"). A Playwright pass is a follow-up once the rows can be seeded. -->

- **GIVEN** a framework whose domain "Getallen" holds K1 (planned and assessed, deepest depth "master") and K2
  (planned only), both for groep 5, and a root goal K3 that applies to every year and nothing covers
- **WHEN** a coordinator opens Curriculum coverage and picks that framework
- **THEN** the matrix lists Getallen as a heading, then K1 and K2 under it, then K3
- **AND** the groep 5 column reads "Planned and assessed (master)" for K1, "Planned" for K2 and "Not covered" for K3
- **AND** K1 and K2 show nothing in any other year column

#### Scenario: Picking a subject narrows the matrix

<!-- @e2e exclude Same reason; covered by tests/unit-js/curriculumCoverage.test.mjs ("a subject selection narrows the rows; a framework without years gets one column"). -->

- **GIVEN** the framework above, with K1 and K2 linked to the subject rekenen
- **WHEN** the coordinator picks rekenen in the subject filter
- **THEN** the matrix shows Getallen, K1 and K2 only, with the groep 5 column

#### Scenario: The report is a menu entry, not a card

<!-- @e2e exclude Manifest shape; covered by tests/unit-js/curriculumCoverage.test.mjs ("the page is a menu entry and not a Reports card") and the registry coverage test. -->

- **GIVEN** the merged manifest
- **WHEN** the Learning menu and the Reports page are read
- **THEN** a "Curriculum coverage" entry routes to `CurriculumCoverageMatrix`, and no Reports card does

### Requirement: A gap list names the uncovered goals per subject and year

Below the matrix the page MUST list, per subject (by course name, or "No subject") and per year (or "All years" when
the framework has no years), the goals nothing aligns to ("Not covered") and the goals planned but never assessed
("Planned, not assessed"), read from the `uncoveredIds` and `plannedNotAssessedIds` of the per-subject coverage rows.
Sections with no gap MUST be left out, and when no section remains the page MUST say that every goal is planned and
assessed. A framework without coverage rows yet MUST show a notice that coverage fills in when a goal, lesson,
course, assignment or assessment in it is saved, not an empty grid.

#### Scenario: The gap list shows what to plan and what to test

<!-- @e2e exclude Same reason; covered by tests/unit-js/curriculumCoverage.test.mjs ("the gap list names uncovered and untested goals per subject and year"). -->

- **GIVEN** the framework above
- **WHEN** the page renders
- **THEN** the gap list has "No subject · groep 5" with K3 under "Not covered"
- **AND** "Rekenen · groep 5" with K2 under "Planned, not assessed"
- **AND** no section for a subject and year without gaps

#### Scenario: A framework without coverage yet explains itself

<!-- @e2e exclude Same reason; covered by tests/unit-js/curriculumCoverage.test.mjs ("no coverage rows give an empty matrix, not an error") plus the notice in the view template. -->

- **GIVEN** a framework with no `CurriculumCoverage` rows
- **WHEN** it is picked
- **THEN** the page shows the notice that coverage fills in on the next save, and no matrix
