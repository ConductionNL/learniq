# Nextcloud App Specification

## ADDED Requirements

### Requirement: Menus follow the kind of organisation
Menu entries whose subject belongs to specific kinds of organisation MUST carry `visibleIf: {"workspace.segment": {"in": [...]}}` listing the segments that see them: staff compliance and external training for companies and training institutes; engagement and course evaluation for MBO, higher education, companies and training institutes; work placements (BPV) for MBO; study progress (BSA) for higher education; exam board, exam accommodations, applications, admissions rounds and review board for secondary school and up; subject choices for secondary school, MBO and higher education; school advies for primary and secondary school. Entries of the school shape (people, classes, attendance, schools and locations, pupil dossier, group plans, support requests, report periods and cards, parent conferences) MUST NOT carry a segment gate. This supersedes the `segment-feature-flags` requirement "Segment-based menu visibility is not implemented by a config-kind change": its precondition, an unpopulated `runtime.workspace`, no longer holds since `segment-runtime-bridge`.

#### Scenario: A primary school sees the school shape
- **GIVEN** `runtime.workspace.segment` is `po` and the user is an admin
- **WHEN** the navigation renders
- **THEN** people, attendance, schools, locations, pupil dossier, group plans, support requests, report cards, parent conferences and school advies show
- **AND** compliance, external training, BPV, BSA, exam board, exam accommodations, subject choices and intake do not

#### Scenario: BPV is for MBO
- **GIVEN** each of the six segments
- **WHEN** the BPV group's gate is evaluated
- **THEN** it shows for `mbo` and `corporate` only

### Requirement: The company default keeps every menu
Every `workspace.segment` gate MUST keep `corporate` visible, because `corporate` is the default of every install that never chose a segment (`segment-feature-flags` Decision 2). Every segment literal MUST be one of the six `LearniqSettings.segment` codes. `npm run check:menu-role-gates` MUST fail when either rule breaks.

#### Scenario: An install that never chose a segment
- **GIVEN** `runtime.workspace.segment` is `corporate`
- **WHEN** every menu entry is evaluated for an admin
- **THEN** every entry shows, as before this change

#### Scenario: A gate that forgets corporate
- **GIVEN** a menu entry with `visibleIf: {"workspace.segment": {"in": ["po"]}}`
- **WHEN** `npm run check:menu-role-gates` runs
- **THEN** it fails and names the entry

### Requirement: The wizard says what the segment does
The setup wizard's segment step MUST tell the admin that the app shows the menus that fit the chosen kind, with a Dutch catalogue entry.

#### Scenario: Reading the segment step
- **GIVEN** the setup wizard's `segment` step
- **WHEN** its body is read in English or Dutch
- **THEN** it says the app shows the menus that fit the choice, and that the choice can change later under App settings
