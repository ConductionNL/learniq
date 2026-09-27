# Learning plan

## ADDED Requirements

### Requirement: Differentiation forms speak of support needs, level and goal
The teacher-facing titles and property descriptions of `GroupPlan`, `GroupPlanSubgroup`, `SupportRequest` and `ExamAccommodation` MUST describe differentiation by level, goal, time or material and the recording of support needs, in plain sentences without engineering rationale. Engineering rationale MUST live in the property's `x-notes`, which the form does not show. Every rewritten title and description MUST have an English catalogue key and a Dutch value.

#### Scenario: A teacher fills in an instruction group
- **GIVEN** a Dutch-language teacher opens the form for a group within a group plan
- **WHEN** the form renders
- **THEN** the title reads "Instructiegroep" and the approach field explains, in Dutch, how the group is taught to reach its goal

#### Scenario: Engineering notes stay out of the form
- **GIVEN** the `GroupPlan.supersedesId` property
- **WHEN** its description is read
- **THEN** it is one plain sentence, and the version-chain rationale sits in `x-notes`

### Requirement: Style-matching wording never reaches a product surface
A unit test MUST scan `lib/`, `src/`, `templates/`, `appinfo/`, `docs/`, `openspec/specs/` and `l10n/en.json` and `l10n/nl.json` for wording that claims pupils have fixed styles of learning to match, in English and Dutch, and MUST fail naming each file and line with a hit.

#### Scenario: Someone adds a style field
- **GIVEN** a change that adds a property described as the pupil's preferred style of learning using the forbidden wording
- **WHEN** the unit tests run
- **THEN** the scan test fails and names the register file and line

### Requirement: Settings explain the evidence once
The AI features section in Settings MUST show one note that Learniq does not profile how a pupil prefers to learn, that research finds no benefit in matching lessons to such a profile, and that teachers differentiate by level, goal, time and material and record support needs instead.

#### Scenario: An administrator reads the AI section
- **GIVEN** an administrator opens Learniq settings
- **WHEN** the AI features section renders
- **THEN** the note is shown with the NRO Kennisrotonde reference
